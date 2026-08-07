<?php
/*
Plugin Name: WP Specter Test Plugin
*/
function stdwp_plugin_used_func() {
    return 'used';
}
stdwp_plugin_used_func();

// UNUSED
function stdwp_plugin_orphan() {}
