<?php
/**
 * Reads and writes log entries.
 *
 * @package AryanX\ActivityLogWithUndo
 */

namespace AryanX\ActivityLogWithUndo;

defined( 'ABSPATH' ) || exit;

/**
 * Database access for the log table.
 *
 * Reads go through the object cache, keyed on a "last changed" stamp that
 * every write bumps, so list screens and counts cost one query per change.
 */
class Store {

	public const CACHE_GROUP = 'activity_log_with_undo';

	/**
	 * Most entries a single query returns.
	 */
	public const MAX_PER_PAGE = 500;

	/**
	 * Adds an entry.
	 *
	 * @param array $row Column values.
	 * @return int New entry ID, or 0 on failure.
	 */
	public function insert( array $row ): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- The plugin's own table; the cache is bumped below.
		$ok = $wpdb->insert( Schema::table(), $row, self::formats( $row ) );
		$this->bump();

		return $ok ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * Changes columns of an entry.
	 *
	 * @param int   $id     Entry ID.
	 * @param array $fields Column values.
	 * @return bool
	 */
	public function update( int $id, array $fields ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own table; the cache is bumped below.
		$ok = $wpdb->update( Schema::table(), $fields, array( 'id' => $id ), self::formats( $fields ), array( '%d' ) );
		$this->bump();

		return false !== $ok;
	}

	/**
	 * Column formats for insert and update.
	 *
	 * WordPress treats columns named like core ones (object_id, parent) as
	 * numbers unless told otherwise, so every column gets its format here.
	 *
	 * @param array $row Column values.
	 * @return string[]
	 */
	private static function formats( array $row ): array {
		$numbers = array( 'id', 'actor_user_id', 'undoable', 'undone_by', 'undo_of', 'agent_request', 'approved_by' );

		return array_map(
			static function ( $column ) use ( $numbers ) {
				return in_array( $column, $numbers, true ) ? '%d' : '%s';
			},
			array_keys( $row )
		);
	}

	/**
	 * Marks an entry as reversed by another one.
	 *
	 * @param int $id Entry ID.
	 * @param int $by The entry that reversed it.
	 */
	public function mark_undone( int $id, int $by ): void {
		$this->update( $id, array( 'undone_by' => $by ) );
	}

	/**
	 * One entry.
	 *
	 * @param int $id Entry ID.
	 * @return Entry|null
	 */
	public function get( int $id ): ?Entry {
		global $wpdb;

		$key = 'entry:' . $id . ':' . $this->last_changed();
		$row = wp_cache_get( $key, self::CACHE_GROUP );
		if ( false === $row ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- The plugin's own table, cached above.
			$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', Schema::table(), $id ), ARRAY_A );
			$row = $row ? $row : array();
			wp_cache_set( $key, $row, self::CACHE_GROUP );
		}

		return $row ? Entry::from_row( $row ) : null;
	}

	/**
	 * Entries matching filters, newest first, with the total count.
	 *
	 * @param array $args event, object_type, object_id, object (ID or name, partial), user, via,
	 *                    since and until (UTC `Y-m-d H:i:s`), search, change_set, undoable, per_page, page, order.
	 * @return array{entries: Entry[], total: int}
	 */
	public function find( array $args = array() ): array {
		global $wpdb;

		$args = wp_parse_args(
			$args,
			array(
				'per_page' => 20,
				'page'     => 1,
				'order'    => 'DESC',
			)
		);

		list( $where, $values ) = $this->where( $args );

		$per_page = max( 1, min( self::MAX_PER_PAGE, (int) $args['per_page'] ) );
		$offset   = ( max( 1, (int) $args['page'] ) - 1 ) * $per_page;
		$order    = 'ASC' === strtoupper( (string) $args['order'] ) ? 'ASC' : 'DESC';
		$key      = 'find:' . md5( (string) wp_json_encode( array( $where, $values, $per_page, $offset, $order ) ) ) . ':' . $this->last_changed();
		$cached   = wp_cache_get( $key, self::CACHE_GROUP );

		if ( false === $cached ) {
			$table = Schema::table();
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $where holds only fixed column names and placeholders built in where(), $order is ASC or DESC; the result is cached.
			$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM %i WHERE {$where} ORDER BY id {$order} LIMIT %d OFFSET %d", array_merge( array( $table ), $values, array( $per_page, $offset ) ) ), ARRAY_A );
			$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE {$where}", array_merge( array( $table ), $values ) ) );
			// phpcs:enable
			$cached = array(
				'rows'  => $rows ? $rows : array(),
				'total' => $total,
			);
			wp_cache_set( $key, $cached, self::CACHE_GROUP );
		}

		return array(
			'entries' => array_map( array( Entry::class, 'from_row' ), $cached['rows'] ),
			'total'   => (int) $cached['total'],
		);
	}

