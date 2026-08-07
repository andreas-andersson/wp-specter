<?php

declare(strict_types=1);

namespace WpSpecter\Finding;

enum FindingCertainty: string
{
    case Error = 'error';    // Definitely unused (✗)
    case Warning = 'warning'; // Likely unused / not fired within project (⚠)
}
