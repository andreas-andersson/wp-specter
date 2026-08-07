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
        // Method calls aren't tracked separately from function calls — $obj->method(),
        // Class::method(), and [$obj, 'method'] callables all land in functionCalls under the
        // bare method name already (see PhpTokenParser). That also means this match is
        // name-only, not scoped to the declaring class: two unrelated classes sharing a method
        // name (e.g. "render") will each look "used" if either one is called anywhere. Warning
        // certainty (not Error) reflects that lower confidence.
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

    /** @param array<string,ClassDef> $classDefsByName */
    private function isContractMethod(string $methodName, ?string $ownerClass, array $classDefsByName): bool
    {
        if ($ownerClass === null) {
            return false;
        }

        $def = $classDefsByName[$ownerClass] ?? null;
        if ($def === null) {
            return false;
        }

        foreach ($def->implements as $interface) {
            if (in_array($methodName, self::INTERFACE_CONTRACT_METHODS[$interface] ?? [], true)) {
                return true;
            }
        }

        foreach ($def->extends as $base) {
            if (in_array($methodName, self::BASE_CLASS_CONTRACT_METHODS[$base] ?? [], true)) {
                return true;
            }
        }

        return false;
    }

    private function isMagicMethod(string $name): bool
    {
        return str_starts_with($name, '__');
    }
}
