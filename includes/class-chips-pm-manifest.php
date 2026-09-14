<?php
/**
 * Loads the list of available CHIPS plugins.
 *
 * Source of truth is plugins.json in the manager's own GitHub repo, so adding a
 * plugin is a one-file commit rather than a release to every client site. The
 * copy bundled with this plugin is the fallback when GitHub is unreachable.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CHIPS_PM_Manifest {

	const TRANSIENT   = 'chips_pm_manifest';
	const TTL_SUCCESS = 6 * HOUR_IN_SECONDS;
	const TTL_FAILURE = 15 * MINUTE_IN_SECONDS;

	/** @var array|null Per-request memo. */
	private static $memo = null;

	/**
	 * All known plugins, keyed by slug.
	 *
	 * @return array<string,array>
	 */
	public static function all() {
		if ( null !== self::$memo ) {
			return self::$memo;
		}

		$entries = self::fetch();

		$normalized = array();
		foreach ( $entries as $entry ) {
			$entry = self::normalize( $entry );
			if ( $entry ) {
				$normalized[ $entry['slug'] ] = $entry;
			}
		}

		/**
		 * Filter the available plugin list — for adding a site-specific repo, or
		 * hiding one that shouldn't be offered here.
		 *
		 * @param array<string,array> $normalized Entries keyed by slug.
		 */
		$normalized = apply_filters( 'chips_pm_manifest', $normalized );

		self::$memo = is_array( $normalized ) ? $normalized : array();

		return self::$memo;
	}

	/**
	 * A single entry by slug, or null.
	 *
	 * @param string $slug Plugin slug.
	 * @return array|null
	 */
	public static function get( $slug ) {
		$all = self::all();

		return isset( $all[ $slug ] ) ? $all[ $slug ] : null;
	}

	/**
	 * Find the entry that owns a plugin basename such as "my-plugin/my-plugin.php".
	 *
	 * @param string $basename Plugin basename.
	 * @return array|null
	 */
	public static function get_by_basename( $basename ) {
		foreach ( self::all() as $entry ) {
			if ( $entry['basename'] === $basename ) {
				return $entry;
			}
		}

		return null;
	}

	/**
	 * Raw entry list, from cache, GitHub, or the bundled file.
	 *
	 * @return array
	 */
	private static function fetch() {
		$cached = get_site_transient( self::TRANSIENT );

		if ( is_array( $cached ) ) {
			return $cached;
		}

		$url = apply_filters( 'chips_pm_manifest_url', CHIPS_PM_MANIFEST_URL );

		$response = wp_remote_get(
			$url,
			array(
				'timeout' => 10,
				'headers' => array( 'Accept' => 'application/json' ),
			)
		);

		$entries = null;

		if ( ! is_wp_error( $response ) && 200 === wp_remote_retrieve_response_code( $response ) ) {
			$entries = self::parse( wp_remote_retrieve_body( $response ) );
		}

		if ( null === $entries ) {
			// Remote fetch failed. Fall back to the bundled copy, and retry the
			// network again soon rather than sitting on a stale failure all day.
			$entries = self::bundled();
			set_site_transient( self::TRANSIENT, $entries, self::TTL_FAILURE );

			return $entries;
		}

		set_site_transient( self::TRANSIENT, $entries, self::TTL_SUCCESS );

		return $entries;
	}

	/**
	 * The plugins.json shipped inside this plugin.
	 *
	 * @return array
	 */
	private static function bundled() {
		$path = CHIPS_PM_DIR . 'plugins.json';

		if ( ! is_readable( $path ) ) {
			return array();
		}

		$entries = self::parse( file_get_contents( $path ) );

		return null === $entries ? array() : $entries;
	}

	/**
	 * Decode a manifest document into a flat entry list.
	 *
	 * @param string $json Raw JSON.
	 * @return array|null Null when the document is unusable.
	 */
	private static function parse( $json ) {
		$data = json_decode( $json, true );

		if ( ! is_array( $data ) ) {
			return null;
		}

		// Accept either a bare array of entries or { "plugins": [ ... ] }.
		if ( isset( $data['plugins'] ) && is_array( $data['plugins'] ) ) {
			return $data['plugins'];
		}

		return $data;
	}

	/**
	 * Fill in defaults and reject entries missing anything essential.
	 *
	 * @param mixed $entry Raw manifest entry.
	 * @return array|null
	 */
	private static function normalize( $entry ) {
		if ( ! is_array( $entry ) || empty( $entry['repo'] ) ) {
			return null;
		}

		$repo = trim( $entry['repo'], '/ ' );

		if ( ! preg_match( '#^[A-Za-z0-9._-]+/[A-Za-z0-9._-]+$#', $repo ) ) {
			return null;
		}

		$repo_name = substr( $repo, strpos( $repo, '/' ) + 1 );

		// The install folder. Defaults to the repo name, but is declared
		// explicitly for repos whose folder already differs on client sites.
		$slug = ! empty( $entry['slug'] ) ? $entry['slug'] : $repo_name;
		$slug = sanitize_key( str_replace( array( '/', '\\' ), '', $slug ) );

		if ( '' === $slug ) {
			return null;
		}

		// The main plugin file *inside* that folder. Often not the repo name —
		// getting this wrong is what makes an updater install a second copy
		// instead of updating the plugin that's already there.
		$main_file = ! empty( $entry['main_file'] ) ? $entry['main_file'] : $repo_name . '.php';
		$main_file = ltrim( str_replace( '\\', '/', $main_file ), '/' );

		if ( false !== strpos( $main_file, '..' ) || '.php' !== substr( $main_file, -4 ) ) {
			return null;
		}

		$branch = ! empty( $entry['branch'] ) ? $entry['branch'] : 'main';

		return array(
			'slug'             => $slug,
			'repo'             => $repo,
			'branch'           => $branch,
			'main_file'        => $main_file,
			'basename'         => $slug . '/' . $main_file,
			'name'             => ! empty( $entry['name'] ) ? $entry['name'] : $repo_name,
			'description'      => ! empty( $entry['description'] ) ? $entry['description'] : '',
			'author'           => ! empty( $entry['author'] ) ? $entry['author'] : 'CHIPS',
			'icon'             => ! empty( $entry['icon'] ) ? ltrim( $entry['icon'], '/' ) : '',
			'requires_plugins' => ! empty( $entry['requires_plugins'] ) && is_array( $entry['requires_plugins'] )
				? $entry['requires_plugins']
				: array(),
		);
	}

	/**
	 * Drop the cached manifest.
	 */
	public static function flush() {
		self::$memo = null;
		delete_site_transient( self::TRANSIENT );
	}
}
