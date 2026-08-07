<?php

declare(strict_types=1);

namespace WpSpecter\Stubs;

interface HookStub
{
    /** @return list<string> Exact hook names */
    public static function hooks(): array;

    /** @return list<string> Prefixes — any hook starting with these is considered known */
    public static function prefixes(): array;
}
