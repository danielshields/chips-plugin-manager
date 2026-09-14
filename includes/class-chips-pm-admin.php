<?php
/**
 * The "CHIPS Plugins" screen under Plugins.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CHIPS_PM_Admin {

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
	}

	public static function menu() {
		add_submenu_page(
			'plugins.php',
			__( 'CHIPS Plugins', 'chips-pm' ),
			__( 'CHIPS Plugins', 'chips-pm' ),
			'install_plugins',
			CHIPS_PM_SLUG,
			array( __CLASS__, 'render' )
		);
	}

	/**
	 * Build a nonced action URL.
	 *
	 * @param string $task Task name.
	 * @param string $slug Plugin slug.
	 * @return string
	 */
	private static function action_url( $task, $slug = '' ) {
		$url = add_query_arg(
			array(
				'action' => CHIPS_PM_Installer::ACTION,
				'task'   => $task,
				'slug'   => $slug,
			),
			admin_url( 'admin-post.php' )
		);

		return wp_nonce_url( $url, CHIPS_PM_Installer::NONCE . '_' . $task . '_' . $slug );
	}

	public static function render() {
		if ( ! current_user_can( 'install_plugins' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'chips-pm' ) );
		}

		$entries   = CHIPS_PM_Manifest::all();
		$installed = CHIPS_PM_Updater::installed_plugins();
		$active    = (array) get_option( 'active_plugins', array() );

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'CHIPS Plugins', 'chips-pm' ); ?></h1>

			<?php self::notice(); ?>

			<p class="description">
				<?php esc_html_e( 'Installed and updated straight from their public GitHub repositories. Updates also appear on the normal Plugins and Updates screens.', 'chips-pm' ); ?>
			</p>

			<p>
				<a href="<?php echo esc_url( self::action_url( 'refresh' ) ); ?>" class="button">
					<?php esc_html_e( 'Check GitHub for updates', 'chips-pm' ); ?>
				</a>
			</p>

			<?php if ( empty( $entries ) ) : ?>
				<div class="notice notice-warning inline">
					<p><?php esc_html_e( 'No plugins found in the manifest.', 'chips-pm' ); ?></p>
				</div>
			<?php else : ?>
				<table class="wp-list-table widefat striped">
					<thead>
						<tr>
							<th scope="col" style="width:26%"><?php esc_html_e( 'Plugin', 'chips-pm' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Description', 'chips-pm' ); ?></th>
							<th scope="col" style="width:10%"><?php esc_html_e( 'Installed', 'chips-pm' ); ?></th>
							<th scope="col" style="width:10%"><?php esc_html_e( 'On GitHub', 'chips-pm' ); ?></th>
							<th scope="col" style="width:22%"><?php esc_html_e( 'Actions', 'chips-pm' ); ?></th>
						</tr>
					</thead>
					<tbody>
					<?php foreach ( $entries as $entry ) : ?>
						<?php
						$basename   = $entry['basename'];
						$is_present = isset( $installed[ $basename ] );
						$is_active  = in_array( $basename, $active, true );
						$local      = $is_present && ! empty( $installed[ $basename ]['Version'] )
							? $installed[ $basename ]['Version']
							: '';
						$remote     = CHIPS_PM_Source::get_version( $entry );
						$has_update = $is_present && $local && $remote && version_compare( $remote, $local, '>' );
						$missing    = self::missing_dependencies( $entry );
						$stray      = self::stray_installs( $entry, $installed );
						?>
						<tr>
							<td>
								<strong><?php echo esc_html( $entry['name'] ); ?></strong>
								<?php if ( CHIPS_PM_SLUG === $entry['slug'] ) : ?>
									<em>&nbsp;<?php esc_html_e( '(this plugin)', 'chips-pm' ); ?></em>
								<?php endif; ?>
								<div class="row-actions visible">
									<a href="<?php echo esc_url( CHIPS_PM_Source::repo_url( $entry ) ); ?>" target="_blank" rel="noopener">
										<?php echo esc_html( $entry['repo'] ); ?>
									</a>
									<span style="color:#787c82">&nbsp;&middot;&nbsp;<?php echo esc_html( $entry['branch'] ); ?></span>
								</div>
							</td>
							<td>
								<?php echo esc_html( $entry['description'] ); ?>
								<?php if ( $stray ) : ?>
									<p style="color:#b32d2e;margin:.4em 0 0">
										<?php
										printf(
											/* translators: %s: comma-separated folder names */
											esc_html__( 'Also installed as: %s — a stray copy from a previous updater. Delete it from the Plugins screen.', 'chips-pm' ),
											esc_html( implode( ', ', array_map( 'dirname', $stray ) ) )
										);
										?>
									</p>
								<?php endif; ?>
								<?php if ( $missing ) : ?>
									<p style="color:#996800;margin:.4em 0 0">
										<?php
										printf(
											/* translators: %s: comma-separated plugin names */
											esc_html__( 'Needs: %s', 'chips-pm' ),
											esc_html( implode( ', ', $missing ) )
										);
										?>
									</p>
								<?php endif; ?>
							</td>
							<td><?php echo $local ? esc_html( $local ) : '&mdash;'; ?></td>
							<td>
								<?php if ( null === $remote ) : ?>
									<span style="color:#b32d2e" title="<?php esc_attr_e( 'Could not reach GitHub, or the main file path in the manifest is wrong.', 'chips-pm' ); ?>">?</span>
								<?php else : ?>
									<?php echo esc_html( $remote ); ?>
								<?php endif; ?>
							</td>
							<td>
								<?php if ( ! $is_present ) : ?>
									<a href="<?php echo esc_url( self::action_url( 'install', $entry['slug'] ) ); ?>" class="button button-primary">
										<?php esc_html_e( 'Install', 'chips-pm' ); ?>
									</a>
								<?php else : ?>
									<?php if ( $has_update ) : ?>
										<a href="<?php echo esc_url( self::action_url( 'update', $entry['slug'] ) ); ?>" class="button button-primary">
											<?php
											printf(
												/* translators: %s: version number */
												esc_html__( 'Update to %s', 'chips-pm' ),
												esc_html( $remote )
											);
											?>
										</a>
									<?php endif; ?>

									<?php if ( CHIPS_PM_SLUG === $entry['slug'] ) : ?>
										<span style="color:#787c82"><?php esc_html_e( 'Active', 'chips-pm' ); ?></span>
									<?php elseif ( $is_active ) : ?>
										<a href="<?php echo esc_url( self::action_url( 'deactivate', $entry['slug'] ) ); ?>" class="button">
											<?php esc_html_e( 'Deactivate', 'chips-pm' ); ?>
										</a>
									<?php else : ?>
										<a href="<?php echo esc_url( self::action_url( 'activate', $entry['slug'] ) ); ?>" class="button">
											<?php esc_html_e( 'Activate', 'chips-pm' ); ?>
										</a>
									<?php endif; ?>

									<?php if ( ! $has_update && null !== $remote ) : ?>
										<span style="color:#787c82">&nbsp;<?php esc_html_e( 'Up to date', 'chips-pm' ); ?></span>
									<?php endif; ?>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Copies of a plugin installed under the wrong folder name.
	 *
	 * A GitHub archive unpacks to "{repo}-{branch}", so an updater that doesn't
	 * rename it leaves behind folders like "chips-duplicator-main" that WordPress
	 * treats as a separate plugin. Surfacing them makes leftovers from a previous
	 * updater obvious instead of mysterious.
	 *
	 * @param array $entry     Manifest entry.
	 * @param array $installed All installed plugins.
	 * @return array Basenames of misplaced copies.
	 */
	private static function stray_installs( $entry, $installed ) {
		$suffix = '/' . $entry['main_file'];
		$stray  = array();

		foreach ( array_keys( $installed ) as $basename ) {
			if ( $basename === $entry['basename'] ) {
				continue;
			}

			if ( substr( $basename, -strlen( $suffix ) ) === $suffix ) {
				$stray[] = $basename;
			}
		}

		return $stray;
	}

	/**
	 * Names of declared dependencies that aren't active.
	 *
	 * @param array $entry Manifest entry.
	 * @return array
	 */
	private static function missing_dependencies( $entry ) {
		if ( empty( $entry['requires_plugins'] ) ) {
			return array();
		}

		$missing = array();
		$active  = (array) get_option( 'active_plugins', array() );

		foreach ( $entry['requires_plugins'] as $dependency ) {
			// Accept either a folder name ("advanced-custom-fields") or a full
			// basename, since ACF ships under several folder names.
			$folder = sanitize_key( strtok( (string) $dependency, '/' ) );

			if ( '' === $folder ) {
				continue;
			}

			$found = false;

			foreach ( $active as $active_basename ) {
				if ( 0 === strpos( $active_basename, $folder . '/' ) ) {
					$found = true;
					break;
				}
			}

			if ( ! $found ) {
				$missing[] = $folder;
			}
		}

		return $missing;
	}

	/**
	 * Render the result of the last action.
	 */
	private static function notice() {
		if ( ! isset( $_GET['chips_pm_msg'] ) ) {
			return;
		}

		$ok      = isset( $_GET['chips_pm_ok'] ) && '1' === $_GET['chips_pm_ok'];
		$message = sanitize_text_field( wp_unslash( $_GET['chips_pm_msg'] ) );

		if ( '' === $message ) {
			return;
		}

		printf(
			'<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
			$ok ? 'success' : 'error',
			esc_html( $message )
		);
	}
}
