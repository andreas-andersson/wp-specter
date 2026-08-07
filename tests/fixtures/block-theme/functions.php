<?php
// USED: called in render.php via block registration
function block_theme_setup() {
    add_theme_support( 'editor-styles' );
}
add_action( 'after_setup_theme', 'block_theme_setup' );

// UNUSED: never called anywhere
function block_theme_orphaned_util() {
    return 'unused';
}

// UNUSED hook: 'block_theme_unused_hook' never fired within project
add_action( 'block_theme_unused_hook', 'block_theme_unused_cb' );
function block_theme_unused_cb() {}
