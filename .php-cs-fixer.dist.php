<?php

declare(strict_types=1);

$finder = (new PhpCsFixer\Finder())
    ->in(__DIR__ . '/src')
    ->in(__DIR__ . '/tests')
    // Fake WP theme/plugin files used as scan targets in integration tests — deliberately varied
    // real-world style, not our code, and not meant to be normalized.
    ->exclude('fixtures')
    ->in(__DIR__ . '/tools')
    ->append([__DIR__ . '/bin/wp-specter']);

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setRules([
        '@PER-CS2.0' => true,
        '@PER-CS2.0:risky' => true,
        'declare_strict_types' => true,
        'ordered_imports' => ['sort_algorithm' => 'alpha'],
        'no_unused_imports' => true,
        'single_quote' => true,
        'trailing_comma_in_multiline' => ['elements' => ['arrays', 'match', 'parameters']],
        'phpdoc_align' => false,
        'yoda_style' => false,
    ])
    ->setFinder($finder);
