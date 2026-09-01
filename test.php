<?php
// WP functions mock
function wp_unslash($str) { return stripslashes($str); }
function wp_parse_url($url, $component = -1) { return parse_url($url, $component); }
function esc_url_raw($url) { return filter_var($url, FILTER_SANITIZE_URL); }

$raw_uri = '/some/path?param="><script>alert(1)</script>';
$parsed = wp_parse_url( $raw_uri, PHP_URL_PATH );
$path = $parsed ? esc_url_raw( $parsed ) : '/';
var_dump($path);
