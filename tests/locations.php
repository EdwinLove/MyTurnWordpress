<?php

declare(strict_types=1);

// Run with php tests/locations.php; no WordPress installation required.
require dirname(__DIR__) . '/vendor/autoload.php';

use EdwinLove\MyTurn\WordPress\Plugin;

$options = [];
$query = [];
$rules = [];
$errors = [];
function get_option($key, $default = false) { return $GLOBALS['options'][$key] ?? $default; }
function update_option($key, $value, ...$args) { $GLOBALS['options'][$key] = $value; }
function get_query_var($key) { return $GLOBALS['query'][$key] ?? ''; }
function sanitize_text_field($value) { return trim(strip_tags($value)); }
function sanitize_title($value) { return trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($value)), '-'); }
function __($value, $domain) { return $value; }
function add_settings_error(...$args) { $GLOBALS['errors'][] = $args; }
function add_rewrite_rule($regex, $target, $position) { $GLOBALS['rules'][$regex] = $target; }
function add_query_arg($key, $value, $url) { return $url . (str_contains($url, '?') ? '&' : '?') . http_build_query([$key => $value]); }
function check($condition, $message) { if (!$condition) { throw new RuntimeException($message); } }
function invoke($plugin, $method, ...$args) { return (new ReflectionMethod($plugin, $method))->invoke($plugin, ...$args); }
function route($path) {
    foreach ($GLOBALS['rules'] as $regex => $target) {
        if (preg_match('#' . $regex . '#', $path, $matches)) {
            foreach ($matches as $index => $value) {
                $target = str_replace('$matches[' . $index . ']', urlencode($value), $target);
            }
            parse_str(substr($target, strlen('index.php?')), $query);
            return $query;
        }
    }
    return [];
}

$plugin = new Plugin();
$base = ['tenant_subdomain' => 'library', 'catalogue_route' => 'catalogue', 'thing_route' => 'thing/{id}', 'search_route' => 'thing/search/{query}'];
$options['myturn_settings'] = $plugin->sanitizeSettings($base + ['locations' => [
    ['title' => 'North Branch', 'code' => '007'], ['title' => 'South', 'code' => '0'],
]]);
check($errors === [], 'Valid locations should save');
$plugin->addRewriteRules();
foreach (['north-branch' => '7', 'south' => '0'] as $slug => $code) {
    $query = route('catalogue/' . $slug);
    check(($query['myturn_catalogue'] ?? '') === '1', 'Catalogue route should match');
    check(invoke($plugin, 'cataloguePath', invoke($plugin, 'currentLocation')) === 'catalogue/' . $slug, 'Canonical catalogue path should match');
    check(str_ends_with(invoke($plugin, 'withLocation', 'https://library.myturn.com/library/inventory/browse/?embed=true'), '&location=' . $code), 'Catalogue embed should select location');
    $query = route('catalogue/' . $slug . '/search/power%20drill/');
    check(($query['myturn_search'] ?? '') === 'power%20drill', 'Search route should preserve encoded term');
    check(($query['myturn_catalogue_search'] ?? '') === '1', 'Search route should use catalogue canonical path');
    check(str_ends_with(invoke($plugin, 'withLocation', 'https://library.myturn.com/library/inventory/search?embed=true'), '&location=' . $code), 'Search embed should select location');
}
check(route('catalogue/unknown') === [], 'Unknown location should not match');
check(route('catalogue') === [], 'Multiple locations should require a title in the path');
check(route('thing/42') === ['myturn_thing' => '42'], 'Existing thing route should still work');
check(route('thing/search/drill') === ['myturn_search' => 'drill'], 'Existing search route should still work');

foreach ([
    [['title' => '', 'code' => '1'], ['title' => 'South', 'code' => '2']],
    [['title' => 'North Branch', 'code' => '1'], ['title' => 'north-branch', 'code' => '2']],
    [['title' => 'North', 'code' => '-1']],
    [['title' => 'North', 'code' => '1.5']],
    [['title' => 'North', 'code' => '']],
    [['title' => 'North', 'code' => '999999999999999999999999']],
    [['title' => 'North', 'code' => '1'], ['title' => 'South', 'code' => '01']],
] as $invalid) {
    $errors = [];
    check($plugin->sanitizeSettings($base + ['locations' => $invalid]) === $options['myturn_settings'], 'Invalid settings should preserve saved values');
    check(count($errors) === 1, 'Invalid locations should report an error');
}

foreach ([['title' => '', 'code' => '12'], ['title' => 'North', 'code' => '12']] as $location) {
    $options['myturn_settings'] = $base + ['locations' => [$location]];
    $rules = [];
    $plugin->addRewriteRules();
    $path = $location['title'] === '' ? 'catalogue' : 'catalogue/north';
    $query = route($path . '/search/drill');
    check(($query['myturn_location'] ?? '') === '12', 'Single location search should match');
    check(invoke($plugin, 'cataloguePath', invoke($plugin, 'currentLocation')) === $path, 'Single location canonical path should match');
}
$options['myturn_settings'] = $base + ['location' => '9'];
$query = [];
check(str_ends_with(invoke($plugin, 'withLocation', 'https://example.com/?embed=true'), '&location=9'), 'Legacy location should be preserved');
$rules = [];
$plugin->addRewriteRules();
check((route('catalogue')['myturn_location'] ?? '') === '9', 'Legacy catalogue URL should be preserved');
$plugin->settingsUpdated(['locations' => []], ['locations' => [['title' => 'North', 'code' => '1']]]);
check(($options['myturn_rewrite_flush_pending'] ?? null) === 1, 'Location changes should flush routes');
$options['myturn_settings'] = $base + ['locations' => []];
$rules = [];
$plugin->addRewriteRules();
$query = route('catalogue');
check(invoke($plugin, 'withLocation', 'https://example.com/') === 'https://example.com/', 'No locations should leave embed unfiltered');
// All location routes must follow the existing configurable catalogue path.
foreach ([[], [['title' => '', 'code' => '7']], [['title' => 'North Branch', 'code' => '7'], ['title' => 'South', 'code' => '12']]] as $locations) {
    $options['myturn_settings'] = array_replace($base, ['catalogue_route' => 'library/tools', 'locations' => $locations]);
    $rules = [];
    $plugin->addRewriteRules();
    $path = count($locations) > 1 ? 'library/tools/north-branch' : 'library/tools';
    $query = route($path);
    check(($query['myturn_catalogue'] ?? '') === '1', 'Catalogue should use configured base path');
    check(invoke($plugin, 'cataloguePath', invoke($plugin, 'currentLocation')) === $path, 'Canonical should use configured base path');
    $query = route($path . '/search/drill');
    check(($query['myturn_search'] ?? '') === 'drill', 'Search should use configured base path');
    check(route('catalogue') === [] && route('catalogue/north-branch/search/drill') === [], 'Default catalogue path should not remain active');
}
$options['myturn_settings']['catalogue_route'] = '';
$rules = [];
$plugin->addRewriteRules();
check(route('catalogue/search/drill') === [], 'Blank catalogue route should disable catalogue searches');
echo "Location checks passed.\n";
