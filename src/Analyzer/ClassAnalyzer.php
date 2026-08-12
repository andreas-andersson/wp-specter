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

    // Base classes whose subclasses get called entirely through framework naming-convention /
    // reflection, not through any fixed method-name contract — so no BASE_CLASS_CONTRACT_METHODS
    // list could ever be exhaustive. Every method (and the class itself) on a subclass is exempt.
    // Keyed by short name (same collision trade-off already accepted by the other curated lists
    // above, e.g. "Walker" isn't qualified either), value is the real FQCN. Unlike the other
    // lists, exempting a whole class is a big effect for a name collision to trigger by accident
    // (a project's own unrelated "Composer" class, say) — so isFullyExemptClass() checks the
    // FQCN via $useImports whenever the extending file actually imported one, and only falls
    // back to the bare short-name match when no import resolved it either way.
    private const FULLY_EXEMPT_BASE_CLASSES = [
        // Roots\Acorn\View\Composer (Sage 10+/Acorn theme scaffolding): subclass methods are
        // Blade-view data providers, discovered by matching an author-chosen method name against
        // the view's requested variable name at render time — never a literal call anywhere in
        // project code.
        'Composer' => 'Roots\Acorn\View\Composer',
    ];

    // Bounds the extends-chain walk in isContractMethod() so a cyclic or malformed extends
    // graph (which would never happen in valid PHP, but this is a token parser with no
    // semantic validation) can't spin forever.
    private const MAX_INHERITANCE_DEPTH = 50;

    public function __construct(private readonly PhpTokenParser $parser) {}

    /**
     * @param list<string> $files
     * @param list<string> $vendorAutoloadPaths
     * @return list<Finding>
     */
    public function analyze(array $files, array $vendorAutoloadPaths = []): array
    {
        $parseResults = array_map(fn(string $f) => $this->parser->parse($f), $files);

        $classDefsByName = [];
        foreach ($parseResults as $result) {
            foreach ($result->classDefs as $def) {
                $classDefsByName[$def->name] = $def;
            }
        }

        $reflector = new VendorClassReflector($vendorAutoloadPaths);

        // Short name/alias => FQCN from every file's `use` imports, merged globally — same
        // "flat, whole-project namespace" trade-off $classDefsByName already makes. Lets
        // isContractMethod resolve an extends/implements short name (PhpTokenParser only ever
        // records short names) back to a real, autoloadable vendor class name.
        $useImports = [];
        foreach ($parseResults as $result) {
            foreach ($result->useImports as $alias => $fqcn) {
                $useImports[$alias] = $fqcn;
            }
        }

        $findings = $this->findUnusedClasses($parseResults, $classDefsByName, $useImports);
        array_push($findings, ...$this->findUnusedMethods($parseResults, $classDefsByName, $reflector, $useImports));

        usort($findings, fn(Finding $a, Finding $b) => $a->file <=> $b->file ?: $a->line <=> $b->line);

        return $findings;
    }

    /**
     * @param list<ParseResult> $parseResults
     * @param array<string,ClassDef> $classDefsByName
     * @param array<string,string> $useImports
     * @return list<Finding>
     */
    private function findUnusedClasses(array $parseResults, array $classDefsByName, array $useImports = []): array
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
                if ($this->isFullyExemptClass($name, $classDefsByName, $useImports)) {
                    continue;
                }
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
     * @param array<string,string> $useImports
     * @return list<Finding>
     */
    private function findUnusedMethods(array $parseResults, array $classDefsByName, VendorClassReflector $reflector, array $useImports): array
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

        // trait name => list of classes/traits whose body directly `use`s it (see TraitUsage /
        // the T_USE handling in PhpTokenParser). A trait's own methods are never called on the
        // trait itself — only through whatever `use`s it — so isUsedByTraitConsumer() walks this
        // graph to widen the check below for methods owned by a trait.
        $traitUsers = [];
        foreach ($parseResults as $result) {
            foreach ($result->traitUsages as $usage) {
                $traitUsers[$usage->trait][] = $usage->user;
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
                    || $this->isFullyExemptClass($def->ownerClass, $classDefsByName, $useImports)
                    || $this->isContractMethod($def->name, $def->ownerClass, $classDefsByName, $reflector, $useImports)
                    || $this->isUsedByTraitConsumer($def->ownerClass, $def->name, $classDefsByName, $traitUsers, $scopedCalled)
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
     * Once the walk steps off the edge of what was scanned (an extends/implements target with
     * no ClassDef — a vendor dependency), $reflector takes over: PHP's own autoloader/Reflection
     * can see inside vendor code the token parser never touched. If that external class or
     * interface already declares $methodName, this is a real override of a vendor contract, not
     * dead code — a strict generalization of the curated lists above that needs no per-framework
     * entry, but only fires when a vendor autoloader was actually found (see
     * VendorClassReflector::isAvailable).
     *
     * $useImports resolves a short name to a real vendor FQCN before handing it to $reflector —
     * PhpTokenParser only ever records the short name off an extends/implements clause, but
     * class_exists()/ReflectionClass need the real name to find a `use`-imported vendor class.
     *
     * @param array<string,ClassDef> $classDefsByName
     * @param array<string,string> $useImports
     */
    private function isContractMethod(string $methodName, ?string $ownerClass, array $classDefsByName, VendorClassReflector $reflector, array $useImports): bool
    {
        if ($ownerClass === null) {
            return false;
        }

        $className = $ownerClass;
        for ($depth = 0; $depth < self::MAX_INHERITANCE_DEPTH; $depth++) {
            $def = $classDefsByName[$className] ?? null;
            if ($def === null) {
                return $reflector->classHasMethod($useImports[$className] ?? $className, $methodName);
            }

            foreach ($def->implements as $interface) {
                if (
                    in_array($methodName, self::INTERFACE_CONTRACT_METHODS[$interface] ?? [], true)
                    || $reflector->classHasMethod($useImports[$interface] ?? $interface, $methodName)
                ) {
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

    /**
     * Walks the extends chain the same way isContractMethod() does, checking each base short
     * name against FULLY_EXEMPT_BASE_CLASSES rather than a per-method list. $className is either
     * a class's own name (whole-class check from findUnusedClasses) or a method's owner class
     * (findUnusedMethods).
     *
     * Exempting an entire class is a much bigger effect than the per-method curated lists above,
     * so a bare short-name match isn't good enough on its own: a project with its own unrelated
     * "Composer" base class would otherwise get every subclass silently exempted. Whenever the
     * extending file actually `use`-imported the base name, $useImports must resolve it to the
     * real FQCN in FULLY_EXEMPT_BASE_CLASSES before this counts as a match — only falling back to
     * the bare short-name comparison (the original, collision-prone behavior) when no import
     * resolved it either way, since then there's no FQCN to check against at all.
     *
     * @param array<string,ClassDef> $classDefsByName
     * @param array<string,string> $useImports
     */
    private function isFullyExemptClass(?string $className, array $classDefsByName, array $useImports = []): bool
    {
        if ($className === null) {
            return false;
        }

        for ($depth = 0; $depth < self::MAX_INHERITANCE_DEPTH; $depth++) {
            $def = $classDefsByName[$className] ?? null;
            if ($def === null) {
                return false;
            }

            $base = $def->extends[0] ?? null;
            if ($base === null) {
                return false;
            }

            $expectedFqcn = self::FULLY_EXEMPT_BASE_CLASSES[$base] ?? null;
            if ($expectedFqcn !== null) {
                $importedFqcn = $useImports[$base] ?? null;
                if ($importedFqcn === null || $importedFqcn === $expectedFqcn) {
                    return true;
                }
            }

            $className = $base;
        }

        return false;
    }

    /**
     * A trait's own method is never called on the trait directly (PHP doesn't allow that) — it's
     * used when a class (or another trait) that `use`s this trait, directly or transitively (one
     * trait `use`-ing another), calls it through a scoped receiver ($this->, self::, a tracked
     * variable) belonging to that consumer. Walks $traitUsers breadth-first from $ownerClass,
     * bounded and cycle-guarded the same way isContractMethod() walks the extends chain, checking
     * every consumer reached against $scopedCalled. No-ops (returns false immediately) unless
     * $ownerClass is itself a trait, since only a trait-owned method needs this indirection —
     * scopedCalled[$ownerClass][...] already covers a method owned by a real class.
     *
     * @param array<string,ClassDef> $classDefsByName
     * @param array<string,list<string>> $traitUsers
     * @param array<string,array<string,bool>> $scopedCalled
     */
    private function isUsedByTraitConsumer(?string $ownerClass, string $methodName, array $classDefsByName, array $traitUsers, array $scopedCalled): bool
    {
        if ($ownerClass === null || ($classDefsByName[$ownerClass]->kind ?? null) !== 'trait') {
            return false;
        }

        $queue = $traitUsers[$ownerClass] ?? [];
        $visited = [];

        for ($depth = 0; $queue !== [] && $depth < self::MAX_INHERITANCE_DEPTH; $depth++) {
            $next = [];
            foreach ($queue as $user) {
                if (isset($visited[$user])) {
                    continue;
                }
                $visited[$user] = true;

                if (isset($scopedCalled[$user][$methodName])) {
                    return true;
                }

                array_push($next, ...($traitUsers[$user] ?? []));
            }
            $queue = $next;
        }

        return false;
    }

    private function isMagicMethod(string $name): bool
    {
        return str_starts_with($name, '__');
    }
}
