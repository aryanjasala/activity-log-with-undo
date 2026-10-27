<?php
/**
 * Tests for what the log screens show: the undo question, names for people,
 * the agent line, the before and after table, and accessibility details.
 *
 * @package AryanX\Axle\ActivityLogWithUndo
 */

namespace AryanX\Axle\ActivityLogWithUndo\Tests\Unit;

use AryanX\Axle\ActivityLogWithUndo\Admin\DiffView;
use AryanX\Axle\ActivityLogWithUndo\Admin\EntriesTable;
use AryanX\Axle\ActivityLogWithUndo\Admin\LogPage;
use AryanX\Axle\ActivityLogWithUndo\Entry;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;

final class AdminViewTest extends LogTestCase {

	protected function setUp(): void {
		parent::setUp();

		Functions\when( 'wp_nonce_url' )->returnArg();
		Functions\when( 'add_query_arg' )->alias(
			static function ( $args, $url ) {
				return $url . '?' . http_build_query( $args );
			}
		);
		Functions\when( 'admin_url' )->alias(
			static function ( $path = '' ) {
				return 'https://example.com/wp-admin/' . $path;
			}
		);
		Functions\when( 'get_post_type_object' )->justReturn( null );
		Functions\when( 'get_taxonomy' )->justReturn( false );
		Functions\when( 'get_post_status_object' )->alias(
			static function ( $status ) {
				return 'publish' === $status ? (object) array( 'label' => 'Published' ) : null;
			}
		);
		Functions\when( 'wp_text_diff' )->justReturn( '<table class="diff"><tr><td>diff</td></tr></table>' );
	}

	public function test_the_undo_question_names_the_other_changes_it_undoes(): void {
		$entry = $this->entry( array( 'event' => 'term_deleted' ) );

		$alone = LogPage::undo_link( $entry, array(), false );
		$this->assertStringContainsString( 'data-alu-confirm="Undo this change?"', $alone );

		$link = LogPage::undo_link( $entry, array( 'Changed Site title', 'Changed Tagline' ), false );
		$this->assertStringContainsString( 'Undo this change? It also undoes 2 other changes saved with it: Changed Site title; Changed Tagline.', $link );
		$this->assertStringContainsString( 'data-alu-count="3"', $link );
		$this->assertStringContainsString( 'data-alu-also="[&quot;Changed Site title&quot;,&quot;Changed Tagline&quot;]"', $link );

		$many = LogPage::undo_link( $entry, array_map( 'strval', range( 1, 8 ) ), false );
		$this->assertStringContainsString( '1; 2; 3; 4; 5; and 3 more.', $many );
	}

	public function test_the_question_is_filterable(): void {
		$entry = $this->entry( array() );
		Filters\expectApplied( 'activity_log_with_undo_confirm_message' )->once()->with( 'Undo this change?', \Mockery::type( Entry::class ), array(), false )->andReturn( 'Really?' );

		$this->assertStringContainsString( 'data-alu-confirm="Really?"', LogPage::undo_link( $entry, array(), false ) );
	}

	public function test_kinds_of_objects_have_names_for_people(): void {
		Functions\when( 'get_post_type_object' )->alias(
			static function ( $type ) {
				return 'wp_template_part' === $type ? (object) array( 'labels' => (object) array( 'singular_name' => 'Template Part' ) ) : null;
			}
		);
		Functions\when( 'get_taxonomy' )->alias(
			static function ( $taxonomy ) {
				return 'category' === $taxonomy ? (object) array( 'labels' => (object) array( 'singular_name' => 'Category' ) ) : false;
			}
		);

		$this->assertSame( 'Template Part', LogPage::object_type_label( 'wp_template_part' ) );
		$this->assertSame( 'Category', LogPage::object_type_label( 'category' ) );
		$this->assertSame( 'Setting', LogPage::object_type_label( 'option' ) );
		$this->assertSame( 'gizmo', LogPage::object_type_label( 'gizmo' ) );

		Filters\expectApplied( 'activity_log_with_undo_object_type_label' )->once()->with( '', 'gizmo' )->andReturn( 'Gizmo' );
		$this->assertSame( 'Gizmo', LogPage::object_type_label( 'gizmo' ) );
	}

