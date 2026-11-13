<?php
/**
 * The Alerts tab of the log screen.
 *
 * @package AryanX\Axle\ActivityLogWithUndo
 */

namespace AryanX\Axle\ActivityLogWithUndo\Admin;

use AryanX\Axle\ActivityLogWithUndo\Alerts\Alerts;
use AryanX\Axle\ActivityLogWithUndo\Alerts\Matcher;
use AryanX\Axle\ActivityLogWithUndo\Alerts\Rules;
use AryanX\Axle\ActivityLogWithUndo\Recorders\Registry;
use AryanX\Axle\ActivityLogWithUndo\Service;
use AryanX\Axle\ActivityLogWithUndo\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Lists the rules, edits one, sends a test, and sets the working hours and the throttle.
 * A webhook URL is never printed back: the field stays empty and the screen shows only the host.
 */
final class AlertsScreen implements Service {

	public const SAVE     = 'activity_log_with_undo_alert_save';
	public const TOGGLE   = 'activity_log_with_undo_alert_toggle';
	public const TEST     = 'activity_log_with_undo_alert_test';
	public const DELETE   = 'activity_log_with_undo_alert_delete';
	public const STARTER  = 'activity_log_with_undo_alert_starter';
	public const SETTINGS = 'activity_log_with_undo_alert_settings';

	/**
	 * Rules.
	 *
	 * @var Rules
	 */
	private Rules $rules;

	/**
	 * Sends alerts.
	 *
	 * @var Alerts
	 */
	private Alerts $alerts;

	/**
	 * Recorders, for the areas and events.
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
	 * Sets up the screen.
	 *
	 * @param Rules    $rules    Rules.
	 * @param Alerts   $alerts   Sends alerts.
	 * @param Registry $registry Recorders.
	 * @param Settings $settings Saved settings.
	 */
	public function __construct( Rules $rules, Alerts $alerts, Registry $registry, Settings $settings ) {
		$this->rules    = $rules;
		$this->alerts   = $alerts;
		$this->registry = $registry;
		$this->settings = $settings;
	}

	/**
	 * Registers the form handlers.
	 */
	public function register(): void {
		foreach ( array(
			self::SAVE     => 'handle_save',
			self::TOGGLE   => 'handle_toggle',
			self::TEST     => 'handle_test',
			self::DELETE   => 'handle_delete',
			self::STARTER  => 'handle_starter',
			self::SETTINGS => 'handle_settings',
		) as $action => $method ) {
			add_action( 'admin_post_' . $action, array( $this, $method ) );
		}
	}

	/**
	 * URL of the tab, or of one rule's form.
	 *
	 * @param string $rule Rule ID, or 'new'.
	 * @return string
	 */
	public static function url( string $rule = '' ): string {
		return LogPage::url(
			'' === $rule ? array( 'tab' => 'alerts' ) : array(
				'tab'  => 'alerts',
				'rule' => $rule,
			) 
		);
	}

	/**
	 * Help tabs for the Alerts tab.
	 *
	 * @return array<int, array{id: string, title: string, content: string}>
	 */
	public static function help(): array {
		return array(
			array(
				'id'      => 'alerts',
				'title'   => __( 'Alerts', 'activity-log-with-undo' ),
				'content' => '<p>' . esc_html__( 'An alert rule tells you when a kind of change happens: a plugin deactivated, a new administrator, a changed site address. Each message names who changed what, with the old and new value, and links to the entry in the log. Undo always happens on the site after you sign in, never from a link in a message.', 'activity-log-with-undo' ) . '</p>',
			),
			array(
				'id'      => 'alert-rules',
				'title'   => __( 'Write a rule', 'activity-log-with-undo' ),
				'content' => '<p>' . esc_html__( 'Pick the events, or leave them empty for any change. You can narrow a rule by area, by who made the change, by role, or to changes made outside working hours. The starter rules are off until you add and turn them on.', 'activity-log-with-undo' ) . '</p>',
			),
			array(
				'id'      => 'alert-delivery',
				'title'   => __( 'Email, webhook and digest', 'activity-log-with-undo' ),
				'content' => '<p>' . esc_html__( 'Send email, a webhook, or both. A webhook gets JSON, or the Slack format that many chat tools accept. Instant rules send one message for a whole bulk change, then wait a few minutes between messages. Daily rules send one digest a day. The webhook URL is a secret: it is never shown again after you save it.', 'activity-log-with-undo' ) . '</p>',
			),
			array(
				'id'      => 'alert-test',
				'title'   => __( 'Send a test', 'activity-log-with-undo' ),
				'content' => '<p>' . esc_html__( 'Send a test sends a sample message through every channel of the rule and says if each one worked. It does not change anything on the site. People who may not edit something never read about it in an alert.', 'activity-log-with-undo' ) . '</p>',
			),
		);
	}

