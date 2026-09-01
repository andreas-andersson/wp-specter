<?php

declare(strict_types=1);

namespace WpSpecter\Tests\unit\Support;

use PHPUnit\Framework\TestCase;
use WpSpecter\Support\StringTransformChain;

final class StringTransformChainTest extends TestCase
{
    public function testEmptyStepsReturnsValueUnchanged(): void
    {
        self::assertSame('admin_email', StringTransformChain::apply('admin_email', []));
    }

    public function testUcfirstStep(): void
    {
        self::assertSame('BulkActions', StringTransformChain::apply('bulkActions', [['ucfirst', []]]));
    }

    public function testStrReplaceStep(): void
    {
        self::assertSame(
            'BulkActions',
            StringTransformChain::apply('admin_post_npBulkActions', [['str_replace', ['admin_post_np', '']]]),
        );
    }

    public function testChainedSteps(): void
    {
        // Real-world shape (WPForms): the original snake_case-to-PascalCase idiom.
        self::assertSame(
            'AdminEmail',
            StringTransformChain::apply('admin_email', [
                ['str_replace', ['_', ' ']],
                ['ucwords', []],
                ['str_replace', [' ', '']],
            ]),
        );
    }

    public function testChainedStepsAcrossTwoStatements(): void
    {
        // Real-world shape (wp-nested-pages): $class = str_replace('admin_post_np', '',
        // $action); $class = ucfirst(str_replace('wp_ajax_np', '', $class));
        self::assertSame(
            'BulkActions',
            StringTransformChain::apply('admin_post_npBulkActions', [
                ['str_replace', ['admin_post_np', '']],
                ['str_replace', ['wp_ajax_np', '']],
                ['ucfirst', []],
            ]),
        );
        self::assertSame(
            'NewChild',
            StringTransformChain::apply('wp_ajax_npnewChild', [
                ['str_replace', ['admin_post_np', '']],
                ['str_replace', ['wp_ajax_np', '']],
                ['ucfirst', []],
            ]),
        );
    }
}
