<?php
/**
 * Changes made by other Axle plugins.
 *
 * @package AryanX\Axle\ActivityLogWithUndo
 */

namespace AryanX\Axle\ActivityLogWithUndo\Recorders;

use AryanX\Axle\ActivityLogWithUndo\Entry;
use AryanX\Axle\ActivityLogWithUndo\Integrations\CategoryTagTrash;
use AryanX\Axle\ActivityLogWithUndo\Integrations\Siblings;
use AryanX\Axle\ActivityLogWithUndo\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Logs what Category & Tag Trash, Leftovers, Config Sync and Block Restore do,
 * so a roll back includes it. The Undo goes to the plugin that owns the
 * change, which knows how to put its own data back. Nothing is logged for a
 * plugin that is not active.
 */
final class SiblingsRecorder extends BaseRecorder {

	/**
	 * Category & Tag Trash.
	 *
	 * @var CategoryTagTrash
	 */
	private CategoryTagTrash $trash;

	/**
	 * Sets up the recorder.
	 *
	 * @param Logger           $logger Writes entries.
	 * @param CategoryTagTrash $trash  Category & Tag Trash integration.
	 */
	public function __construct( Logger $logger, CategoryTagTrash $trash ) {
		parent::__construct( $logger );
		$this->trash = $trash;
	}

	/**
	 * Recorder ID.
	 *
	 * @return string
	 */
	public function id(): string {
		return 'siblings';
	}

	/**
	 * Name on the settings screen.
	 *
	 * @return string
	 */
	public function label(): string {
		return __( 'Other Axle plugins', 'activity-log-with-undo' );
	}

	/**
	 * Events.
	 *
	 * @return array<string, string>
	 */
	public function events(): array {
		return array(
			'ctt_merged'            => __( 'Terms merged', 'activity-log-with-undo' ),
			'ctt_restored'          => __( 'Term restored from the trash', 'activity-log-with-undo' ),
			'leftovers_trashed'     => __( 'Leftovers moved to the trash', 'activity-log-with-undo' ),
			'leftovers_restored'    => __( 'Leftovers restored', 'activity-log-with-undo' ),
			'config_sync_applied'   => __( 'Config Sync applied', 'activity-log-with-undo' ),
			'block_restore_applied' => __( 'Block restored from a revision', 'activity-log-with-undo' ),
		);
	}

	/**
	 * Hooks into the other plugins. Hooks for a plugin that is not there never fire.
	 */
	public function register(): void {
		$this->trash->register();
		$this->trash->log_merges();
		add_action( 'axle_category_tag_trash_merged', array( $this, 'merged' ), 10, 4 );
		add_action( 'axle_category_tag_trash_restored', array( $this, 'term_restored' ), 10, 4 );
		add_action( 'axle_leftovers_trashed', array( $this, 'leftovers_trashed' ), 10, 2 );
		add_action( 'axle_leftovers_restored', array( $this, 'leftovers_restored' ), 10, 2 );
		add_action( 'axle_config_sync_apply_finished', array( $this, 'config_applied' ), 10, 1 );
		add_action( 'axle_block_restore_restored', array( $this, 'block_restored' ), 10, 4 );
	}

	/**
	 * Two terms were merged.
	 *
	 * @param int   $item_id Trash item of the merged-away term.
	 * @param mixed $source  Merged-away term (WP_Term).
	 * @param mixed $target  Term that received the posts (WP_Term).
	 * @param int   $moved   Objects moved.
	 */
	public function merged( $item_id, $source, $target, $moved = 0 ): void {
		if ( ! $source instanceof \WP_Term || ! $target instanceof \WP_Term ) {
			return;
		}
		$this->record(
			'ctt_merged',
			$target->taxonomy,
			$target->term_id,
			$target->name,
			array(
				'ctt_item'  => (int) $item_id,
				'source'    => $source->name,
				'source_id' => (int) $source->term_id,
				'target'    => $target->name,
				'target_id' => (int) $target->term_id,
			),
			array(
				'merged' => true,
				'moved'  => (int) $moved,
			),
			(int) $item_id > 0
		);
	}

	/**
	 * A term came back from the trash. Logged only: the term itself is logged as created, and undoing that puts it back in the trash.
	 *
	 * @param int    $term_id    Term ID.
	 * @param string $taxonomy   Taxonomy.
	 * @param mixed  $snapshot   What was saved.
	 * @param array  $object_ids Objects re-attached.
	 */
	public function term_restored( $term_id, $taxonomy, $snapshot = null, $object_ids = array() ): void {
		$name = is_object( $snapshot ) && isset( $snapshot->name ) ? (string) $snapshot->name : '#' . (int) $term_id;
		$this->record( 'ctt_restored', (string) $taxonomy, (int) $term_id, $name, null, array( 'objects' => count( (array) $object_ids ) ), false );
	}

