<?php
/**
 * Handles the install / update / activate actions from the admin screen.
 *
 * Deletion is deliberately not offered here — the normal Plugins screen already
 * does that, with WordPress's own confirmation step.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CHIPS_PM_Installer {

	const ACTION = 'chips_pm_action';
	const NONCE  = 'chips_pm_action';

	public static function init() {
		add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'handle' ) );
	}

	/**
	 * Dispatch a posted action.
	 */
	public static function handle() {
		$slug = isset( $_REQUEST['slug'] ) ? sanitize_key( wp_unslash( $_REQUEST['slug'] ) ) : '';
		$task = isset( $_REQUEST['task'] ) ? sanitize_key( wp_unslash( $_REQUEST['task'] ) ) : '';

		check_admin_referer( self::NONCE . '_' . $task . '_' . $slug );

		$result = self::run( $task, $slug );

		$redirect = add_query_arg(
			array(
				'page'        => CHIPS_PM_SLUG,
				'chips_pm_ok' => is_wp_error( $result ) ? '0' : '1',
				'chips_pm_msg' => rawurlencode( is_wp_error( $result ) ? $result->get_error_message() : $result ),
			),
			admin_url( 'plugins.php' )
		);

		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * @param string $task Task name.
	 * @param string $slug Plugin slug.
	 * @return string|WP_Error Success message, or error.
	 */
	private static function run( $task, $slug ) {
		if ( 'refresh' === $task ) {
			if ( ! current_user_can( 'update_plugins' ) ) {
				return new WP_Error( 'chips_pm_cap', __( 'You are not allowed to check for updates.', 'chips-pm' ) );
			}

			chips_pm_flush_caches();

			return __( 'Refreshed from GitHub.', 'chips-pm' );
		}

		$entry = CHIPS_PM_Manifest::get( $slug );

		if ( ! $entry ) {
			return new WP_Error( 'chips_pm_unknown', __( 'That plugin is not in the manifest.', 'chips-pm' ) );
		}

		switch ( $task ) {
			case 'install':
				return self::install( $entry );
			case 'update':
				return self::update( $entry );
			case 'activate':
				return self::activate( $entry );
			case 'deactivate':
				return self::deactivate( $entry );
		}

		return new WP_Error( 'chips_pm_task', __( 'Unknown action.', 'chips-pm' ) );
	}

	/**
	 * Download and install a plugin that isn't on the site yet.
	 *
	 * @param array $entry Manifest entry.
	 * @return string|WP_Error
	 */
	private static function install( $entry ) {
		if ( ! current_user_can( 'install_plugins' ) ) {
			return new WP_Error( 'chips_pm_cap', __( 'You are not allowed to install plugins.', 'chips-pm' ) );
		}

		$upgrader = self::upgrader();

		// The rename filter has no plugin basename to work from during a fresh
		// install, so hand it the slug directly.
		CHIPS_PM_Updater::set_installing_slug( $entry['slug'] );
		$result = $upgrader->install( CHIPS_PM_Source::package_url( $entry ) );
		CHIPS_PM_Updater::set_installing_slug( '' );

		chips_pm_flush_caches();

		$error = self::error_from( $upgrader, $result, __( 'Install failed.', 'chips-pm' ) );

		if ( $error ) {
			return $error;
		}

		return sprintf(
			/* translators: %s: plugin name */
			__( 'Installed %s.', 'chips-pm' ),
			$entry['name']
		);
	}

	/**
	 * Update an installed plugin to the latest commit on its branch.
	 *
	 * @param array $entry Manifest entry.
	 * @return string|WP_Error
	 */
	private static function update( $entry ) {
		if ( ! current_user_can( 'update_plugins' ) ) {
			return new WP_Error( 'chips_pm_cap', __( 'You are not allowed to update plugins.', 'chips-pm' ) );
		}

		if ( ! self::is_installed( $entry ) ) {
			return new WP_Error( 'chips_pm_missing', __( 'That plugin is not installed.', 'chips-pm' ) );
		}

		// Clear caches first so the upgrader sees the current remote version,
		// then let our transient filter supply the package URL.
		chips_pm_flush_caches();

		$upgrader = self::upgrader();

		// bulk_upgrade(), not upgrade(): the single-plugin path deactivates the
		// plugin before upgrading and never turns it back on, so a routine update
		// would silently switch the plugin off. This is the path the Plugins
		// screen itself uses, and it preserves activation state.
		$results = $upgrader->bulk_upgrade( array( $entry['basename'] ) );

		$result = is_array( $results ) && array_key_exists( $entry['basename'], $results )
			? $results[ $entry['basename'] ]
			: false;

		chips_pm_flush_caches();

		$error = self::error_from( $upgrader, $result, __( 'Update failed.', 'chips-pm' ) );

		if ( $error ) {
			return $error;
		}

		return sprintf(
			/* translators: %s: plugin name */
			__( 'Updated %s.', 'chips-pm' ),
			$entry['name']
		);
	}

	/**
	 * @param array $entry Manifest entry.
	 * @return string|WP_Error
	 */
	private static function activate( $entry ) {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return new WP_Error( 'chips_pm_cap', __( 'You are not allowed to activate plugins.', 'chips-pm' ) );
		}

		$result = activate_plugin( $entry['basename'] );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return sprintf(
			/* translators: %s: plugin name */
			__( 'Activated %s.', 'chips-pm' ),
			$entry['name']
		);
	}

	/**
	 * @param array $entry Manifest entry.
	 * @return string|WP_Error
	 */
	private static function deactivate( $entry ) {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return new WP_Error( 'chips_pm_cap', __( 'You are not allowed to deactivate plugins.', 'chips-pm' ) );
		}

		deactivate_plugins( array( $entry['basename'] ) );

		return sprintf(
			/* translators: %s: plugin name */
			__( 'Deactivated %s.', 'chips-pm' ),
			$entry['name']
		);
	}

	/**
	 * A quiet upgrader that collects messages instead of printing them.
	 *
	 * @return Plugin_Upgrader
	 */
	private static function upgrader() {
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/misc.php';

		return new Plugin_Upgrader( new WP_Ajax_Upgrader_Skin() );
	}

	/**
	 * Normalize the several ways an upgrader reports failure.
	 *
	 * @param Plugin_Upgrader $upgrader Upgrader.
	 * @param mixed           $result   Return value from install()/upgrade().
	 * @param string          $fallback Generic message.
	 * @return WP_Error|null
	 */
	private static function error_from( $upgrader, $result, $fallback ) {
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( is_wp_error( $upgrader->skin->result ) ) {
			return $upgrader->skin->result;
		}

		if ( method_exists( $upgrader->skin, 'get_errors' ) ) {
			$errors = $upgrader->skin->get_errors();
			if ( is_wp_error( $errors ) && $errors->has_errors() ) {
				return $errors;
			}
		}

		if ( false === $result || null === $result ) {
			return new WP_Error( 'chips_pm_failed', $fallback );
		}

		return null;
	}

	/**
	 * @param array $entry Manifest entry.
	 * @return bool
	 */
	public static function is_installed( $entry ) {
		$installed = CHIPS_PM_Updater::installed_plugins();

		return isset( $installed[ $entry['basename'] ] );
	}
}
