<?php
/**
 * Plugin activation, deactivation, installs, updates and deletion.
 *
 * @package AryanX\ActivityLogWithUndo
 */

namespace AryanX\ActivityLogWithUndo\Recorders;

use AryanX\ActivityLogWithUndo\Entry;

defined( 'ABSPATH' ) || exit;

/**
 * Activation and deactivation can be reversed. Installs, updates and
 * deletions are logged only: WordPress keeps no copy of the old files.
 */
final class PluginsRecorder extends BaseRecorder {

	/**
	 * Recorder ID.
	 *
	 * @return string
	 */
	public function id(): string {
		return 'plugins';
	}

	/**
	 * Name on the settings screen.
	 *
	 * @return string
	 */
	public function label(): string {
		return __( 'Plugins', 'activity-log-with-undo' );
	}

	/**
	 * Events.
	 *
	 * @return array<string, string>
	 */
	public function events(): array {
		return array(
			'plugin_activated'   => __( 'Plugin activated', 'activity-log-with-undo' ),
			'plugin_deactivated' => __( 'Plugin deactivated', 'activity-log-with-undo' ),
			'plugin_installed'   => __( 'Plugin installed', 'activity-log-with-undo' ),
			'plugin_updated'     => __( 'Plugin updated', 'activity-log-with-undo' ),
			'plugin_deleted'     => __( 'Plugin deleted', 'activity-log-with-undo' ),
		);
	}

	/**
	 * Hooks into plugin changes.
	 */
	public function register(): void {
		add_action( 'activated_plugin', array( $this, 'activated' ), 10, 2 );
		add_action( 'deactivated_plugin', array( $this, 'deactivated' ), 10, 2 );
		add_action( 'upgrader_process_complete', array( $this, 'upgraded' ), 10, 2 );
		add_action( 'deleted_plugin', array( $this, 'deleted' ), 10, 2 );
	}

	/**
	 * A plugin was activated.
	 *
	 * @param string $plugin       Plugin file, e.g. hello.php.
	 * @param bool   $network_wide Whether it was network activated.
	 */
	public function activated( $plugin, $network_wide = false ): void {
		$network = (bool) $network_wide;
		$this->record(
			'plugin_activated',
			'plugin',
			$plugin,
			self::name( (string) $plugin ),
			array(
				'active'  => false,
				'network' => $network,
			),
			array(
				'active'  => true,
				'network' => $network,
			),
			true,
			$network
		);
	}

	/**
	 * A plugin was deactivated.
	 *
	 * @param string $plugin       Plugin file.
	 * @param bool   $network_wide Whether it was network deactivated.
	 */
	public function deactivated( $plugin, $network_wide = false ): void {
		$network = (bool) $network_wide;
		$this->record(
			'plugin_deactivated',
			'plugin',
			$plugin,
			self::name( (string) $plugin ),
			array(
				'active'  => true,
				'network' => $network,
			),
			array(
				'active'  => false,
				'network' => $network,
			),
			true,
			$network
		);
	}

	/**
	 * Plugins were installed or updated.
	 *
	 * @param mixed $upgrader Upgrader.
	 * @param array $extra    type, action, plugins.
	 */
	public function upgraded( $upgrader, $extra ): void {
		$extra = (array) $extra;
		if ( 'plugin' !== ( $extra['type'] ?? '' ) ) {
			return;
		}

		if ( 'install' === ( $extra['action'] ?? '' ) ) {
			$file = is_object( $upgrader ) && method_exists( $upgrader, 'plugin_info' ) ? (string) $upgrader->plugin_info() : '';
			$this->record( 'plugin_installed', 'plugin', $file, '' !== $file ? self::name( $file ) : __( 'A plugin', 'activity-log-with-undo' ), null, array( 'version' => self::version( $file ) ), false );
			return;
		}

		$plugins = isset( $extra['plugins'] ) ? (array) $extra['plugins'] : ( isset( $extra['plugin'] ) ? array( $extra['plugin'] ) : array() );
		foreach ( $plugins as $plugin ) {
			$this->record( 'plugin_updated', 'plugin', $plugin, self::name( (string) $plugin ), null, array( 'version' => self::version( (string) $plugin ) ), false );
		}
	}

	/**
	 * A plugin was deleted.
	 *
	 * @param string $plugin  Plugin file.
	 * @param bool   $deleted Whether the files were removed.
	 */
	public function deleted( $plugin, $deleted ): void {
		if ( $deleted ) {
			$this->record( 'plugin_deleted', 'plugin', $plugin, (string) $plugin, null, null, false );
		}
	}

