<?php
function bedrock_theme_setup() {}
add_action( 'after_setup_theme', 'bedrock_theme_setup' );

// Fired by the companion plugin (test-plugin.php), not from within the theme itself —
// proves cross-target hook matching in project-mode scans.
add_action( 'bedrock_cross_target_hook', 'bedrock_cross_target_cb' );
function bedrock_cross_target_cb() {}

// UNUSED
function bedrock_orphaned_func() {}

// UNUSED hook
add_action( 'bedrock_unused_hook', 'bedrock_unused_cb' );
function bedrock_unused_cb() {}
