<?php
/**
 * Plugin Name: CHIPS Plugin Manager
 * Description: Installs and updates CHIPS utility plugins directly from their public GitHub repositories.
 * Version: 0.1.0
 * Author: CHIPS
 * License: GPL-2.0-or-later
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'CHIPS_PM_VERSION', '0.1.0' );
define( 'CHIPS_PM_FILE', __FILE__ );
define( 'CHIPS_PM_DIR', plugin_dir_path( __FILE__ ) );
define( 'CHIPS_PM_SLUG', 'chips-plugin-manager' );

/**
 * Where the canonical plugins.json lives. A site can point somewhere else with
 * the `chips_pm_manifest_url` filter, or skip the network entirely by filtering
 * `chips_pm_manifest` to return its own array.
 */
define( 'CHIPS_PM_MANIFEST_URL', 'https://raw.githubusercontent.com/danielshields/chips-plugin-manager/main/plugins.json' );

require_once CHIPS_PM_DIR . 'includes/class-chips-pm-manifest.php';
require_once CHIPS_PM_DIR . 'includes/class-chips-pm-source.php';
require_once CHIPS_PM_DIR . 'includes/class-chips-pm-updater.php';
require_once CHIPS_PM_DIR . 'includes/class-chips-pm-installer.php';
require_once CHIPS_PM_DIR . 'includes/class-chips-pm-admin.php';

CHIPS_PM_Updater::init();

if ( is_admin() ) {
	CHIPS_PM_Admin::init();
	CHIPS_PM_Installer::init();
}

/**
 * Clear every cached manifest and version lookup.
 */
function chips_pm_flush_caches() {
	CHIPS_PM_Manifest::flush();
	CHIPS_PM_Source::flush_all();
	delete_site_transient( 'update_plugins' );
}

register_deactivation_hook( __FILE__, 'chips_pm_flush_caches' );
