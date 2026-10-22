<?php
/**
 * One row of the activity log.
 *
 * @package AryanX\ActivityLogWithUndo
 */

namespace AryanX\ActivityLogWithUndo;

defined( 'ABSPATH' ) || exit;

/**
 * A logged change, read from the table.
 */
final class Entry {

	/**
	 * User ID left in entries whose user was erased by the privacy tools.
	 */
	public const ERASED = -1;

	/**
	 * Entry ID.
	 *
	 * @var int
	 */
	public int $id = 0;

	/**
	 * When it happened, `Y-m-d H:i:s` in UTC.
	 *
	 * @var string
	 */
	public string $created_gmt = '';

	/**
	 * Changes made in the same request share this ID.
	 *
	 * @var string
	 */
	public string $change_set = '';

	/**
	 * What happened, e.g. option_updated.
	 *
	 * @var string
	 */
	public string $event = '';

	/**
	 * ID of the recorder that logged it, e.g. options.
	 *
	 * @var string
	 */
	public string $recorder = '';

	/**
	 * Kind of thing that changed, e.g. option, post, user.
	 *
	 * @var string
	 */
	public string $object_type = '';

	/**
	 * Its ID or name.
	 *
	 * @var string
	 */
	public string $object_id = '';

	/**
	 * Its name at the time, for people.
	 *
	 * @var string
	 */
	public string $object_label = '';

	/**
	 * User who made the change, 0 for none, ERASED when removed.
	 *
	 * @var int
	 */
	public int $actor_user_id = 0;

	/**
	 * Where the change came from: admin, rest, app-password, cli, cron, xmlrpc or ability.
	 *
	 * @var string
	 */
	public string $actor_via = '';

	/**
	 * IP address, possibly masked or empty.
	 *
	 * @var string
	 */
	public string $ip = '';

	/**
	 * Encoded state before the change.
	 *
	 * @var string|null
	 */
	public ?string $before = null;

	/**
	 * Encoded state after the change.
	 *
	 * @var string|null
	 */
	public ?string $after = null;

	/**
	 * Whether the change can be reversed.
	 *
	 * @var bool
	 */
	public bool $undoable = false;

	/**
	 * Entry that reversed this one.
	 *
	 * @var int|null
	 */
	public ?int $undone_by = null;

	/**
	 * Entry this one reversed.
	 *
	 * @var int|null
	 */
	public ?int $undo_of = null;

	/**
	 * Builds an entry from a database row.
	 *
	 * @param array|object $row Row.
	 * @return Entry
	 */
	public static function from_row( $row ): Entry {
		$row   = (array) $row;
		$entry = new self();

		$entry->id            = (int) ( $row['id'] ?? 0 );
		$entry->created_gmt   = (string) ( $row['created_gmt'] ?? '' );
		$entry->change_set    = (string) ( $row['change_set'] ?? '' );
		$entry->event         = (string) ( $row['event'] ?? '' );
		$entry->recorder      = (string) ( $row['recorder'] ?? '' );
		$entry->object_type   = (string) ( $row['object_type'] ?? '' );
		$entry->object_id     = (string) ( $row['object_id'] ?? '' );
		$entry->object_label  = (string) ( $row['object_label'] ?? '' );
		$entry->actor_user_id = (int) ( $row['actor_user_id'] ?? 0 );
		$entry->actor_via     = (string) ( $row['actor_via'] ?? '' );
		$entry->ip            = (string) ( $row['ip'] ?? '' );
		$entry->before        = isset( $row['before_state'] ) ? (string) $row['before_state'] : null;
		$entry->after         = isset( $row['after_state'] ) ? (string) $row['after_state'] : null;
		$entry->undoable      = ! empty( $row['undoable'] );
		$entry->undone_by     = isset( $row['undone_by'] ) ? (int) $row['undone_by'] : null;
		$entry->undo_of       = isset( $row['undo_of'] ) ? (int) $row['undo_of'] : null;

		return $entry;
	}

	/**
	 * The state before the change.
	 *
	 * @return mixed
	 */
	public function before() {
		return Payload::decode( $this->before );
	}

	/**
	 * The state after the change.
	 *
	 * @return mixed
	 */
	public function after() {
		return Payload::decode( $this->after );
	}

	/**
	 * Whether a later entry reversed this one.
	 *
	 * @return bool
	 */
	public function is_undone(): bool {
		return null !== $this->undone_by;
	}

	/**
	 * Unix time of the change.
	 *
	 * @return int
	 */
	public function timestamp(): int {
		$time = strtotime( $this->created_gmt . ' UTC' );

		return false === $time ? 0 : $time;
	}

	/**
	 * Login of the user who made the change, or a stand-in.
	 *
	 * @return string
	 */
	public function actor_name(): string {
		if ( self::ERASED === $this->actor_user_id ) {
			return __( '(removed)', 'activity-log-with-undo' );
		}
		if ( ! $this->actor_user_id ) {
			return __( 'System', 'activity-log-with-undo' );
		}
		$user = get_userdata( $this->actor_user_id );

		return $user ? $user->user_login : '#' . $this->actor_user_id;
	}

	/**
	 * The entry as plain data, for WP-CLI, the REST API and abilities.
	 *
	 * @param bool $with_states Include the before and after states.
	 * @return array
	 */
	public function to_array( bool $with_states = false ): array {
		$data = array(
			'id'           => $this->id,
			'created'      => gmdate( 'c', $this->timestamp() ),
			'change_set'   => $this->change_set,
			'event'        => $this->event,
			'object_type'  => $this->object_type,
			'object_id'    => $this->object_id,
			'object_label' => $this->object_label,
			'user_id'      => $this->actor_user_id,
			'user'         => $this->actor_name(),
			'via'          => $this->actor_via,
			'ip'           => $this->ip,
			'undoable'     => $this->undoable,
			'undone_by'    => $this->undone_by,
			'undo_of'      => $this->undo_of,
		);

		if ( $with_states ) {
			$data['before'] = $this->before();
			$data['after']  = $this->after();
		}

		return $data;
	}
}
