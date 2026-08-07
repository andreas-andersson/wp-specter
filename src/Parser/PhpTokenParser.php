<?php

declare(strict_types=1);

namespace WpSpecter\Parser;

final class PhpTokenParser
{
    private const HOOK_REGISTER_FUNCS = ['add_action', 'add_filter'];
    private const HOOK_INVOKE_FUNCS = ['do_action', 'apply_filters', 'do_action_ref_array', 'apply_filters_ref_array'];
    // Hook tag argument position (0-indexed) for WP-Cron scheduling calls — the hook itself
    // fires later inside WP-Cron core, not via a visible do_action() in project code.
    private const CRON_SCHEDULE_FUNCS = ['wp_schedule_event' => 2, 'wp_schedule_single_event' => 1];
    private const TEMPLATE_FUNCS = ['get_template_part', 'get_header', 'get_footer', 'get_sidebar'];
    private const INCLUDE_KEYWORDS = [T_INCLUDE, T_INCLUDE_ONCE, T_REQUIRE, T_REQUIRE_ONCE];
    // T_STRING: plain `Foo`. T_NAME_QUALIFIED: `Foo\Bar`. T_NAME_FULLY_QUALIFIED: `\Foo\Bar`.
    private const CLASS_NAME_TOKENS = [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED];

    public function parse(string $file): ParseResult
    {
        $code = file_get_contents($file);
        if ($code === false) {
            return $this->emptyResult($file, "Cannot read file: {$file}");
        }

        try {
            $tokens = token_get_all($code, TOKEN_PARSE);
        } catch (\ParseError $e) {
            return $this->emptyResult($file, $e->getMessage());
        }

        $functionDefs = [];
        $functionCalls = [];
        $hookRegistrations = [];
        $hookInvocations = [];
        $templateRefs = [];
        $phpPathStrings = [];
        $classDefs = [];
        $classReferences = [];

        $count = count($tokens);
        $line = 1;
        $skipNextString = false;

        // Class context tracking: push brace depth when a class body opens, pop when it closes.
        // $classNameStack runs in lockstep with $classDepthStack, tracking which class a method
        // belongs to (null for interface/trait/enum/anonymous-class bodies, which have no
        // ClassDef) — needed so a method's contract (implements/extends) can be checked later.
        $braceDepth = 0;
        $classDepthStack = [];
        $classNameStack = [];
        $expectingClassOpen = false;
        $pendingClassName = null;
        // String interpolation `{$var}` and `${var}` emit a STRING "}" token that closes the
        // interpolation but is NOT a code-level brace. Track depth so we skip those.
        $interpolationDepth = 0;

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];

            if (is_string($token)) {
                if ($token === '{') {
                    $braceDepth++;
                    if ($expectingClassOpen) {
                        $classDepthStack[] = $braceDepth;
                        $classNameStack[] = $pendingClassName;
                        $expectingClassOpen = false;
                        $pendingClassName = null;
                    }
                } elseif ($token === '}') {
                    if ($interpolationDepth > 0) {
                        $interpolationDepth--;
                    } else {
                        if (!empty($classDepthStack) && end($classDepthStack) === $braceDepth) {
                            array_pop($classDepthStack);
                            array_pop($classNameStack);
                        }
                        $braceDepth--;
                    }
                } elseif ($token === '&' && $skipNextString) {
                    // & in "function &foo()" — skip following function name too
                }
                continue;
            }

            [$type, $value, $tokenLine] = $token;
            $line = $tokenLine;

            if ($type === T_WHITESPACE) {
                continue;
            }

            // T_CURLY_OPEN ({$var}) and T_DOLLAR_OPEN_CURLY_BRACES (${var}) start a string
            // interpolation block closed by the next STRING "}" — don't count that } as a
            // code-level brace.
            if ($type === T_CURLY_OPEN || $type === T_DOLLAR_OPEN_CURLY_BRACES) {
                $interpolationDepth++;
                continue;
            }

            if ($type === T_CLASS) {
                // T_CLASS shows up in three unrelated shapes: `class Foo {}` (a real
                // declaration — next token is the name), `Foo::class` (a class-const
                // reference — no name follows; "Foo" was already captured as a reference when
                // we processed that T_STRING, see below), and `new class {}` (anonymous — no
                // name, but it does open a body). Only declarations and anonymous classes open
                // a brace context; only declarations get a ClassDef.
                if ($this->nextMeaningfulIsIdentifier($tokens, $i)) {
                    $expectingClassOpen = true;
                    $def = $this->parseClassDef($tokens, $i, $line, $file);
                    if ($def !== null) {
                        $classDefs[] = $def;
                        $pendingClassName = $def->name;
                    }
                } else {
                    $expectingClassOpen = $this->isPrecededByNew($tokens, $i);
                }
                continue;
            }

