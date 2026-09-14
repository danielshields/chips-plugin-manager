<?php
/**
 * Wires CHIPS plugins into the normal WordPress update machinery, so they
 * update from Plugins > Updates like anything from wordpress.org.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CHIPS_PM_Updater {

	/**
	 * Slug being installed, for the rename filter during a fresh install where
	 * WordPress has no plugin basename to hand us yet.
	 *
	 * @var string
	 */
	private static $installing_slug = '';

	public static function init() {
		// Injected last: other plugins' updaters (ACF and WP Migrate DB Pro both
		// do this) rebuild the transient at the default priority and silently drop
		// entries added before them, which makes updates appear to work and then
		// never show up.
		add_filter( 'site_transient_update_plugins', array( __CLASS__, 'inject_updates' ), PHP_INT_MAX );
		add_filter( 'plugins_api', array( __CLASS__, 'plugin_information' ), 10, 3 );
		add_filter( 'upgrader_source_selection', array( __CLASS__, 'rename_source' ), 10, 4 );
	}

	/**
	 * Tell the rename filter which slug an in-progress install belongs to.
	 *
	 * @param string $slug Plugin slug, or '' to clear.
	 */
	public static function set_installing_slug( $slug ) {
		self::$installing_slug = (string) $slug;
	}

	/**
	 * Add our plugins to WordPress's update payload.
	 *
	 * @param mixed $transient Update transient.
	 * @return mixed
	 */
	public static function inject_updates( $transient ) {
		if ( ! is_object( $transient ) ) {
			return $transient;
		}

		$installed = self::installed_plugins();

		// Only reach out to GitHub in contexts that are actually checking for
		// updates. Front-end requests still get whatever is already cached.
		$allow_remote = is_admin() || wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI );

		foreach ( CHIPS_PM_Manifest::all() as $entry ) {
			$basename = $entry['basename'];

			if ( ! isset( $installed[ $basename ] ) ) {
				continue;
			}

			$local  = isset( $installed[ $basename ]['Version'] ) ? $installed[ $basename ]['Version'] : '0.0.0';
			$remote = CHIPS_PM_Source::get_version( $entry, $allow_remote );

			if ( null === $remote ) {
				continue;
			}

			$payload = (object) array(
				'id'            => 'github.com/' . $entry['repo'],
				'slug'          => $entry['slug'],
				'plugin'        => $basename,
				'new_version'   => $remote,
				'url'           => CHIPS_PM_Source::repo_url( $entry ),
				'package'       => CHIPS_PM_Source::package_url( $entry ),
				'icons'         => CHIPS_PM_Source::icons( $entry ),
				'banners'       => array(),
				'banners_rtl'   => array(),
				'tested'        => '',
				'requires_php'  => '',
				'compatibility' => new stdClass(),
			);

			if ( version_compare( $remote, $local, '>' ) ) {
				if ( ! isset( $transient->response ) || ! is_array( $transient->response ) ) {
					$transient->response = array();
				}
				$transient->response[ $basename ] = $payload;
				unset( $transient->no_update[ $basename ] );
			} else {
				// Listing up-to-date plugins here keeps them out of the "unknown
				// source" bucket and lets auto-update toggles work.
				if ( ! isset( $transient->no_update ) || ! is_array( $transient->no_update ) ) {
					$transient->no_update = array();
				}
				$transient->no_update[ $basename ] = $payload;
			}
		}

		return $transient;
	}

	/**
	 * Supply details for the "View version details" modal.
	 *
	 * @param false|object|array $result Response.
	 * @param string             $action API action.
	 * @param object             $args   Request args.
	 * @return false|object|array
	 */
	public static function plugin_information( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || empty( $args->slug ) ) {
			return $result;
		}

		$entry = CHIPS_PM_Manifest::get( $args->slug );

		if ( ! $entry ) {
			return $result;
		}

		$version = CHIPS_PM_Source::get_version( $entry );

		return (object) array(
			'name'          => $entry['name'],
			'slug'          => $entry['slug'],
			'version'       => null === $version ? '' : $version,
			'author'        => $entry['author'],
			'homepage'      => CHIPS_PM_Source::repo_url( $entry ),
			'download_link' => CHIPS_PM_Source::package_url( $entry ),
			'requires'      => '',
			'tested'        => '',
			'icons'         => CHIPS_PM_Source::icons( $entry ),
			'sections'      => array(
				'description' => wpautop(
					esc_html( $entry['description'] ) . "\n\n" . sprintf(
						/* translators: %s: repository URL */
						esc_html__( 'Installed and updated from %s.', 'chips-pm' ),
						'<a href="' . esc_url( CHIPS_PM_Source::repo_url( $entry ) ) . '">' . esc_html( $entry['repo'] ) . '</a>'
					)
				),
			),
		);
	}

	/**
	 * Rename the unpacked download to the plugin's real folder name.
	 *
	 * GitHub archives extract to "{repo}-{branch}", e.g. "chips-wp_rte-input-main".
	 * Without this, WordPress installs that folder verbatim — creating a second
	 * copy of the plugin rather than updating the one already on the site.
	 *
	 * @param string      $source        Unpacked folder.
	 * @param string      $remote_source Parent temp folder.
	 * @param WP_Upgrader $upgrader      Upgrader instance.
	 * @param array       $hook_extra    Context.
	 * @return string|WP_Error
	 */
	public static function rename_source( $source, $remote_source, $upgrader, $hook_extra = array() ) {
		global $wp_filesystem;

		$slug = '';

		if ( ! empty( $hook_extra['plugin'] ) ) {
			// An update: resolve the slug from the plugin being upgraded.
			$entry = CHIPS_PM_Manifest::get_by_basename( $hook_extra['plugin'] );
			if ( $entry ) {
				$slug = $entry['slug'];
			}
		} elseif ( self::$installing_slug ) {
			// A fresh install started by our own installer.
			$slug = self::$installing_slug;
		}

		if ( ! $slug || ! $wp_filesystem ) {
			return $source;
		}

		$desired = trailingslashit( $remote_source ) . $slug;

		if ( untrailingslashit( $source ) === untrailingslashit( $desired ) ) {
			return $source;
		}

		// Only touch a source that actually looks like this repo's archive.
		$current = basename( untrailingslashit( $source ) );
		$entry   = CHIPS_PM_Manifest::get( $slug );
		$repo    = $entry ? substr( $entry['repo'], strpos( $entry['repo'], '/' ) + 1 ) : '';

		if ( $repo && 0 !== strpos( $current, $repo ) ) {
			return $source;
		}

		if ( $wp_filesystem->exists( $desired ) ) {
			$wp_filesystem->delete( $desired, true );
		}

		if ( ! $wp_filesystem->move( untrailingslashit( $source ), $desired ) ) {
			return new WP_Error(
				'chips_pm_rename_failed',
				sprintf(
					/* translators: 1: source folder, 2: target folder */
					__( 'Could not rename the downloaded folder %1$s to %2$s.', 'chips-pm' ),
					$current,
					$slug
				)
			);
		}

		return trailingslashit( $desired );
	}

	/**
	 * Installed plugin data keyed by basename.
	 *
	 * @return array
	 */
	public static function installed_plugins() {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		return get_plugins();
	}
}
