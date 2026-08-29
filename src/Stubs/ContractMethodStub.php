<?php

declare(strict_types=1);

namespace WpSpecter\Stubs;

interface ContractMethodStub
{
    /**
     * Base class short name => every public method WP core declares on it *and* calls via
     * `$this->method()` from somewhere else in that same class's own body — the "dispatches on
     * self, possibly a subclass" signature every WP core base class designed for subclassing
     * shares (WP_Widget calling $this->widget() from display_callback(), Walker calling
     * $this->start_el() from walk(), ...). A project class overriding one of these is never
     * called by a visible name reference in project code, only reached by WP core itself.
     *
     * @return array<string, list<string>>
     */
    public static function methods(): array;
}
