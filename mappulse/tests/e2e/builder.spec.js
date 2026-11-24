// Builds a two-area map in the builder, publishes it, and checks it on a page and on reopening.
const { test, expect } = require( '@playwright/test' );

const USER = process.env.MAPPULSE_E2E_USER || 'admin';
const PASS = process.env.MAPPULSE_E2E_PASS || 'password';
// Real boundary sources can take a while; the base map is six requests.
const SLOW = 180000;
// The second area. It should be near Fisher Island, FL when real sources are used, so one base map covers both.
const CITY = process.env.MAPPULSE_E2E_CITY || 'Gulfport';
const BUILDER =
	'/wp-admin/edit.php?post_type=mappulse_map&page=mappulse-builder';

async function addArea( page, name, { expectClean } ) {
	await page.getByLabel( 'Name or ZIP code' ).fill( name );
	await page.getByLabel( 'Kind of area' ).selectOption( 'city' );
	await page.getByLabel( 'State', { exact: true } ).selectOption( 'FL' );
	await page.getByRole( 'button', { name: 'Find', exact: true } ).click();
	const found = page.getByRole( 'button', {
		name: `Preview ${ name }`,
		exact: true,
	} );
	await expect( found ).toBeVisible( { timeout: SLOW } );
	await found.click();

	const dialog = page.getByRole( 'dialog' );
	await expect(
		dialog.getByRole( 'heading', { name: `Is this ${ name }?` } )
	).toBeVisible( { timeout: SLOW } );
	// The outline is drawn on the street map.
	await expect(
		dialog.locator( '.leaflet-overlay-pane path' ).first()
	).toBeAttached();
	const clean = dialog.getByLabel( 'Use clean outline' );
	if ( expectClean ) {
		await expect( clean ).toBeChecked();
		await expect(
			dialog.locator( '.leaflet-overlay-pane path' )
		).toHaveCount( 2 );
	} else if ( expectClean === false ) {
		await expect( clean ).toHaveCount( 0 );
	}
	await dialog.getByRole( 'button', { name: 'Add to map' } ).click();
	await expect( dialog ).toHaveCount( 0 );
	await expect(
		page.getByRole( 'button', { name: `Remove ${ name }` } )
	).toBeVisible();
}

