<?php
/**
 * "Undo last change" in the admin bar.
 *
 * @package AryanX\Axle\ActivityLogWithUndo
 */

namespace AryanX\Axle\ActivityLogWithUndo\Admin;

use AryanX\Axle\ActivityLogWithUndo\Recorders\Registry;
use AryanX\Axle\ActivityLogWithUndo\Service;
use AryanX\Axle\ActivityLogWithUndo\Settings;
use AryanX\Axle\ActivityLogWithUndo\Store;

defined( 'ABSPATH' ) || exit;

/**
 * When turned on in the settings, the admin bar offers to undo the current
 * user's most recent change that can still be undone, with its change set.
 */
final class AdminBar implements Service {

	/**
	 * Entries.
	 *
	 * @var Store
	 */
	private Store $store;

	/**
	 * Recorders, for descriptions.
	 *
	 * @var Registry
	 */
	private Registry $registry;

	/**
	 * Saved settings.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Sets up the admin bar item.
	 *
	 * @param Store    $store    Entries.
	 * @param Registry $registry Recorders.
	 * @param Settings $settings Saved settings.
	 */
	public function __construct( Store $store, Registry $registry, Settings $settings ) {
		$this->store    = $store;
		$this->registry = $registry;
		$this->settings = $settings;
	}

	/**
	 * Adds the hook.
	 */
	public function register(): void {
		add_action( 'admin_bar_menu', array( $this, 'add_nodes' ), 100 );
	}

	/**
	 * The label: "Undo: changed Tagline", short enough for the bar.
	 *
	 * @param string $description What the change was, as the log words it.
	 * @return string
	 */
	public static function label( string $description ): string {
		$short = wp_html_excerpt( $description, 32, '…' );
		$short = '' === $short ? $short : strtolower( substr( $short, 0, 1 ) ) . substr( $short, 1 );

		/**
		 * Filters the admin bar label of the undo item.
		 *
		 * @param string $label       Default "Undo: " and a short, lower-cased description of the change.
		 * @param string $description Full description of the change.
		 */
		$label = (string) apply_filters( 'activity_log_with_undo_admin_bar_label', sprintf( /* translators: %s: a short description of the change, e.g. "changed Tagline". */ __( 'Undo: %s', 'activity-log-with-undo' ), $short ), $description );

		return '' !== $label ? $label : __( 'Undo last change', 'activity-log-with-undo' );
	}

	/**
	 * Adds "Undo last change".
	 *
	 * @param mixed $admin_bar Admin bar (WP_Admin_Bar).
	 */
	public function add_nodes( $admin_bar ): void {
		if ( ! $admin_bar instanceof \WP_Admin_Bar || ! $this->settings->get( 'admin_bar' ) || ! current_user_can( LogPage::capability() ) ) {
			return;
		}

		$entry = $this->store->latest_undoable( get_current_user_id() );
		if ( ! $entry ) {
			return;
		}

		$description = $this->registry->describe( $entry );
		$admin_bar->add_node(
			array(
				'id'    => 'activity-log-with-undo',
				'title' => '<span class="ab-icon dashicons dashicons-undo" aria-hidden="true"></span><span class="ab-label">' . esc_html( self::label( $description ) ) . '</span>',
				'href'  => LogPage::undo_url( $entry->id, false, true ),
				'meta'  => array( 'title' => $description ),
			)
		);
		// Core hides nodes it does not list below 782 px; this keeps the icon.
		AxleUi::admin_bar_mobile( 'activity-log-with-undo', 'dashicons-undo' );
		$admin_bar->add_node(
			array(
				'parent' => 'activity-log-with-undo',
				'id'     => 'activity-log-with-undo-what',
				'title'  => esc_html( wp_html_excerpt( $description, 70, '…' ) ),
				'href'   => LogPage::url( array( 'entry' => $entry->id ) ),
			)
		);
		$admin_bar->add_node(
			array(
				'parent' => 'activity-log-with-undo',
				'id'     => 'activity-log-with-undo-log',
				'title'  => esc_html__( 'Open the activity log', 'activity-log-with-undo' ),
				'href'   => LogPage::url(),
			)
		);
	}
}