            if ($type === T_INTERFACE || $type === T_TRAIT || $type === T_ENUM) {
                $expectingClassOpen = true;
                continue;
            }

            if ($type === T_NEW || $type === T_INSTANCEOF) {
                $ref = $this->captureClassNameAfter($tokens, $i);
                if ($ref !== null) {
                    $classReferences[] = $ref;
                }
                continue;
            }

            if ($type === T_EXTENDS || $type === T_IMPLEMENTS) {
                foreach ($this->captureClassNameList($tokens, $i) as $ref) {
                    $classReferences[] = $ref;
                }
                continue;
            }

            if ($type === T_FUNCTION) {
                $insideClass = !empty($classDepthStack);
                $ownerClass = empty($classNameStack) ? null : end($classNameStack);
                $def = $this->parseFunctionDef($tokens, $i, $line, $file, $insideClass, $ownerClass);
                if ($def !== null) {
                    $functionDefs[] = $def;
                }
                $skipNextString = true;
                continue;
            }

            if ($type === T_STRING) {
                // Skip the function name token — it's a definition, not a call
                if ($skipNextString) {
                    $skipNextString = false;
                    continue;
                }
                $skipNextString = false;
                $name = $value;
                $nextNonWhitespace = $this->peekNextMeaningful($tokens, $i);

                if ($nextNonWhitespace === '::') {
                    // Foo::method(), Foo::CONST, Foo::class, Foo::$prop — whatever comes after
                    // the '::', "Foo" itself is a class reference either way.
                    $classReferences[] = $name;
                    continue;
                }

                if ($nextNonWhitespace !== '(') {
                    // Could be a string callback — handled below via T_CONSTANT_ENCAPSED_STRING
                    continue;
                }

                if (in_array($name, self::HOOK_REGISTER_FUNCS, true)) {
                    $reg = $this->parseHookRegistration($tokens, $i, $line, $file, $name);
                    if ($reg !== null) {
                        $hookRegistrations[] = $reg;
                    }
                    continue;
                }

                if (in_array($name, self::HOOK_INVOKE_FUNCS, true)) {
                    $inv = $this->parseHookInvocation($tokens, $i, $line, $file, $name);
                    if ($inv !== null) {
                        $hookInvocations[] = $inv;
                    }
                    continue;
                }

                if (array_key_exists($name, self::CRON_SCHEDULE_FUNCS)) {
                    $inv = $this->parseCronScheduleHook($tokens, $i, $line, $file, $name);
                    if ($inv !== null) {
                        $hookInvocations[] = $inv;
                    }
                    continue;
                }

                if (in_array($name, self::TEMPLATE_FUNCS, true)) {
                    $ref = $this->parseTemplateRef($tokens, $i, $line, $file, $name);
                    if ($ref !== null) {
                        $templateRefs[] = $ref;
                    }
                    continue;
                }

                // Regular function call
                $functionCalls[] = new FunctionCall($name, $line, $file);
                continue;
            }

            $skipNextString = false;

            // String callbacks e.g. add_action('init', 'my_callback')
            if ($type === T_CONSTANT_ENCAPSED_STRING) {
                $stringVal = $this->stripQuotes($value);
                if ($this->looksLikeCallback($stringVal)) {
                    $functionCalls[] = new FunctionCall($stringVal, $line, $file);
                }
                // Any ".php"-suffixed literal is a plausible file reference — e.g. ACF's
                // 'render_template' => get_template_directory() . '/blocks/foo.php', page
                // template registration arrays, or other config-driven includes that never
                // pass through include()/require(). Cheap net, no call-site required.
                if (str_ends_with($stringVal, '.php')) {
                    $phpPathStrings[] = $stringVal;
                }
                continue;
            }