	public function test_an_approved_agent_change_says_which_agent_and_who_approved_it(): void {
		Functions\when( 'get_userdata' )->justReturn( (object) array( 'user_login' => 'aryan' ) );

		$this->assertSame( '', LogPage::agent_line( $this->entry( array() ) ) );
		$this->assertSame(
			'Claude, request #12, approved by aryan',
			LogPage::agent_line(
				$this->entry(
					array(
						'agent'         => 'Claude',
						'agent_request' => 12,
						'approved_by'   => 1,
					)
				)
			)
		);
		$this->assertSame( 'An AI agent, approved by (removed)', LogPage::agent_line( $this->entry( array( 'approved_by' => Entry::ERASED ) ) ) );
	}

	public function test_fields_get_names_for_people_and_statuses_their_labels(): void {
		$html = DiffView::compare(
			array(
				'post_title'  => 'Old',
				'post_status' => 'draft',
				'odd_key'     => 1,
			),
			array(
				'post_title'  => 'New',
				'post_status' => 'publish',
				'odd_key'     => 2,
			),
			'Before',
			'After'
		);

		$this->assertStringContainsString( '<th scope="row">Title</th>', $html );
		$this->assertStringContainsString( '<th scope="row">Status</th>', $html );
		$this->assertStringContainsString( 'Published', $html );
		$this->assertStringNotContainsString( '>publish<', $html );
		$this->assertStringContainsString( '<code dir="ltr">odd_key</code>', $html );
		$this->assertStringNotContainsString( 'post_title', $html );
	}

	public function test_the_field_labels_filter_renames_fields(): void {
		Filters\expectApplied( 'activity_log_with_undo_field_labels' )->once()->andReturnUsing(
			static function ( array $labels ) {
				$labels['odd_key'] = 'Odd';
				return $labels;
			}
		);

		$this->assertStringContainsString( '<th scope="row">Odd</th>', DiffView::compare( array( 'odd_key' => 1 ), array( 'odd_key' => 2 ), 'Before', 'After' ) );
	}

	public function test_the_table_header_has_text_and_code_reads_left_to_right(): void {
		$table = DiffView::compare( array( 'a' => array( 'x' => 1 ) ), array( 'a' => 'b' ), 'Before', 'After' );
		$this->assertStringContainsString( '<th scope="col"><span class="screen-reader-text">Field</span></th>', $table );
		$this->assertStringContainsString( '<pre class="alu-json" dir="ltr">', $table );
		$this->assertStringContainsString( '<td dir="auto">', $table );

		$markup = DiffView::compare( '<!-- wp:html --><p>One</p>' . str_repeat( ' x', 50 ), '<!-- wp:html --><p>Two</p>' . str_repeat( ' x', 50 ), 'Before', 'After' );
		$this->assertStringContainsString( '<div class="alu-text-diff" dir="ltr">', $markup );

		$prose = DiffView::compare( str_repeat( 'שלום ', 30 ), str_repeat( 'עולם ', 30 ), 'Before', 'After' );
		$this->assertStringContainsString( '<div class="alu-text-diff" dir="auto">', $prose );
	}

	public function test_rows_that_cannot_be_selected_still_have_a_checkbox_header_text(): void {
		$table  = ( new \ReflectionClass( EntriesTable::class ) )->newInstanceWithoutConstructor();
		$method = new \ReflectionMethod( EntriesTable::class, 'column_cb' );
		$method->setAccessible( true );

		$cell = $method->invoke( $table, $this->entry( array( 'undoable' => 0 ) ) );

		$this->assertStringContainsString( 'screen-reader-text', $cell );
		$this->assertStringContainsString( 'cannot be selected', $cell );
	}
}
