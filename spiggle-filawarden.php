<?php
/**
 * Plugin Name: FilaWarden Core
 * Description: Operations intelligence, deployment auditor, and production sentinel for WordPress. Same activities and look as FilaWarden for Filament.
 * Version: 1.0.2
 * Author: Spiggle
 * License: MIT
 * Requires at least: 6.4
 * Requires PHP: 8.1
 * Text Domain: filawarden
 */

if (!defined('ABSPATH')) {
    exit;
}

define('FILAWARDEN_VERSION', '1.0.2');
define('FILAWARDEN_FILE', __FILE__);
define('FILAWARDEN_DIR', plugin_dir_path(__FILE__));

require_once FILAWARDEN_DIR . 'includes/class-filawarden-config.php';
require_once FILAWARDEN_DIR . 'includes/class-filawarden-engine.php';
require_once FILAWARDEN_DIR . 'includes/class-filawarden-plugin.php';

register_activation_hook(__FILE__, ['FilaWardenPlugin', 'activate']);
register_deactivation_hook(__FILE__, ['FilaWardenPlugin', 'deactivate']);
add_action('plugins_loaded', static function () {
    FilaWardenPlugin::instance()->boot();
});
