<?php
/**
 * Abilities: read the log and undo changes, for AI agents and other Abilities API clients.
 *
 * @package AryanX\ActivityLogWithUndo
 */

namespace AryanX\ActivityLogWithUndo;

use AryanX\ActivityLogWithUndo\Admin\LogPage;
use AryanX\ActivityLogWithUndo\Recorders\Registry;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the Activity Log with Undo abilities on WordPress 6.9 and later.
 *
 * An agent can list what changed, look at one change with its before and
 * after states, and undo it. Undo refuses when things changed since, unless
 * the agent passes `force`.
 */
final class Abilities implements Service {

	public const CATEGORY = 'activity-log-with-undo';

	/**
	 * Entries.
	 *
	 * @var Store
	 */
	private Store $store;

	/**
	 * Recorders.
	 *
	 * @var Registry
	 */
	private Registry $registry;

	/**
	 * Reverses changes.
	 *
	 * @var Undoer
	 */
	private Undoer $undoer;

	/**
	 * Sets up the abilities.
	 *
	 * @param Store    $store    Entries.
	 * @param Registry $registry Recorders.
	 * @param Undoer   $undoer   Reverses changes.
	 */
	public function __construct( Store $store, Registry $registry, Undoer $undoer ) {
		$this->store    = $store;
		$this->registry = $registry;
		$this->undoer   = $undoer;
	}

	/**
	 * Hooks into the Abilities API when WordPress has it.
	 */
	public function register(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		add_action( 'wp_abilities_api_categories_init', array( $this, 'register_category' ) );
		add_action( 'wp_abilities_api_init', array( $this, 'register_abilities' ) );
	}

	/**
	 * Registers the category.
	 */
	public function register_category(): void {
		if ( ! function_exists( 'wp_register_ability_category' ) ) {
			return;
		}

		wp_register_ability_category(
			self::CATEGORY,
			array(
				'label'       => __( 'Activity Log with Undo', 'activity-log-with-undo' ),
				'description' => __( 'See what changed on the site, with the old and new values, and undo a change.', 'activity-log-with-undo' ),
			)
		);
	}

