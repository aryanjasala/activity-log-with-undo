<?php
/**
 * The list of logged changes.
 *
 * @package AryanX\Axle\ActivityLogWithUndo
 */

namespace AryanX\Axle\ActivityLogWithUndo\Admin;

use AryanX\Axle\ActivityLogWithUndo\Context;
use AryanX\Axle\ActivityLogWithUndo\Entry;
use AryanX\Axle\ActivityLogWithUndo\Recorders\Registry;
use AryanX\Axle\ActivityLogWithUndo\Store;
use AryanX\Axle\ActivityLogWithUndo\Undoer;

defined( 'ABSPATH' ) || exit;

/**
 * Entries with filters (event, user, source, dates, object), search, an
 * Undo or Redo button per row, and bulk undo.
 *
 * Load wp-admin/includes/class-wp-list-table.php before creating it.
 */
final class EntriesTable extends \WP_List_Table {

	public const PLURAL = 'activity_log_with_undo_entries';

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
	 * Size of each change set on this page.
	 *
	 * @var array<string, int>
	 */
	private array $set_sizes = array();

	/**
	 * Entries of the change sets on this page that hold more than one entry, keyed by change set.
	 *
	 * @var array<string, Entry[]>
	 */
	private array $sets = array();

	/**
	 * Works out what an undo reverses.
	 *
	 * @var Undoer|null
	 */
	private ?Undoer $undoer;

	/**
	 * Sets up the table.
	 *
	 * @param Store       $store    Entries.
	 * @param Registry    $registry Recorders.
	 * @param Undoer|null $undoer   Works out what an undo reverses, for the question asked first.
	 */
	public function __construct( Store $store, Registry $registry, ?Undoer $undoer = null ) {
		$this->store    = $store;
		$this->registry = $registry;
		$this->undoer   = $undoer;

		parent::__construct(
			array(
				'singular' => 'activity_log_with_undo_entry',
				'plural'   => self::PLURAL,
				'ajax'     => false,
			)
		);
	}

	/**
	 * A filter value from the URL.
	 *
	 * @param string $key Query argument.
	 * @return string
	 */
	public static function filter( string $key ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only list filters.
		return isset( $_GET[ $key ] ) ? sanitize_text_field( wp_unslash( $_GET[ $key ] ) ) : '';
	}

	/**
	 * The filters in the URL, as Store::find() arguments.
	 *
	 * @return array
	 */
	public static function query_args(): array {
		$user = self::filter( 'alu_user' );

		return array(
			'event'      => sanitize_key( self::filter( 'alu_event' ) ),
			'via'        => in_array( self::filter( 'alu_via' ), Context::VIAS, true ) ? self::filter( 'alu_via' ) : '',
			'user'       => '' === $user ? '' : (int) $user,
			'since'      => \AryanX\Axle\ActivityLogWithUndo\Abilities::date( self::filter( 'alu_from' ) ),
			'until'      => \AryanX\Axle\ActivityLogWithUndo\Abilities::date( self::filter( 'alu_to' ), true ),
			'object'     => self::filter( 'alu_object' ),
			'change_set' => preg_match( '/^[a-f0-9]{32}$/', self::filter( 'alu_set' ) ) ? self::filter( 'alu_set' ) : '',
			'search'     => self::filter( 's' ),
		);
	}

	/**
	 * Entries per page.
	 *
	 * @return int
	 */
	public static function per_page(): int {
		/**
		 * Filters how many entries Tools > Activity Log shows per page.
		 *
		 * @param int $per_page Default 20.
		 */
		return max( 1, min( Store::MAX_PER_PAGE, (int) apply_filters( 'activity_log_with_undo_list_per_page', 20 ) ) );
	}

	/**
	 * Loads the entries for this page.
	 */
	public function prepare_items(): void {
		$per_page = self::per_page();
		$found    = $this->store->find(
			self::query_args() + array(
				'per_page' => $per_page,
				'page'     => $this->get_pagenum(),
			)
		);

		$this->items     = $found['entries'];
		$this->set_sizes = $this->store->set_sizes(
			array_map(
				static function ( Entry $entry ) {
					return $entry->change_set;
				},
				$found['entries']
			)
		);

		// One query for the change sets on this page, so each Undo can name what else it undoes.
		$shared = array_keys(
			array_filter(
				$this->set_sizes,
				static function ( $size ) {
					return $size > 1;
				}
			)
		);
		if ( $shared && $this->undoer ) {
			$members = $this->store->find(
				array(
					'change_set' => $shared,
					'per_page'   => Store::MAX_PER_PAGE,
					'order'      => 'ASC',
				)
			)['entries'];
			foreach ( $members as $member ) {
				$this->sets[ $member->change_set ][] = $member;
			}
		}

		$this->_column_headers = array( $this->get_columns(), array(), array(), 'change' );
		$this->set_pagination_args(
			array(
				'total_items' => $found['total'],
				'per_page'    => $per_page,
			)
		);
	}