	/**
	 * Every entry in a change set, oldest first.
	 *
	 * @param string $change_set Change set ID.
	 * @return Entry[]
	 */
	public function change_set( string $change_set ): array {
		if ( '' === $change_set ) {
			return array();
		}

		return $this->find(
			array(
				'change_set' => $change_set,
				'per_page'   => self::MAX_PER_PAGE,
				'order'      => 'ASC',
			)
		)['entries'];
	}

	/**
	 * The newest change made by a user that can still be undone.
	 *
	 * @param int $user_id User ID.
	 * @return Entry|null
	 */
	public function latest_undoable( int $user_id ): ?Entry {
		$found = $this->find(
			array(
				'user'     => $user_id,
				'undoable' => true,
				'per_page' => 1,
			)
		);

		return $found['entries'][0] ?? null;
	}

	/**
	 * Number of entries in each of the given change sets.
	 *
	 * @param string[] $sets Change set IDs.
	 * @return array<string, int>
	 */
	public function set_sizes( array $sets ): array {
		global $wpdb;

		$sets = array_values( array_unique( array_filter( array_map( 'strval', $sets ) ) ) );
		if ( ! $sets ) {
			return array();
		}

		$key   = 'sets:' . md5( implode( ',', $sets ) ) . ':' . $this->last_changed();
		$sizes = wp_cache_get( $key, self::CACHE_GROUP );
		if ( false === $sizes ) {
			$in = implode( ',', array_fill( 0, count( $sets ), '%s' ) );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $in is a list of placeholders; cached above.
			$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT change_set, COUNT(*) AS n FROM %i WHERE change_set IN ({$in}) GROUP BY change_set", array_merge( array( Schema::table() ), $sets ) ), ARRAY_A );
			$sizes = array();
			foreach ( (array) $rows as $row ) {
				$sizes[ (string) $row['change_set'] ] = (int) $row['n'];
			}
			wp_cache_set( $key, $sizes, self::CACHE_GROUP );
		}

		return (array) $sizes;
	}

	/**
	 * Users who appear in the log.
	 *
	 * @return int[]
	 */
	public function actors(): array {
		global $wpdb;

		$key = 'actors:' . $this->last_changed();
		$ids = wp_cache_get( $key, self::CACHE_GROUP );
		if ( false === $ids ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- The plugin's own table, cached above.
			$ids = $wpdb->get_col( $wpdb->prepare( 'SELECT DISTINCT actor_user_id FROM %i WHERE actor_user_id > 0 LIMIT 200', Schema::table() ) );
			wp_cache_set( $key, $ids, self::CACHE_GROUP );
		}

		return array_map( 'intval', (array) $ids );
	}

	/**
	 * Number of entries.
	 *
	 * @return int
	 */
	public function count(): int {
		return $this->find( array( 'per_page' => 1 ) )['total'];
	}

	/**
	 * Deletes up to $limit entries older than a date.
	 *
	 * @param string $cutoff UTC `Y-m-d H:i:s`.
	 * @param int    $limit  Most rows to delete.
	 * @return int Rows deleted.
	 */
	public function purge_before( string $cutoff, int $limit ): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Batched clean-up of the plugin's own table; the cache is bumped below.
		$deleted = (int) $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE created_gmt < %s ORDER BY id ASC LIMIT %d', Schema::table(), $cutoff, max( 1, $limit ) ) );
		$this->bump();

