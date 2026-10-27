<?php
/**
 * One-time notices after an action.
 *
 * @package AryanX\Axle\ActivityLogWithUndo
 */

namespace AryanX\Axle\ActivityLogWithUndo\Admin;

use AryanX\Axle\ActivityLogWithUndo\Service;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps the result of an undo for the next screen the user sees, so the
 * message can say exactly what happened without putting it in the URL.
 */
final class Notices implements Service {

	/**
	 * Shows waiting notices on other admin screens (after an undo from the admin bar).
	 */
	public function register(): void {
		add_action( 'admin_notices', array( $this, 'render_elsewhere' ) );
	}

	/**
	 * Transient key for a user.
	 *
	 * @param int $user_id User ID.
	 * @return string
	 */
	private static function key( int $user_id ): string {
		return 'activity_log_with_undo_notice_' . $user_id;
	}

	/**
	 * Adds a notice for the current user.
	 *
	 * @param string $type    success, warning, error or info.
	 * @param string $message Message.
	 */
	public static function add( string $type, string $message ): void {
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return;
		}
		$notices   = (array) get_transient( self::key( $user_id ) );
		$notices[] = array(
			'type'    => in_array( $type, array( 'success', 'warning', 'error', 'info' ), true ) ? $type : 'info',
			'message' => $message,
		);
		set_transient( self::key( $user_id ), array_values( array_filter( $notices ) ), 5 * MINUTE_IN_SECONDS );
	}

	/**
	 * Prints and clears the current user's notices.
	 */
	public static function render(): void {
		$user_id = get_current_user_id();
		$notices = $user_id ? get_transient( self::key( $user_id ) ) : false;
		if ( ! is_array( $notices ) || ! $notices ) {
			return;
		}
		delete_transient( self::key( $user_id ) );

		foreach ( $notices as $notice ) {
			if ( is_array( $notice ) && isset( $notice['message'] ) ) {
				printf( '<div class="notice notice-%1$s is-dismissible alu-notice"><p>%2$s</p></div>', esc_attr( (string) $notice['type'] ), esc_html( (string) $notice['message'] ) );
			}
		}
	}

	/**
	 * Prints waiting notices on any admin screen but the log, which prints its own.
	 */
	public function render_elsewhere(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( $screen && false !== strpos( (string) $screen->id, LogPage::SLUG ) ) {
			return;
		}
		self::render();
	}
}