	/**
	 * Registers the abilities.
	 */
	public function register_abilities(): void {
		$can = static function (): bool {
			return current_user_can( LogPage::capability() );
		};

		$entry = array(
			'type'       => 'object',
			'properties' => array(
				'id'           => array( 'type' => 'integer' ),
				'created'      => array(
					'type'        => 'string',
					'description' => 'ISO 8601, UTC.',
				),
				'description'  => array( 'type' => 'string' ),
				'change_set'   => array( 'type' => 'string' ),
				'event'        => array( 'type' => 'string' ),
				'object_type'  => array( 'type' => 'string' ),
				'object_id'    => array( 'type' => 'string' ),
				'object_label' => array( 'type' => 'string' ),
				'user_id'      => array( 'type' => 'integer' ),
				'user'         => array( 'type' => 'string' ),
				'via'          => array( 'type' => 'string' ),
				'ip'           => array( 'type' => 'string' ),
				'undoable'     => array( 'type' => 'boolean' ),
				'undone_by'    => array( 'type' => array( 'integer', 'null' ) ),
				'undo_of'      => array( 'type' => array( 'integer', 'null' ) ),
				'agent'        => array(
					'type'        => array( 'object', 'null' ),
					'description' => 'For a change an AI agent made after a person approved it in Agent Governance: name, request, approved_by and approver.',
				),
			),
		);

		$this->ability(
			'list-activity',
			__( 'List activity', 'activity-log-with-undo' ),
			__( 'What changed on the site, newest first: settings, plugins, themes, users, content, terms, menus and Site Editor changes, with who made each change and whether it can be undone. Filter by event, object, user, source or date.', 'activity-log-with-undo' ),
			array(
				'type'                 => 'object',
				'properties'           => array(
					'event'         => array(
						'type'        => 'string',
						'description' => __( 'Event, e.g. option_updated, plugin_deactivated, post_trashed.', 'activity-log-with-undo' ),
					),
					'object_type'   => array(
						'type'        => 'string',
						'description' => __( 'Kind of object, e.g. option, plugin, user, post, page, category, menu.', 'activity-log-with-undo' ),
					),
					'object'        => array(
						'type'        => 'string',
						'description' => __( 'Object ID or name, or part of the name.', 'activity-log-with-undo' ),
					),
					'user'          => array(
						'type'        => 'integer',
						'description' => __( 'ID of the user who made the change.', 'activity-log-with-undo' ),
					),
					'via'           => array(
						'type' => 'string',
						'enum' => Context::VIAS,
					),
					'since'         => array(
						'type'        => 'string',
						'description' => __( 'Only changes on or after this date (YYYY-MM-DD or ISO 8601).', 'activity-log-with-undo' ),
					),
					'until'         => array(
						'type'        => 'string',
						'description' => __( 'Only changes on or before this date.', 'activity-log-with-undo' ),
					),
					'search'        => array( 'type' => 'string' ),
					'undoable_only' => array(
						'type'    => 'boolean',
						'default' => false,
					),
					'limit'         => array(
						'type'    => 'integer',
						'minimum' => 1,
						'maximum' => 100,
						'default' => 20,
					),
					'page'          => array(
						'type'    => 'integer',
						'minimum' => 1,
						'default' => 1,
					),
				),
				'additionalProperties' => false,
				'default'              => array(),
			),
			array(
				'type'  => 'array',
				'items' => $entry,
			),
			array( $this, 'list_activity' ),
			$can,
			array(
				'readonly'   => true,
				'idempotent' => true,
			)
		);

		$this->ability(
			'get-entry',
			__( 'Get a log entry', 'activity-log-with-undo' ),
			__( 'One change with its before and after values, whether it can be undone, and whether the thing changed again since (an undo would then overwrite that).', 'activity-log-with-undo' ),
			array(
				'type'                 => 'object',
				'properties'           => array(
					'id' => array(
						'type'    => 'integer',
						'minimum' => 1,
					),
				),
				'required'             => array( 'id' ),
				'additionalProperties' => false,
			),
			array(
				'type'       => 'object',
				'properties' => $entry['properties'] + array(
					'before'        => array(
						'type'        => array( 'object', 'array', 'string', 'number', 'boolean', 'null' ),
						'description' => 'State before the change.',
					),
					'after'         => array(
						'type'        => array( 'object', 'array', 'string', 'number', 'boolean', 'null' ),
						'description' => 'State after the change.',
					),
					'can_undo'      => array( 'type' => 'boolean' ),
					'reason'        => array(
						'type'        => 'string',
						'description' => 'Why it cannot be undone, when it cannot.',
					),
					'changed_since' => array( 'type' => 'boolean' ),
					'conflicts'     => array( 'type' => 'array' ),
					'change_count'  => array(
						'type'        => 'integer',
						'description' => 'Changes in the same change set, undone together.',
					),
				),
			),
			array( $this, 'get_entry' ),
			$can,
			array(
				'readonly'   => true,
				'idempotent' => true,
			)
		);

		$this->ability(
			'undo-change',
			__( 'Undo a change', 'activity-log-with-undo' ),
			__( 'Puts things back the way they were before a logged change, together with the other changes saved at the same time. Refuses when things changed again since, unless force is true. The undo is logged too, so it can be undone.', 'activity-log-with-undo' ),
			array(
				'type'                 => 'object',
				'properties'           => array(
					'id'    => array(
						'type'    => 'integer',
						'minimum' => 1,
					),
					'force' => array(
						'type'        => 'boolean',
						'default'     => false,
						'description' => __( 'Undo even when things changed since. That overwrites the later change.', 'activity-log-with-undo' ),
					),
				),
				'required'             => array( 'id' ),
				'additionalProperties' => false,
			),
			array(
				'type'       => 'object',
				'properties' => array(
					'undone'  => array(
						'type'  => 'array',
						'items' => array( 'type' => 'integer' ),
					),
					'failed'  => array(
						'type'        => 'object',
						'description' => 'Entry ID => reason, for entries that could not be undone.',
					),
					'entries' => array(
						'type'        => 'array',
						'items'       => array( 'type' => 'integer' ),
						'description' => 'New entries that log the undo.',
					),
					'forced'  => array( 'type' => 'boolean' ),
				),
			),
			array( $this, 'undo_change' ),
			$can,
			array(
				'readonly'    => false,
				'destructive' => true,
				'idempotent'  => false,
			)
		);
	}

	/**
	 * Ability: entries matching filters.
	 *
	 * @param array|null $input Ability input.
	 * @return array[]
	 */
	public function list_activity( $input = null ): array {
		$input = (array) $input;
		$found = $this->store->find(
			array(
				'event'       => (string) ( $input['event'] ?? '' ),
				'object_type' => (string) ( $input['object_type'] ?? '' ),
				'object'      => (string) ( $input['object'] ?? '' ),
				'user'        => isset( $input['user'] ) ? (int) $input['user'] : '',
				'via'         => (string) ( $input['via'] ?? '' ),
				'since'       => self::date( (string) ( $input['since'] ?? '' ) ),
				'until'       => self::date( (string) ( $input['until'] ?? '' ), true ),
				'search'      => (string) ( $input['search'] ?? '' ),
				'undoable'    => ! empty( $input['undoable_only'] ),
				'per_page'    => max( 1, min( 100, (int) ( $input['limit'] ?? 20 ) ) ),
				'page'        => max( 1, (int) ( $input['page'] ?? 1 ) ),
			)
		);

		return array_map( array( $this, 'summary' ), $found['entries'] );
	}

