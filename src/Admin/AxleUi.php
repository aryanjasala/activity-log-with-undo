<?php
/**
 * The parts every Axle plugin screen shares.
 *
 * Generated from _dev/repo-template by apply-template.py. Do not edit it in a
 * plugin; change the template and re-apply. See _dev/DESIGN.md.
 *
 * @package AryanX\Axle\ActivityLogWithUndo
 */

namespace AryanX\Axle\ActivityLogWithUndo\Admin;

use const AryanX\Axle\ActivityLogWithUndo\PLUGIN_FILE;
use const AryanX\Axle\ActivityLogWithUndo\VERSION;

defined( 'ABSPATH' ) || exit;

/**
 * Loads axle-ui.css and prints the family footer.
 */
final class AxleUi {

	public const HANDLE = 'activity-log-with-undo-axle-ui';

	/**
	 * Registers and enqueues the shared stylesheet. Call it from the
	 * plugin's own admin_enqueue_scripts callback, after checking the screen.
	 */
	public static function enqueue(): void {
		wp_enqueue_style( self::HANDLE, plugins_url( 'assets/axle-ui.css', PLUGIN_FILE ), array(), VERSION );
	}

	/**
	 * Prints the one-line footer at the bottom of the plugin's own screens.
	 */
	public static function footer(): void {
		printf(
			'<p class="axle-footer">%1$s &middot; <a href="%2$s">%3$s</a> &middot; <a href="%4$s">%5$s</a></p>',
			esc_html__( 'Part of Axle by AryanX', 'activity-log-with-undo' ),
			esc_url( 'https://axlewp.com/plugins/activity-log-with-undo/' ),
			esc_html__( 'Documentation', 'activity-log-with-undo' ),
			esc_url( 'https://wordpress.org/support/plugin/activity-log-with-undo/' ),
			esc_html__( 'Support', 'activity-log-with-undo' )
		);
	}
}
