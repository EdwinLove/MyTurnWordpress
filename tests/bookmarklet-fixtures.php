<?php

declare(strict_types=1);

// WordPress stubs for bookmark generation; no WordPress or Composer install required.
require dirname(__DIR__) . '/src/Plugin.php';

function get_option($name, $default = []) { return $GLOBALS['settings']; }
function home_url($path) { return 'https://example.org/wordpress' . $path; }
function user_trailingslashit($path) { return rtrim($path, '/') . ($GLOBALS['trailingSlash'] ? '/' : ''); }
function sanitize_title($value) { return trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($value)), '-'); }
function wp_json_encode($value, $flags = 0) { return json_encode($value, $flags | JSON_THROW_ON_ERROR); }
function __($value, $domain) { return $value; }

$plugin = new EdwinLove\MyTurn\WordPress\Plugin();
$base = ['thing_route' => 'items/{id}', 'search_route' => 'find/{query}', 'catalogue_route' => 'borrow/things'];
$scenarios = [
    'multiple' => ['locations' => [['title' => 'North Branch', 'code' => '7'], ['title' => 'South', 'code' => '0']]],
    'single' => ['locations' => [['title' => '', 'code' => '12']]],
    'none' => ['locations' => []],
    'disabled' => ['locations' => [['title' => 'North', 'code' => '7']], 'catalogue_route' => ''],
    'legacy' => ['location' => '9'],
];
$fixtures = [];
foreach ($scenarios as $name => $settings) {
    $GLOBALS['settings'] = $settings + $base;
    $GLOBALS['trailingSlash'] = true;
    foreach (['thing', 'search'] as $type) {
        $fixtures[$name][$type] = (new ReflectionMethod($plugin, $type . 'LinkBookmarklet'))->invoke($plugin);
    }
}
$GLOBALS['settings'] = $scenarios['multiple'] + $base;
$GLOBALS['trailingSlash'] = false;
$fixtures['noSlash']['search'] = (new ReflectionMethod($plugin, 'searchLinkBookmarklet'))->invoke($plugin);
echo json_encode($fixtures, JSON_THROW_ON_ERROR);
