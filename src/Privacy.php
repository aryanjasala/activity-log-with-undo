<?php
/**
 * Personal data export, erase and privacy policy text.
 *
 * @package AryanX\Axle\ActivityLogWithUndo
 */

namespace AryanX\Axle\ActivityLogWithUndo;

use AryanX\Axle\ActivityLogWithUndo\Recorders\Registry;

defined( 'ABSPATH' ) || exit;

/**
 * The log holds who changed what, from which IP address. It takes part in
 * Tools > Export Personal Data and Tools > Erase Personal Data. Erasing keeps
 * the change history and removes the person from it: their user ID and IP
 * address, and the stored profile data of their own account.
 */
final class Privacy implements Service {

	private const PAGE = 100;

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
	 * Sets up the privacy tools.
	 *
	 * @param Store    $store    Entries.
	 * @param Registry $registry Recorders.
	 */
	public function __construct( Store $store, Registry $registry ) {
		$this->store    = $store;
		$this->registry = $registry;
	}

	/**
	 * Registers the exporter, eraser and policy text.
	 */
	public function register(): void {
		add_filter( 'wp_privacy_personal_data_exporters', array( $this, 'add_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( $this, 'add_eraser' ) );
		add_action( 'admin_init', array( $this, 'policy' ) );
	}

	/**
	 * Adds the exporter.
	 *
	 * @param array $exporters Exporters.
	 * @return array
	 */
	public function add_exporter( $exporters ): array {
		$exporters                           = (array) $exporters;
		$exporters['activity-log-with-undo'] = array(
			'exporter_friendly_name' => __( 'Activity Log with Undo', 'activity-log-with-undo' ),
			'callback'               => array( $this, 'export' ),
		);

		return $exporters;
	}

	/**
	 * Adds the eraser.
	 *
	 * @param array $erasers Erasers.
	 * @return array
	 */
	public function add_eraser( $erasers ): array {
		$erasers                           = (array) $erasers;
		$erasers['activity-log-with-undo'] = array(
			'eraser_friendly_name' => __( 'Activity Log with Undo', 'activity-log-with-undo' ),
			'callback'             => array( $this, 'erase' ),
		);

		return $erasers;
	}

	/**
	 * Exporter: the changes a user made, 100 per page.
	 *
	 * @param string $email Email address.
	 * @param int    $page  Page, from 1.
	 * @return array{data: array, done: bool}
	 */
	public function export( $email, $page = 1 ): array {
		$user = get_user_by( 'email', (string) $email );
		if ( ! $user ) {
			return array(
				'data' => array(),
				'done' => true,
			);
		}

		$entries = $this->store->by_user( $user->ID, self::PAGE, ( max( 1, (int) $page ) - 1 ) * self::PAGE );
		$data    = array();
		foreach ( $entries as $entry ) {
			$data[] = array(
				'group_id'    => 'activity-log-with-undo',
				'group_label' => __( 'Changes you made to the site', 'activity-log-with-undo' ),
				'item_id'     => 'activity-log-with-undo-' . $entry->id,
				'data'        => array(
					array(
						'name'  => __( 'Date', 'activity-log-with-undo' ),
						'value' => $entry->created_gmt,
					),
					array(
						'name'  => __( 'Change', 'activity-log-with-undo' ),
						'value' => $this->registry->describe( $entry ),
					),
					array(
						'name'  => __( 'Made through', 'activity-log-with-undo' ),
						'value' => $entry->actor_via,
					),
					array(
						'name'  => __( 'IP address', 'activity-log-with-undo' ),
						'value' => $entry->ip,
					),
				),
			);
		}

		return array(
			'data' => $data,
			'done' => count( $entries ) < self::PAGE,
		);
	}

	/**
	 * Eraser: removes the user from their entries and their profile data from the log.
	 *
	 * @param string $email Email address.
	 * @return array{items_removed: bool, items_retained: bool, messages: string[], done: bool}
	 */
	public function erase( $email ): array {
		$user    = get_user_by( 'email', (string) $email );
		$changed = $user ? $this->store->anonymise_user( $user->ID, 500 ) : 0;

		return array(
			'items_removed'  => $changed > 0,
			'items_retained' => $changed > 0,
			'messages'       => $changed > 0 ? array( __( 'Activity Log with Undo keeps the change history, without the person who made the changes.', 'activity-log-with-undo' ) ) : array(),
			'done'           => $changed < 500,
		);
	}

	/**
	 * Suggested text for Settings > Privacy.
	 */
	public function policy(): void {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}

		wp_add_privacy_policy_content(
			__( 'Activity Log with Undo', 'activity-log-with-undo' ),
			'<p>' . esc_html__( 'When a logged-in user changes settings, plugins, themes, users, content, terms or menus, the site keeps a record of the change: what changed, the old and new values, when, the account that made it, and the IP address it came from (shortened by default). Records are deleted after 90 days unless the site owner chooses another period. A user can ask for an export of their records, or for their name and IP address to be removed from them.', 'activity-log-with-undo' ) . '</p>'
		);
	}
}
