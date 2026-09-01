<?php

declare(strict_types=1);

namespace WpSpecter\Analyzer;

/**
 * Cheap, tokenizer-free scan for `do_action()`/`apply_filters()`-style calls with a literal tag
 * inside vendor-prefixed files (vendor/, vendor_prefixed/, jetpack_vendor/, ...) — the exact
 * files FileScanner excludes from every other check (auditing a dependency's own internal dead
 * code is out of scope), but which routinely fire hooks the host project's own code registers
 * callbacks for; see HookAnalyzer::analyze()'s own docblock for the real-world Jetpack case this
 * closes. Deliberately NOT run through PhpTokenParser: a vendor-prefixed tree can be most of a
 * plugin's own file count (confirmed in the corpus: Jetpack ~1,250 files, wpforms-lite ~3,100),
 * and full tokenization exists to answer "is this project's own code dead", a question that's
 * out of scope for vendor code entirely — a raw regex pass over each file's own text is a much
 * cheaper way to answer the one question this scanner actually needs to: "does a literal string
 * appear as this call's first argument anywhere in this file". Coarser than real parsing (a tag
 * built via concatenation, or the same text appearing inside a comment/unrelated string, isn't
 * distinguished), but this only ever *adds* a hook to the fired set — it can suppress a genuine
 * `UnmatchedHook` finding on a rare false positive, never fabricate a new one, so the risk
 * profile is one-directional and low-stakes, the same "coarse net, not proven causality" trade-off
 * this codebase already accepts throughout the class-name-transform and literal-path mechanisms.
 */
final class VendorHookInvocationScanner
{
    private const HOOK_INVOKE_FUNCS = ['do_action', 'apply_filters', 'do_action_ref_array', 'apply_filters_ref_array'];

    /**
     * @param list<string> $files
     * @return array<string,true>
     */
    public static function scan(array $files): array
    {
        if ($files === []) {
            return [];
        }

        // Two separate quote-style alternatives rather than a backreference to "whichever quote
        // opened this" — simpler to get right than a variable-delimiter pattern, at the cost of
        // one extra (harmlessly empty) capture group per match.
        $pattern = '/\b(?:' . implode('|', self::HOOK_INVOKE_FUNCS) . ')\s*\(\s*(?:\'((?:\\\\.|[^\\\\\'])*)\'|"((?:\\\\.|[^\\\\"])*)")/';

        $tags = [];
        foreach ($files as $file) {
            $content = @file_get_contents($file);
            if ($content === false || $content === '') {
                continue;
            }
            if (preg_match_all($pattern, $content, $matches) > 0) {
                foreach ($matches[1] as $index => $singleQuoted) {
                    $tag = $singleQuoted !== '' ? $singleQuoted : $matches[2][$index];
                    if ($tag !== '') {
                        $tags[$tag] = true;
                    }
                }
            }
        }

        return $tags;
    }
}