test( 'a map is built, published, shown on a page and reopened', async ( {
	page,
	baseURL,
} ) => {
	const problems = [];
	page.on( 'pageerror', ( error ) =>
		problems.push( `page error: ${ error.message }` )
	);
	page.on( 'console', ( message ) => {
		// A resource that fails to load is reported below with its address, so it is not counted twice here.
		if (
			message.type() === 'error' &&
			! /Failed to load resource/i.test( message.text() )
		) {
			problems.push( `console error: ${ message.text() }` );
		}
	} );
	// Street tiles and avatars come from other hosts and may be unreachable where this runs. Only this site's own requests count.
	const outside = ( url ) =>
		new URL( url ).origin !== new URL( baseURL ).origin ||
		/\/favicon\.ico$/.test( url );
	page.on( 'response', ( response ) => {
		if ( response.status() >= 400 && ! outside( response.url() ) ) {
			problems.push( `HTTP ${ response.status() }: ${ response.url() }` );
		}
	} );
	page.on( 'requestfailed', ( request ) => {
		if (
			! outside( request.url() ) &&
			! /ERR_ABORTED/.test( ( request.failure() || {} ).errorText || '' )
		) {
			problems.push( `request failed: ${ request.url() }` );
		}
	} );

	await page.goto( '/wp-login.php' );
	await page.locator( '#user_login' ).fill( USER );
	await page.locator( '#user_pass' ).fill( PASS );
	await page.locator( '#wp-submit' ).click();
	await page.waitForURL( /wp-admin/ );

	await page.goto( BUILDER );
	await expect(
		page.getByRole( 'heading', { name: 'Find an area' } )
	).toBeVisible();
	await expect(
		page.getByRole( 'button', { name: 'Publish', exact: true } )
	).toBeDisabled();

	const title = `E2E map ${ Date.now() }`;
	await page.getByLabel( 'Map name' ).fill( title );
	await addArea( page, CITY, {
		expectClean: CITY === 'Gulfport' ? false : null,
	} );
	await addArea( page, 'Fisher Island', { expectClean: true } );

	// The preview is the real renderer's output, with both zones and the map's own colors.
	const preview = page.locator( '.mappulse-preview__map' );
	await expect( preview.locator( '.mappulse__zone' ) ).toHaveCount( 2, {
		timeout: SLOW,
	} );
	await expect( preview.locator( '.mappulse__zone path' ).first() ).toHaveCSS(
		'fill',
		'rgb(220, 207, 182)'
	);
	// Cleaned, Fisher Island is one solid shape: a single ring in its path.
	const fisher = await preview
		.locator( '.mappulse__zone[data-zone="fisher-island"] path' )
		.getAttribute( 'd' );
	expect( fisher.split( 'M' ).length - 1 ).toBe( 1 );

	// Reorder with the keyboard-reachable buttons.
	await page.getByRole( 'button', { name: 'Move Fisher Island up' } ).click();
	await expect(
		page
			.locator( '.mappulse-zone-row' )
			.first()
			.getByLabel( 'Name on the map' )
	).toHaveValue( 'Fisher Island' );
	await expect( preview.locator( '.mappulse__zone' ) ).toHaveCount( 2 );

	const publish = page.getByRole( 'button', {
		name: 'Publish',
		exact: true,
	} );
	await expect( publish ).toBeEnabled( { timeout: SLOW } );
	await publish.click();
	await expect( page.locator( '.mappulse-builder__status' ) ).toHaveText(
		'Published'
	);
	const mapId = Number( new URL( page.url() ).searchParams.get( 'map' ) );
	expect( mapId ).toBeGreaterThan( 0 );
	await expect(
		page.locator( '.mappulse-builder__shortcode' )
	).toContainText( `[mappulse id="${ mapId }"]` );

	// Place it on a page with the shortcode and look at the front end.
	const link = await page.evaluate(
		( id ) =>
			window.wp
				.apiFetch( {
					path: '/wp/v2/pages',
					method: 'POST',
					data: {
						title: 'E2E builder page',
						status: 'publish',
						content: `[mappulse id="${ id }"]`,
					},
				} )
				.then( ( p ) => p.link ),
		mapId
	);
	await page.goto( link );
	await expect( page.locator( '.mappulse svg .mappulse__zone' ) ).toHaveCount(
		2
	);
	await expect( page.locator( '.mappulse__list li' ) ).toHaveText( [
		'Fisher Island',
		CITY,
	] );
	// No builder code reaches the front end.
	expect(
		await page
			.locator(
				'[src*="mappulse/build"], [href*="mappulse/build"], [href*="leaflet"]'
			)
			.count()
	).toBe( 0 );

	// Reopen: the areas come back from the saved map without searching again.
	await page.goto( `${ BUILDER }&map=${ mapId }` );
	await expect( page.getByLabel( 'Map name' ) ).toHaveValue( title );
	await expect( page.locator( '.mappulse-zone-row' ) ).toHaveCount( 2 );
	await expect(
		page.locator( '.mappulse-preview__map .mappulse__zone' )
	).toHaveCount( 2 );
	await expect( page.locator( '.mappulse-builder__status' ) ).toHaveText(
		'Published'
	);

	// The map list links back to the builder.
	await page.goto( '/wp-admin/edit.php?post_type=mappulse_map' );
	await expect(
		page.getByRole( 'link', { name: 'Build a map' } )
	).toBeVisible();
	await expect(
		page.locator( `#post-${ mapId } a.row-title` )
	).toHaveAttribute(
		'href',
		new RegExp( `page=mappulse-builder&map=${ mapId }$` )
	);

	expect( problems ).toEqual( [] );

	// For a person to look at: a one-city map as the builder draws it, printed into the log as a small image.
	if ( process.env.MAPPULSE_E2E_SHOT ) {
		await page.goto( `${ BUILDER }&map=${ mapId }` );
		await expect( page.locator( '.mappulse-zone-row' ) ).toHaveCount( 2 );
		await page
			.getByRole( 'button', { name: 'Remove Fisher Island' } )
			.click();
		const one = page.locator( '.mappulse-preview__map' );
		await expect( one.locator( '.mappulse__zone' ) ).toHaveCount( 1, {
			timeout: SLOW,
		} );
		await expect( one ).not.toHaveClass( /is-busy/, { timeout: SLOW } );
		const layers = await one
			.locator( 'svg' )
			.evaluate( ( svg ) =>
				[ ...svg.querySelectorAll( 'g[class]' ) ]
					.map(
						( g ) =>
							`${ g
								.getAttribute( 'class' )
								.replace( 'mappulse__', '' ) }=${
								g.innerHTML.length
							}`
					)
					.join( ' ' )
			);
		// eslint-disable-next-line no-console
		console.log( `LAYERS ${ layers }` );
		const shot = await one.screenshot( { type: 'jpeg', quality: 45 } );
		// eslint-disable-next-line no-console
		console.log( `SHOT ${ shot.toString( 'base64' ) }` );
	}
} );
