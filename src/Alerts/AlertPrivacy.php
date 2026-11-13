<?php
/**
 * Personal data kept by alert rules.
 *
 * @package AryanX\Axle\ActivityLogWithUndo
 */

namespace AryanX\Axle\ActivityLogWithUndo\Alerts;

use AryanX\Axle\ActivityLogWithUndo\Service;

defined( 'ABSPATH' ) || exit;

/**
 * Alert rules hold the email addresses that get alerts and the user who made the rule.
 * Both take part in the personal data export and erase tools. Erasing removes the address
 * and the owner from the rules and keeps the rules.
 */
final class AlertPrivacy implements Service {

	/**
	 * Rules.
	 *
	 * @var Rules
	 */
	private Rules $rules;

	/**
	 * Sets up the privacy tools.
	 *
	 * @param Rules $rules Rules.
	 */
	public function __construct( Rules $rules ) {
		$this->rules = $rules;
	}

	/**
	 * Registers the exporter and the eraser.
	 */
	public function register(): void {
		add_filter( 'wp_privacy_personal_data_exporters', array( $this, 'add_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( $this, 'add_eraser' ) );
	}

	/**
	 * Adds the exporter.
	 *
	 * @param mixed $exporters Exporters.
	 * @return array
	 */
	public function add_exporter( $exporters ): array {
		$exporters                                  = (array) $exporters;
		$exporters['activity-log-with-undo-alerts'] = array(
			'exporter_friendly_name' => __( 'Activity Log with Undo alert rules', 'activity-log-with-undo' ),
			'callback'               => array( $this, 'export' ),
		);

		return $exporters;
	}

	/**
	 * Adds the eraser.
	 *
	 * @param mixed $erasers Erasers.
	 * @return array
	 */
	public function add_eraser( $erasers ): array {
		$erasers                                  = (array) $erasers;
		$erasers['activity-log-with-undo-alerts'] = array(
			'eraser_friendly_name' => __( 'Activity Log with Undo alert rules', 'activity-log-with-undo' ),
			'callback'             => array( $this, 'erase' ),
		);

		return $erasers;
	}

	/**
	 * The rules a person gets alerts from, or made.
	 *
	 * @param string $email Email address.
	 * @return array<string, array>
	 */
	private function rules_of( string $email ): array {
		$user  = get_user_by( 'email', $email );
		$email = strtolower( $email );
		$found = array();
		foreach ( $this->rules->all() as $id => $rule ) {
			$emails = array_map( 'strtolower', $rule['emails'] );
			if ( in_array( $email, $emails, true ) || ( $user && (int) $user->ID === $rule['owner'] ) ) {
				$found[ $id ] = $rule;
			}
		}

		return $found;
	}

	/**
	 * Exports the rules a person is named in.
	 *
	 * @param mixed $email Email address.
	 * @return array{data: array, done: bool}
	 */
	public function export( $email ): array {
		$data = array();
		foreach ( $this->rules_of( (string) $email ) as $id => $rule ) {
			$data[] = array(
				'group_id'    => 'activity-log-with-undo-alerts',
				'group_label' => __( 'Activity Log with Undo alert rules', 'activity-log-with-undo' ),
				'item_id'     => 'alert-rule-' . $id,
				'data'        => array(
					array(
						'name'  => __( 'Rule', 'activity-log-with-undo' ),
						'value' => $rule['name'],
					),
					array(
						'name'  => __( 'Alerts go to', 'activity-log-with-undo' ),
						'value' => implode( ', ', $rule['emails'] ),
					),
				),
			);
		}

		return array(
			'data' => $data,
			'done' => true,
		);
	}

	/**
	 * Removes a person's address and ownership from the rules.
	 *
	 * @param mixed $email Email address.
	 * @return array{items_removed: bool, items_retained: bool, messages: array, done: bool}
	 */
	public function erase( $email ): array {
		$email   = strtolower( (string) $email );
		$user    = get_user_by( 'email', $email );
		$changed = false;
		foreach ( $this->rules_of( $email ) as $rule ) {
			$rule['emails'] = array_values(
				array_filter(
					$rule['emails'],
					static function ( $address ) use ( $email ) {
						return strtolower( $address ) !== $email;
					}
				)
			);
			if ( $user && (int) $user->ID === $rule['owner'] ) {
				$rule['owner'] = 0;
			}
			$saved   = $this->rules->save( $rule );
			$changed = $changed || ! is_wp_error( $saved );
		}

		return array(
			'items_removed'  => $changed,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => true,
		);
	}
}
