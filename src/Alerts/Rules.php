<?php
/**
 * Alert rules and their private data.
 *
 * @package AryanX\Axle\ActivityLogWithUndo
 */

namespace AryanX\Axle\ActivityLogWithUndo\Alerts;

defined( 'ABSPATH' ) || exit;

/**
 * Stores alert rules. Webhook URLs are secrets: they live in their own option, never autoloaded,
 * and nothing in this class hands one back except {@see Rules::webhook_url()}, which only the sender uses.
 * Delivery state (last result, held and daily queues) sits in a third option.
 */
final class Rules {

	public const OPTION  = 'activity_log_with_undo_alert_rules';
	public const SECRETS = 'activity_log_with_undo_alert_secrets';
	public const STATE   = 'activity_log_with_undo_alert_state';

	public const FORMATS = array( 'json', 'slack' );
	public const MODES   = array( 'instant', 'daily' );

	/**
	 * Rule defaults.
	 *
	 * @return array
	 */
	public static function blank(): array {
		return array(
			'id'            => '',
			'name'          => '',
			'enabled'       => false,
			'events'        => array(),
			'areas'         => array(),
			'users'         => array(),
			'roles'         => array(),
			'objects'       => array(),
			'condition'     => '',
			'outside_hours' => false,
			'email'         => true,
			'emails'        => array(),
			'webhook'       => false,
			'format'        => 'json',
			'mode'          => 'instant',
			'owner'         => 0,
		);
	}

	/**
	 * The starter rules, all off until someone adds and turns them on.
	 *
	 * @return array<string, array>
	 */
	public static function starters(): array {
		$starters = array(
			'new-administrator' => array(
				'name'      => __( 'A new administrator', 'activity-log-with-undo' ),
				'events'    => array( 'user_created', 'user_role_changed' ),
				'condition' => 'becomes_administrator',
			),
			'plugin-removed'    => array(
				'name'   => __( 'A plugin is deactivated or deleted', 'activity-log-with-undo' ),
				'events' => array( 'plugin_deactivated', 'plugin_deleted' ),
			),
			'theme-switched'    => array(
				'name'   => __( 'The theme is switched', 'activity-log-with-undo' ),
				'events' => array( 'theme_switched' ),
			),
			'site-address'      => array(
				'name'    => __( 'The site address changes', 'activity-log-with-undo' ),
				'events'  => array( 'option_updated' ),
				'objects' => array( 'siteurl', 'home' ),
			),
			'registration-on'   => array(
				'name'      => __( 'Anyone can register is turned on', 'activity-log-with-undo' ),
				'events'    => array( 'option_updated' ),
				'objects'   => array( 'users_can_register' ),
				'condition' => 'turned_on',
			),
			'default-role'      => array(
				'name'    => __( 'The default role for new users changes', 'activity-log-with-undo' ),
				'events'  => array( 'option_updated' ),
				'objects' => array( 'default_role' ),
			),
		);

		/**
		 * Filters the starter alert rules offered on the Alerts screen.
		 *
		 * @param array $starters Rule ID => rule fields (name, events, areas, objects, condition, outside_hours).
		 */
		$filtered = apply_filters( 'activity_log_with_undo_alert_starter_rules', $starters );
		$out      = array();
		foreach ( (array) $filtered as $id => $fields ) {
			$id = sanitize_key( (string) $id );
			if ( '' !== $id && is_array( $fields ) ) {
				$out[ $id ] = array( 'id' => $id ) + $fields;
			}
		}

		return $out;
	}

	/**
	 * Most rules a site may have.
	 *
	 * @return int
	 */
	public static function max_rules(): int {
		/**
		 * Filters how many alert rules a site may have.
		 *
		 * @param int $max Default 50.
		 */
		return max( 1, (int) apply_filters( 'activity_log_with_undo_alert_max_rules', 50 ) );
	}

