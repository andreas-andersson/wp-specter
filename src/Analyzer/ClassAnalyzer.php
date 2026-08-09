<?php

declare(strict_types=1);

namespace WpSpecter\Analyzer;

use WpSpecter\Finding\Finding;
use WpSpecter\Finding\FindingCertainty;
use WpSpecter\Finding\FindingType;
use WpSpecter\Parser\ClassDef;
use WpSpecter\Parser\ParseResult;
use WpSpecter\Parser\PhpTokenParser;

final class ClassAnalyzer
{
    // Methods PHP or WordPress core calls by contract (interface/base-class requirement), never
    // by a visible name reference anywhere in project code. Keyed by the interface/base class's
    // short name; only checked against a method's *own* declaring class's extends/implements
    // (see findClassHierarchy in PhpTokenParser) — not the whole inheritance chain, so a
    // grandchild class (extends My_Widget, which itself extends WP_Widget) won't get the
    // exemption. That's a known gap, not a silent one: it just falls back to normal matching.
    private const INTERFACE_CONTRACT_METHODS = [
        'ArrayAccess' => ['offsetExists', 'offsetGet', 'offsetSet', 'offsetUnset'],
        'Iterator' => ['current', 'key', 'next', 'rewind', 'valid'],
        'IteratorAggregate' => ['getIterator'],
        'Countable' => ['count'],
        'JsonSerializable' => ['jsonSerialize'],
        'Serializable' => ['serialize', 'unserialize'],
    ];

    private const BASE_CLASS_CONTRACT_METHODS = [
        'WP_Widget' => ['widget', 'form', 'update'],
        'WP_REST_Controller' => ['register_routes'],
        'Walker' => ['start_lvl', 'end_lvl', 'start_el', 'end_el'],
    ];

    // Bounds the extends-chain walk in isContractMethod() so a cyclic or malformed extends
    // graph (which would never happen in valid PHP, but this is a token parser with no
    // semantic validation) can't spin forever.
    private const MAX_INHERITANCE_DEPTH = 50;

    public function __construct(private readonly PhpTokenParser $parser) {}

    /**
     * @param list<string> $files
     * @return list<Finding>
     */
    public function analyze(array $files): array
    {
        $parseResults = array_map(fn(string $f) => $this->parser->parse($f), $files);

        $classDefsByName = [];
        foreach ($parseResults as $result) {
            foreach ($result->classDefs as $def) {
                $classDefsByName[$def->name] = $def;
            }
        }

        $findings = $this->findUnusedClasses($parseResults, $classDefsByName);
        array_push($findings, ...$this->findUnusedMethods($parseResults, $classDefsByName));

        usort($findings, fn(Finding $a, Finding $b) => $a->file <=> $b->file ?: $a->line <=> $b->line);

        return $findings;
    }

    /**
     * @param list<ParseResult> $parseResults
     * @param array<string,ClassDef> $classDefsByName
     * @return list<Finding>
     */
    private function findUnusedClasses(array $parseResults, array $classDefsByName): array
    {
        $referenced = [];
        foreach ($parseResults as $result) {
            foreach ($result->classReferences as $ref) {
                $referenced[$ref] = true;
            }
        }

        $findings = [];
        foreach ($classDefsByName as $name => $def) {
            if (!isset($referenced[$name])) {
                $findings[] = new Finding(
                    type: FindingType::UnusedClass,
                    name: $name,
                    file: $def->file,
                    line: $def->line,
                    certainty: FindingCertainty::Error,
                    note: $def->kind === 'class' ? null : 'unused ' . $def->kind,
                );
            }
        }

        return $findings;
    }

    /**
     * @param list<ParseResult> $parseResults
     * @param array<string,ClassDef> $classDefsByName
     * @return list<Finding>
     */
    private function findUnusedMethods(array $parseResults, array $classDefsByName): array
    {
        // Calls PhpTokenParser could resolve to a concrete receiver class — $this->method(),
        // self::/parent::/static::method(), and Foo::method() with a literal class name — are
        // precise: they're never added to the generic $called pool below at all (see
        // findScopedCallTarget in the parser), so they can't cause an unrelated same-named
        // method on some other class to look used.
        $scopedCalled = [];
        foreach ($parseResults as $result) {
            foreach ($result->scopedMethodCalls as $call) {
                $scopedCalled[$call->receiverClass][$call->method] = true;
            }
        }

        // Everything else — $obj->method() on a variable of unknown type, [$obj, 'method'] /
        // [Class::class, 'method'] array callbacks (the common add_action/add_filter shape),
        // string callbacks — still can't be attributed to a class, so it falls back to the same
        // name-only pool FunctionAnalyzer uses. This is where the remaining imprecision lives:
        // two unrelated classes sharing a method name (e.g. "render") will each look "used" if
        // either is called this way. Warning certainty (not Error) reflects that lower
        // confidence for whatever a finding's fallback-pool check alone couldn't rule out.
        $called = [];
        foreach ($parseResults as $result) {
            foreach ($result->functionCalls as $call) {
                $called[$call->name] = true;
            }
        }

        $findings = [];
        foreach ($parseResults as $result) {
            foreach ($result->functionDefs as $def) {
                if (
                    !$def->isMethod
                    || $this->isMagicMethod($def->name)
                    || isset($called[$def->name])
                    || isset($scopedCalled[$def->ownerClass ?? ''][$def->name])
                    || $this->isContractMethod($def->name, $def->ownerClass, $classDefsByName)
                ) {
                    continue;
                }
                $findings[] = new Finding(
                    type: FindingType::UnusedMethod,
                    name: $def->name,
                    file: $def->file,
                    line: $def->line,
                    certainty: FindingCertainty::Warning,
                );
            }
        }

        return $findings;
    }

    /**
     * Walks the full extends chain from $ownerClass upward — not just its own declaration's
     * clause — since a class extending My_Base_Widget, which itself extends WP_Widget, still
     * inherits the widget()/form()/update() contract even though "WP_Widget" never appears on
     * $ownerClass's own ClassDef. implements is checked at every level walked too, so an
     * interface attached higher up the chain (rather than redeclared on every subclass) is
     * still honored. Bounded depth (MAX_INHERITANCE_DEPTH) guards against a cyclic/malformed
     * extends graph.
     *
     * @param array<string,ClassDef> $classDefsByName
     */
    private function isContractMethod(string $methodName, ?string $ownerClass, array $classDefsByName): bool
    {
        if ($ownerClass === null) {
            return false;
        }

        $className = $ownerClass;
        for ($depth = 0; $depth < self::MAX_INHERITANCE_DEPTH; $depth++) {
            $def = $classDefsByName[$className] ?? null;
            if ($def === null) {
                return false;
            }

            foreach ($def->implements as $interface) {
                if (in_array($methodName, self::INTERFACE_CONTRACT_METHODS[$interface] ?? [], true)) {
                    return true;
                }
            }

            $base = $def->extends[0] ?? null;
            if ($base === null) {
                return false;
            }

            if (in_array($methodName, self::BASE_CLASS_CONTRACT_METHODS[$base] ?? [], true)) {
                return true;
            }

            $className = $base;
        }

        return false;
    }

    private function isMagicMethod(string $name): bool
    {
        return str_starts_with($name, '__');
    }
}
