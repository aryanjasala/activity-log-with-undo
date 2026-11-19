// Needs Axle Category & Tag Trash in the test site (mounted read-only next to this plugin). Skipped without it.
const { test, expect, wp, wpEval } = require( './utils' );

const PAGE = '/wp-admin/tools.php?page=activity-log-with-undo';
const SLUG = 'axle-category-tag-trash';

const installed = () => {
	try {
		wp( 'plugin', 'is-installed', SLUG );
		return true;
	} catch ( error ) {
		return false;
	}
};
const entries = () => JSON.parse( wp( 'activity-log-with-undo', 'list', '--format=json', '--fields=id,event,description,undoable,undone_by' ) );
const termCount = ( name ) => Number( wpEval( `echo count( get_terms( array( 'taxonomy' => 'category', 'name' => ${ JSON.stringify( name ) }, 'hide_empty' => false ) ) );` ) );
const trashItems = () => Number( wp( 'axle-category-tag-trash', 'list', '--format=count' ) );

test.describe( 'Category & Tag Trash integration', () => {
	test.skip( ! installed(), 'Category & Tag Trash is not mounted in this test site.' );

	test.beforeAll( () => {
		wp( 'plugin', 'activate', SLUG );
	} );
	test.afterAll( () => {
		wp( 'plugin', 'deactivate', SLUG );
	} );
	test.beforeEach( () => {
		wpEval( 'AryanX\\Axle\\ActivityLogWithUndo\\Plugin::instance()->store()->truncate();' );
	} );

	test( 'deleting a term records its trash item, and Undo restores it once', async ( { page } ) => {
		const term = wp( 'term', 'create', 'category', 'Soups', '--porcelain' );
		const post = wp( 'post', 'create', '--post_title=Chowder', '--post_status=publish', '--porcelain' );
		wpEval( `wp_set_object_terms( ${ post }, array( ${ term } ), 'category', false );` );
		wpEval( 'AryanX\\Axle\\ActivityLogWithUndo\\Plugin::instance()->store()->truncate();' );

		wp( 'term', 'delete', 'category', term );
		const entry = entries().find( ( item ) => item.event === 'term_deleted' );
		expect( entry.description ).toContain( 'kept in Category & Tag Trash' );
		const before = JSON.parse( wpEval( `echo wp_json_encode( AryanX\\Axle\\ActivityLogWithUndo\\Plugin::instance()->store()->get( ${ entry.id } )->before() );` ) );
		expect( before.ctt_item ).toBeGreaterThan( 0 );
		expect( trashItems() ).toBe( 1 );

		await page.goto( `${ PAGE }&entry=${ entry.id }` );
		const link = page.getByRole( 'link', { name: 'Open it in the Category & Tag Trash' } );
		await expect( link ).toHaveAttribute( 'href', new RegExp( `page=${ SLUG }` ) );
		await link.click();
		await expect( page ).toHaveURL( new RegExp( `page=${ SLUG }` ) );
		await expect( page.locator( '.wrap' ) ).toContainText( 'Soups' );

		wp( 'activity-log-with-undo', 'undo', String( entry.id ) );
		expect( termCount( 'Soups' ) ).toBe( 1 );
		expect( trashItems() ).toBe( 0 );
		expect( wp( 'post', 'term', 'list', post, 'category', '--field=name' ) ).toContain( 'Soups' );
		wp( 'post', 'delete', post, '--force' );
	} );

	test( 'a merge is one entry, and Undo puts the term and its posts back through the trash', async () => {
		const source = wp( 'term', 'create', 'category', 'Stews', '--porcelain' );
		const target = wp( 'term', 'create', 'category', 'Meals', '--porcelain' );
		const post = wp( 'post', 'create', '--post_title=Goulash', '--post_status=publish', '--porcelain' );
		wpEval( `wp_set_object_terms( ${ post }, array( ${ source } ), 'category', false );` );
		wpEval( 'AryanX\\Axle\\ActivityLogWithUndo\\Plugin::instance()->store()->truncate();' );

		wp( 'axle-category-tag-trash', 'merge', source, target, '--taxonomy=category' );
		const list = entries();
		const merged = list.find( ( item ) => item.event === 'ctt_merged' );
		expect( merged.description ).toContain( 'Merged "Stews" into "Meals"' );
		expect( merged.undoable ).toBe( true );
		// One merge, not a merge plus a term deletion and a pile of term changes.
		expect( list.filter( ( item ) => [ 'term_deleted', 'post_terms_changed' ].includes( item.event ) ) ).toHaveLength( 0 );
		expect( termCount( 'Stews' ) ).toBe( 0 );

		wp( 'activity-log-with-undo', 'undo', String( merged.id ) );
		expect( termCount( 'Stews' ) ).toBe( 1 );
		expect( trashItems() ).toBe( 0 );
		// The post has the source again and no longer holds the target it got from the merge.
		const names = wp( 'post', 'term', 'list', post, 'category', '--field=name' ).split( '\n' );
		expect( names ).toContain( 'Stews' );
		expect( names ).not.toContain( 'Meals' );
		wp( 'post', 'delete', post, '--force' );
	} );
} );