	/**
	 * Stops the request unless the person may manage alerts and the nonce is right.
	 *
	 * @param string $action Nonce action.
	 */
	private function guard( string $action ): void {
		check_admin_referer( $action );
		if ( ! current_user_can( Alerts::capability() ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to manage alerts.', 'activity-log-with-undo' ), 403 );
		}
	}

	/**
	 * Redirects back to the tab.
	 *
	 * @param string $rule A rule form to open.
	 */
	private function back( string $rule = '' ): void {
		wp_safe_redirect( self::url( $rule ) );
		exit;
	}

	/**
	 * The posted rule ID.
	 *
	 * @return string
	 */
	private function posted_id(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Checked in guard().
		return isset( $_POST['rule'] ) ? sanitize_key( wp_unslash( $_POST['rule'] ) ) : '';
	}

	/**
	 * Saves a rule.
	 */
	public function handle_save(): void {
		$this->guard( self::SAVE );

		// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Checked in guard(); every field is cleaned in Rules::sanitize().
		$id     = $this->posted_id();
		$fields = array(
			'id'            => $id,
			'name'          => isset( $_POST['name'] ) ? wp_unslash( $_POST['name'] ) : '',
			'enabled'       => ! empty( $_POST['enabled'] ),
			'events'        => isset( $_POST['events'] ) ? wp_unslash( $_POST['events'] ) : array(),
			'areas'         => isset( $_POST['areas'] ) ? wp_unslash( $_POST['areas'] ) : array(),
			'roles'         => isset( $_POST['roles'] ) ? wp_unslash( $_POST['roles'] ) : array(),
			'objects'       => isset( $_POST['objects'] ) ? wp_unslash( $_POST['objects'] ) : '',
			'condition'     => isset( $_POST['condition'] ) ? wp_unslash( $_POST['condition'] ) : '',
			'outside_hours' => ! empty( $_POST['outside_hours'] ),
			'email'         => ! empty( $_POST['email'] ),
			'emails'        => isset( $_POST['emails'] ) ? wp_unslash( $_POST['emails'] ) : '',
			'webhook'       => ! empty( $_POST['webhook'] ),
			'format'        => isset( $_POST['format'] ) ? wp_unslash( $_POST['format'] ) : 'json',
			'mode'          => isset( $_POST['mode'] ) ? wp_unslash( $_POST['mode'] ) : 'instant',
		);
		$logins = isset( $_POST['users'] ) ? preg_split( '/[\s,]+/', (string) wp_unslash( $_POST['users'] ) ) : array();
		$url    = isset( $_POST['webhook_url'] ) ? esc_url_raw( trim( (string) wp_unslash( $_POST['webhook_url'] ) ) ) : '';
		$remove = ! empty( $_POST['webhook_remove'] );
		// phpcs:enable

		$users   = array();
		$unknown = array();
		foreach ( array_filter(
			(array) $logins,
			static function ( $login ) {
				return '' !== $login;
			}
		) as $login ) {
			$user = get_user_by( 'login', sanitize_user( $login ) );
			if ( $user ) {
				$users[] = (int) $user->ID;
			} else {
				$unknown[] = sanitize_user( $login );
			}
		}
		$fields['users'] = $users;

		if ( $remove ) {
			$fields['webhook'] = false;
		}
		$saved = $this->rules->save( $fields, $remove ? '' : ( '' !== $url ? $url : null ) );
		if ( is_wp_error( $saved ) ) {
			Notices::add( 'error', $saved->get_error_message() );
			$this->back( '' === $id ? 'new' : $id );
		}

		$message = sprintf(
			/* translators: %s: rule name. */
			__( 'Saved the alert rule "%s".', 'activity-log-with-undo' ),
			$saved['name']
		);
		if ( $unknown ) {
			/* translators: %s: user names. */
			$message .= ' ' . sprintf( __( 'These users do not exist and were left out: %s.', 'activity-log-with-undo' ), implode( ', ', $unknown ) );
		}
		Notices::add( $unknown ? 'warning' : 'success', $message );
		$this->back();
	}

	/**
	 * Turns a rule on or off.
	 */
	public function handle_toggle(): void {
		$this->guard( self::TOGGLE );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Checked in guard().
		$on   = ! empty( $_POST['on'] );
		$rule = $this->rules->set_enabled( $this->posted_id(), $on );
		if ( is_wp_error( $rule ) ) {
			Notices::add( 'error', $rule->get_error_message() );
		} else {
			Notices::add(
				'success',
				$on
					/* translators: %s: rule name. */
					? sprintf( __( 'Turned on "%s". It starts with the next change.', 'activity-log-with-undo' ), $rule['name'] )
					/* translators: %s: rule name. */
					: sprintf( __( 'Turned off "%s". You can turn it on again at any time.', 'activity-log-with-undo' ), $rule['name'] )
			);
		}
		$this->back();
	}

	/**
	 * Sends a test.
	 */
	public function handle_test(): void {
		$this->guard( self::TEST );

		$results = $this->alerts->test( $this->posted_id() );
		if ( is_wp_error( $results ) ) {
			Notices::add( 'error', $results->get_error_message() );
			$this->back();
		}

		$failed = array();
		$sent   = array();
		foreach ( $results as $result ) {
			if ( $result['ok'] ) {
				$sent[] = $result['target'];
			} else {
				$failed[] = $result['target'] . ': ' . $result['error'];
			}
		}
		if ( $sent ) {
			/* translators: %s: where it went. */
			Notices::add( 'success', sprintf( __( 'Sent a test to %s.', 'activity-log-with-undo' ), implode( ', ', $sent ) ) );
		}
		if ( $failed ) {
			/* translators: %s: what went wrong. */
			Notices::add( 'error', sprintf( __( 'The test did not go through. %s', 'activity-log-with-undo' ), implode( ' ', $failed ) ) );
		}
		$this->back();
	}

	/**
	 * Deletes a rule.
	 */
	public function handle_delete(): void {
		$this->guard( self::DELETE );

		$id   = $this->posted_id();
		$rule = $this->rules->get( $id );
		if ( $rule && $this->rules->delete( $id ) ) {
			/* translators: %s: rule name. */
			Notices::add( 'success', sprintf( __( 'Deleted the alert rule "%s". It cannot be brought back.', 'activity-log-with-undo' ), $rule['name'] ) );
		} else {
			Notices::add( 'error', __( 'That alert rule does not exist.', 'activity-log-with-undo' ) );
		}
		$this->back();
	}

	/**
	 * Adds a starter rule, off, or all of them.
	 */
	public function handle_starter(): void {
		$this->guard( self::STARTER );

		$id       = $this->posted_id();
		$starters = Rules::starters();
		$wanted   = 'all' === $id ? array_keys( $starters ) : array( $id );
		$added    = 0;
		foreach ( $wanted as $key ) {
			if ( isset( $starters[ $key ] ) && ! $this->rules->get( $key ) ) {
				$saved = $this->rules->save( array( 'enabled' => false ) + $starters[ $key ] );
				if ( ! is_wp_error( $saved ) ) {
					++$added;
				}
			}
		}

		Notices::add(
			$added ? 'success' : 'warning',
			$added
				/* translators: %d: number of rules. */
				? sprintf( _n( 'Added %d starter rule. It is off until you turn it on.', 'Added %d starter rules. They are off until you turn them on.', $added, 'activity-log-with-undo' ), $added )
				: __( 'That starter rule is already in your list.', 'activity-log-with-undo' )
		);
		$this->back();
	}

	/**
	 * Saves the working hours and the throttle.
	 */
	public function handle_settings(): void {
		$this->guard( self::SETTINGS );

		// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Checked in guard(); Settings::sanitize() cleans every value.
		$this->settings->save(
			array(
				'work_days'      => isset( $_POST['work_days'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['work_days'] ) ) : array(),
				'work_start'     => isset( $_POST['work_start'] ) ? wp_unslash( $_POST['work_start'] ) : '09:00',
				'work_end'       => isset( $_POST['work_end'] ) ? wp_unslash( $_POST['work_end'] ) : '18:00',
				'alert_throttle' => isset( $_POST['alert_throttle'] ) ? absint( $_POST['alert_throttle'] ) : 10,
			)
		);
		// phpcs:enable

		Notices::add( 'success', __( 'Saved the working hours and the wait between messages.', 'activity-log-with-undo' ) );
		$this->back();
	}

	/**
	 * Prints the tab.
	 */
	public function render(): void {
		if ( ! current_user_can( Alerts::capability() ) ) {
			echo '<p>' . esc_html__( 'You are not allowed to manage alerts.', 'activity-log-with-undo' ) . '</p>';
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only view switch.
		$rule_id = isset( $_GET['rule'] ) ? sanitize_key( wp_unslash( $_GET['rule'] ) ) : '';
		if ( 'new' === $rule_id ) {
			$this->render_form( Rules::blank() );
			return;
		}
		$rule = '' !== $rule_id ? $this->rules->get( $rule_id ) : null;
		if ( $rule ) {
			$this->render_form( $rule );
			return;
		}

		$this->render_list();
	}

	/**
	 * A small form with one button, for a row.
	 *
	 * @param string $action Admin-post action.
	 * @param string $rule   Rule ID.
	 * @param string $label  Button text.
	 * @param array  $extra  Extra hidden fields.
	 * @param string $button_class Button class.
	 */
	private function mini_form( string $action, string $rule, string $label, array $extra = array(), string $button_class = 'button button-small' ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="alu-inline-form">';
		wp_nonce_field( $action );
		echo '<input type="hidden" name="action" value="' . esc_attr( $action ) . '"><input type="hidden" name="rule" value="' . esc_attr( $rule ) . '">';
		foreach ( $extra as $name => $value ) {
			echo '<input type="hidden" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '">';
		}
		echo '<button type="submit" class="' . esc_attr( $button_class ) . '">' . esc_html( $label ) . '</button></form>';
	}

	/**
	 * What a rule listens for, in words.
	 *
	 * @param array $rule Rule.
	 * @return string
	 */
	private function summary( array $rule ): string {
		$labels = $this->registry->events();
		$events = array();
		foreach ( $rule['events'] as $event ) {
			$events[] = $labels[ $event ] ?? $event;
		}
		$parts  = array( $events ? implode( ', ', $events ) : __( 'Any change', 'activity-log-with-undo' ) );
		$bounds = array();
		$areas  = $this->registry->all();
		foreach ( $rule['areas'] as $area ) {
			$bounds[] = isset( $areas[ $area ] ) ? $areas[ $area ]->label() : $area;
		}
		if ( $rule['objects'] ) {
			$bounds[] = implode( ', ', $rule['objects'] );
		}
		if ( $rule['users'] || $rule['roles'] ) {
			$bounds[] = __( 'by chosen users or roles', 'activity-log-with-undo' );
		}
		if ( $rule['outside_hours'] ) {
			$bounds[] = __( 'outside working hours', 'activity-log-with-undo' );
		}
		if ( $bounds ) {
			$parts[] = implode( ', ', $bounds );
		}

		return implode( ' - ', $parts );
	}

	/**
	 * The rule list and the settings under it.
	 */
	private function render_list(): void {
		$rules    = $this->rules->all();
		$state    = $this->rules->state();
		$starters = array_diff_key( Rules::starters(), $rules );
		?>
		<p class="axle-intro"><?php esc_html_e( 'Hear about the changes that matter. Each message names who changed what and links to the entry, and you undo it on the site after you sign in.', 'activity-log-with-undo' ); ?></p>
		<p><a class="button button-primary" href="<?php echo esc_url( self::url( 'new' ) ); ?>"><?php esc_html_e( 'Add a rule', 'activity-log-with-undo' ); ?></a></p>

		<?php if ( ! $rules ) : ?>
			<div class="axle-empty">
				<p><?php esc_html_e( 'You have no alert rules yet. A rule sends an email or a webhook when a kind of change is logged, such as a plugin being deactivated or a new administrator. Start from the rules below, or write your own.', 'activity-log-with-undo' ); ?></p>
			</div>
		<?php else : ?>
			<div class="axle-scroll" tabindex="0" role="region" aria-label="<?php esc_attr_e( 'Alert rules', 'activity-log-with-undo' ); ?>">
			<table class="wp-list-table widefat striped alu-alert-rules">
				<thead><tr>
					<th scope="col"><?php esc_html_e( 'Rule', 'activity-log-with-undo' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Sends when', 'activity-log-with-undo' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Delivery', 'activity-log-with-undo' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Last sent', 'activity-log-with-undo' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Actions', 'activity-log-with-undo' ); ?></th>
				</tr></thead>
				<tbody>
				<?php foreach ( $rules as $rule ) : ?>
					<?php
					$info  = $state['rules'][ $rule['id'] ] ?? array();
					$where = array();
					if ( $rule['email'] ) {
						$where[] = $rule['emails'] ? sprintf(
							/* translators: %d: number of addresses. */
							_n( 'Email to %d address', 'Email to %d addresses', count( $rule['emails'] ), 'activity-log-with-undo' ),
							count( $rule['emails'] )
						) : __( 'Email to the site admin', 'activity-log-with-undo' );
					}
					if ( $rule['webhook'] ) {
						$host = $this->rules->webhook_host( $rule['id'] );
						/* translators: 1: format, 2: host name. */
						$where[] = sprintf( __( 'Webhook (%1$s) to %2$s', 'activity-log-with-undo' ), 'slack' === $rule['format'] ? 'Slack' : 'JSON', '' !== $host ? $host : '?' );
					}
					?>
					<tr>
						<td>
							<strong><a href="<?php echo esc_url( self::url( $rule['id'] ) ); ?>"><?php echo esc_html( $rule['name'] ); ?></a></strong><br>
							<?php if ( $rule['enabled'] ) : ?>
								<span class="axle-badge axle-badge--good"><?php esc_html_e( 'On', 'activity-log-with-undo' ); ?></span>
							<?php else : ?>
								<span class="axle-badge axle-badge--neutral"><?php esc_html_e( 'Off', 'activity-log-with-undo' ); ?></span>
							<?php endif; ?>
						</td>
						<td><?php echo esc_html( $this->summary( $rule ) ); ?></td>
						<td>
							<?php echo esc_html( implode( '; ', $where ) ); ?><br>
							<span class="axle-muted"><?php echo 'daily' === $rule['mode'] ? esc_html__( 'Daily digest', 'activity-log-with-undo' ) : esc_html__( 'Right away', 'activity-log-with-undo' ); ?></span>
						</td>
						<td>
							<?php echo AxleUi::relative_time( (int) ( $info['last_sent'] ?? 0 ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in AxleUi. ?>
							<?php if ( ! empty( $info['error'] ) ) : ?>
								<br><span class="axle-badge axle-badge--error"><?php esc_html_e( 'Failed', 'activity-log-with-undo' ); ?></span>
								<span class="description"><?php echo esc_html( (string) $info['error'] ); ?></span>
							<?php endif; ?>
						</td>
						<td class="alu-row-buttons">
							<a class="button button-small" href="<?php echo esc_url( self::url( $rule['id'] ) ); ?>"><?php esc_html_e( 'Edit', 'activity-log-with-undo' ); ?></a>
							<?php
							$this->mini_form( self::TEST, $rule['id'], __( 'Send a test', 'activity-log-with-undo' ) );
							$this->mini_form(
								self::TOGGLE,
								$rule['id'],
								$rule['enabled'] ? __( 'Turn off', 'activity-log-with-undo' ) : __( 'Turn on', 'activity-log-with-undo' ),
								$rule['enabled'] ? array() : array( 'on' => '1' )
							);
							?>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			</div>
		<?php endif; ?>

		<?php if ( $starters ) : ?>
			<section class="axle-card" aria-labelledby="alu-starters-title">
				<div class="axle-card__head">
					<h2 class="axle-card__title" id="alu-starters-title"><?php esc_html_e( 'Starter rules', 'activity-log-with-undo' ); ?></h2>
					<?php $this->mini_form( self::STARTER, 'all', __( 'Add all starter rules', 'activity-log-with-undo' ) ); ?>
				</div>
				<p><?php esc_html_e( 'Rules for changes that are worth hearing about at once. They are added turned off. Turn one on when you are ready.', 'activity-log-with-undo' ); ?></p>
				<ul class="alu-starters">
					<?php foreach ( $starters as $id => $starter ) : ?>
						<li>
							<span><?php echo esc_html( (string) ( $starter['name'] ?? $id ) ); ?></span>
							<?php $this->mini_form( self::STARTER, (string) $id, __( 'Add this rule', 'activity-log-with-undo' ) ); ?>
						</li>
					<?php endforeach; ?>
				</ul>
			</section>
		<?php endif; ?>

		<?php $this->render_settings(); ?>
		<?php
	}

	/**
	 * Working hours and the throttle.
	 */
	private function render_settings(): void {
		$settings = $this->settings->all();
		$days     = array(
			1 => __( 'Monday', 'activity-log-with-undo' ),
			2 => __( 'Tuesday', 'activity-log-with-undo' ),
			3 => __( 'Wednesday', 'activity-log-with-undo' ),
			4 => __( 'Thursday', 'activity-log-with-undo' ),
			5 => __( 'Friday', 'activity-log-with-undo' ),
			6 => __( 'Saturday', 'activity-log-with-undo' ),
			7 => __( 'Sunday', 'activity-log-with-undo' ),
		);
		?>
		<section class="axle-card" aria-labelledby="alu-alert-settings-title">
			<h2 class="axle-card__title" id="alu-alert-settings-title"><?php esc_html_e( 'Working hours and message timing', 'activity-log-with-undo' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( self::SETTINGS ); ?>
				<input type="hidden" name="action" value="<?php echo esc_attr( self::SETTINGS ); ?>">
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Working days', 'activity-log-with-undo' ); ?></th>
						<td><fieldset>
							<legend class="screen-reader-text"><?php esc_html_e( 'Working days', 'activity-log-with-undo' ); ?></legend>
							<?php foreach ( $days as $number => $label ) : ?>
								<label><input type="checkbox" name="work_days[]" value="<?php echo esc_attr( (string) $number ); ?>" <?php checked( in_array( $number, $settings['work_days'], true ) ); ?>> <?php echo esc_html( $label ); ?></label><br>
							<?php endforeach; ?>
						</fieldset></td>
					</tr>
					<tr>
						<th scope="row"><label for="alu-work-start"><?php esc_html_e( 'Working hours', 'activity-log-with-undo' ); ?></label></th>
						<td>
							<input type="time" id="alu-work-start" name="work_start" value="<?php echo esc_attr( $settings['work_start'] ); ?>">
							<label for="alu-work-end"><?php esc_html_e( 'to', 'activity-log-with-undo' ); ?></label>
							<input type="time" id="alu-work-end" name="work_end" value="<?php echo esc_attr( $settings['work_end'] ); ?>">
							<p class="description"><?php esc_html_e( 'In the site time zone. Rules set to "only outside working hours" fire at any other time. Daily digests go out at the start of working hours.', 'activity-log-with-undo' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="alu-throttle"><?php esc_html_e( 'Wait between messages', 'activity-log-with-undo' ); ?></label></th>
						<td>
							<input type="number" min="0" max="1440" class="small-text" id="alu-throttle" name="alert_throttle" value="<?php echo esc_attr( (string) $settings['alert_throttle'] ); ?>"> <?php esc_html_e( 'minutes', 'activity-log-with-undo' ); ?>
							<p class="description"><?php esc_html_e( 'After an instant message, a rule holds later changes and sends them together once this time is over. One bulk change is always one message.', 'activity-log-with-undo' ); ?></p>
						</td>
					</tr>
				</table>
				<?php submit_button( __( 'Save timing', 'activity-log-with-undo' ) ); ?>
			</form>
		</section>
		<?php
	}

	/**
	 * The form for one rule.
	 *
	 * @param array $rule Rule; blank for a new one.
	 */
	private function render_form( array $rule ): void {
		$is_new = '' === $rule['id'];
		$logins = array();
		foreach ( $rule['users'] as $user_id ) {
			$user = get_userdata( (int) $user_id );
			if ( $user ) {
				$logins[] = $user->user_login;
			}
		}
		$host = $is_new ? '' : $this->rules->webhook_host( $rule['id'] );
		?>
		<p><a href="<?php echo esc_url( self::url() ); ?>">&larr; <?php esc_html_e( 'Back to the alert rules', 'activity-log-with-undo' ); ?></a></p>
		<section class="axle-card">
		<h2 class="axle-card__title"><?php echo $is_new ? esc_html__( 'Add an alert rule', 'activity-log-with-undo' ) : esc_html__( 'Edit the alert rule', 'activity-log-with-undo' ); ?></h2>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="alu-alert-form">
			<?php wp_nonce_field( self::SAVE ); ?>
			<input type="hidden" name="action" value="<?php echo esc_attr( self::SAVE ); ?>">
			<input type="hidden" name="rule" value="<?php echo esc_attr( $rule['id'] ); ?>">
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="alu-rule-name"><?php esc_html_e( 'Name', 'activity-log-with-undo' ); ?></label></th>
					<td><input type="text" class="regular-text" id="alu-rule-name" name="name" value="<?php echo esc_attr( $rule['name'] ); ?>" maxlength="120" required></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Status', 'activity-log-with-undo' ); ?></th>
					<td><label><input type="checkbox" name="enabled" value="1" <?php checked( $rule['enabled'] ); ?>> <?php esc_html_e( 'Send alerts for this rule', 'activity-log-with-undo' ); ?></label></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Events', 'activity-log-with-undo' ); ?></th>
					<td>
						<fieldset class="alu-event-list">
							<legend class="screen-reader-text"><?php esc_html_e( 'Events', 'activity-log-with-undo' ); ?></legend>
							<?php foreach ( $this->registry->all() as $recorder ) : ?>
								<?php
								if ( ! $recorder->events() ) {
									continue; }
								?>
								<p><strong><?php echo esc_html( $recorder->label() ); ?></strong></p>
								<?php foreach ( $recorder->events() as $event => $label ) : ?>
									<label><input type="checkbox" name="events[]" value="<?php echo esc_attr( (string) $event ); ?>" <?php checked( in_array( $event, $rule['events'], true ) ); ?>> <?php echo esc_html( $label ); ?></label><br>
								<?php endforeach; ?>
							<?php endforeach; ?>
						</fieldset>
						<p class="description"><?php esc_html_e( 'Tick none for any change.', 'activity-log-with-undo' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Areas', 'activity-log-with-undo' ); ?></th>
					<td>
						<fieldset>
							<legend class="screen-reader-text"><?php esc_html_e( 'Areas', 'activity-log-with-undo' ); ?></legend>
							<?php foreach ( $this->registry->all() as $id => $recorder ) : ?>
								<label><input type="checkbox" name="areas[]" value="<?php echo esc_attr( (string) $id ); ?>" <?php checked( in_array( $id, $rule['areas'], true ) ); ?>> <?php echo esc_html( $recorder->label() ); ?></label><br>
							<?php endforeach; ?>
						</fieldset>
						<p class="description"><?php esc_html_e( 'Tick none for every area.', 'activity-log-with-undo' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="alu-rule-objects"><?php esc_html_e( 'Only these objects', 'activity-log-with-undo' ); ?></label></th>
					<td>
						<input type="text" class="regular-text" id="alu-rule-objects" name="objects" value="<?php echo esc_attr( implode( ', ', $rule['objects'] ) ); ?>">
						<p class="description"><?php esc_html_e( 'Optional. Setting names, plugin files or IDs, separated by commas, such as siteurl, home.', 'activity-log-with-undo' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="alu-rule-users"><?php esc_html_e( 'Made by', 'activity-log-with-undo' ); ?></label></th>
					<td>
						<input type="text" class="regular-text" id="alu-rule-users" name="users" value="<?php echo esc_attr( implode( ', ', $logins ) ); ?>">
						<p class="description"><?php esc_html_e( 'Optional. User names separated by commas. A change by any of them, or by anyone with a role below, fires the rule.', 'activity-log-with-undo' ); ?></p>
						<fieldset>
							<legend class="screen-reader-text"><?php esc_html_e( 'Roles', 'activity-log-with-undo' ); ?></legend>
							<?php foreach ( wp_roles()->get_names() as $slug => $name ) : ?>
								<label><input type="checkbox" name="roles[]" value="<?php echo esc_attr( (string) $slug ); ?>" <?php checked( in_array( $slug, $rule['roles'], true ) ); ?>> <?php echo esc_html( translate_user_role( $name ) ); ?></label><br>
							<?php endforeach; ?>
						</fieldset>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="alu-rule-condition"><?php esc_html_e( 'Also needs', 'activity-log-with-undo' ); ?></label></th>
					<td>
						<select id="alu-rule-condition" name="condition">
							<option value=""><?php esc_html_e( 'Nothing more', 'activity-log-with-undo' ); ?></option>
							<?php foreach ( Matcher::conditions() as $id => $label ) : ?>
								<option value="<?php echo esc_attr( (string) $id ); ?>" <?php selected( $rule['condition'], $id ); ?>><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
						<br><label><input type="checkbox" name="outside_hours" value="1" <?php checked( $rule['outside_hours'] ); ?>> <?php esc_html_e( 'Only outside working hours', 'activity-log-with-undo' ); ?></label>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Email', 'activity-log-with-undo' ); ?></th>
					<td>
						<label><input type="checkbox" name="email" value="1" <?php checked( $rule['email'] ); ?>> <?php esc_html_e( 'Send an email', 'activity-log-with-undo' ); ?></label><br>
						<label for="alu-rule-emails" class="screen-reader-text"><?php esc_html_e( 'Email addresses', 'activity-log-with-undo' ); ?></label>
						<input type="text" class="regular-text" id="alu-rule-emails" name="emails" value="<?php echo esc_attr( implode( ', ', $rule['emails'] ) ); ?>">
						<p class="description"><?php esc_html_e( 'Addresses separated by commas. Empty means the site admin email. Each person gets their own message, and only reads about what they may edit.', 'activity-log-with-undo' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="alu-rule-webhook"><?php esc_html_e( 'Webhook', 'activity-log-with-undo' ); ?></label></th>
					<td>
						<label><input type="checkbox" name="webhook" value="1" <?php checked( $rule['webhook'] ); ?>> <?php esc_html_e( 'Send to a webhook', 'activity-log-with-undo' ); ?></label><br>
						<input type="url" class="regular-text" id="alu-rule-webhook" name="webhook_url" value="" autocomplete="off" placeholder="https://" aria-describedby="alu-webhook-note">
						<p class="description" id="alu-webhook-note">
							<?php
							if ( '' !== $host ) {
								/* translators: %s: host name. */
								echo esc_html( sprintf( __( 'A URL for %s is saved. For safety it is never shown again. Paste a new one to replace it, or leave this empty to keep it.', 'activity-log-with-undo' ), $host ) );
							} else {
								esc_html_e( 'Paste the URL from Slack, Mattermost, Discord or your own service. Once saved, it is never shown again.', 'activity-log-with-undo' );
							}
							?>
						</p>
						<?php if ( '' !== $host ) : ?>
							<label><input type="checkbox" name="webhook_remove" value="1"> <?php esc_html_e( 'Remove the saved webhook URL', 'activity-log-with-undo' ); ?></label><br>
						<?php endif; ?>
						<fieldset>
							<legend class="screen-reader-text"><?php esc_html_e( 'Webhook format', 'activity-log-with-undo' ); ?></legend>
							<label><input type="radio" name="format" value="json" <?php checked( $rule['format'], 'json' ); ?>> <?php esc_html_e( 'JSON data', 'activity-log-with-undo' ); ?></label><br>
							<label><input type="radio" name="format" value="slack" <?php checked( $rule['format'], 'slack' ); ?>> <?php esc_html_e( 'Slack format', 'activity-log-with-undo' ); ?></label>
						</fieldset>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'When to send', 'activity-log-with-undo' ); ?></th>
					<td>
						<fieldset>
							<legend class="screen-reader-text"><?php esc_html_e( 'When to send', 'activity-log-with-undo' ); ?></legend>
							<label><input type="radio" name="mode" value="instant" <?php checked( $rule['mode'], 'instant' ); ?>> <?php esc_html_e( 'Right away, one message per bulk change', 'activity-log-with-undo' ); ?></label><br>
							<label><input type="radio" name="mode" value="daily" <?php checked( $rule['mode'], 'daily' ); ?>> <?php esc_html_e( 'Once a day, as a digest', 'activity-log-with-undo' ); ?></label>
						</fieldset>
					</td>
				</tr>
			</table>
			<p class="submit axle-actions">
				<?php submit_button( __( 'Save rule', 'activity-log-with-undo' ), 'primary', 'submit', false ); ?>
				<a class="button" href="<?php echo esc_url( self::url() ); ?>"><?php esc_html_e( 'Cancel', 'activity-log-with-undo' ); ?></a>
			</p>
		</form>
		</section>

		<?php if ( ! $is_new ) : ?>
			<section class="axle-card axle-card--danger">
				<h2 class="axle-card__title"><?php esc_html_e( 'Delete this rule', 'activity-log-with-undo' ); ?></h2>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( self::DELETE ); ?>
					<input type="hidden" name="action" value="<?php echo esc_attr( self::DELETE ); ?>">
					<input type="hidden" name="rule" value="<?php echo esc_attr( $rule['id'] ); ?>">
					<button type="button" class="button axle-button--danger alu-reveal" data-alu-reveal="alu-alert-delete-confirm" aria-expanded="false" aria-controls="alu-alert-delete-confirm" hidden><?php esc_html_e( 'Delete this rule', 'activity-log-with-undo' ); ?></button>
					<div class="axle-confirm axle-confirm--danger" id="alu-alert-delete-confirm" role="group" aria-labelledby="alu-alert-delete-title" data-alu-panel data-alu-collapsible>
						<p id="alu-alert-delete-title"><strong><?php esc_html_e( 'This deletes the rule, its saved webhook URL and any messages it is holding.', 'activity-log-with-undo' ); ?></strong></p>
						<p><?php esc_html_e( 'It cannot be undone. Changes already logged stay in the log.', 'activity-log-with-undo' ); ?></p>
						<p class="axle-actions">
							<?php submit_button( __( 'Delete the rule', 'activity-log-with-undo' ), 'secondary axle-button--danger', 'submit', false ); ?>
							<button type="button" class="button alu-cancel" data-alu-cancel hidden><?php esc_html_e( 'Keep it', 'activity-log-with-undo' ); ?></button>
						</p>
					</div>
				</form>
			</section>
		<?php endif; ?>
		<?php
	}
}
