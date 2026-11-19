<?php
/**
 * Plugin activation, deactivation, installs, updates and deletion.
 *
 * @package AryanX\Axle\ActivityLogWithUndo
 */

namespace AryanX\Axle\ActivityLogWithUndo\Recorders;

use AryanX\Axle\ActivityLogWithUndo\Entry;
use AryanX\Axle\ActivityLogWithUndo\Integrations\Siblings;

defined( 'ABSPATH' ) || exit;

/**
 * Activation and deactivation can be reversed. So can an update, when Axle
 * Smoke Test saved the old version before it ran. Installs and deletions are
 * logged only: WordPress keeps no copy of the files.
 */
final class PluginsRecorder extends BaseRecorder {

	/**
	 * Versions before an update, keyed by plugin file.
	 *
	 * @var array<string, string>
	 */
	private array $versions = array();

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
		add_filter( 'upgrader_pre_install', array( $this, 'before_install' ), 5, 2 );
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
	 * Remembers the version a plugin has before an update replaces it.
	 *
	 * @param mixed $response   Result so far; returned untouched.
	 * @param mixed $hook_extra What the upgrader is working on.
	 * @return mixed
	 */
	public function before_install( $response, $hook_extra = array() ) {
		$plugin = is_array( $hook_extra ) && ! empty( $hook_extra['plugin'] ) ? (string) $hook_extra['plugin'] : '';
		if ( '' !== $plugin && ! isset( $this->versions[ $plugin ] ) ) {
			$this->versions[ $plugin ] = self::version( $plugin );
		}

		return $response;
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
			$plugin = (string) $plugin;
			$old    = $this->versions[ $plugin ] ?? '';
			unset( $this->versions[ $plugin ] );
			$new = self::version( $plugin );
			// Undoable only when the files of the old version were saved before the update.
			$this->record( 'plugin_updated', 'plugin', $plugin, self::name( $plugin ), '' !== $old ? array( 'version' => $old ) : null, array( 'version' => $new ), '' !== $old && $old !== $new && null !== Siblings::smoke_backup( 'plugin', $plugin, $old ) );
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
				return $this->can_undo_update( $entry );
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
		if ( 'plugin_updated' === $entry->event ) {
			return array( 'version' => self::version( $entry->object_id ) );
		}
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
		if ( 'plugin_updated' === $entry->event ) {
			return $this->undo_update( $entry );
		}
		$network = ! empty( ( (array) $entry->after() )['network'] );

		if ( 'plugin_activated' === $entry->event ) {
			deactivate_plugins( $entry->object_id, false, $network );
			return true;
		}

		$result = activate_plugin( $entry->object_id, '', $network );

		return is_wp_error( $result ) ? $result : true;
	}

	/**
	 * Whether an update can be reversed: the user may update plugins, and Smoke Test still has the zip.
	 *
	 * @param Entry $entry Entry.
	 * @return true|\WP_Error
	 */
	private function can_undo_update( Entry $entry ) {
		$old = (string) ( ( (array) $entry->before() )['version'] ?? '' );
		if ( '' === $old ) {
			return self::refuse( __( 'The version before this update was not recorded, so it cannot be reversed here.', 'activity-log-with-undo' ) );
		}
		if ( ! current_user_can( 'update_plugins' ) && ! ( defined( 'WP_CLI' ) && WP_CLI && ! is_user_logged_in() ) ) {
			return self::refuse( __( 'You are not allowed to change plugins.', 'activity-log-with-undo' ) );
		}
		if ( ! Siblings::smoke_rollback() ) {
			return self::refuse( __( 'WordPress keeps no copy of the old version. Axle Smoke Test saves one before each update; activate it to reverse updates.', 'activity-log-with-undo' ) );
		}
		if ( null === Siblings::smoke_backup( 'plugin', $entry->object_id, $old ) ) {
			/* translators: %s: version number. */
			return self::refuse( sprintf( __( 'No copy of version %s is saved any more, so the update cannot be reversed here.', 'activity-log-with-undo' ), $old ) );
		}

		return parent::can_undo( $entry );
	}

	/**
	 * Puts the files of the old version back, through Axle Smoke Test.
	 *
	 * @param Entry $entry Entry.
	 * @return true|\WP_Error
	 */
	private function undo_update( Entry $entry ) {
		$old      = (string) ( ( (array) $entry->before() )['version'] ?? '' );
		$backup   = Siblings::smoke_backup( 'plugin', $entry->object_id, $old );
		$rollback = Siblings::smoke_rollback();
		if ( ! $backup || ! $rollback || ! method_exists( $rollback, 'restore' ) ) {
			return $this->can_undo_update( $entry );
		}

		$result = $rollback->restore(
			array(
				'type'    => 'plugin',
				'id'      => $entry->object_id,
				'slug'    => (string) preg_replace( '/\.php$/', '', (string) strtok( $entry->object_id, '/' ) ),
				'name'    => $entry->object_label,
				'current' => self::version( $entry->object_id ),
				'version' => $old,
				'backup'  => $backup,
			)
		);
		if ( function_exists( 'wp_clean_plugins_cache' ) ) {
			wp_clean_plugins_cache( true );
		}

		return $result instanceof \WP_Error ? $result : true;
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

		$text = sprintf( $messages[ $entry->event ] ?? '%s', $name );
		$old  = (string) ( ( (array) $entry->before() )['version'] ?? '' );
		$new  = (string) ( ( (array) $entry->after() )['version'] ?? '' );
		if ( 'plugin_updated' === $entry->event && '' !== $old && '' !== $new ) {
			/* translators: 1: plugin description, 2: old version, 3: new version. */
			$text = sprintf( __( '%1$s from %2$s to %3$s', 'activity-log-with-undo' ), $text, $old, $new );
		}

		return $text;
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