	/**
	 * Leftovers were moved to the trash.
	 *
	 * @param string $batch Batch ID.
	 * @param array  $rows  Trash rows.
	 */
	public function leftovers_trashed( $batch, $rows = array() ): void {
		$items = array();
		foreach ( array_slice( (array) $rows, 0, 20 ) as $row ) {
			$row     = (array) $row;
			$items[] = (string) ( $row['item_type'] ?? '' ) . ': ' . (string) ( $row['name'] ?? '' );
		}
		$this->record(
			'leftovers_trashed',
			'leftovers',
			(string) $batch,
			/* translators: %d: number of items. */
			sprintf( _n( '%d leftover', '%d leftovers', count( (array) $rows ), 'activity-log-with-undo' ), count( (array) $rows ) ),
			array(
				'batch' => (string) $batch,
				'count' => count( (array) $rows ),
				'items' => $items,
			),
			array( 'trashed' => true ),
			true
		);
	}

	/**
	 * Leftovers were restored.
	 *
	 * @param array  $rows  Rows restored.
	 * @param string $batch Batch ID, or ''.
	 */
	public function leftovers_restored( $rows, $batch = '' ): void {
		$this->record(
			'leftovers_restored',
			'leftovers',
			(string) $batch,
			/* translators: %d: number of items. */
			sprintf( _n( '%d leftover', '%d leftovers', count( (array) $rows ), 'activity-log-with-undo' ), count( (array) $rows ) ),
			null,
			array( 'restored' => count( (array) $rows ) ),
			false
		);
	}

	/**
	 * Config Sync finished applying a change set.
	 *
	 * @param int $changeset_id Change set ID.
	 */
	public function config_applied( $changeset_id ): void {
		$engine = Siblings::config_engine();
		$set    = $engine && method_exists( $engine, 'changesets' ) ? $engine->changesets()->get( (int) $changeset_id ) : null;
		$count  = is_array( $set ) ? count( (array) ( $set['items'] ?? array() ) ) : 0;
		$this->record(
			'config_sync_applied',
			'config_sync',
			(int) $changeset_id,
			/* translators: %d: change set number. */
			sprintf( __( 'change set #%d', 'activity-log-with-undo' ), (int) $changeset_id ),
			array(
				'changeset' => (int) $changeset_id,
				'items'     => $count,
			),
			array( 'status' => 'applied' ),
			true
		);
	}

	/**
	 * Block Restore put a block back from a revision. The post edit is logged as a content edit, so this entry only says where it came from.
	 *
	 * @param int    $post_id     Post ID.
	 * @param mixed  $block       Block.
	 * @param int    $revision_id Revision ID.
	 * @param string $mode        Mode.
	 */
	public function block_restored( $post_id, $block = null, $revision_id = 0, $mode = '' ): void {
		$post = get_post( (int) $post_id );
		if ( ! $post ) {
			return;
		}
		$name = is_array( $block ) && ! empty( $block['blockName'] ) ? (string) $block['blockName'] : '';
		$this->record(
			'block_restore_applied',
			$post->post_type,
			$post->ID,
			$post->post_title,
			null,
			array(
				'revision' => (int) $revision_id,
				'block'    => $name,
				'mode'     => (string) $mode,
			),
			false
		);
	}

	/**
	 * Whether the change can be reversed, by the plugin that owns it.
	 *
	 * @param Entry $entry Entry.
	 * @return true|\WP_Error
	 */
	public function can_undo( Entry $entry ) {
		switch ( $entry->event ) {
			case 'ctt_merged':
				$item = (int) ( ( (array) $entry->before() )['ctt_item'] ?? 0 );
				if ( ! $this->trash->active() ) {
					return self::refuse( __( 'The merged term is kept in Category & Tag Trash, which is not active. Activate it to undo the merge.', 'activity-log-with-undo' ) );
				}
				if ( ! $item || ! $this->trash->has_item( $item ) ) {
					return self::refuse( __( 'The merged term is no longer in the Category & Tag Trash, so the merge cannot be undone here.', 'activity-log-with-undo' ) );
				}
				break;
			case 'leftovers_trashed':
				if ( ! Siblings::leftovers_trash() ) {
					return self::refuse( __( 'These leftovers are kept by Leftovers, which is not active. Activate it to restore them.', 'activity-log-with-undo' ) );
				}
				break;
			case 'config_sync_applied':
				if ( ! Siblings::config_engine() ) {
					return self::refuse( __( 'This change set belongs to Config Sync, which is not active. Activate it to undo the change set.', 'activity-log-with-undo' ) );
				}
				break;
			default:
				return self::refuse( __( 'This change is logged only.', 'activity-log-with-undo' ) );
		}

		return parent::can_undo( $entry );
	}

