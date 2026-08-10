<?php

declare(strict_types=1);

namespace WpSpecter\Parser;

/**
 * @phpstan-type Token array{0: int, 1: string, 2: int}|string
 *   token_get_all()'s element shape: either a single-character token (a plain string) or
 *   [token type, token text, line number] for everything else.
 */
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
    // Built-in/pseudo types that tokenize the same as a class name (T_STRING) in a type-hint
    // position — must be excluded so e.g. `int $x` doesn't get treated as a reference to a
    // class named "int". `array` and `callable` have their own dedicated tokens (T_ARRAY,
    // T_CALLABLE) so they never reach this list in the first place; "iterable" doesn't have a
    // dedicated token as of PHP 8.4, so it's listed here instead.
    private const PRIMITIVE_TYPE_NAMES = [
        'int', 'float', 'string', 'bool', 'object', 'iterable',
        'mixed', 'void', 'never', 'null', 'false', 'true',
    ];

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
        $scopedMethodCalls = [];
        $traitUsages = [];
        // glob(__DIR__ . '/inc/*.php') + a foreach/require loop is a common WP bulk-include
        // pattern this tokenizer can't trace as dataflow (the require target is a plain loop
        // variable, fully dynamic). $globIncludeDirs records every directory a glob() call in
        // this file scans; $hasIncludeStatement records whether an include/require keyword
        // appears anywhere in it too — FileAnalyzer only trusts a glob'd directory as "reachable"
        // when both signals are present in the same file, a coarse but WP-idiomatic heuristic.
        $globIncludeDirs = [];
        $hasIncludeStatement = false;

        $count = count($tokens);
        $line = 1;
        $skipNextString = false;

        // Class context tracking: push brace depth when a class body opens, pop when it closes.
        // $classNameStack and $classParentStack run in lockstep with $classDepthStack, tracking
        // which class (and its extends[0], for `parent::`) a method belongs to — null for
        // interface/trait/enum/anonymous-class bodies, which have no ClassDef. Used both for
        // contract (implements/extends) checks and for resolving $this->/self::/parent::/
        // static:: calls to a concrete receiver class below.
        $braceDepth = 0;
        $classDepthStack = [];
        $classNameStack = [];
        $classParentStack = [];
        $expectingClassOpen = false;
        $pendingClassName = null;
        $pendingClassParent = null;
        // String interpolation `{$var}` and `${var}` emit a STRING "}" token that closes the
        // interpolation but is NOT a code-level brace. Track depth so we skip those.
        $interpolationDepth = 0;

        // Local variable type tracking: $var = new ClassName(...) is remembered for the rest of
        // that function/method's body, so $var->method() can be scoped the same way $this->
        // already is. $varTypesStack runs in lockstep with function-body brace depth (a fresh,
        // empty scope per function/closure — PHP variables don't leak into nested closures
        // without an explicit `use()`, and this parser doesn't attempt to track those either).
        $expectingFunctionOpen = false;
        $functionDepthStack = [];
        $varTypesStack = [[]];
        // Type-hinted parameters (`function foo(My_Class $x)`) seed the new scope's
        // $varTypesStack the same way `$x = new My_Class()` does — computed when T_FUNCTION is
        // seen, applied when its body's `{` actually opens the scope, mirroring the
        // $pendingClassName pattern above.
        $pendingParamTypes = [];

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];

            if (is_string($token)) {
                if ($token === '{') {
                    $braceDepth++;
                    if ($expectingClassOpen) {
                        $classDepthStack[] = $braceDepth;
                        $classNameStack[] = $pendingClassName;
                        $classParentStack[] = $pendingClassParent;
                        $expectingClassOpen = false;
                        $pendingClassName = null;
                        $pendingClassParent = null;
                    }
                    if ($expectingFunctionOpen) {
                        $functionDepthStack[] = $braceDepth;
                        $varTypesStack[] = $pendingParamTypes;
                        $expectingFunctionOpen = false;
                        $pendingParamTypes = [];
                    }
                } elseif ($token === '}') {
                    if ($interpolationDepth > 0) {
                        $interpolationDepth--;
                    } else {
                        if (!empty($classDepthStack) && end($classDepthStack) === $braceDepth) {
                            array_pop($classDepthStack);
                            array_pop($classNameStack);
                            array_pop($classParentStack);
                        }
                        if (!empty($functionDepthStack) && end($functionDepthStack) === $braceDepth) {
                            array_pop($functionDepthStack);
                            array_pop($varTypesStack);
                        }
                        $braceDepth--;
                    }
                } elseif ($token === '&' && $skipNextString) {
                    // & in "function &foo()" — skip following function name too
                } elseif ($token === ';') {
                    // Abstract/interface method declarations end in `;`, never open a body —
                    // clear a pending function-scope push so it doesn't wrongly latch onto
                    // whatever brace comes next (e.g. a sibling method's own body).
                    $expectingFunctionOpen = false;
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
                    $def = $this->parseClassDef($tokens, $i, $file);
                    if ($def !== null) {
                        $classDefs[] = $def;
                        $pendingClassName = $def->name;
                        $pendingClassParent = $def->extends[0] ?? null;
                    }
                } else {
                    $expectingClassOpen = $this->isPrecededByNew($tokens, $i);
                }
                continue;
            }

            if ($type === T_INTERFACE || $type === T_TRAIT || $type === T_ENUM) {
                // Interfaces/enums deliberately do NOT set $pendingClassName/$pendingClassParent
                // the way T_CLASS does below — interface bodies have no method bodies to begin
                // with, and enum-method scoping isn't attempted yet, so their bodies keep
                // resolving to the unscoped fallback pool (ownerClass stays null).
                //
                // Traits are different: $pendingClassName IS set to the trait's own name, so
                // $this->method() calls made *within* the trait's own methods resolve precisely
                // to that name (ScopedMethodCall) instead of silently falling through to the
                // unscoped pool. That alone would make a trait method that's only ever called
                // via $this-> from the consuming class (not the trait itself) look unused — a
                // trait's methods are never called on the trait directly, only through whatever
                // class `use`s it. ClassAnalyzer closes that gap using $traitUsages below (every
                // `use TraitName;` paired with its enclosing class/trait) to widen a trait
                // method's "used" check to every class that use()s it, transitively.
                $expectingClassOpen = true;
                $kind = match ($type) {
                    T_INTERFACE => 'interface',
                    T_TRAIT => 'trait',
                    default => 'enum',
                };
                $def = $this->parseClassDef($tokens, $i, $file, $kind);
                if ($def !== null) {
                    $classDefs[] = $def;
                    if ($kind === 'trait') {
                        $pendingClassName = $def->name;
                    }
                }
                continue;
            }

            if ($type === T_USE && !empty($classDepthStack) && end($classDepthStack) === $braceDepth) {
                // `use TraitName;` directly inside a class/trait/enum body — a trait reference,
                // not the file-level `use Some\Namespace\Class;` import (guarded out: that's at
                // $braceDepth 0, outside any class body) nor a closure's `function() use ($v)`
                // (guarded out: that's nested inside a method body, one or more braces deeper
                // than the class body's own depth).
                $user = empty($classNameStack) ? null : end($classNameStack);
                foreach ($this->captureClassNameList($tokens, $i) as $ref) {
                    $classReferences[] = $ref;
                    if ($user !== null) {
                        $traitUsages[] = new TraitUsage($user, $ref);
                    }
                }
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
                $ownerParent = empty($classParentStack) ? null : end($classParentStack);
                $def = $this->parseFunctionDef($tokens, $i, $file, $insideClass, $ownerClass);
                if ($def !== null) {
                    $functionDefs[] = $def;
                }
                // Type-hinted parameters: `function foo(My_Class $x)` both references My_Class
                // and, same as `$x = new My_Class()`, tells us $x's type for the rest of the
                // body — applies equally to anonymous functions/closures, which is why this
                // runs unconditionally rather than only when $def !== null.
                $parenIndex = $this->findParenAfterFunctionKeyword($tokens, $i);
                if ($parenIndex !== null) {
                    [$hintClassRefs, $pendingParamTypes] = $this->parseParamTypeHints($tokens, $parenIndex, $ownerClass, $ownerParent);
                    foreach ($hintClassRefs as $ref) {
                        $classReferences[] = $ref;
                    }
                }
                $skipNextString = true;
                // Every function/method/closure opens its own variable scope — including
                // anonymous ones ($def === null for those), which need this exactly as much.
                $expectingFunctionOpen = true;
                continue;
            }

            if ($type === T_VARIABLE) {
                $scopeTop = count($varTypesStack) - 1;

                if ($value === '$this') {
                    // The one case where an object's exact class is always known without any
                    // type inference: it's whichever class this code is physically inside.
                    $target = $this->findScopedCallTarget($tokens, $i);
                    if ($target !== null) {
                        $receiverClass = empty($classNameStack) ? null : end($classNameStack);
                        if ($receiverClass !== null) {
                            [$methodName, $methodNameIndex] = $target;
                            $scopedMethodCalls[] = new ScopedMethodCall($receiverClass, $methodName);
                            $i = $methodNameIndex;
                        }
                    }
                } elseif (($equalsIndex = $this->peekNextMeaningfulIndex($tokens, $i)) !== null && $tokens[$equalsIndex] === '=') {
                    // $var = new ClassName(...) — remember it for the rest of this scope. Any
                    // other RHS invalidates a previous tracked type rather than leaving it
                    // stale (e.g. $var = new Foo(); ... $var = some_call(); — $var's type is no
                    // longer known, so a later $var->method() must fall back to unscoped).
                    // No control-flow awareness: `if ($c) { $x = new A(); } else { $x = new B(); }`
                    // just tracks whichever assignment comes last in source order, not "could be
                    // either" — an approximation, same spirit as the rest of this parser, and
                    // still strictly more precise than the unscoped fallback it replaces.
                    $newClass = $this->assignedNewClassName($tokens, $equalsIndex, $classNameStack, $classParentStack);
                    if ($newClass !== null) {
                        $varTypesStack[$scopeTop][$value] = $newClass;
                    } else {
                        unset($varTypesStack[$scopeTop][$value]);
                    }
                } else {
                    $trackedClass = $varTypesStack[$scopeTop][$value] ?? null;
                    if ($trackedClass !== null) {
                        $target = $this->findScopedCallTarget($tokens, $i);
                        if ($target !== null) {
                            [$methodName, $methodNameIndex] = $target;
                            $scopedMethodCalls[] = new ScopedMethodCall($trackedClass, $methodName);
                            $i = $methodNameIndex;
                        }
                    }
                }
                // No `continue` — variables are used in plenty of other ways (property access,
                // passed as an argument, ...) that need no special handling here.
            }

            // static::method() — "static" is its own token (T_STATIC), never T_STRING, so it
            // can't be reached by the T_STRING branch below the way self:: and parent:: are.
            if ($type === T_STATIC) {
                $target = $this->findScopedCallTarget($tokens, $i);
                if ($target !== null) {
                    $receiverClass = empty($classNameStack) ? null : end($classNameStack);
                    if ($receiverClass !== null) {
                        [$methodName, $methodNameIndex] = $target;
                        $scopedMethodCalls[] = new ScopedMethodCall($receiverClass, $methodName);
                        $i = $methodNameIndex;
                    }
                }
                // No `continue` — "static" is also a method/property modifier and a local-variable
                // keyword ("static function", "static $var") that need no handling here either.
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

                    // self::method()/parent::method()/Foo::method() (but not Foo::CONST,
                    // Foo::class, Foo::$prop — findScopedCallTarget only matches an actual call).
                    $target = $this->findScopedCallTarget($tokens, $i);
                    if ($target !== null) {
                        $receiverClass = $this->resolveClassNameToken($name, $classNameStack, $classParentStack);
                        if ($receiverClass !== null) {
                            [$methodName, $methodNameIndex] = $target;
                            $scopedMethodCalls[] = new ScopedMethodCall($receiverClass, $methodName);
                            $i = $methodNameIndex;
                        }
                    }
                    continue;
                }

                if ($nextNonWhitespace !== '(') {
                    // Could be a string callback — handled below via T_CONSTANT_ENCAPSED_STRING
                    continue;
                }

                if (in_array($name, self::HOOK_REGISTER_FUNCS, true)) {
                    $hookRegistrations[] = $this->parseHookRegistration($tokens, $i, $line, $file, $name);
                    continue;
                }

                if (in_array($name, self::HOOK_INVOKE_FUNCS, true)) {
                    $hookInvocations[] = $this->parseHookInvocation($tokens, $i, $line, $file, $name);
                    continue;
                }

                if (array_key_exists($name, self::CRON_SCHEDULE_FUNCS)) {
                    $hookInvocations[] = $this->parseCronScheduleHook($tokens, $i, $line, $file, $name);
                    continue;
                }

                if (in_array($name, self::TEMPLATE_FUNCS, true)) {
                    $templateRefs[] = $this->parseTemplateRef($tokens, $i, $line, $file, $name);
                    continue;
                }

                if ($name === 'glob') {
                    $dir = $this->parseGlobDirRef($tokens, $i);
                    if ($dir !== null) {
                        $globIncludeDirs[] = $dir;
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
                    // [$this, 'method'] / [self::class, 'method'] / [Foo::class, 'method'] /
                    // ['Foo', 'method'] — the common add_action/add_filter array-callback shape
                    // — has a resolvable receiver often enough to be worth checking here, same
                    // as the $this->/self::/parent::/Foo:: call scoping above. Anything else
                    // (a plain variable receiver, or not an array callback at all) falls back to
                    // the existing name-only pool.
                    $receiverClass = $this->arrayCallbackReceiverClass($tokens, $i, $classNameStack, $classParentStack);
                    if ($receiverClass !== null) {
                        $scopedMethodCalls[] = new ScopedMethodCall($receiverClass, $stringVal);
                    } else {
                        $functionCalls[] = new FunctionCall($stringVal, $line, $file);
                    }
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
                $hasIncludeStatement = true;
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
            scopedMethodCalls: $scopedMethodCalls,
            traitUsages: $traitUsages,
            globIncludeDirs: $globIncludeDirs,
            hasIncludeStatement: $hasIncludeStatement,
        );
    }

    /** @param list<Token> $tokens */
    private function parseFunctionDef(array $tokens, int $i, string $file, bool $isMethod = false, ?string $ownerClass = null): ?FunctionDef
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

        return new FunctionDef($next[1], $next[2], $file, $isMethod, $ownerClass);
    }

    /** @param list<Token> $tokens */
    private function findParenAfterFunctionKeyword(array $tokens, int $i): ?int
    {
        $j = $i + 1;
        while (isset($tokens[$j])) {
            if (is_string($tokens[$j]) && $tokens[$j] === '(') {
                return $j;
            }
            $j++;
        }
        return null;
    }

    /**
     * Walks a parameter list looking for class-like type hints — `TypeName $var`,
     * `?TypeName $var`, `self`/`static`/`parent`, and constructor-promoted properties
     * (`public readonly TypeName $var`) — resolving each to a concrete class name. Every
     * class-like type found is a genuine reference regardless of shape, so all of them are
     * returned as references; but only an unambiguous single type seeds $varTypesStack, since
     * a union (`A|B`) or intersection (`A&B`) type doesn't tell us which one $var actually is
     * at runtime — same "don't guess" stance as the rest of this parser's variable tracking.
     *
     * @return array{list<string>, array<string,string>}  [classReferences, paramVar => className]
     * @param list<Token> $tokens
     */
    private function parseParamTypeHints(array $tokens, int $parenIndex, ?string $ownerClass, ?string $ownerParent): array
    {
        $classRefs = [];
        $varTypes = [];

        $depth = 0;
        $paramTokens = [];
        $j = $parenIndex + 1;

        while (isset($tokens[$j])) {
            $t = $tokens[$j];

            if (is_array($t) && $t[0] === T_ATTRIBUTE) {
                // #[Attribute] before a parameter — its matching close is a plain "]" token,
                // handled by the generic bracket-depth tracking below.
                $depth++;
                $j++;
                continue;
            }

            if (is_string($t) && ($t === '(' || $t === '[')) {
                $depth++;
                $j++;
                continue;
            }

            if (is_string($t) && ($t === ')' || $t === ']')) {
                if ($depth === 0) {
                    break; // end of parameter list
                }
                $depth--;
                $j++;
                continue;
            }

            if (is_string($t) && $t === ',' && $depth === 0) {
                $this->collectParamTypeHint($paramTokens, $ownerClass, $ownerParent, $classRefs, $varTypes);
                $paramTokens = [];
                $j++;
                continue;
            }

            if ($depth === 0) {
                $paramTokens[] = $t;
            }
            $j++;
        }

        $this->collectParamTypeHint($paramTokens, $ownerClass, $ownerParent, $classRefs, $varTypes);

        return [$classRefs, $varTypes];
    }

    /**
     * Reads one parameter's already-collected top-level tokens (type hint, name, promotion
     * modifiers — no default-value tokens, since those live at bracket depth > 0 and
     * parseParamTypeHints never collects them) and records its resolved type(s).
     *
     * @param list<Token> $paramTokens
     * @param list<string> $classRefs
     * @param array<string,string> $varTypes
     */
    private function collectParamTypeHint(array $paramTokens, ?string $ownerClass, ?string $ownerParent, array &$classRefs, array &$varTypes): void
    {
        $typeNames = [];
        $varName = null;

        foreach ($paramTokens as $t) {
            if (is_string($t)) {
                continue; // '?', '|', '&', '=', ... — none of these are type-name tokens
            }

            if ($t[0] === T_VARIABLE) {
                $varName = $t[1];
                break; // the parameter's own name; nothing after it is part of its type
            }

            if (in_array($t[0], self::CLASS_NAME_TOKENS, true) || $t[0] === T_STATIC) {
                $name = $t[0] === T_STATIC ? 'static' : $t[1];
                $resolved = match (strtolower($name)) {
                    'self', 'static' => $ownerClass,
                    'parent' => $ownerParent,
                    default => in_array(strtolower($name), self::PRIMITIVE_TYPE_NAMES, true)
                        ? null
                        : $this->shortClassName($name),
                };
                if ($resolved !== null) {
                    $typeNames[] = $resolved;
                }
            }
        }

        foreach ($typeNames as $name) {
            $classRefs[] = $name;
        }

        if ($varName !== null && count($typeNames) === 1) {
            $varTypes[$varName] = $typeNames[0];
        }
    }

    /** @param list<Token> $tokens */
    private function parseHookRegistration(array $tokens, int $i, string|int $line, string $file, string $funcName): HookRegistration
    {
        // add_action( 'tag', callback )
        $arg = $this->extractStringArgAt($tokens, $i, 0);
        if ($arg === null) {
            return new HookRegistration('', $funcName, (int) $line, $file, true);
        }
        [$tag, $isDynamic] = $arg;
        return new HookRegistration($tag, $funcName, (int) $line, $file, $isDynamic);
    }

    /** @param list<Token> $tokens */
    private function parseHookInvocation(array $tokens, int $i, string|int $line, string $file, string $funcName): HookInvocation
    {
        $arg = $this->extractStringArgAt($tokens, $i, 0);
        if ($arg === null) {
            return new HookInvocation('', $funcName, (int) $line, $file, true, '');
        }
        [$tag, $isDynamic, $prefix] = $arg;
        return new HookInvocation($tag, $funcName, (int) $line, $file, $isDynamic, $prefix);
    }

    /** @param list<Token> $tokens */
    private function parseCronScheduleHook(array $tokens, int $i, string|int $line, string $file, string $funcName): HookInvocation
    {
        $argIndex = self::CRON_SCHEDULE_FUNCS[$funcName];
        $arg = $this->extractStringArgAt($tokens, $i, $argIndex);
        if ($arg === null) {
            return new HookInvocation('', $funcName, (int) $line, $file, true, '');
        }
        [$tag, $isDynamic, $prefix] = $arg;
        return new HookInvocation($tag, $funcName, (int) $line, $file, $isDynamic, $prefix);
    }

    /** @param list<Token> $tokens */
    private function parseTemplateRef(array $tokens, int $i, string|int $line, string $file, string $funcName): TemplateRef
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

    /** @param list<Token> $tokens */
    private function parseIncludeRef(array $tokens, int $i, string|int $line, string $file, string $keyword): ?TemplateRef
    {
        // include 'path/to/file.php';
        // include dirname(__FILE__) . '/file.php';  — take the trailing literal segment
        // include $dynamic_var;                     — no literal anywhere, skip
        $lastString = $this->findTrailingStringLiteral($tokens, $i);
        if ($lastString === null) {
            return null; // fully dynamic include — skip
        }

        return new TemplateRef($lastString, strtolower($keyword), (int) $line, $file);
    }

    /**
     * glob()'s argument is a filename *pattern* ('inc/*.php'), not a directory — dirname()
     * strips the wildcard/filename segment, leaving the directory glob() actually scans. A
     * pattern with no directory component at all ('*.php', or the concatenated-literal-only
     * remainder '/*.php') collapses to '.' or '/' — both mean "this file's own directory",
     * which FileAnalyzer resolves against the calling file's own location, same as it does for
     * a proper subdirectory.
     *
     * @param list<Token> $tokens
     */
    private function parseGlobDirRef(array $tokens, int $i): ?string
    {
        $pattern = $this->findTrailingStringLiteral($tokens, $i);
        return $pattern === null ? null : dirname($pattern);
    }

    /**
     * Walks tokens starting just after $i — an include/require keyword, or a function-call
     * name like "glob" — tracking paren depth, and returns the last string literal seen before
     * the enclosing statement/call argument ends. Handles every shape a path expression takes
     * in practice: a bare literal, `dirname(__FILE__) . '/literal'`, or a longer concatenation
     * chain — the trailing literal segment is what carries usable path information regardless
     * of how much dynamic prefix comes before it. Returns null when there's no literal
     * anywhere (a fully dynamic value).
     *
     * @param list<Token> $tokens
     */
    private function findTrailingStringLiteral(array $tokens, int $i): ?string
    {
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
            } elseif ($t[0] === T_CONSTANT_ENCAPSED_STRING) {
                $lastString = $this->stripQuotes($t[1]);
            }

            $j++;
        }

        return $lastString;
    }

    /**
     * Skip to the '(' after the current function name, then read the argument at $argIndex
     * (0-indexed), splitting on top-level commas. Returns null if the call doesn't have that
     * many arguments.
     *
     * @return array{string, bool, string}|null  [exact tag or '', isDynamic, literal prefix]
     * @param list<Token> $tokens
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

    /** @param list<Token> $tokens */
    private function nextMeaningfulIsIdentifier(array $tokens, int $i): bool
    {
        $j = $i + 1;
        while (isset($tokens[$j]) && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
            $j++;
        }
        return isset($tokens[$j]) && is_array($tokens[$j]) && $tokens[$j][0] === T_STRING;
    }

    /**
     * @param 'class'|'interface'|'trait'|'enum' $kind
     * @param list<Token> $tokens
     */
    private function parseClassDef(array $tokens, int $i, string $file, string $kind = 'class'): ?ClassDef
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

        return new ClassDef($next[1], $next[2], $file, $extends, $implements, $kind);
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
     * @param list<Token> $tokens
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

    /** @param list<Token> $tokens */
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
     * @param list<Token> $tokens
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
     * @param list<Token> $tokens
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

    /** @param list<Token> $tokens */
    private function peekNextMeaningful(array $tokens, int $i): string
    {
        $j = $i + 1;
        while (isset($tokens[$j])) {
            $t = $tokens[$j];
            if (is_array($t) && $t[0] === T_WHITESPACE) {
                $j++;
                continue;
            }
            return is_string($t) ? $t : $t[1];
        }
        return '';
    }

    /** @param list<Token> $tokens */
    private function peekNextMeaningfulIndex(array $tokens, int $i): ?int
    {
        $j = $i + 1;
        while (isset($tokens[$j])) {
            if (is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
                $j++;
                continue;
            }
            return $j;
        }
        return null;
    }

    /**
     * Given the index of a receiver token ($this, self, parent, static, or a class name),
     * checks whether it's immediately followed by `::` or `->`, an identifier, and a call `(`
     * — i.e. an actual method call, not e.g. Foo::CONST, Foo::class, Foo::$prop, or $this->prop.
     * Also guards against non-call uses of the same tokens, like the `static` *modifier* in
     * `public static function foo()` — that's `static` immediately followed by `function`, not
     * by `::`, so it must not be mistaken for `static::foo(`.
     *
     * @return array{string, int}|null  [method name, its token index] or null if this isn't a call.
     * @param list<Token> $tokens
     */
    private function findScopedCallTarget(array $tokens, int $receiverIndex): ?array
    {
        $sepIndex = $this->peekNextMeaningfulIndex($tokens, $receiverIndex);
        if ($sepIndex === null) {
            return null;
        }
        $sepToken = $tokens[$sepIndex];
        $sepValue = is_string($sepToken) ? $sepToken : $sepToken[1];
        if ($sepValue !== '::' && $sepValue !== '->') {
            return null;
        }

        $nameIndex = $this->peekNextMeaningfulIndex($tokens, $sepIndex);
        if ($nameIndex === null) {
            return null;
        }
        $nameToken = $tokens[$nameIndex];
        if (!is_array($nameToken) || $nameToken[0] !== T_STRING) {
            return null;
        }

        $afterIndex = $this->peekNextMeaningfulIndex($tokens, $nameIndex);
        if ($afterIndex === null || $tokens[$afterIndex] !== '(') {
            return null;
        }

        return [$nameToken[1], $nameIndex];
    }

    /**
     * Resolves a receiver identifier's raw text ("self", "parent", or a literal class name) to
     * the class it actually refers to. "self" and "parent" depend on where this code physically
     * is; anything else is already a concrete name (namespace-qualified names get shortened).
     *
     * @param list<?string> $classNameStack
     * @param list<?string> $classParentStack
     */
    private function resolveClassNameToken(string $name, array $classNameStack, array $classParentStack): ?string
    {
        return match ($name) {
            'self' => empty($classNameStack) ? null : end($classNameStack),
            'parent' => empty($classParentStack) ? null : end($classParentStack),
            default => $this->shortClassName($name),
        };
    }

    /**
     * Given the index of an assignment's `=` token, checks whether the right-hand side is
     * `new ClassName(...)` (or `new self()`/`new parent()`/`new static()`) and resolves it to a
     * concrete class name. Returns null for anything else — `new class {}` (anonymous),
     * `new $dynamicClass()`, or a non-`new` expression entirely.
     *
     * @param list<?string> $classNameStack
     * @param list<?string> $classParentStack
     * @param list<Token> $tokens
     */
    private function assignedNewClassName(array $tokens, int $equalsIndex, array $classNameStack, array $classParentStack): ?string
    {
        $newIndex = $this->peekNextMeaningfulIndex($tokens, $equalsIndex);
        if ($newIndex === null || !is_array($tokens[$newIndex]) || $tokens[$newIndex][0] !== T_NEW) {
            return null;
        }

        $nameIndex = $this->peekNextMeaningfulIndex($tokens, $newIndex);
        if ($nameIndex === null) {
            return null;
        }
        $nameToken = $tokens[$nameIndex];

        if (is_array($nameToken) && $nameToken[0] === T_STATIC) {
            return empty($classNameStack) ? null : end($classNameStack);
        }

        if (!is_array($nameToken) || !in_array($nameToken[0], self::CLASS_NAME_TOKENS, true)) {
            return null;
        }

        return $this->resolveClassNameToken($nameToken[1], $classNameStack, $classParentStack);
    }

    /** @param list<Token> $tokens */
    private function peekPrevMeaningfulIndex(array $tokens, int $i): ?int
    {
        $j = $i - 1;
        while ($j >= 0) {
            if (is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
                $j--;
                continue;
            }
            return $j;
        }
        return null;
    }

    /** @param list<Token> $tokens */
    private function isArrayOpenAt(array $tokens, int $index): bool
    {
        if (($tokens[$index] ?? null) === '[') {
            return true;
        }
        if (($tokens[$index] ?? null) === '(') {
            // Long array syntax: array( ... ) — the '(' must belong to an `array` keyword, not
            // an ordinary function call.
            $arrIndex = $this->peekPrevMeaningfulIndex($tokens, $index);
            return $arrIndex !== null && is_array($tokens[$arrIndex]) && $tokens[$arrIndex][0] === T_ARRAY;
        }
        return false;
    }

    /**
     * Given the index of a string token that looks like a method/function name, checks whether
     * it's the second element of an array-callback literal — [receiver, 'method'] or
     * array(receiver, 'method') — with a receiver wp-specter can resolve to a concrete class:
     * $this, self::class, parent::class, Foo::class, or a literal 'Foo' string. Returns the
     * resolved class name, or null if this isn't that shape at all (an arbitrary variable
     * receiver like [$obj, 'method'], more/fewer than two elements, no array literal here).
     *
     * @param list<?string> $classNameStack
     * @param list<?string> $classParentStack
     * @param list<Token> $tokens
     */
    private function arrayCallbackReceiverClass(array $tokens, int $i, array $classNameStack, array $classParentStack): ?string
    {
        $commaIndex = $this->peekPrevMeaningfulIndex($tokens, $i);
        if ($commaIndex === null || $tokens[$commaIndex] !== ',') {
            return null;
        }

        $receiverEndIndex = $this->peekPrevMeaningfulIndex($tokens, $commaIndex);
        if ($receiverEndIndex === null) {
            return null;
        }
        $receiverEndToken = $tokens[$receiverEndIndex];

        // [$this, 'method']
        if (is_array($receiverEndToken) && $receiverEndToken[0] === T_VARIABLE && $receiverEndToken[1] === '$this') {
            $openIndex = $this->peekPrevMeaningfulIndex($tokens, $receiverEndIndex);
            if ($openIndex === null || !$this->isArrayOpenAt($tokens, $openIndex)) {
                return null;
            }
            return empty($classNameStack) ? null : end($classNameStack);
        }

        // [Foo::class, 'method'] / [self::class, 'method'] / [parent::class, 'method']
        // Note: under TOKEN_PARSE (used throughout this parser), the "class" in "Foo::class"
        // always tokenizes as T_STRING with value "class" — T_CLASS is only the `class`
        // *keyword* (declarations, `new class {}`), never this magic-constant access.
        if (is_array($receiverEndToken) && $receiverEndToken[0] === T_STRING && $receiverEndToken[1] === 'class') {
            $dcIndex = $this->peekPrevMeaningfulIndex($tokens, $receiverEndIndex);
            if ($dcIndex === null || !is_array($tokens[$dcIndex]) || $tokens[$dcIndex][0] !== T_DOUBLE_COLON) {
                return null;
            }
            $nameIndex = $this->peekPrevMeaningfulIndex($tokens, $dcIndex);
            if ($nameIndex === null || !is_array($tokens[$nameIndex]) || $tokens[$nameIndex][0] !== T_STRING) {
                return null;
            }
            $openIndex = $this->peekPrevMeaningfulIndex($tokens, $nameIndex);
            if ($openIndex === null || !$this->isArrayOpenAt($tokens, $openIndex)) {
                return null;
            }
            return $this->resolveClassNameToken($tokens[$nameIndex][1], $classNameStack, $classParentStack);
        }

        // ['Foo', 'method']
        if (is_array($receiverEndToken) && $receiverEndToken[0] === T_CONSTANT_ENCAPSED_STRING) {
            $openIndex = $this->peekPrevMeaningfulIndex($tokens, $receiverEndIndex);
            if ($openIndex === null || !$this->isArrayOpenAt($tokens, $openIndex)) {
                return null;
            }
            $literal = $this->stripQuotes($receiverEndToken[1]);
            return $literal !== '' ? $literal : null;
        }

        return null;
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
