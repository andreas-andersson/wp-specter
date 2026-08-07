<?php

declare(strict_types=1);

namespace WpSpecter\Stubs;

// Sourced from https://www.advancedcustomfields.com/resources/
final class AcfProHooks implements HookStub
{
    private const HOOKS = [
        // --- Bootstrap ---
        'acf/init',
        'acf/include_fields',
        'acf/register_block_type',
        'acf/register_block_type_args',

        // --- Post save/load ---
        'acf/save_post',
        'acf/pre_save_post',
        'acf/load_post_id',
        'acf/update_post_id',
        'acf/validate_post_id',

        // --- Admin ---
        'acf/input/admin_enqueue_scripts',
        'acf/input/admin_head',
        'acf/input/admin_footer',
        'acf/field_group/admin_enqueue_scripts',
        'acf/field_group/admin_head',

        // --- Settings ---
        'acf/settings/save_json',
        'acf/settings/load_json',
        'acf/settings/path',
        'acf/settings/dir',
        'acf/settings/url',
        'acf/settings/show_admin',
        'acf/settings/show_updates',
        'acf/settings/stripslashes',
        'acf/settings/current_language',
        'acf/settings/default_language',
        'acf/settings/l10n',
        'acf/settings/l10n_textdomain',
        'acf/settings/l10n_var_export',
        'acf/settings/google_api_key',

        // --- Location rules ---
        'acf/location/rule_types',

        // --- Misc ---
        'acf/get_post_id_info',
        'acf/pre_load_post_id',
        'acf/pre_update_post_id',
    ];

    // ACF uses slash-separated hook names; all variants share these prefixes
    private const PREFIXES = [
        'acf/load_field',         // acf/load_field, acf/load_field/name=x, acf/load_field/key=x
        'acf/update_field',
        'acf/delete_field',
        'acf/duplicate_field',
        'acf/render_field',       // acf/render_field/type=text etc.
        'acf/prepare_field',
        'acf/validate_value',
        'acf/format_value',       // acf/format_value/type=text etc.
        'acf/load_value',
        'acf/update_value',
        'acf/delete_value',
        'acf/validate_attachment',
        'acf/upload_prefilter',
        'acf/fields/',            // acf/fields/{type}/...
        'acf/location/rule_values',
        'acf/location/rule_match',
        'acf/field_group/',       // acf/field_group/render_field_settings etc.
        'acf/input_admin_',
        'acf/get_field_label',
        'acf/get_field_description',
    ];

    public static function hooks(): array
    {
        return self::HOOKS;
    }

    public static function prefixes(): array
    {
        return self::PREFIXES;
    }
}