	/**
	 * The current state, in the shape of the after state.
	 *
	 * @param Entry $entry Entry.
	 * @return array|null
	 */
	public function current_state( Entry $entry ): ?array {
		$before = (array) $entry->before();

		switch ( $entry->event ) {
			case 'ctt_merged':
				return array(
					'merged' => $this->trash->has_item( (int) ( $before['ctt_item'] ?? 0 ) ),
					'moved'  => (int) ( ( (array) $entry->after() )['moved'] ?? 0 ),
				);
			case 'leftovers_trashed':
				$trash = Siblings::leftovers_trash();
				$rows  = $trash && method_exists( $trash, 'rows' ) ? (array) $trash->rows( array( 'batch_id' => (string) ( $before['batch'] ?? '' ) ) ) : array();
				foreach ( $rows as $row ) {
					if ( 'trashed' === ( (array) $row )['status'] ) {
						return array( 'trashed' => true );
					}
				}
				return array( 'trashed' => false );
			case 'config_sync_applied':
				$engine = Siblings::config_engine();
				$set    = $engine && method_exists( $engine, 'changesets' ) ? $engine->changesets()->get( (int) ( $before['changeset'] ?? 0 ) ) : null;
				return array( 'status' => is_array( $set ) ? (string) $set['status'] : 'gone' );
		}

		return null;
	}

	/**
	 * Hands the undo to the plugin that owns the change.
	 *
	 * @param Entry $entry Entry.
	 * @return true|\WP_Error
	 */
	public function undo( Entry $entry ) {
		$before = (array) $entry->before();

		switch ( $entry->event ) {
			case 'ctt_merged':
				return $this->trash->restore( (int) ( $before['ctt_item'] ?? 0 ) );

			case 'leftovers_trashed':
				$trash = Siblings::leftovers_trash();
				if ( ! $trash || ! method_exists( $trash, 'restore' ) ) {
					return self::refuse( __( 'Leftovers is not active.', 'activity-log-with-undo' ) );
				}
				$result = $trash->restore( (string) ( $before['batch'] ?? '' ) );
				if ( $result instanceof \WP_Error ) {
					return $result;
				}

				return empty( $result['failed'] ) ? true : self::refuse( implode( ' ', array_map( 'strval', array_column( (array) $result['failed'], 'message' ) ) ) );

			case 'config_sync_applied':
				$engine = Siblings::config_engine();
				if ( ! $engine || ! method_exists( $engine, 'undo' ) ) {
					return self::refuse( __( 'Config Sync is not active.', 'activity-log-with-undo' ) );
				}
				$result = $engine->undo( (int) ( $before['changeset'] ?? 0 ), false, 'admin' );

				return $result instanceof \WP_Error ? $result : true;
		}

		return self::refuse( __( 'This change cannot be undone.', 'activity-log-with-undo' ) );
	}

	/**
	 * One line about the change.
	 *
	 * @param Entry $entry Entry.
	 * @return string
	 */
	public function describe( Entry $entry ): string {
		$before = (array) $entry->before();
		$after  = (array) $entry->after();
		$name   = '' !== $entry->object_label ? $entry->object_label : '#' . $entry->object_id;

		switch ( $entry->event ) {
			case 'ctt_merged':
				/* translators: 1: merged-away term, 2: term it went into, 3: number of posts moved. */
				return sprintf( __( 'Merged "%1$s" into "%2$s" (%3$d moved)', 'activity-log-with-undo' ), (string) ( $before['source'] ?? '' ), (string) ( $before['target'] ?? $name ), (int) ( $after['moved'] ?? 0 ) );
			case 'ctt_restored':
				/* translators: %s: term name. */
				return sprintf( __( 'Restored "%s" from the Category & Tag Trash', 'activity-log-with-undo' ), $name );
			case 'leftovers_trashed':
				/* translators: %s: for example "3 leftovers". */
				return sprintf( __( 'Moved %s to the Leftovers trash', 'activity-log-with-undo' ), $name );
			case 'leftovers_restored':
				/* translators: %s: for example "3 leftovers". */
				return sprintf( __( 'Restored %s from the Leftovers trash', 'activity-log-with-undo' ), $name );
			case 'config_sync_applied':
				/* translators: 1: change set, 2: number of items. */
				return sprintf( _n( 'Config Sync applied %1$s (%2$d item)', 'Config Sync applied %1$s (%2$d items)', (int) ( $before['items'] ?? 0 ), 'activity-log-with-undo' ), $name, (int) ( $before['items'] ?? 0 ) );
			default:
				/* translators: %s: post title. */
				return sprintf( __( 'Block Restore put a block back from a revision in "%s"', 'activity-log-with-undo' ), $name );
		}
	}
}
