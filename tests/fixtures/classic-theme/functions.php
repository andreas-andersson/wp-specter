<?php
// USED: called in inc/helpers.php
function classic_theme_setup() {
    add_theme_support( 'title-tag' );
}
add_action( 'after_setup_theme', 'classic_theme_setup' );

// UNUSED: never called anywhere
function classic_orphaned_helper() {
    return 'nobody calls me';
}

// USED hook: do_action fires 'classic_theme_before_header' in header.php
add_action( 'classic_theme_before_header', 'classic_before_header_cb' );
function classic_before_header_cb() {}

// UNUSED hook: 'classic_unused_hook' is never do_action'd within the project
add_action( 'classic_unused_hook', 'classic_unused_cb' );
function classic_unused_cb() {}

// USED: called below
function classic_enqueue_scripts() {
    wp_enqueue_style( 'theme-style', get_stylesheet_uri() );
}
add_action( 'wp_enqueue_scripts', 'classic_enqueue_scripts' );