	/**
	 * Whether the plugin can be switched back.
	 *
	 * @param Entry $entry Entry.
	 * @return true|\WP_Error
	 */
	public function can_undo( Entry $entry ) {
		switch ( $entry->event ) {
			case 'plugin_installed':
				return self::refuse( __( 'Installs are logged only. Delete the plugin from the Plugins screen if you do not want it.', 'activity-log-with-undo' ) );
			case 'plugin_updated':
				return self::refuse( __( 'WordPress keeps no copy of the old version, so an update cannot be reversed here.', 'activity-log-with-undo' ) );
			case 'plugin_deleted':
				return self::refuse( __( 'The plugin files are gone. Install the plugin again to get it back.', 'activity-log-with-undo' ) );
		}

		self::load();
		if ( 'plugin_deactivated' === $entry->event && is_wp_error( validate_plugin( $entry->object_id ) ) ) {
			return self::refuse( __( 'The plugin is no longer installed.', 'activity-log-with-undo' ) );
		}

		return parent::can_undo( $entry );
	}

	/**
	 * Whether the plugin is active now.
	 *
	 * @param Entry $entry Entry.
	 * @return array
	 */
	public function current_state( Entry $entry ): array {
		self::load();
		$network = ! empty( ( (array) $entry->after() )['network'] );

		return array(
			'active'  => $network ? is_plugin_active_for_network( $entry->object_id ) : is_plugin_active( $entry->object_id ),
			'network' => $network,
		);
	}

	/**
	 * Activates or deactivates the plugin again.
	 *
	 * @param Entry $entry Entry.
	 * @return true|\WP_Error
	 */
	public function undo( Entry $entry ) {
		self::load();
		$network = ! empty( ( (array) $entry->after() )['network'] );

		if ( 'plugin_activated' === $entry->event ) {
			deactivate_plugins( $entry->object_id, false, $network );
			return true;
		}

		$result = activate_plugin( $entry->object_id, '', $network );

		return is_wp_error( $result ) ? $result : true;
	}

	/**
	 * One line about the change.
	 *
	 * @param Entry $entry Entry.
	 * @return string
	 */
	public function describe( Entry $entry ): string {
		$name     = '' !== $entry->object_label ? $entry->object_label : $entry->object_id;
		$messages = array(
			/* translators: %s: plugin name. */
			'plugin_activated'   => __( 'Activated the %s plugin', 'activity-log-with-undo' ),
			/* translators: %s: plugin name. */
			'plugin_deactivated' => __( 'Deactivated the %s plugin', 'activity-log-with-undo' ),
			/* translators: %s: plugin name. */
			'plugin_installed'   => __( 'Installed the %s plugin', 'activity-log-with-undo' ),
			/* translators: %s: plugin name. */
			'plugin_updated'     => __( 'Updated the %s plugin', 'activity-log-with-undo' ),
			/* translators: %s: plugin file. */
			'plugin_deleted'     => __( 'Deleted the %s plugin', 'activity-log-with-undo' ),
		);

		return sprintf( $messages[ $entry->event ] ?? '%s', $name );
	}

	/**
	 * Loads the plugin admin functions outside wp-admin.
	 */
	private static function load(): void {
		if ( ! function_exists( 'activate_plugin' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
	}

	/**
	 * A plugin's name from its header, or its file.
	 *
	 * @param string $plugin Plugin file.
	 * @return string
	 */
	public static function name( string $plugin ): string {
		self::load();
		$file = WP_PLUGIN_DIR . '/' . $plugin;
		if ( '' === $plugin || validate_file( $plugin ) || ! is_readable( $file ) ) {
			return $plugin;
		}
		$data = get_plugin_data( $file, false, false );

		return '' !== $data['Name'] ? $data['Name'] : $plugin;
	}

	/**
	 * A plugin's version from its header.
	 *
	 * @param string $plugin Plugin file.
	 * @return string
	 */
	private static function version( string $plugin ): string {
		self::load();
		$file = WP_PLUGIN_DIR . '/' . $plugin;
		if ( '' === $plugin || validate_file( $plugin ) || ! is_readable( $file ) ) {
			return '';
		}

		return (string) get_plugin_data( $file, false, false )['Version'];
	}
}
