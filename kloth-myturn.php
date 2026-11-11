<?php
/**
 * Plugin Name: KLOTH MyTurn
 * Description: MyTurn catalogue embeds and thing/search routes.
 * Version: 0.1.0
 * Requires PHP: 8.4
 */

declare(strict_types=1);

use EdwinLove\MyTurn\WordPress\Plugin;

if (!defined('ABSPATH')) {
    exit;
}

$autoload = __DIR__ . '/vendor/autoload.php';
if (is_file($autoload)) {
    require_once $autoload;
} elseif (is_file(dirname(__DIR__, 2) . '/vendor/autoload.php')) {
    // Convenient for this monorepo while the core and plugin are developed together.
    require_once dirname(__DIR__, 2) . '/vendor/autoload.php';
}

add_action('plugins_loaded', static function (): void {
    (new Plugin())->register();
});

register_activation_hook(__FILE__, [Plugin::class, 'activate']);
register_deactivation_hook(__FILE__, [Plugin::class, 'deactivate']);
