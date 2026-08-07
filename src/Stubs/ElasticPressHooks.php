<?php

declare(strict_types=1);

namespace WpSpecter\Stubs;

// Sourced from https://github.com/10up/ElasticPress
final class ElasticPressHooks implements HookStub
{
    private const HOOKS = [
        // --- Indexing ---
        'ep_index_name',
        'ep_index_settings',
        'ep_mapping',
        'ep_config_mapping',
        'ep_default_index_number_of_shards',
        'ep_default_index_number_of_replicas',
        'ep_indexable_post_types',
        'ep_indexable_post_status',
        'ep_post_formatted_args',
        'ep_formatted_args',
        'ep_post_sync_args',
        'ep_post_sync_args_post_prepare_meta',
        'ep_prepare_meta_allowed_protected_keys',
        'ep_prepare_meta_excluded_public_keys',
        'ep_skip_post_meta_sync',
        'ep_post_index_filtered_data',

        // --- Search / query ---
        'ep_query_request_path',
        'ep_query_request_args',
        'ep_search_request_path',
        'ep_search_request_args',
        'ep_search_fields',
        'ep_facet_search_fields',
        'ep_post_match_fuzziness',
        'ep_match_phrase_boost',
        'ep_match_boost',
        'ep_allowed_documents_for_checkout',
        'ep_analyzer_language',
        'ep_fuzziness_arg',
        'ep_highlight_number_of_fragments',
        'ep_highlight_type',
        'ep_enable_do_weighting',
        'ep_weighting_fields_for_post_type',
        'ep_weighting_configuration',
        'ep_weighting_configuration_for_search',
        'ep_find_related_args',

        // --- Results ---
        'ep_search_results_array',
        'ep_retrieve_the_post',
        'ep_search_post_return_args',

        // --- Suggestions / autosuggest ---
        'ep_suggestion_html',
        'ep_autosuggest_query_placeholder',
        'ep_autosuggest_options',

        // --- Sync ---
        'ep_sync_insert_permissions_bypass',
        'ep_sync_delete_permissions_bypass',
        'ep_allow_post_content_filtered_index',

        // --- Facets ---
        'ep_facet_search_threshold',
        'ep_facet_widget_term_html',
        'ep_facet_widget_html',

        // --- Admin / UI ---
        'ep_dashboard_index_status',
        'ep_admin_notices',

        // --- Features ---
        'ep_feature_active',
        'ep_feature_requirements_status',
        'ep_registered_feature',

        // --- Misc ---
        'ep_index_name',
        'ep_bulk_items_per_page',
        'ep_http_request_args',
        'ep_es_query_args',
        'ep_valid_response',
        'ep_upsert_request',
        'ep_delete_request',
        'ep_get_request',
    ];

    private const PREFIXES = [
        'ep_',  // All ElasticPress hooks share the ep_ prefix
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
