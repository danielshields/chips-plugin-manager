<?php
/**
 * Talks to GitHub.
 *
 * Version checks read the plugin header straight off raw.githubusercontent.com
 * rather than the REST API: raw file reads aren't subject to the API's 60
 * requests/hour unauthenticated limit, which matters when every client site is
 * polling for several plugins.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CHIPS_PM_Source {

	const TTL_SUCCESS  = 6 * HOUR_IN_SECONDS;
	const TTL_FAILURE  = 30 * MINUTE_IN_SECONDS;
	const INDEX_OPTION = 'chips_pm_version_keys';

	/**
	 * Latest version on the tracked branch, or null if it can't be determined.
	 *
	 * @param array $entry  Manifest entry.
	 * @param bool  $remote Allow a network request on cache miss.
	 * @return string|null
	 */
	public static function get_version( $entry, $remote = true ) {
		$key    = self::cache_key( $entry );
		$cached = get_site_transient( $key );

		if ( is_array( $cached ) && array_key_exists( 'version', $cached ) ) {
			return $cached['version'];
		}

		if ( ! $remote ) {
			return null;
		}

		$version = self::fetch_version( $entry );

		self::remember_key( $key );
		set_site_transient(
			$key,
			array( 'version' => $version ),
			null === $version ? self::TTL_FAILURE : self::TTL_SUCCESS
		);

		return $version;
	}

	/**
	 * Read the Version header from the main plugin file on the branch.
	 *
	 * @param array $entry Manifest entry.
	 * @return string|null
	 */
	private static function fetch_version( $entry ) {
		$response = wp_remote_get(
			self::raw_url( $entry, $entry['main_file'] ),
			array(
				'timeout' => 10,
				'headers' => array(
					'Accept' => 'text/plain',
					// The header block is at the top of the file; no need to pull
					// down a large plugin to read six lines. Harmless if GitHub
					// ignores it and sends the whole file.
					'Range'  => 'bytes=0-8191',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return null;
		}

		$code = wp_remote_retrieve_response_code( $response );

		// 206 Partial Content when the Range was honoured, 200 when it wasn't.
		if ( 200 !== $code && 206 !== $code ) {
			return null;
		}

		return self::parse_version( wp_remote_retrieve_body( $response ) );
	}

	/**
	 * Pull "Version:" out of a plugin header block, the way WordPress does.
	 *
	 * @param string $contents Start of a PHP plugin file.
	 * @return string|null
	 */
	public static function parse_version( $contents ) {
		if ( ! is_string( $contents ) || '' === $contents ) {
			return null;
		}

		$contents = str_replace( "\r", "\n", $contents );

		if ( ! preg_match( '/^[ \t\/*#@]*Version:(.*)$/mi', $contents, $match ) ) {
			return null;
		}

		$version = trim( preg_replace( '/\s*(?:\*\/|\?>).*/', '', $match[1] ) );

		return '' === $version ? null : $version;
	}

	/**
	 * URL of a file on the tracked branch.
	 *
	 * @param array  $entry Manifest entry.
	 * @param string $path  Path within the repo.
	 * @return string
	 */
	public static function raw_url( $entry, $path ) {
		return sprintf(
			'https://raw.githubusercontent.com/%s/%s/%s',
			$entry['repo'],
			rawurlencode( $entry['branch'] ),
			implode( '/', array_map( 'rawurlencode', explode( '/', $path ) ) )
		);
	}

	/**
	 * Download URL for the branch.
	 *
	 * Uses /archive/refs/heads/ rather than the API zipball endpoint because it
	 * extracts to a predictable "{repo}-{branch}" folder instead of one suffixed
	 * with a commit sha.
	 *
	 * @param array $entry Manifest entry.
	 * @return string
	 */
	public static function package_url( $entry ) {
		return sprintf(
			'https://github.com/%s/archive/refs/heads/%s.zip',
			$entry['repo'],
			rawurlencode( $entry['branch'] )
		);
	}

	/**
	 * Human-facing repo URL.
	 *
	 * @param array $entry Manifest entry.
	 * @return string
	 */
	public static function repo_url( $entry ) {
		return 'https://github.com/' . $entry['repo'];
	}

	/**
	 * Icon URLs for the updates screen, if the manifest declared one.
	 *
	 * @param array $entry Manifest entry.
	 * @return array
	 */
	public static function icons( $entry ) {
		if ( empty( $entry['icon'] ) ) {
			return array();
		}

		$url = self::raw_url( $entry, $entry['icon'] );

		return array(
			'1x'      => $url,
			'2x'      => $url,
			'default' => $url,
		);
	}

	/**
	 * @param array $entry Manifest entry.
	 * @return string
	 */
	private static function cache_key( $entry ) {
		return 'chips_pm_v_' . md5( $entry['repo'] . '|' . $entry['branch'] . '|' . $entry['main_file'] );
	}

	/**
	 * Track which version transients exist so they can all be cleared later.
	 *
	 * @param string $key Transient key.
	 */
	private static function remember_key( $key ) {
		$keys = get_option( self::INDEX_OPTION, array() );

		if ( ! is_array( $keys ) ) {
			$keys = array();
		}

		if ( ! in_array( $key, $keys, true ) ) {
			$keys[] = $key;
			update_option( self::INDEX_OPTION, $keys, false );
		}
	}

	/**
	 * Drop every cached version lookup.
	 */
	public static function flush_all() {
		$keys = get_option( self::INDEX_OPTION, array() );

		if ( is_array( $keys ) ) {
			foreach ( $keys as $key ) {
				delete_site_transient( $key );
			}
		}

		delete_option( self::INDEX_OPTION );
	}
}