	/**
	 * Cleans a rule. Webhook URLs are not part of a rule.
	 *
	 * @param array $raw Raw fields.
	 * @return array
	 */
	public static function sanitize( array $raw ): array {
		$rule = self::blank();

		$rule['id']      = substr( sanitize_key( (string) ( $raw['id'] ?? '' ) ), 0, 40 );
		$rule['name']    = substr( trim( sanitize_text_field( (string) ( $raw['name'] ?? '' ) ) ), 0, 120 );
		$rule['enabled'] = ! empty( $raw['enabled'] );
		foreach ( array( 'events', 'areas', 'roles' ) as $key ) {
			$rule[ $key ] = self::keys( $raw[ $key ] ?? array() );
		}
		$rule['users'] = array_values( array_unique( array_filter( array_map( 'absint', self::list( $raw['users'] ?? array() ) ) ) ) );
		$objects       = array();
		foreach ( self::list( $raw['objects'] ?? array() ) as $object ) {
			$object = substr( trim( sanitize_text_field( $object ) ), 0, 191 );
			if ( '' !== $object ) {
				$objects[] = $object;
			}
		}
		$rule['objects']       = array_values( array_unique( $objects ) );
		$rule['condition']     = sanitize_key( (string) ( $raw['condition'] ?? '' ) );
		$rule['outside_hours'] = ! empty( $raw['outside_hours'] );
		$rule['email']         = ! empty( $raw['email'] );
		$emails                = array();
		foreach ( self::list( $raw['emails'] ?? array() ) as $email ) {
			$clean = sanitize_email( trim( $email ) );
			if ( '' !== $clean && is_email( $clean ) ) {
				$emails[] = $clean;
			}
		}
		$rule['emails']  = array_slice( array_values( array_unique( $emails ) ), 0, 20 );
		$rule['webhook'] = ! empty( $raw['webhook'] );
		$format          = (string) ( $raw['format'] ?? 'json' );
		$rule['format']  = in_array( $format, self::FORMATS, true ) ? $format : 'json';
		$mode            = (string) ( $raw['mode'] ?? 'instant' );
		$rule['mode']    = in_array( $mode, self::MODES, true ) ? $mode : 'instant';
		$rule['owner']   = absint( $raw['owner'] ?? 0 );

		return $rule;
	}

	/**
	 * A string or an array as a list of non-empty strings; a string splits on commas and whitespace.
	 *
	 * @param mixed $value Raw value.
	 * @return string[]
	 */
	private static function list( $value ): array {
		if ( is_string( $value ) ) {
			$value = preg_split( '/[\s,]+/', $value );
		}

		return array_values(
			array_filter(
				array_map( 'strval', is_array( $value ) ? $value : array() ),
				static function ( $item ) {
					return '' !== $item;
				}
			) 
		);
	}

	/**
	 * A list of keys.
	 *
	 * @param mixed $value Raw value.
	 * @return string[]
	 */
	private static function keys( $value ): array {
		return array_values( array_unique( array_filter( array_map( 'sanitize_key', self::list( $value ) ) ) ) );
	}

	/**
	 * Every rule, keyed by ID.
	 *
	 * @return array<string, array>
	 */
	public function all(): array {
		$saved = get_option( self::OPTION, array() );
		$out   = array();
		foreach ( is_array( $saved ) ? $saved : array() as $rule ) {
			if ( is_array( $rule ) ) {
				$rule = self::sanitize( $rule );
				if ( '' !== $rule['id'] ) {
					$out[ $rule['id'] ] = $rule;
				}
			}
		}

		return $out;
	}

	/**
	 * Rules that are on.
	 *
	 * @return array<string, array>
	 */
	public function enabled(): array {
		return array_filter(
			$this->all(),
			static function ( $rule ) {
				return $rule['enabled'];
			}
		);
	}

	/**
	 * One rule.
	 *
	 * @param string $id Rule ID.
	 * @return array|null
	 */
	public function get( string $id ): ?array {
		return $this->all()[ $id ] ?? null;
	}

	/**
	 * A new rule ID.
	 *
	 * @return string
	 */
	public function new_id(): string {
		return 'r' . substr( str_replace( '-', '', wp_generate_uuid4() ), 0, 8 );
	}

	/**
	 * Saves a rule.
	 *
	 * @param array       $fields  Raw fields; `id` picks the rule, a missing one makes a new rule.
	 * @param string|null $webhook A new webhook URL, '' to remove it, null to keep the saved one.
	 * @return array|\WP_Error The saved rule.
	 */
	public function save( array $fields, ?string $webhook = null ) {
		$rules = $this->all();
		$rule  = self::sanitize( $fields );
		$new   = '' === $rule['id'] || ! isset( $rules[ $rule['id'] ] );
		if ( $new && count( $rules ) >= self::max_rules() ) {
			return new \WP_Error( 'activity_log_with_undo_alert_limit', __( 'There are too many alert rules. Delete one you do not need first.', 'activity-log-with-undo' ) );
		}
		if ( '' === $rule['id'] ) {
			$rule['id'] = $this->new_id();
		}
		if ( ! $new && ! array_key_exists( 'owner', $fields ) ) {
			$rule['owner'] = $rules[ $rule['id'] ]['owner'];
		} elseif ( $new && ! $rule['owner'] ) {
			$rule['owner'] = get_current_user_id();
		}

		if ( null !== $webhook ) {
			if ( '' !== $webhook ) {
				$valid = self::valid_webhook( $webhook );
				if ( is_wp_error( $valid ) ) {
					return $valid;
				}
			}
			$this->set_webhook( $rule['id'], $webhook );
		}
		if ( $rule['webhook'] && '' === $this->webhook_url( $rule['id'] ) ) {
			return new \WP_Error( 'activity_log_with_undo_alert_no_webhook', __( 'Add a webhook URL, or turn the webhook off.', 'activity-log-with-undo' ) );
		}
		if ( ! $rule['email'] && ! $rule['webhook'] ) {
			$rule['email'] = true;
		}
		if ( '' === $rule['name'] ) {
			$rule['name'] = __( 'Untitled rule', 'activity-log-with-undo' );
		}

		$rules[ $rule['id'] ] = $rule;
		update_option( self::OPTION, $rules, true );

		/**
		 * Fires after an alert rule is saved.
		 *
		 * @param array $rule The rule. It holds no webhook URL.
		 * @param bool  $new  Whether the rule is new.
		 */
		do_action( 'activity_log_with_undo_alert_rule_saved', $rule, $new );

		return $rule;
	}

