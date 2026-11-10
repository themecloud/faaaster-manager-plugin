<?php

/** WP request/query boundaries only; run the real OD optimizer and detection generator. */
class WP_Query {
    public $posts = array();
    public $post_count = 0;
    public function __construct($args = array()) {}
    public function get_queried_object() { return null; }
}
function is_404() { return false; }
function is_multisite() { return false; }
function wp_is_block_theme() { return false; }
function get_template() { return 'test-theme'; }
function get_stylesheet() { return 'test-theme'; }
function wp_get_theme($theme = null) { return new class { public function get($key) { return '1.0'; } }; }
function get_queried_object() { return null; }
function wp_unslash($value) { return $value; }
function is_user_logged_in() { return false; }
function current_user_can($capability) { return false; }
function wp_hash($value, $scheme = null) { return hash('sha256', $value); }
function rest_url($path) { return apply_filters('rest_url', home_url('/wp-json/' . ltrim($path, '/')), $path); }