	/**
	 * Columns.
	 *
	 * @return array<string, string>
	 */
	public function get_columns(): array {
		return array(
			'cb'     => '<input type="checkbox">',
			'change' => __( 'Change', 'activity-log-with-undo' ),
			'user'   => __( 'Who', 'activity-log-with-undo' ),
			'when'   => __( 'When', 'activity-log-with-undo' ),
			'undo'   => __( 'Undo', 'activity-log-with-undo' ),
		);
	}

	/**
	 * Bulk actions.
	 *
	 * @return array<string, string>
	 */
	protected function get_bulk_actions(): array {
		return array( 'undo' => __( 'Undo', 'activity-log-with-undo' ) );
	}

	/**
	 * Message when nothing matches.
	 */
	public function no_items(): void {
		esc_html_e( 'No changes logged yet. Change a setting, a post or a plugin and it shows up here.', 'activity-log-with-undo' );
	}

	/**
	 * Checkbox column.
	 *
	 * @param Entry $item Entry.
	 * @return string
	 */
	protected function column_cb( $item ): string {
		if ( ! $item->undoable || $item->is_undone() ) {
			/* translators: %d: entry number. */
			return '<span class="screen-reader-text">' . esc_html( sprintf( __( 'Change #%d cannot be selected for undo', 'activity-log-with-undo' ), $item->id ) ) . '</span>';
		}

		return sprintf(
			'<label class="screen-reader-text" for="alu-entry-%1$d">%2$s</label><input type="checkbox" id="alu-entry-%1$d" name="entry[]" value="%1$d">',
			$item->id,
			/* translators: %d: entry number. */
			esc_html( sprintf( __( 'Select change #%d', 'activity-log-with-undo' ), $item->id ) )
		);
	}

	/**
	 * Description, with the event and change set under it.
	 *
	 * @param Entry $item Entry.
	 * @return string
	 */
	protected function column_change( $item ): string {

		$events = $this->registry->events();
		$size   = $this->set_sizes[ $item->change_set ] ?? 1;
		$meta   = array( esc_html( $events[ $item->event ] ?? $item->event ) );
		if ( $size > 1 ) {
			$meta[] = sprintf(
				'<a href="%1$s">%2$s</a>',
				esc_url( add_query_arg( 'alu_set', $item->change_set, LogPage::url() ) ),
				/* translators: %d: number of changes saved together. */
				esc_html( sprintf( _n( 'Saved with %d other change', 'Saved with %d other changes', $size - 1, 'activity-log-with-undo' ), $size - 1 ) )
			);
		}
		if ( $item->undo_of ) {
			$meta[] = sprintf(
				'<a href="%1$s">%2$s</a>',
				esc_url( LogPage::url( array( 'entry' => $item->undo_of ) ) ),
				/* translators: %d: entry number. */
				esc_html( sprintf( __( 'Undo of #%d', 'activity-log-with-undo' ), $item->undo_of ) )
			);
		}

		return sprintf(
			'<a class="row-title" href="%1$s">%2$s</a><div class="alu-meta">%3$s</div>%4$s',
			esc_url( LogPage::url( array( 'entry' => $item->id ) ) ),
			esc_html( $this->registry->describe( $item ) ),
			implode( ' <span aria-hidden="true">&middot;</span> ', $meta ),
			$this->row_actions(
				array(
					'details' => sprintf( '<a href="%1$s">%2$s</a>', esc_url( LogPage::url( array( 'entry' => $item->id ) ) ), esc_html__( 'Details and differences', 'activity-log-with-undo' ) ),
				)
			)
		);
	}

	/**
	 * Who made the change, and through what.
	 *
	 * @param Entry $item Entry.
	 * @return string
	 */
	protected function column_user( $item ): string {
		$agent = LogPage::agent_line( $item );

		return sprintf(
			'%1$s<br><span class="alu-via alu-via-%2$s">%3$s</span>%4$s',
			esc_html( $item->actor_name() ),
			esc_attr( $item->actor_via ),
			esc_html( LogPage::via_label( $item->actor_via ) ),
			'' !== $agent ? '<div class="alu-meta alu-agent">' . esc_html( $agent ) . '</div>' : ''
		);
	}