	/**
	 * Ability: one entry with its states and undo status.
	 *
	 * @param array|null $input Ability input.
	 * @return array|\WP_Error
	 */
	public function get_entry( $input = null ) {
		$entry = $this->store->get( (int) ( ( (array) $input )['id'] ?? 0 ) );
		if ( ! $entry ) {
			return new \WP_Error( 'activity_log_with_undo_not_found', __( 'That log entry does not exist.', 'activity-log-with-undo' ), array( 'status' => 404 ) );
		}

		return $this->details( $entry );
	}

	/**
	 * An entry with everything an agent needs before undoing it.
	 *
	 * @param Entry $entry Entry.
	 * @return array
	 */
	public function details( Entry $entry ): array {
		$check = $this->undoer->check( $entry->id );
		$data  = $this->summary( $entry ) + array(
			'before' => $entry->before(),
			'after'  => $entry->after(),
		);

		if ( $check instanceof \WP_Error ) {
			return $data + array(
				'can_undo'      => false,
				'reason'        => $check->get_error_message(),
				'changed_since' => false,
				'conflicts'     => array(),
				'change_count'  => 1,
			);
		}

		$blocked = $check['blocked'][ $entry->id ] ?? null;

		return $data + array(
			'can_undo'      => ! $blocked && count( $check['blocked'] ) < count( $check['entries'] ),
			'reason'        => $blocked ? $blocked->get_error_message() : '',
			'changed_since' => (bool) $check['conflicts'],
			'conflicts'     => $check['conflicts'],
			'change_count'  => count( $check['entries'] ),
		);
	}

	/**
	 * Ability: undoes a change.
	 *
	 * @param array|null $input Ability input.
	 * @return array|\WP_Error
	 */
	public function undo_change( $input = null ) {
		$input = (array) $input;

		return $this->undoer->undo( (int) ( $input['id'] ?? 0 ), ! empty( $input['force'] ) );
	}

	/**
	 * An entry with its description.
	 *
	 * @param Entry $entry Entry.
	 * @return array
	 */
	public function summary( Entry $entry ): array {
		$data                = $entry->to_array();
		$data['description'] = $this->registry->describe( $entry );

		return $data;
	}

	/**
	 * A date from input as UTC `Y-m-d H:i:s`, or ''.
	 *
	 * @param string $value  Date, e.g. 2026-10-01, an ISO 8601 time or "7 days ago".
	 * @param bool   $end    Use the end of a bare date.
	 * @return string
	 */
	public static function date( string $value, bool $end = false ): string {
		$value = trim( $value );
		if ( '' === $value ) {
			return '';
		}
		if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) {
			$value .= $end ? ' 23:59:59' : ' 00:00:00';
			try {
				return ( new \DateTimeImmutable( $value, wp_timezone() ) )->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
			} catch ( \Exception $e ) {
				return '';
			}
		}
		$time = strtotime( $value );

		return false === $time ? '' : gmdate( 'Y-m-d H:i:s', $time );
	}

	/**
	 * Registers one ability in the category.
	 *
	 * @param string   $name        Name without the namespace.
	 * @param string   $label       Label.
	 * @param string   $description What it does, for people and agents.
	 * @param array    $input       Input schema.
	 * @param array    $output      Output schema.
	 * @param callable $execute     Execute callback.
	 * @param callable $permission  Permission callback.
	 * @param array    $annotations readonly, destructive, idempotent.
	 */
	private function ability( string $name, string $label, string $description, array $input, array $output, callable $execute, callable $permission, array $annotations ): void {
		wp_register_ability(
			self::CATEGORY . '/' . $name,
			array(
				'label'               => $label,
				'description'         => $description,
				'category'            => self::CATEGORY,
				'input_schema'        => $input,
				'output_schema'       => $output,
				'execute_callback'    => $execute,
				'permission_callback' => $permission,
				'meta'                => array(
					'annotations'  => $annotations,
					'show_in_rest' => true,
					// Clients such as the MCP adapter only list abilities marked public:
					// `public` from WordPress 7.1, `mcp.public` for the adapter on 6.9 and 7.0.
					'public'       => true,
					'mcp'          => array( 'public' => true ),
				),
			)
		);
	}
}