            if (in_array($type, self::INCLUDE_KEYWORDS, true)) {
                $ref = $this->parseIncludeRef($tokens, $i, $line, $file, token_name($type));
                if ($ref !== null) {
                    $templateRefs[] = $ref;
                }
            }
        }

        return new ParseResult(
            file: $file,
            functionDefs: $functionDefs,
            functionCalls: $functionCalls,
            hookRegistrations: $hookRegistrations,
            hookInvocations: $hookInvocations,
            templateRefs: $templateRefs,
            phpPathStrings: $phpPathStrings,
            classDefs: $classDefs,
            classReferences: $classReferences,
        );
    }

    private function parseFunctionDef(array $tokens, int $i, int $line, string $file, bool $isMethod = false, ?string $ownerClass = null): ?FunctionDef
    {
        // function [whitespace] <name> (
        $j = $i + 1;
        while (isset($tokens[$j]) && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
            $j++;
        }

        if (!isset($tokens[$j])) {
            return null;
        }

        $next = $tokens[$j];

        // Anonymous function or arrow function — skip
        if (is_string($next) && $next === '(') {
            return null;
        }

        // function &name(...) — skip reference markers
        if (is_string($next) && $next === '&') {
            $j++;
            while (isset($tokens[$j]) && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
                $j++;
            }
            $next = $tokens[$j] ?? null;
        }

        if (!is_array($next) || $next[0] !== T_STRING) {
            return null;
        }

        return new FunctionDef($next[1], $next[2] ?? $line, $file, $isMethod, $ownerClass);
    }

    private function parseHookRegistration(array $tokens, int $i, string|int $line, string $file, string $funcName): ?HookRegistration
    {
        // add_action( 'tag', callback )
        $arg = $this->extractStringArgAt($tokens, $i, 0);
        if ($arg === null) {
            return new HookRegistration('', $funcName, (int) $line, $file, true);
        }
        [$tag, $isDynamic] = $arg;
        return new HookRegistration($tag, $funcName, (int) $line, $file, $isDynamic);
    }

    private function parseHookInvocation(array $tokens, int $i, string|int $line, string $file, string $funcName): ?HookInvocation
    {
        $arg = $this->extractStringArgAt($tokens, $i, 0);
        if ($arg === null) {
            return new HookInvocation('', $funcName, (int) $line, $file, true, '');
        }
        [$tag, $isDynamic, $prefix] = $arg;
        return new HookInvocation($tag, $funcName, (int) $line, $file, $isDynamic, $prefix);
    }

    private function parseCronScheduleHook(array $tokens, int $i, string|int $line, string $file, string $funcName): ?HookInvocation
    {
        $argIndex = self::CRON_SCHEDULE_FUNCS[$funcName];
        $arg = $this->extractStringArgAt($tokens, $i, $argIndex);
        if ($arg === null) {
            return new HookInvocation('', $funcName, (int) $line, $file, true, '');
        }
        [$tag, $isDynamic, $prefix] = $arg;
        return new HookInvocation($tag, $funcName, (int) $line, $file, $isDynamic, $prefix);
    }

    private function parseTemplateRef(array $tokens, int $i, string|int $line, string $file, string $funcName): ?TemplateRef
    {
        // Unlike hook tags, a template ref keeps a usable value even when the arg is a dynamic
        // interpolated string — e.g. get_template_part("variants/$variant") still tells us
        // every "variants/*" file is reachable, even though the exact suffix isn't known.
        // The literal prefix doubles as the exact value when the arg isn't dynamic at all.
        [, , $path] = $this->extractStringArgAt($tokens, $i, 0) ?? ['', true, ''];

        // get_header('kiosk') loads header-kiosk.php; prefix the stem so matching works
        if ($path !== '') {
            $prefix = match ($funcName) {
                'get_header' => 'header',
                'get_footer' => 'footer',
                'get_sidebar' => 'sidebar',
                default => null,
            };
            if ($prefix !== null) {
                $path = $prefix . '-' . $path;
            }
        }

        return new TemplateRef($path, $funcName, (int) $line, $file);
    }

    private function parseIncludeRef(array $tokens, int $i, string|int $line, string $file, string $keyword): ?TemplateRef
    {
        // include 'path/to/file.php';
        // include dirname(__FILE__) . '/file.php';  — take the trailing literal segment
        // include $dynamic_var;                     — no literal anywhere, skip
        $j = $i + 1;
        $depth = 0;
        $lastString = null;

        while (isset($tokens[$j])) {
            $t = $tokens[$j];

            if (is_string($t)) {
                if ($t === '(') {
                    $depth++;
                } elseif ($t === ')') {
                    if ($depth === 0) {
                        break;
                    }
                    $depth--;
                } elseif ($depth === 0 && ($t === ';' || $t === ',')) {
                    break;
                }
            } elseif (is_array($t) && $t[0] === T_CONSTANT_ENCAPSED_STRING) {
                $lastString = $this->stripQuotes($t[1]);
            }

            $j++;
        }

        if ($lastString === null) {
            return null; // fully dynamic include — skip
        }

        return new TemplateRef($lastString, strtolower($keyword), (int) $line, $file);
    }

    /**
     * Skip to the '(' after the current function name, then read the argument at $argIndex
     * (0-indexed), splitting on top-level commas. Returns null if the call doesn't have that
     * many arguments.
     *
     * @return array{string, bool, string}|null  [exact tag or '', isDynamic, literal prefix]
     */
    private function extractStringArgAt(array $tokens, int $i, int $argIndex): ?array
    {
        $j = $i + 1;
        while (isset($tokens[$j])) {
            $t = $tokens[$j];
            if (is_string($t) && $t === '(') {
                $j++;
                break;
            }
            $j++;
        }

        $currentIndex = 0;
        $depth = 0;
        $argTokens = [];

        while (isset($tokens[$j])) {
            $t = $tokens[$j];

            if (is_string($t) && $t === '(') {
                $depth++;
                $argTokens[] = $t;
            } elseif (is_string($t) && $t === ')') {
                if ($depth === 0) {
                    break;
                }
                $depth--;
                $argTokens[] = $t;
            } elseif (is_string($t) && $t === ',' && $depth === 0) {
                if ($currentIndex === $argIndex) {
                    return $this->classifyArgTokens($argTokens);
                }
                $currentIndex++;
                $argTokens = [];
            } elseif (is_array($t) && $t[0] === T_WHITESPACE) {
                // skip
            } else {
                $argTokens[] = $t;
            }

            $j++;
        }

        if ($currentIndex === $argIndex) {
            return $this->classifyArgTokens($argTokens);
        }

        return null;
    }

    /**
     * Classifies a single already-collected argument as one of three shapes:
     *  - a fully literal string, nothing else: exact tag known, not dynamic.
     *  - a string with a resolvable literal *prefix* — an interpolated double-quoted string
     *    ("acf/settings/{$name}") or a literal segment followed by concatenation
     *    ('acf/settings/' . $name) — exact tag unknown, but everything up to the first
     *    variable/expression is. This is what turns something like ACF's single dynamic
     *    dispatcher (every acf/settings/* hook fires through one apply_filters call) into a
     *    still-useful signal instead of a total blind spot.
     *  - anything else (bare variable, function call, array, ...): no literal information at
     *    all.
     *
     * @param list<mixed> $argTokens
     * @return array{string, bool, string}
     */
    private function classifyArgTokens(array $argTokens): array
    {
        if (count($argTokens) === 1 && is_array($argTokens[0]) && $argTokens[0][0] === T_CONSTANT_ENCAPSED_STRING) {
            $value = $this->stripQuotes($argTokens[0][1]);
            return [$value, false, $value];
        }

        if (
            isset($argTokens[0], $argTokens[1])
            && is_array($argTokens[0]) && $argTokens[0][0] === T_CONSTANT_ENCAPSED_STRING
            && is_string($argTokens[1]) && $argTokens[1] === '.'
        ) {
            return ['', true, $this->stripQuotes($argTokens[0][1])];
        }

        if (
            isset($argTokens[0], $argTokens[1])
            && is_string($argTokens[0]) && $argTokens[0] === '"'
            && is_array($argTokens[1]) && $argTokens[1][0] === T_ENCAPSED_AND_WHITESPACE
        ) {
            return ['', true, $argTokens[1][1]];
        }

        return ['', true, ''];
    }

    private function nextMeaningfulIsIdentifier(array $tokens, int $i): bool
    {
        $j = $i + 1;
        while (isset($tokens[$j]) && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
            $j++;
        }
        return isset($tokens[$j]) && is_array($tokens[$j]) && $tokens[$j][0] === T_STRING;
    }

    private function parseClassDef(array $tokens, int $i, int $line, string $file): ?ClassDef
    {
        $j = $i + 1;
        while (isset($tokens[$j]) && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
            $j++;
        }

        $next = $tokens[$j] ?? null;
        if (!is_array($next) || $next[0] !== T_STRING) {
            return null;
        }

        [$extends, $implements] = $this->findClassHierarchy($tokens, $j);

        return new ClassDef($next[1], $next[2] ?? $line, $file, $extends, $implements);
    }

    /**
     * Looks ahead from the class-name token, past any `extends`/`implements` clauses, to the
     * declaration's opening brace — used to know which known interfaces/base classes a class
     * commits to, e.g. so `implements Iterator` can exempt current()/next()/etc. from being
     * flagged as unused methods. Doesn't advance the main token loop; the main loop's own
     * T_EXTENDS/T_IMPLEMENTS handling (which feeds the flat classReferences list) still sees
     * these same tokens afterwards, same as parseFunctionDef's lookahead does for T_STRING.
     *
     * @return array{list<string>, list<string>}
     */
    private function findClassHierarchy(array $tokens, int $nameIndex): array
    {
        $extends = [];
        $implements = [];
        $j = $nameIndex + 1;

        while (isset($tokens[$j])) {
            $t = $tokens[$j];

            if (is_string($t)) {
                if ($t === '{' || $t === ';') {
                    break;
                }
                $j++;
                continue;
            }

            if ($t[0] === T_WHITESPACE) {
                $j++;
                continue;
            }

            if ($t[0] === T_EXTENDS) {
                $extends = $this->captureClassNameList($tokens, $j);
            } elseif ($t[0] === T_IMPLEMENTS) {
                $implements = $this->captureClassNameList($tokens, $j);
            }

            $j++;
        }

        return [$extends, $implements];
    }

    private function isPrecededByNew(array $tokens, int $i): bool
    {
        $j = $i - 1;
        while ($j >= 0 && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
            $j--;
        }
        return $j >= 0 && is_array($tokens[$j]) && $tokens[$j][0] === T_NEW;
    }

    /**
     * Reads the class name following `new` or `instanceof`. Skips non-name cases such as
     * `new class {}` (anonymous — next token is T_CLASS) and `new static()` (T_STATIC, a
     * late-static-binding placeholder, not a literal class name).
     */
    private function captureClassNameAfter(array $tokens, int $i): ?string
    {
        $j = $i + 1;
        while (isset($tokens[$j]) && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
            $j++;
        }

        $next = $tokens[$j] ?? null;
        if (!is_array($next) || !in_array($next[0], self::CLASS_NAME_TOKENS, true)) {
            return null;
        }

        return $this->shortClassName($next[1]);
    }

    /**
     * Reads a comma-separated list of class/interface names after `extends` or `implements`,
     * e.g. `class A extends B implements C, D`. Stops at the class body's opening brace, the
     * end of an interface-only declaration, or a following `implements` clause.
     *
     * @return list<string>
     */
    private function captureClassNameList(array $tokens, int $i): array
    {
        $names = [];
        $j = $i + 1;

        while (isset($tokens[$j])) {
            $t = $tokens[$j];

            if (is_string($t)) {
                if ($t === '{' || $t === ';') {
                    break;
                }
                $j++;
                continue;
            }

            if ($t[0] === T_WHITESPACE) {
                $j++;
                continue;
            }

            if ($t[0] === T_IMPLEMENTS) {
                break;
            }

            if (in_array($t[0], self::CLASS_NAME_TOKENS, true)) {
                $names[] = $this->shortClassName($t[1]);
            }

            $j++;
        }

        return $names;
    }

    private function shortClassName(string $name): string
    {
        $pos = strrpos($name, '\\');
        return $pos === false ? $name : substr($name, $pos + 1);
    }

    private function peekNextMeaningful(array $tokens, int $i): string
    {
        $j = $i + 1;
        while (isset($tokens[$j])) {
            $t = $tokens[$j];
            if (is_array($t) && $t[0] === T_WHITESPACE) {
                $j++;
                continue;
            }
            return is_string($t) ? $t : ($t[1] ?? '');
        }
        return '';
    }

    private function stripQuotes(string $value): string
    {
        if (strlen($value) >= 2 && ($value[0] === '"' || $value[0] === "'")) {
            return substr($value, 1, -1);
        }
        return $value;
    }

    private function looksLikeCallback(string $value): bool
    {
        // Valid PHP function name: letters, digits, underscore, starts with letter or _
        // Also allow Namespace\Class::method but keep simple for now
        return (bool) preg_match('/^[a-zA-Z_\x80-\xff][a-zA-Z0-9_\x80-\xff]*$/', $value);
    }

    private function emptyResult(string $file, string $error): ParseResult
    {
        return new ParseResult(
            file: $file,
            functionDefs: [],
            functionCalls: [],
            hookRegistrations: [],
            hookInvocations: [],
            templateRefs: [],
            phpPathStrings: [],
            error: $error,
        );
    }
}
