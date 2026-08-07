<?php
function stdwp_theme_setup() {}
add_action( 'after_setup_theme', 'stdwp_theme_setup' );

// UNUSED
function stdwp_orphaned_func() {}

// UNUSED hook
add_action( 'stdwp_unused_hook', 'stdwp_unused_cb' );
function stdwp_unused_cb() {}
