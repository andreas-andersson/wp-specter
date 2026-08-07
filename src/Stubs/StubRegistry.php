<?php

declare(strict_types=1);

namespace WpSpecter\Stubs;

final class StubRegistry
{
    /** @var list<class-string<HookStub>> */
    private const STUBS = [
        WpCoreHooks::class,
    ];

    /** @var array<string,true>|null */
    private static ?array $hookIndex = null;

    /** @var list<string>|null */
    private static ?array $prefixes = null;

    /**
     * Load additional hooks from a generated stubs JSON file.
     * Must be called before the first contains() call (or resets the index).
     */
    public static function loadFile(string $path): void
    {
        if (!is_file($path)) {
            throw new \RuntimeException("Cannot read stubs file: {$path}");
        }
        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new \RuntimeException("Cannot read stubs file: {$path}");
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            throw new \RuntimeException("Invalid stubs file (expected JSON): {$path}");
        }

        // Support both {"hooks": [...]} and a flat array
        $hooks = isset($data['hooks']) && is_array($data['hooks']) ? $data['hooks'] : $data;
        // Prefixes cover dynamically-dispatched hook families (e.g. ACF's single
        // apply_filters("acf/settings/{$name}", ...) call site fanning out to every
        // acf/settings/* hook) — generate-stubs writes these when it finds one, same idea as
        // the built-in HookStub::prefixes() used for WordPress core.
        $prefixes = isset($data['prefixes']) && is_array($data['prefixes']) ? $data['prefixes'] : [];

        self::build();
        foreach ($hooks as $hook) {
            if (is_string($hook) && $hook !== '') {
                self::$hookIndex[$hook] = true;
            }
        }
        foreach ($prefixes as $prefix) {
            if (is_string($prefix) && $prefix !== '') {
                self::$prefixes[] = $prefix;
            }
        }
    }

    public static function contains(string $hook): bool
    {
        self::build();
        if (isset(self::$hookIndex[$hook])) {
            return true;
        }
        // self::build() above always initializes this to at least [] — PHPStan can't see that
        // invariant across a static method call, hence the null coalesce.
        foreach (self::$prefixes ?? [] as $prefix) {
            if (str_starts_with($hook, $prefix)) {
                return true;
            }
        }
        return false;
    }

    private static function build(): void
    {
        if (self::$hookIndex !== null) {
            return;
        }
        self::$hookIndex = [];
        self::$prefixes = [];
        foreach (self::STUBS as $stub) {
            foreach ($stub::hooks() as $h) {
                self::$hookIndex[$h] = true;
            }
            foreach ($stub::prefixes() as $p) {
                self::$prefixes[] = $p;
            }
        }
    }
}