	/**
	 * When.
	 *
	 * @param Entry $item Entry.
	 * @return string
	 */
	protected function column_when( $item ): string {
		$time = $item->timestamp();

		return sprintf(
			'<time datetime="%1$s" title="%2$s">%3$s</time>',
			esc_attr( gmdate( 'c', $time ) ),
			esc_attr( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $time ) ),
			/* translators: %s: time ago, e.g. "5 mins". */
			esc_html( sprintf( __( '%s ago', 'activity-log-with-undo' ), human_time_diff( $time ) ) )
		);
	}

	/**
	 * Undo, Redo, or why not.
	 *
	 * @param Entry $item Entry.
	 * @return string
	 */
	protected function column_undo( $item ): string {
		if ( $item->is_undone() ) {
			return sprintf(
				'<a class="alu-status alu-status-undone" href="%1$s">%2$s</a>',
				esc_url( LogPage::url( array( 'entry' => (int) $item->undone_by ) ) ),
				/* translators: %d: entry number. */
				esc_html( sprintf( __( 'Undone (#%d)', 'activity-log-with-undo' ), (int) $item->undone_by ) )
			);
		}
		if ( ! $item->undoable ) {
			return '<span class="alu-status alu-muted">' . esc_html__( 'Logged only', 'activity-log-with-undo' ) . '</span>';
		}

		return LogPage::undo_link( $item, $this->also( $item ), false );
	}

	/**
	 * Descriptions of the other changes an undo of this entry reverses.
	 *
	 * @param Entry $item Entry.
	 * @return string[]
	 */
	private function also( Entry $item ): array {
		if ( ! $this->undoer || empty( $this->sets[ $item->change_set ] ) ) {
			return array();
		}

		$also = array();
		foreach ( $this->undoer->members_of( $item, $this->sets[ $item->change_set ] ) as $member ) {
			if ( $member->id !== $item->id ) {
				$also[] = $this->registry->describe( $member );
			}
		}

		return $also;
	}

	/**
	 * Filters above the table.
	 *
	 * @param string $which top or bottom.
	 */
	protected function extra_tablenav( $which ): void {
		if ( 'top' !== $which ) {
			return;
		}

		$events = $this->registry->events();
		?>
		<div class="alignleft actions alu-filters">
			<label class="screen-reader-text" for="alu-event"><?php esc_html_e( 'Event', 'activity-log-with-undo' ); ?></label>
			<select name="alu_event" id="alu-event">
				<option value=""><?php esc_html_e( 'All events', 'activity-log-with-undo' ); ?></option>
				<?php foreach ( $this->registry->all() as $recorder ) : ?>
					<optgroup label="<?php echo esc_attr( $recorder->label() ); ?>">
						<?php foreach ( $recorder->events() as $event => $label ) : ?>
							<option value="<?php echo esc_attr( $event ); ?>" <?php selected( self::filter( 'alu_event' ), $event ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</optgroup>
				<?php endforeach; ?>
				<option value="undo" <?php selected( self::filter( 'alu_event' ), 'undo' ); ?>><?php echo esc_html( $events['undo'] ); ?></option>
			</select>

			<label class="screen-reader-text" for="alu-user"><?php esc_html_e( 'User', 'activity-log-with-undo' ); ?></label>
			<select name="alu_user" id="alu-user">
				<option value=""><?php esc_html_e( 'All users', 'activity-log-with-undo' ); ?></option>
				<option value="0" <?php selected( self::filter( 'alu_user' ), '0' ); ?>><?php esc_html_e( 'System (no user)', 'activity-log-with-undo' ); ?></option>
				<?php foreach ( $this->store->actors() as $user_id ) : ?>
					<?php $user = get_userdata( $user_id ); ?>
					<option value="<?php echo esc_attr( (string) $user_id ); ?>" <?php selected( self::filter( 'alu_user' ), (string) $user_id ); ?>><?php echo esc_html( $user ? $user->user_login : '#' . $user_id ); ?></option>
				<?php endforeach; ?>
			</select>

			<label class="screen-reader-text" for="alu-via"><?php esc_html_e( 'Made through', 'activity-log-with-undo' ); ?></label>
			<select name="alu_via" id="alu-via">
				<option value=""><?php esc_html_e( 'Any source', 'activity-log-with-undo' ); ?></option>
				<?php foreach ( Context::VIAS as $via ) : ?>
					<option value="<?php echo esc_attr( $via ); ?>" <?php selected( self::filter( 'alu_via' ), $via ); ?>><?php echo esc_html( LogPage::via_label( $via ) ); ?></option>
				<?php endforeach; ?>
			</select>

			<label for="alu-from"><?php esc_html_e( 'From', 'activity-log-with-undo' ); ?></label>
			<input type="date" name="alu_from" id="alu-from" value="<?php echo esc_attr( self::filter( 'alu_from' ) ); ?>">
			<label for="alu-to"><?php esc_html_e( 'To', 'activity-log-with-undo' ); ?></label>
			<input type="date" name="alu_to" id="alu-to" value="<?php echo esc_attr( self::filter( 'alu_to' ) ); ?>">

			<label class="screen-reader-text" for="alu-object"><?php esc_html_e( 'Object', 'activity-log-with-undo' ); ?></label>
			<input type="text" name="alu_object" id="alu-object" class="alu-object" placeholder="<?php esc_attr_e( 'Object ID or name', 'activity-log-with-undo' ); ?>" value="<?php echo esc_attr( self::filter( 'alu_object' ) ); ?>">

			<?php submit_button( __( 'Filter', 'activity-log-with-undo' ), '', 'filter_action', false, array( 'id' => 'alu-filter' ) ); ?>
		</div>
		<?php
	}
}
