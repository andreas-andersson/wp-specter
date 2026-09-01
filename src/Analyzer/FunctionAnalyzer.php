<?php

declare(strict_types=1);

namespace WpSpecter\Analyzer;

use WpSpecter\Finding\Finding;
use WpSpecter\Finding\FindingCertainty;
use WpSpecter\Finding\FindingType;
use WpSpecter\Parser\PhpTokenParser;
use WpSpecter\Support\StringTransformChain;

final class FunctionAnalyzer
{
    private const MAGIC_PREFIXES = ['__'];

    // WordPress's "object cache drop-in" contract: a plugin/theme ships wp-content/object-
    // cache.php (WP core loads it INSTEAD of its own wp-includes/cache.php whenever present),
    // which must declare every one of these exact bare function names itself — WP core calls
    // them directly at bootstrap, from a file that lives OUTSIDE the scanned project's own tree
    // entirely (WP copies the plugin's template file there), so no call site can ever exist in
    // project code no matter how the object-cache backend is actually implemented. Real-world
    // finding (LiteSpeed Cache): all ~19 of `src/object.lib.php`'s own `wp_cache_*` function
    // declarations (the exact template WP copies into wp-content/object-cache.php) looked
    // unused. This list is exactly WP core's own wp-includes/cache.php function set (confirmed
    // against LiteSpeed's real drop-in template, which must mirror it exactly for the drop-in
    // swap to work at all) — not one plugin's own naming, so a name-only match here is safe: WP
    // core itself never loads its own wp-includes/cache.php once ANY of these is redeclared,
    // making a same-named unrelated function an immediate fatal redeclaration error rather than
    // a plausible false-match risk.
    private const WP_OBJECT_CACHE_DROPIN_FUNCS = [
        'wp_cache_init', 'wp_cache_add', 'wp_cache_add_multiple', 'wp_cache_replace',
        'wp_cache_set', 'wp_cache_set_multiple', 'wp_cache_get', 'wp_cache_get_multiple',
        'wp_cache_delete', 'wp_cache_delete_multiple', 'wp_cache_incr', 'wp_cache_decr',
        'wp_cache_flush', 'wp_cache_flush_runtime', 'wp_cache_flush_group', 'wp_cache_supports',
        'wp_cache_close', 'wp_cache_add_global_groups', 'wp_cache_add_non_persistent_groups',
        'wp_cache_switch_to_blog',
    ];

    public function __construct(private readonly PhpTokenParser $parser) {}

    /**
     * Keyed by FQCN (`ParseResult::$functionDefs`/`$functionCalls` — see `FunctionDef::$fqcn`/
     * `FunctionCall::$extraCandidateFqcn`), not bare short name — two unrelated namespaced
     * functions sharing a short name no longer collide the way `ClassAnalyzer` used to before
     * its own namespace-aware rework. Deliberately NOT the same treatment as classes throughout,
     * though: an unqualified *class* reference always resolves to exactly one place, but PHP
     * resolves an unqualified *function call* by trying the current namespace first and falling
     * back to the *global* namespace at runtime if nothing matches there — a real ambiguity a
     * static parser can't resolve on its own. `$called` below credits BOTH candidates for a bare
     * call made from namespaced code (the current-namespace form and the bare/global form),
     * favoring a false negative over a false positive in the rare case both happen to exist —
     * the same conservative bias this analyzer already takes everywhere else.
     *
     * @param list<string> $files
     * @param (callable(int, int): void)|null $onProgress See PhpTokenParser::parseAll().
     * @return list<Finding>
     */
    public function analyze(array $files, ?callable $onProgress = null): array
    {
        $parseResults = $this->parser->parseAll($files, $onProgress);

        // Pass 1: collect all definitions (skip class methods, magic methods, and real
        // function_exists()-guarded polyfills — see isExcluded()'s own docblock)
        $definitions = [];
        foreach ($parseResults as $result) {
            foreach ($result->functionDefs as $def) {
                if ($def->isMethod || $def->guarded || $this->isExcluded($def->name)) {
                    continue;
                }
                $definitions[$def->fqcn] = $def;
            }
        }

        // Pass 2: collect all calls (direct + string callbacks)
        $called = [];
        foreach ($parseResults as $result) {
            foreach ($result->functionCalls as $call) {
                $called[$call->name] = true;
                if ($call->extraCandidateFqcn !== null) {
                    $called[$call->extraCandidateFqcn] = true;
                }
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

        // A function name synthesized via a recognized transform chain applied to a *different*
        // function's own returned array of literals, dispatched via `call_user_func()` — the
        // procedural (no enclosing class) counterpart to ClassAnalyzer's own
        // $classNameTransformTemplates cross-product. Real-world shape (Botiga):
        // `botiga_get_quick_view_summary_components()` runs `array_map()` over
        // `botiga_get_default_single_product_components()`'s own returned array, turning
        // `'woocommerce_template_single_title'` into `'botiga_quick_view_summary_title'` — two
        // unrelated top-level functions, not methods sharing a class, so there's no owner to
        // scope this by; every $functionNameTransformTemplates entry is cross-referenced against
        // every $functionArrayReturns entry project-wide instead (see
        // PhpTokenParser::interpolatedPrefixVarAt's own docblock for the full real-world code and
        // the two gates — inside an `array_map()` closure, and the literal looking like a
        // snake_case function-name prefix — that keep this from over-matching against unrelated
        // procedural array transforms). A resolved name that never matches any real function
        // definition is simply never looked up below; only a genuine collision could ever
        // misfire, the same "coarse net" trade-off this project makes throughout.
        $functionNameTransformTemplates = [];
        foreach ($parseResults as $result) {
            array_push($functionNameTransformTemplates, ...$result->functionNameTransformTemplates);
        }
        if ($functionNameTransformTemplates !== []) {
            $functionArrayReturns = [];
            foreach ($parseResults as $result) {
                foreach ($result->functionArrayReturns as $key => $values) {
                    if (!isset($functionArrayReturns[$key])) {
                        $functionArrayReturns[$key] = [];
                    }
                    array_push($functionArrayReturns[$key], ...$values);
                }
            }
            foreach ($functionNameTransformTemplates as [$prefix, $steps]) {
                foreach ($functionArrayReturns as $values) {
                    foreach ($values as $value) {
                        $called[$prefix . StringTransformChain::apply($value, $steps)] = true;
                    }
                }
            }
        }

        // Report defined-but-never-called
        $findings = [];
        foreach ($definitions as $fqcn => $def) {
            if (!isset($called[$fqcn])) {
                $findings[] = new Finding(
                    type: FindingType::UnusedFunction,
                    name: $def->name,
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
        return in_array($name, self::WP_OBJECT_CACHE_DROPIN_FUNCS, true);
    }
}