		return $deleted;
	}

	/**
	 * Entries made by a user, oldest first, for the personal data exporter.
	 *
	 * @param int $user_id User ID.
	 * @param int $limit   Page size.
	 * @param int $offset  Rows to skip.
	 * @return Entry[]
	 */
	public function by_user( int $user_id, int $limit, int $offset ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Paged export, run once per request.
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE actor_user_id = %d ORDER BY id ASC LIMIT %d OFFSET %d', Schema::table(), $user_id, $limit, $offset ), ARRAY_A );

		return array_map( array( Entry::class, 'from_row' ), $rows ? $rows : array() );
	}

	/**
	 * Removes a user from up to $limit entries: who did it, from where, and their profile data.
	 *
	 * @param int $user_id User ID.
	 * @param int $limit   Batch size.
	 * @return int Entries changed.
	 */
	public function anonymise_user( int $user_id, int $limit ): int {
		global $wpdb;

		$table = Schema::table();
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Privacy eraser on the plugin's own table, in batches; the cache is bumped below.
		$changed  = (int) $wpdb->query( $wpdb->prepare( "UPDATE %i SET actor_user_id = %d, ip = '' WHERE actor_user_id = %d LIMIT %d", $table, Entry::ERASED, $user_id, $limit ) );
		$changed += (int) $wpdb->query( $wpdb->prepare( "UPDATE %i SET object_label = %s, before_state = NULL, after_state = NULL, undoable = 0 WHERE object_type = 'user' AND object_id = %s AND object_label <> %s LIMIT %d", $table, '(removed)', (string) $user_id, '(removed)', $limit ) );
		$changed += (int) $wpdb->query( $wpdb->prepare( 'UPDATE %i SET approved_by = %d WHERE approved_by = %d LIMIT %d', $table, Entry::ERASED, $user_id, $limit ) );
		// phpcs:enable
		$this->bump();

		return $changed;
	}

	/**
	 * How many entries each option has, for the clean-up of entries version 1 logged.
	 *
	 * @return array<string, int> Option name => entries. Network options are keyed `network:<name>`.
	 */
	public function option_entry_counts(): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-off scan of the plugin's own table, stored by the caller.
		$rows   = $wpdb->get_results( $wpdb->prepare( "SELECT object_type, object_id, COUNT(*) AS n FROM %i WHERE object_type IN ('option','network_option') GROUP BY object_type, object_id", Schema::table() ), ARRAY_A );
		$counts = array();
		foreach ( (array) $rows as $row ) {
			$key            = ( 'network_option' === $row['object_type'] ? 'network:' : '' ) . (string) $row['object_id'];
			$counts[ $key ] = (int) $row['n'];
		}

		return $counts;
	}

	/**
	 * Deletes up to $limit entries of the given options.
	 *
	 * @param string[] $names Option names; `network:<name>` for network options.
	 * @param int      $limit Most rows to delete.
	 * @return int Rows deleted.
	 */
	public function delete_option_entries( array $names, int $limit ): int {
		global $wpdb;

		$deleted = 0;
		foreach ( array( 'option', 'network_option' ) as $type ) {
			$prefix = 'network_option' === $type ? 'network:' : '';
			$list   = array();
			foreach ( $names as $name ) {
				$name = (string) $name;
				if ( '' === $prefix ? 0 !== strpos( $name, 'network:' ) : 0 === strpos( $name, $prefix ) ) {
					$list[] = substr( $name, strlen( $prefix ) );
				}
			}

			foreach ( array_chunk( $list, 100 ) as $chunk ) {
				if ( $deleted >= $limit ) {
					break 2;
				}
				$in = implode( ',', array_fill( 0, count( $chunk ), '%s' ) );
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $in is a list of placeholders; batched clean-up of the plugin's own table, the cache is bumped below.
				$deleted += (int) $wpdb->query( $wpdb->prepare( "DELETE FROM %i WHERE object_type = %s AND object_id IN ({$in}) LIMIT %d", array_merge( array( Schema::table(), $type ), $chunk, array( max( 1, $limit - $deleted ) ) ) ) );
			}
		}
		$this->bump();

		return $deleted;
	}

	/**
	 * Size of the table on disk in bytes, as the database reports it. 0 when unknown.
	 *
	 * Cached for a few minutes, so logging a change does not ask every time.
	 *
	 * @return int
	 */
	public function size(): int {
		global $wpdb;

		$cached = get_transient( 'activity_log_with_undo_size' );
		if ( false !== $cached ) {
			return (int) $cached;
		}

		// Some hosts and SQLite do not answer this; the size is then unknown, not an error on screen.
		$quiet = $wpdb->suppress_errors();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The size of the plugin's own table, cached in a transient below.
		$size = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT data_length + index_length FROM information_schema.TABLES WHERE table_schema = DATABASE() AND table_name = %s', Schema::table() ) );
		$wpdb->suppress_errors( $quiet );
		set_transient( 'activity_log_with_undo_size', $size, 5 * MINUTE_IN_SECONDS );

		return $size;
	}

	/**
	 * Forgets the cached table size, after a clean-up.
	 */
	public function forget_size(): void {
		delete_transient( 'activity_log_with_undo_size' );
	}

	/**
	 * Empties the table.
	 */
	public function truncate(): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Emptying the plugin's own table; the cache is bumped below.
		$wpdb->query( $wpdb->prepare( 'TRUNCATE TABLE %i', Schema::table() ) );
		$this->bump();
		$this->forget_size();
	}

	/**
	 * Builds the WHERE clause for find().
	 *
	 * @param array $args Filters.
	 * @return array{0: string, 1: array}
	 */
	private function where( array $args ): array {
		global $wpdb;

		$where  = array( '1=1' );
		$values = array();

		foreach ( array(
			'event',
			'object_type',
			'object_id',
			'change_set',
			'actor_via' => 'via',
		) as $column => $arg ) {
			$column = is_int( $column ) ? $arg : $column;
			if ( isset( $args[ $arg ] ) && '' !== $args[ $arg ] && array() !== $args[ $arg ] ) {
				$list     = array_map( 'strval', (array) $args[ $arg ] );
				$where[]  = "{$column} IN (" . implode( ',', array_fill( 0, count( $list ), '%s' ) ) . ')';
				$values[] = $list;
			}
		}
		if ( isset( $args['user'] ) && '' !== $args['user'] ) {
			$where[]  = 'actor_user_id = %d';
			$values[] = (int) $args['user'];
		}
		if ( ! empty( $args['since'] ) ) {
			$where[]  = 'created_gmt >= %s';
			$values[] = (string) $args['since'];
		}
		if ( ! empty( $args['until'] ) ) {
			$where[]  = 'created_gmt <= %s';
			$values[] = (string) $args['until'];
		}
		if ( ! empty( $args['undoable'] ) ) {
			$where[] = 'undoable = 1 AND undone_by IS NULL';
		}
		if ( isset( $args['object'] ) && '' !== $args['object'] ) {
			$like     = '%' . $wpdb->esc_like( (string) $args['object'] ) . '%';
			$where[]  = '(object_id = %s OR object_label LIKE %s)';
			$values[] = (string) $args['object'];
			$values[] = $like;
		}
		if ( isset( $args['search'] ) && '' !== $args['search'] ) {
			$like     = '%' . $wpdb->esc_like( (string) $args['search'] ) . '%';
			$where[]  = '(object_label LIKE %s OR object_id LIKE %s OR event LIKE %s)';
			$values[] = $like;
			$values[] = $like;
			$values[] = $like;
		}

		$flat = array();
		foreach ( $values as $value ) {
			$flat = array_merge( $flat, (array) $value );
		}

		return array( implode( ' AND ', $where ), $flat );
	}

	/**
	 * Cache stamp, changed by every write.
	 *
	 * @return string
	 */
	private function last_changed(): string {
		return (string) wp_cache_get_last_changed( self::CACHE_GROUP );
	}

	/**
	 * Invalidates cached reads.
	 */
	private function bump(): void {
		wp_cache_set_last_changed( self::CACHE_GROUP );
	}
}
