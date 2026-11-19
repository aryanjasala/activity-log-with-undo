<?php
/**
 * Looks up the other Axle plugins this one works with.
 *
 * @package AryanX\Axle\ActivityLogWithUndo
 */

namespace AryanX\Axle\ActivityLogWithUndo\Integrations;

defined( 'ABSPATH' ) || exit;

/**
 * Every lookup is guarded, so nothing breaks when a sibling plugin is not
 * active or is an older version without the method asked for.
 */
final class Siblings {

	/**
	 * A sibling plugin's running instance.
	 *
	 * @param string $class_name Its Plugin class.
	 * @return object|null
	 */
	private static function instance( string $class_name ) {
		if ( ! class_exists( $class_name ) || ! is_callable( array( $class_name, 'instance' ) ) ) {
			return null;
		}
		$instance = call_user_func( array( $class_name, 'instance' ) );

		return is_object( $instance ) ? $instance : null;
	}

	/**
	 * A part of a sibling plugin, such as its trash or its engine.
	 *
	 * @param string $class_name Its Plugin class.
	 * @param string $method     Accessor on the Plugin class.
	 * @return object|null
	 */
	private static function part( string $class_name, string $method ) {
		$plugin = self::instance( $class_name );
		if ( ! $plugin || ! method_exists( $plugin, $method ) ) {
			return null;
		}
		$part = $plugin->$method();

		return is_object( $part ) ? $part : null;
	}

	/**
	 * Leftovers' trash.
	 *
	 * @return object|null
	 */
	public static function leftovers_trash() {
		return self::part( 'AryanX\\Axle\\Leftovers\\Plugin', 'trash' );
	}

	/**
	 * Whether Leftovers is running.
	 *
	 * @return bool
	 */
	public static function leftovers_active(): bool {
		return null !== self::instance( 'AryanX\\Axle\\Leftovers\\Plugin' );
	}

	/**
	 * Config Sync's engine.
	 *
	 * @return object|null
	 */
	public static function config_engine() {
		return self::part( 'AryanX\\Axle\\ConfigSync\\Plugin', 'engine' );
	}

	/**
	 * Smoke Test's rollback.
	 *
	 * @return object|null
	 */
	public static function smoke_rollback() {
		return self::part( 'AryanX\\Axle\\SmokeTest\\Plugin', 'rollback' );
	}

	/**
	 * The zip Smoke Test saved before an update, if it has one.
	 *
	 * @param string $type    plugin or theme.
	 * @param string $item    Plugin file or theme folder.
	 * @param string $version Version the zip holds.
	 * @return array|null
	 */
	public static function smoke_backup( string $type, string $item, string $version ): ?array {
		$class_name = 'AryanX\\Axle\\SmokeTest\\Backups';
		if ( '' === $version || ! class_exists( $class_name ) || ! is_callable( array( $class_name, 'find' ) ) ) {
			return null;
		}
		$found = call_user_func( array( $class_name, 'find' ), $type, $item, $version );

		return is_array( $found ) ? $found : null;
	}

	/**
	 * Link to Leftovers.
	 *
	 * @return string
	 */
	public static function leftovers_url(): string {
		return admin_url( 'tools.php?page=axle-leftovers' );
	}

	/**
	 * Link to Config Sync.
	 *
	 * @return string
	 */
	public static function config_sync_url(): string {
		return admin_url( 'options-general.php?page=axle-config-sync' );
	}
}
