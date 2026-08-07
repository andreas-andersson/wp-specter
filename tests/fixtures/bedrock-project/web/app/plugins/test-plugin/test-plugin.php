<?php
/*
Plugin Name: WP Specter Bedrock Test Plugin
*/
function bedrock_plugin_used_func() {}
bedrock_plugin_used_func();

// Fires a hook registered by the companion theme (functions.php), not from within this
// plugin itself — proves cross-target hook matching in project-mode scans.
do_action( 'bedrock_cross_target_hook' );

// UNUSED
function bedrock_plugin_orphan() {}
