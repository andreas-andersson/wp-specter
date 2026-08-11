<?php

declare(strict_types=1);

namespace WpSpecter\Analyzer;

/**
 * Answers "does this vendor class/interface declare method X" for extends/implements targets
 * PhpTokenParser never parsed — a Composer dependency outside the scan. The token parser has no
 * way to know what a vendor base class contains; PHP's own autoloader + Reflection API does.
 * Used by ClassAnalyzer as a fallback, only reached once the project's own ClassDef map is
 * exhausted (see isContractMethod).
 *
 * Takes a *list* of autoload paths, not just one: a Bedrock-style layout has the project root's
 * own vendor/autoload.php, but a theme like Roots Sage that requires its own Composer packages
 * (Acorn) has a second, separate vendor/ directly under the theme — the classes a scan needs to
 * reflect on can live in either. Composer autoloaders coexist fine as multiple registered SPL
 * autoloaders, so every path found is loaded; PHP tries each in turn when a class is resolved.
 *
 * Deliberately opt-in and best-effort: loading an autoloader executes the target project's own
 * autoload files, and resolving a class executes whatever PSR-4 file declares it. Every entry
 * point is wrapped against \Throwable, per path, so one missing/broken/side-effecting vendor
 * file degrades to "no answer from that path" rather than aborting the scan or losing the others.
 */
final class VendorClassReflector
{
    private bool $autoloadersLoaded = false;

    /** @param list<string> $autoloadPaths */
    public function __construct(private readonly array $autoloadPaths) {}

    public function isAvailable(): bool
    {
        if ($this->autoloadPaths === []) {
            return false;
        }

        if ($this->autoloadersLoaded) {
            return true;
        }

        foreach ($this->autoloadPaths as $path) {
            try {
                require_once $path;
            } catch (\Throwable) {
                // Try the rest — a broken autoloader at one path shouldn't cost the others.
            }
        }
        $this->autoloadersLoaded = true;

        return true;
    }

    /**
     * Whether $className (or anything further up its real inheritance chain — PHP's own
     * Reflection resolves that natively, no manual walking needed) declares a method named
     * $methodName. Works for classes, interfaces, and traits alike.
     */
    public function classHasMethod(string $className, string $methodName): bool
    {
        if (!$this->isAvailable()) {
            return false;
        }

        try {
            if (!class_exists($className) && !interface_exists($className) && !trait_exists($className)) {
                return false;
            }

            return (new \ReflectionClass($className))->hasMethod($methodName);
        } catch (\Throwable) {
            return false;
        }
    }
}
