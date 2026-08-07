<?php

declare(strict_types=1);

namespace WpSpecter\Finding;

enum FindingType: string
{
    case UnusedFunction = 'unused_function';
    case UnmatchedHook = 'unmatched_hook';
    case UnusedTemplate = 'unused_template';
    case UnusedFile = 'unused_file';
    case UnusedClass = 'unused_class';
    case UnusedMethod = 'unused_method';
}
