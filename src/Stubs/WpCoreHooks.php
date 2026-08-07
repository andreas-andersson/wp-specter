<?php

declare(strict_types=1);

namespace WpSpecter\Stubs;

final class WpCoreHooks implements HookStub
{
    // Sourced from https://developer.wordpress.org/reference/hooks/
    private const HOOKS = [
        // --- Bootstrap / lifecycle ---
        'muplugins_loaded',
        'plugins_loaded',
        'setup_theme',
        'after_setup_theme',
        'after_load_textdomain',
        'init',
        'wp_loaded',
        'send_headers',
        'parse_request',
        'parse_query',
        'pre_get_posts',
        'wp',
        'template_redirect',
        'shutdown',

        // --- Template ---
        'wp_head',
        'wp_footer',
        'wp_body_open',
        'loop_start',
        'loop_end',
        'the_post',
        'get_header',
        'get_footer',
        'get_sidebar',
        'get_search_form',

        // --- Comments template ---
        'comment_form',
        'comment_form_top',
        'comment_form_before',
        'comment_form_after',
        'comment_form_before_fields',
        'comment_form_after_fields',

        // --- Enqueue ---
        'wp_enqueue_scripts',
        'wp_print_scripts',
        'wp_print_styles',
        'wp_print_footer_scripts',
        'admin_enqueue_scripts',
        'admin_print_scripts',
        'admin_print_styles',
        'admin_print_footer_scripts',
        'login_enqueue_scripts',

        // --- Admin: meta boxes / columns / actions ---
        'add_meta_boxes',
        'post_submitbox_misc_actions',
        'post_submitbox_start',
        'restrict_manage_posts',
        'manage_pages_custom_column',
        'manage_users_columns',
        'manage_users_custom_column',
        'manage_users_sortable_columns',
        'bulk_edit_custom_box',
        'quick_edit_custom_box',
        'admin_action_duplicate_post_as_draft',

        // --- Admin ---
        'admin_init',
        'admin_menu',
        'admin_head',
        'admin_footer',
        'admin_notices',
        'admin_bar_menu',
        'network_admin_menu',
        'user_admin_menu',
        'current_screen',
        'in_admin_header',
        'in_admin_footer',
        'admin_bar_render',
        'admin_color_scheme_picker',
        'admin_page_access_denied',

        // --- Post CRUD ---
        'save_post',
        'pre_post_update',
        'post_updated',
        'wp_insert_post',
        'wp_update_post',
        'delete_post',
        'before_delete_post',
        'after_delete_post',
        'trash_post',
        'untrash_post',
        'publish_post',
        'publish_page',
        'transition_post_status',
        'post_stuck',
        'post_unstuck',
        'attachment_updated',
        'edit_attachment',
        'add_attachment',
        'delete_attachment',
        'wp_generate_attachment_metadata',

        // --- Multisite ---
        'signup_extra_fields',
        'wpmu_validate_user_signup',
        'add_signup_meta',
        'wpmu_activate_user',
        'wpmu_new_user',
        'wpmu_new_blog',

        // --- User / auth ---
        'user_register',
        'profile_update',
        'delete_user',
        'deleted_user',
        'wp_login',
        'wp_login_failed',
        'wp_logout',
        'set_current_user',
        'set_logged_in_cookie',
        'clear_auth_cookie',
        'auth_cookie_valid',
        'auth_cookie_malformed',
        'auth_cookie_expired',
        'auth_cookie_bad_username',
        'auth_cookie_bad_password',
        'login_init',
        'login_head',
        'login_header',
        'login_footer',
        'login_form',
        'register_form',
        'lostpassword_form',
        'resetpass_form',
        'password_reset',
        'retrieve_password',

        // --- Widgets / sidebars ---
        'widgets_init',
        'dynamic_sidebar',
        'dynamic_sidebar_before',
        'dynamic_sidebar_after',
        'register_sidebar',
        'unregister_sidebar',
        'sidebar_admin_setup',

        // --- Nav menus ---
        'wp_update_nav_menu',
        'wp_create_nav_menu',
        'wp_delete_nav_menu',
        'wp_register_nav_menus',

        // --- Customizer ---
        'customize_register',
        'customize_preview_init',
        'customize_save',
        'customize_save_after',
        'customize_controls_init',
        'customize_controls_enqueue_scripts',
        'customize_controls_print_styles',
        'customize_controls_print_scripts',

        // --- Comments ---
        'comment_post',
        'edit_comment',
        'delete_comment',
        'spam_comment',
        'unspam_comment',
        'trash_comment',
        'untrash_comment',
        'pre_comment_on_post',
        'comment_closed',
        'comment_flood_trigger',

        // --- Post types / taxonomies ---
        'register_post_type',
        'registered_post_type',
        'unregister_post_type',
        'register_taxonomy',
        'registered_taxonomy',
        'unregister_taxonomy',
        'create_term',
        'created_term',
        'edit_term',
        'edited_term',
        'delete_term',
        'split_shared_term',
        'edit_category',
        'edited_category',
        'edit_tag',
        'edited_tag',

        // --- Options ---
        'add_option',
        'added_option',
        'update_option',
        'updated_option',
        'delete_option',
        'deleted_option',

        // --- Plugins ---
        'activated_plugin',
        'deactivated_plugin',
        'upgrader_process_complete',

        // --- Theme switch ---
        'switch_theme',
        'after_switch_theme',
        'check_theme_switched',

        // --- Cron ---
        'wp_scheduled_delete',
        'wp_scheduled_auto_draft_delete',

        // --- Block editor (Gutenberg) ---
        'enqueue_block_editor_assets',
        'enqueue_block_assets',
        'allowed_block_types_all',
        'block_categories_all',
        'block_categories',
        'block_editor_settings_all',
        'block_editor_meta_box_hidden_fields',
        'register_block_type',
        'init',
        'use_widgets_block_editor',
        'gutenberg_use_widgets_block_editor',
        'should_load_separate_core_block_assets',

        // --- REST API ---
        'rest_api_init',

        // --- Rewrite ---
        'generate_rewrite_rules',
        'parse_request',

        // --- Mail ---
        'phpmailer_init',
        'wp_mail_failed',
        'wp_mail',

        // --- Template filters ---
        'archive_template',
        'single_template',
        'page_template',
        'home_template',
        'frontpage_template',
        'search_template',
        'attachment_template',
        'author_template',
        'category_template',
        'tag_template',
        'taxonomy_template',
        'date_template',
        '404_template',
        'index_template',
        'template_include',

        // --- Misc ---
        'wp_default_scripts',
        'wp_default_styles',
        'wp_loaded',
        'xmlrpc_call',
        'wp_before_admin_bar_render',
        'wp_after_admin_bar_render',
        'wp_trash_post',
        'before_wp_tiny_mce',
        'after_wp_tiny_mce',
        'do_shortcode_tag',
        'pre_do_shortcode_tag',
        'wp_link_query',
        'do_robots',
        'do_feed',
        'do_feed_rss2',
        'do_feed_atom',
        'wp_install',
        'wpmu_new_blog',
        'wpmu_activate_blog',
        'automatic_updates_complete',
        'upgrader_pre_install',
        'upgrader_post_install',
        'heartbeat_received',
        'heartbeat_send',
        'heartbeat_nopriv_received',
        'heartbeat_nopriv_send',

        // --- Filters: content ---
        'the_content',
        'the_title',
        'the_excerpt',
        'get_the_excerpt',
        'the_content_feed',
        'the_excerpt_rss',
        'the_title_rss',
        'the_content_rss',
        'the_category',
        'the_tags',
        'the_terms',
        'the_author',
        'the_author_link',
        'the_password_form',
        'the_date',
        'the_time',
        'the_modified_date',
        'the_modified_time',
        'the_permalink',

        // --- Filters: classes ---
        'body_class',
        'post_class',
        'admin_body_class',
        'comment_class',

        // --- Filters: title / document ---
        'wp_title',
        'document_title_parts',
        'document_title_separator',
        'pre_get_document_title',
        'bloginfo',
        'bloginfo_rss',
        'get_bloginfo_rss',

        // --- Filters: excerpt ---
        'excerpt_length',
        'excerpt_more',

        // --- Filters: nav menus ---
        'wp_nav_menu',
        'wp_nav_menu_items',
        'wp_nav_menu_args',
        'wp_nav_menu_objects',
        'wp_nav_menu_container_allowedtags',
        'nav_menu_item_title',
        'nav_menu_link_attributes',
        'nav_menu_css_class',
        'nav_menu_item_args',
        'nav_menu_item_id',
        'nav_menu_submenu_css_class',
        'walker_nav_menu_start_el',

        // --- Filters: avatar ---
        'get_avatar',
        'get_avatar_url',
        'avatar_defaults',
        'get_avatar_data',

        // --- Filters: comments ---
        'comment_text',
        'comment_excerpt',
        'comment_author',
        'comment_url',
        'comment_email',
        'comment_reply_link',
        'comment_reply_link_args',
        'comment_form_default_fields',
        'comment_form_defaults',
        'get_comments_number',
        'comments_number',
        'comments_open',
        'pings_open',
        'comments_template',
        'wp_list_comments_args',

        // --- Filters: URLs ---
        'home_url',
        'site_url',
        'admin_url',
        'includes_url',
        'content_url',
        'plugins_url',
        'theme_url',
        'network_home_url',
        'network_site_url',
        'network_admin_url',
        'get_permalink',
        'post_link',
        'page_link',
        'post_type_link',
        'term_link',
        'author_link',
        'get_author_posts_url',
        'attachment_link',
        'redirect_canonical',
        'wp_redirect',
        'allowed_redirect_hosts',

        // --- Filters: theme paths ---
        'stylesheet_uri',
        'template_uri',
        'template_directory_uri',
        'stylesheet_directory_uri',
        'get_stylesheet',
        'get_template',
        'theme_file_path',
        'theme_file_uri',

        // --- Filters: locale ---
        'locale',
        'determine_locale',

        // --- Filters: uploads ---
        'upload_dir',
        'wp_upload_dir',
        'wp_handle_upload',
        'wp_handle_sideload',

        // --- Filters: query ---
        'query_vars',
        'request',
        'found_posts',
        'posts_results',
        'the_posts',
        'posts_where',
        'posts_join',
        'posts_orderby',
        'posts_groupby',
        'posts_fields',
        'post_limits',
        'posts_per_page',
        'posts_search',
        'pre_get_posts',

        // --- Filters: users ---
        'user_contactmethods',
        'user_has_cap',
        'map_meta_cap',
        'sanitize_user',
        'pre_user_login',
        'pre_user_display_name',
        'user_display_name_publicly_as',
        'login_headerurl',
        'login_headertitle',
        'login_headertext',
        'login_message',
        'login_errors',
        'login_form_middle',
        'login_form_bottom',

        // --- Filters: auth ---
        'wp_authenticate_user',
        'wp_authenticate',
        'check_password',
        'authenticate',

        // --- Filters: scripts / styles ---
        'script_loader_tag',
        'style_loader_tag',
        'style_loader_src',
        'script_loader_src',
        'wp_resource_hints',
        'wp_preload_resources',

        // --- Filters: images ---
        'image_downsize',
        'wp_get_attachment_image_attributes',
        'wp_get_attachment_image_src',
        'wp_calculate_image_sizes',
        'wp_calculate_image_srcset',
        'get_image_tag',
        'get_image_tag_class',
        'image_size_names_choose',
        'intermediate_image_sizes',
        'intermediate_image_sizes_advanced',
        'big_image_size_threshold',
        'wp_image_editors',

        // --- Filters: widgets ---
        'widget_title',
        'widget_text',
        'widget_text_content',
        'widget_display_callback',
        'widget_form_callback',
        'dynamic_sidebar_params',
        'is_active_sidebar',

        // --- Filters: editor ---
        'wp_editor_settings',
        'the_editor_content',
        'mce_buttons',
        'mce_buttons_2',
        'mce_buttons_3',
        'mce_external_plugins',
        'tiny_mce_before_init',
        'media_upload_tabs',
        'quicktags_settings',

        // --- Filters: archive / lists ---
        'get_the_archive_title',
        'get_the_archive_description',
        'the_archive_title',
        'the_archive_description',
        'wp_list_categories',
        'wp_list_pages',
        'wp_tag_cloud',
        'get_the_categories',
        'get_the_tags',
        'get_the_terms',
        'get_term',
        'get_terms',

        // --- Filters: pagination ---
        'paginate_links',
        'get_pagenum_link',
        'next_posts_link',
        'previous_posts_link',
        'next_post_link',
        'previous_post_link',
        'posts_nav_link',

        // --- Filters: feed ---
        'feed_links_extra',
        'feed_links_show_posts_feed',
        'feed_links_show_comments_feed',
        'the_feed_link',

        // --- Filters: search ---
        'get_search_form',
        'get_search_query',
        'search_form_format',

        // --- Filters: oEmbed ---
        'oembed_providers',
        'embed_oembed_html',
        'embed_html',
        'oembed_result',
        'oembed_response_data',

        // --- Filters: sanitize / security ---
        'sanitize_text_field',
        'sanitize_email',
        'sanitize_url',
        'sanitize_key',
        'sanitize_file_name',
        'sanitize_title',
        'sanitize_html_class',
        'kses_allowed_protocols',
        'wp_kses_allowed_html',
        'is_email',
        'is_protected_meta',
        'allowed_http_origins',

        // --- Filters: post insert ---
        'wp_insert_post_data',
        'wp_update_attachment_metadata',
        'attachment_fields_to_edit',
        'attachment_fields_to_save',

        // --- Filters: REST API ---
        'rest_pre_serve_request',
        'rest_endpoints',
        'rest_url',
        'rest_url_prefix',
        'rest_authentication_errors',
        'rest_request_before_callbacks',
        'rest_request_after_callbacks',

        // --- Filters: rewrite ---
        'rewrite_rules_array',
        'robots_txt',
        'wp_robots',

        // --- Filters: cron ---
        'cron_schedules',
        'cron_request',

        // --- Filters: misc ---
        'language_attributes',
        'the_generator',
        'use_block_editor_for_post',
        'use_block_editor_for_post_type',
        'post_thumbnail_html',
        'wp_page_menu_args',
        'wp_headers',
        'wp_img_tag_add_auto_sizes',
        'xmlrpc_enabled',
        'gettext',
        'gettext_with_context',
        'ngettext',
        'ngettext_with_context',
        'send_password_change_email',
        'send_email_change_email',
        'parse_tax_query',
        'pre_get_users',
        'next_posts_link_attributes',
        'previous_posts_link_attributes',
        'wp_insert_post_data',
        'pre_option_siteurl',
        'option_siteurl',
        'locale',
        'is_ssl',
        'got_rewrite',
        'got_mod_rewrite',
        'auto_update_plugin',
        'auto_update_theme',
        'auto_update_translation',
        'auto_update_core',
        'automatic_updater_disabled',
        'site_transient_update_plugins',
        'site_transient_update_themes',
        'site_transient_update_core',
        'wp_mail',
        'wp_mail_content_type',
        'wp_mail_charset',
        'wp_mail_from',
        'wp_mail_from_name',
        'manage_posts_columns',
        'manage_pages_columns',
        'manage_posts_custom_column',
        'page_attributes_dropdown_pages_args',
        'page_row_actions',
        'post_row_actions',
        'bulk_actions-edit-post',
        'get_sample_permalink',
        'get_sample_permalink_html',
        'wp_unique_post_slug',
    ];

    // Dynamic WP core hook prefixes — any hook starting with these is fired by WP core
    private const PREFIXES = [
        'wp_ajax_',           // wp_ajax_{action}
        'wp_ajax_nopriv_',    // wp_ajax_nopriv_{action}
        'option_',            // option_{name}
        'pre_option_',        // pre_option_{name}
        'default_option_',    // default_option_{name}
        'update_option_',     // update_option_{name}
        'added_option_',
        'deleted_option_',
        'site_option_',
        'pre_site_option_',
        'update_site_option_',
        'transient_',         // transient_{name}
        'site_transient_',    // site_transient_{name}
        'publish_',           // publish_{post_type}
        'save_post_',         // save_post_{post_type}
        'delete_post_',       // delete_post_{post_type}
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
