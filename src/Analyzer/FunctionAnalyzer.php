<?php

declare(strict_types=1);

namespace WpSpecter\Analyzer;

use WpSpecter\Finding\Finding;
use WpSpecter\Finding\FindingCertainty;
use WpSpecter\Finding\FindingType;
use WpSpecter\Parser\PhpTokenParser;

final class FunctionAnalyzer
{
    private const MAGIC_PREFIXES = ['__'];

    public function __construct(private readonly PhpTokenParser $parser) {}

    /**
     * @param list<string> $files
     * @return list<Finding>
     */
    public function analyze(array $files): array
    {
        $parseResults = array_map(fn(string $f) => $this->parser->parse($f), $files);

        // Pass 1: collect all definitions (skip class methods, magic methods, and real
        // function_exists()-guarded polyfills — see isExcluded()'s own docblock)
        $definitions = [];
        foreach ($parseResults as $result) {
            foreach ($result->functionDefs as $def) {
                if ($def->isMethod || $def->guarded || $this->isExcluded($def->name)) {
                    continue;
                }
                $definitions[$def->name] = $def;
            }
        }

        // Pass 2: collect all calls (direct + string callbacks)
        $called = [];
        foreach ($parseResults as $result) {
            foreach ($result->functionCalls as $call) {
                $called[$call->name] = true;
            }
        }

        // $var = someCall(); $var->render(); — before PendingReturnTypedCall existed, this always
        // landed directly in $functionCalls above, regardless of whether someCall()'s return type
        // was ever resolvable; ClassAnalyzer now resolves it against a declared return type when
        // it can, but this analyzer doesn't care about classes at all — it just needs the same
        // "credit the name, unconditionally" behavior it always had, so a same-named plain
        // function isn't newly (and wrongly) reported unused now that this shape has its own
        // dedicated tracking instead of folding into $functionCalls at the parser level.
        foreach ($parseResults as $result) {
            foreach ($result->pendingReturnTypedCalls as $call) {
                $called[$call->readMethod] = true;
            }
        }

        // Report defined-but-never-called
        $findings = [];
        foreach ($definitions as $name => $def) {
            if (!isset($called[$name])) {
                $findings[] = new Finding(
                    type: FindingType::UnusedFunction,
                    name: $name,
                    file: $def->file,
                    line: $def->line,
                    certainty: FindingCertainty::Error,
                );
            }
        }

        usort($findings, fn(Finding $a, Finding $b) => $a->file <=> $b->file ?: $a->line <=> $b->line);

        return $findings;
    }

    /**
     * A magic method-like name (`__construct` etc.) at the top level is vanishingly rare and
     * meaningless outside a class body — excluded defensively, same as ClassAnalyzer's own
     * isMagicMethod(). Real WP-polyfill exclusion (a function only meant to exist if WP core/
     * another plugin hasn't already declared it, so it's never callable from this project's own
     * code) is handled via FunctionDef::$guarded instead of this method — previously a blanket
     * name-prefix list (wp_/get_/the_/is_/has_/do_/apply_) undocumented since the project's first
     * commit. Replaced after a fresh gap-hunting pass found it both too broad (wp-smushit's own
     * genuinely dead `wp_smush_php_deprecated_notice()` — every call site commented out — was
     * invisible purely because of its `wp_` prefix) and only accidentally protective in the one
     * direction it mattered (a real WP-core-name polyfill, `wp_sizes_attribute_includes_valid_auto()`,
     * wrapped in its own `function_exists()` guard) — the guard shape is the real signal, not the
     * name.
     */
    private function isExcluded(string $name): bool
    {
        foreach (self::MAGIC_PREFIXES as $prefix) {
            if (str_starts_with($name, $prefix)) {
                return true;
            }
        }
        return false;
    }
}