	/**
	 * Turns a rule on or off.
	 *
	 * @param string $id      Rule ID.
	 * @param bool   $enabled On or off.
	 * @return array|\WP_Error
	 */
	public function set_enabled( string $id, bool $enabled ) {
		$rule = $this->get( $id );
		if ( ! $rule ) {
			return new \WP_Error( 'activity_log_with_undo_alert_unknown', __( 'That alert rule does not exist.', 'activity-log-with-undo' ) );
		}
		$rule['enabled'] = $enabled;

		return $this->save( $rule );
	}

	/**
	 * Deletes a rule, its webhook URL and anything held for it.
	 *
	 * @param string $id Rule ID.
	 * @return bool Whether it existed.
	 */
	public function delete( string $id ): bool {
		$rules = $this->all();
		if ( ! isset( $rules[ $id ] ) ) {
			return false;
		}
		$rule = $rules[ $id ];
		unset( $rules[ $id ] );
		update_option( self::OPTION, $rules, true );
		$this->set_webhook( $id, '' );
		$state = $this->state();
		unset( $state['rules'][ $id ], $state['queue'][ $id ] );
		$this->save_state( $state );

		/**
		 * Fires after an alert rule is deleted.
		 *
		 * @param array $rule The rule that was deleted.
		 */
		do_action( 'activity_log_with_undo_alert_rule_deleted', $rule );

		return true;
	}

	/**
	 * Whether a webhook URL is saved for a rule.
	 *
	 * @param string $id Rule ID.
	 * @return bool
	 */
	public function has_webhook( string $id ): bool {
		return '' !== $this->webhook_url( $id );
	}

	/**
	 * The host of a rule's webhook, for display. The path and query hold the secret and are never shown.
	 *
	 * @param string $id Rule ID.
	 * @return string
	 */
	public function webhook_host( string $id ): string {
		$host = wp_parse_url( $this->webhook_url( $id ), PHP_URL_HOST );

		return is_string( $host ) ? $host : '';
	}

	/**
	 * The webhook URL. Only the sender calls this.
	 *
	 * @param string $id Rule ID.
	 * @return string
	 */
	public function webhook_url( string $id ): string {
		$secrets = get_option( self::SECRETS, array() );

		return is_array( $secrets ) && isset( $secrets[ $id ] ) && is_string( $secrets[ $id ] ) ? $secrets[ $id ] : '';
	}

	/**
	 * Saves or removes a webhook URL.
	 *
	 * @param string $id  Rule ID.
	 * @param string $url URL, or '' to remove it.
	 */
	private function set_webhook( string $id, string $url ): void {
		$secrets = get_option( self::SECRETS, array() );
		$secrets = is_array( $secrets ) ? $secrets : array();
		if ( '' === $url ) {
			unset( $secrets[ $id ] );
		} else {
			$secrets[ $id ] = $url;
		}
		update_option( self::SECRETS, $secrets, false );
	}

	/**
	 * Checks a webhook URL.
	 *
	 * @param string $url URL.
	 * @return true|\WP_Error
	 */
	public static function valid_webhook( string $url ) {
		/**
		 * Filters the URL schemes a webhook may use.
		 *
		 * @param string[] $schemes Default https only.
		 */
		$schemes = array_map( 'strval', (array) apply_filters( 'activity_log_with_undo_alert_webhook_schemes', array( 'https' ) ) );
		$scheme  = wp_parse_url( $url, PHP_URL_SCHEME );
		if ( ! is_string( $scheme ) || ! in_array( strtolower( $scheme ), $schemes, true ) || ! wp_http_validate_url( $url ) ) {
			return new \WP_Error( 'activity_log_with_undo_alert_bad_webhook', __( 'The webhook URL is not valid. Use a public address that starts with https://.', 'activity-log-with-undo' ) );
		}

		return true;
	}

	/**
	 * Delivery state: when each rule last sent, how it went, and what is queued.
	 *
	 * @return array{rules: array, queue: array}
	 */
	public function state(): array {
		$state = get_option( self::STATE, array() );
		$state = is_array( $state ) ? $state : array();

		return array(
			'rules' => isset( $state['rules'] ) && is_array( $state['rules'] ) ? $state['rules'] : array(),
			'queue' => isset( $state['queue'] ) && is_array( $state['queue'] ) ? $state['queue'] : array(),
		);
	}

	/**
	 * Saves delivery state.
	 *
	 * @param array $state State from {@see Rules::state()}.
	 */
	public function save_state( array $state ): void {
		update_option( self::STATE, $state, false );
	}
}
