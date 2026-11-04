<?php

/**
 * Plugin Name: RSD Products Filter
 * Plugin URI: https://github.com/rasoulmarzban/RSD-products-filter
 * Description: WooCommerce product filtering via shortcode, hierarchical filters, price sliders, and custom product cards.
 * Version: 1.0.0
 * Author: Rasoul Marzban
 * Text Domain: rsd-products-filter
 * Domain Path: /languages
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

define('RSDPF_VERSION', '1.0.0');
define('RSDPF_FILE', __FILE__);
define('RSDPF_PATH', plugin_dir_path(__FILE__));
define('RSDPF_URL', plugin_dir_url(__FILE__));

spl_autoload_register(
    static function (string $class): void {
        $prefix = 'RSDPF\\';

        if (strpos($class, $prefix) !== 0) {
            return;
        }

        $relativeClass = substr($class, strlen($prefix));
        $file = RSDPF_PATH . 'src/' . str_replace('\\', '/', $relativeClass) . '.php';

        if (file_exists($file)) {
            require_once $file;
        }
    }
);

add_action(
    'plugins_loaded',
    static function (): void {
        if (!class_exists('WooCommerce')) {
            add_action(
                'admin_notices',
                static function (): void {
                    if (!current_user_can('activate_plugins')) {
                        return;
                    }

                    echo '<div class="notice notice-error"><p>';
                    echo esc_html__('RSD Products Filter requires WooCommerce to be active.', 'rsd-products-filter');
                    echo '</p></div>';
                }
            );

            return;
        }

        $pluginClass = '\\RSDPF\\Plugin';
        (new $pluginClass())->boot();
    }
);
