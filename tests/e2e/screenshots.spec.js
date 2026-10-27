// Regenerates .wordpress-org/screenshot-N.png (readme.txt "== Screenshots ==" and README.md).
// Run on demand: npm run screenshots
const { test, expect, wp, wpEval } = require( './utils' );
const { prepareSite, shot } = require( './utils/media' );
const { BASE_URL, STORAGE_STATE } = require( './utils/config' );

const PAGE = '/wp-admin/tools.php?page=activity-log-with-undo';
const latest = ( event ) => JSON.parse( wp( 'activity-log-with-undo', 'list', '--format=json', '--fields=id,event' ) ).find( ( entry ) => entry.event === event );

test.describe.serial( 'Screenshots', () => {
	test.use( { viewport: { width: 1280, height: 800 }, deviceScaleFactor: 2 } );
	const made = { posts: [], users: [], terms: [] };

	test.beforeAll( async ( { browser } ) => {
		prepareSite( 'Kitchen Notes' );
		wpEval( `update_option( 'blogdescription', 'Recipes from a small kitchen' ); update_option( 'posts_per_page', 10 );` );
		made.users.push( wp( 'user', 'create', 'maria', 'maria@example.com', '--role=author', '--display_name=Maria', '--porcelain' ) );
		made.posts.push(
			wp(
				'post',
				'create',
				'--post_title=Lemon tart',
				'--post_status=publish',
				'--post_content=<!-- wp:paragraph --><p>A short pastry case, a sharp lemon curd and a thin layer of burnt sugar on top. Bake the case blind for 15 minutes first.</p><!-- /wp:paragraph -->',
				'--porcelain'
			)
		);
		made.posts.push( wp( 'post', 'create', '--post_title=Spring menu', '--post_status=publish', '--porcelain' ) );
		made.terms.push( wp( 'term', 'create', 'category', 'Desserts', '--porcelain' ) );
		wp( 'plugin', 'activate', 'hello' );
		wpEval( 'AryanX\\Axle\\ActivityLogWithUndo\\Plugin::instance()->store()->truncate();' );

		// A morning of changes, from the dashboard, the editor and WP-CLI.
		const context = await browser.newContext( { baseURL: BASE_URL, storageState: STORAGE_STATE } );
		const page = await context.newPage();
		const nonce = ( await ( await page.request.get( '/wp-admin/admin-ajax.php?action=rest-nonce' ) ).text() ).trim();
		const rest = ( method, path, data ) => page.request.fetch( `/index.php?rest_route=${ encodeURIComponent( path ) }`, { method, headers: { 'X-WP-Nonce': nonce }, data } );

		await page.goto( '/wp-admin/options-general.php' );
		await page.locator( '#blogname' ).fill( 'Kitchen Notes Weekly' );
		await page.locator( '#submit' ).click();
		wp( 'plugin', 'deactivate', 'hello', '--user=admin' );
		await rest( 'POST', `/wp/v2/posts/${ made.posts[ 0 ] }`, {
			content: '<!-- wp:paragraph --><p>A short pastry case, a sharp lemon curd and a thin layer of burnt sugar on top. Bake the case blind for 20 minutes, then let it cool before you pour in the curd.</p><!-- /wp:paragraph -->',
		} );
		await rest( 'POST', `/wp/v2/categories/${ made.terms[ 0 ] }`, { name: 'Sweet things' } );
		await page.goto( `/wp-admin/user-edit.php?user_id=${ made.users[ 0 ] }` );
		await page.locator( '#role' ).selectOption( 'editor' );
		await page.locator( '#submit' ).click();
		await rest( 'DELETE', `/wp/v2/posts/${ made.posts[ 1 ] }` );
		wpEval( `wp_set_current_user( 1 ); update_option( 'blogname', 'Kitchen Notes' ); update_option( 'blogdescription', 'Recipes, menus and kitchen notes' );` );
		await context.close();
	} );

	test.afterAll( () => {
		made.posts.forEach( ( id ) => wp( 'post', 'delete', id, '--force' ) );
		made.users.forEach( ( id ) => wp( 'user', 'delete', id, '--yes', '--reassign=1' ) );
		made.terms.forEach( ( id ) => wp( 'term', 'delete', 'category', id ) );
		wpEval( `update_option( 'blogname', 'E2E' ); update_option( 'blogdescription', 'Just another site' );` );
		wpEval( 'AryanX\\Axle\\ActivityLogWithUndo\\Plugin::instance()->store()->truncate();' );
	} );

	test( '1: the log', async ( { page } ) => {
		await page.goto( PAGE );
		await expect( page.locator( '#the-list tr' ) ).toHaveCount( 8 );
		await expect( page.locator( '#the-list tr' ).first() ).toContainText( 'Saved with 1 other change' );
		await shot( page, 1 );
	} );

	test( '2: one change with its before and after values', async ( { page } ) => {
		await page.goto( `${ PAGE }&entry=${ latest( 'post_updated' ).id }` );
		await expect( page.locator( '.alu-text-diff' ) ).toBeVisible();
		await shot( page, 2 );
	} );

	test( '3: changed since, with Undo anyway', async ( { page } ) => {
		const first = JSON.parse( wp( 'activity-log-with-undo', 'list', '--format=json', '--fields=id,event,object_id', '--event=option_updated' ) ).filter( ( entry ) => entry.object_id === 'blogname' ).pop();
		await page.goto( `${ PAGE }&entry=${ first.id }` );
		await expect( page.locator( '.alu-conflict' ) ).toBeVisible();
		await shot( page, 3 );
	} );

	test( '4: the settings', async ( { page } ) => {
		await page.goto( `${ PAGE }&tab=settings` );
		await shot( page, 4 );
	} );
} );
