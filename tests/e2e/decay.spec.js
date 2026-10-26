// Runs against a FRESH site with the release zip installed (see factory.ps1 / TESTING.md).
const { test, expect } = require( '@playwright/test' );
const { execSync } = require( 'child_process' );
const path = require( 'path' );

const root = path.resolve( __dirname, '../../../..' );
const shots = path.resolve( __dirname, '../../assets' );
const wp = ( args ) =>
	execSync( `npx wp-env run cli wp ${ args }`, { cwd: path.join( root, 'pkgtest' ), encoding: 'utf8' } );

test.describe.configure( { mode: 'serial' } );

test.beforeAll( () => {
	wp( 'plugin install /tmp/content-decay-auditor.zip --force --activate' );
	wp( "option update permalink_structure '/%postname%/'" );
	// Re-runnable: remove leftovers from earlier runs.
	const stale = wp( 'post list --s="Ancient Guide" --post_status=any --format=ids' ).trim().split( /\s+/ ).filter( ( s ) => /^\d+$/.test( s ) );
	if ( stale.length ) {
		wp( `post delete ${ stale.join( ' ' ) } --force` );
	}
	// One thin, old post with a broken internal link, backdated through SQL.
	const id = wp( 'post create --post_title="Ancient Guide" --post_status=publish --post_content="Short. <a href=\\"/nowhere-at-all/\\">gone</a>" --porcelain' ).trim().split( /\s+/ ).pop();
	wp( `db query "UPDATE wp_posts SET post_date='2022-01-01 00:00:00', post_date_gmt='2022-01-01 00:00:00', post_modified='2022-01-01 00:00:00', post_modified_gmt='2022-01-01 00:00:00' WHERE ID=${ id }"` );
} );

/** Rows of this plugin's post meta. */
function metaCount() {
	return Number( wp( `db query "SELECT COUNT(*) FROM wp_postmeta WHERE meta_key LIKE '%cdaudit%'" --skip-column-names` ).trim() );
}

test( 'activate, scan, queue, mark reviewed, configure, uninstall, clean database', async ( { page } ) => {
	await page.goto( '/wp-login.php' );
	await page.fill( '#user_login', 'admin' );
	await page.fill( '#user_pass', 'password' );
	await page.click( '#wp-submit' );
	await expect( page.locator( '#wpadminbar' ) ).toBeVisible();

	await page.goto( '/wp-admin/plugins.php' );
	await expect( page.locator( '#deactivate-content-decay-auditor' ) ).toBeVisible();

	await page.goto( '/wp-admin/tools.php?page=cdaudit' );
	await page.getByRole( 'button', { name: 'Scan next 100 posts' } ).click();
	await expect( page.locator( '.notice-success' ) ).toContainText( 'Scanned' );

	const row = page.locator( 'tr', { hasText: 'Ancient Guide' } );
	await expect( row ).toBeVisible();
	await expect( row ).toContainText( 'broken internal link' );
	await expect( row ).toContainText( 'Very thin' );
	await page.waitForTimeout( 800 ); // Let the admin notice finish its fade-in so the capture is clean.
	await page.screenshot( { path: path.join( shots, 'screenshot-1.png' ), fullPage: true } );

	const scoreBefore = Number( await row.locator( 'td' ).nth( 1 ).textContent() );
	expect( scoreBefore ).toBeGreaterThanOrEqual( 80 );
	await row.getByRole( 'button', { name: 'Mark reviewed' } ).click();
	await expect( page.locator( '.notice-success' ) ).toContainText( 'Marked as reviewed' );
	await page.waitForTimeout( 800 );
	await page.screenshot( { path: path.join( shots, 'screenshot-2.png' ), fullPage: true } );
	// Reviewed: the staleness points are gone; thin + broken + age remain, so it is still queued with a lower score.
	const scoreAfter = Number( await page.locator( 'tr', { hasText: 'Ancient Guide' } ).locator( 'td' ).nth( 1 ).textContent() );
	expect( scoreAfter ).toBeLessThan( scoreBefore );

	await page.fill( '#cdaudit_threshold', '90' );
	await page.getByRole( 'button', { name: 'Save', exact: true } ).click();
	await expect( page.locator( '#cdaudit_threshold' ) ).toHaveValue( '90' );

	// Positive control: the clean-database check below is only meaningful if this query can see the data.
	expect( metaCount() ).toBeGreaterThan( 0 );

	await page.goto( '/wp-admin/plugins.php' );
	await page.click( '#deactivate-content-decay-auditor' );
	await page.locator( '#delete-content-decay-auditor' ).waitFor( { timeout: 60000 } );
	// The Delete link is wired up by JavaScript. Clicking before the page settles follows the plain link to a
	// "Yes, delete" confirmation screen, which deletes nothing, so wait first and then check for real.
	await page.waitForLoadState( 'networkidle' );
	page.once( 'dialog', ( d ) => d.accept() );
	await page.click( '#delete-content-decay-auditor' );
	// WordPress leaves a placeholder row behind after an AJAX delete, so the reliable evidence is the plugin list itself.
	await expect.poll( () => wp( 'plugin list --field=name --skip-plugins --skip-themes' ).split( /\s+/ ).includes( 'content-decay-auditor' ), { timeout: 90000, intervals: [ 3000 ] } ).toBe( false );

	expect( wp( "option list --search='cdaudit*' --format=count" ).trim() ).toBe( '0' );
	expect( metaCount() ).toBe( 0 );
} );