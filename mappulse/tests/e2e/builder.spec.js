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

test( 'a map is built, styled, linked, published, shown on a page and reopened', async ( {
	page,
	baseURL,
	browser,
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
	// The later steps wait for the first area.
	await expect(
		page.getByRole( 'button', { name: '2 Style' } )
	).toBeDisabled();

	// A page named like the city, for auto-match to find. The stamp keeps reruns apart.
	const stamp = Date.now();
	// Pages left by an earlier run would make the match unclear, so they go to the trash first.
	await page.evaluate( async ( name ) => {
		const old = await window.wp.apiFetch( {
			path: `/wp/v2/pages?per_page=100&search=${ encodeURIComponent(
				name
			) }`,
		} );
		for ( const post of old ) {
			if ( post.title.rendered === name ) {
				await window.wp.apiFetch( {
					path: `/wp/v2/pages/${ post.id }`,
					method: 'DELETE',
				} );
			}
		}
	}, CITY );
	const cityPage = await page.evaluate(
		( title ) =>
			window.wp
				.apiFetch( {
					path: '/wp/v2/pages',
					method: 'POST',
					data: { title, status: 'publish', content: 'Area page.' },
				} )
				.then( ( p ) => ( { id: p.id, link: p.link } ) ),
		CITY
	);

	const title = `E2E map ${ stamp }`;
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
	// Cleaned, Fisher Island is drawn as a solid shape, not as the 14 pieces the source holds. On its own
	// it is exactly one ring; next to real water and a neighbouring city a cut can add a ring or two.
	const fisher = await preview
		.locator( '.mappulse__zone[data-zone="fisher-island"] path' )
		.getAttribute( 'd' );
	const rings = fisher.split( 'M' ).length - 1;
	expect( rings ).toBeGreaterThanOrEqual( 1 );
	expect( rings ).toBeLessThanOrEqual( CITY === 'Gulfport' ? 1 : 4 );

	// Reorder with the keyboard-reachable buttons.
	await page.getByRole( 'button', { name: 'Move Fisher Island up' } ).click();
	await expect(
		page
			.locator( '.mappulse-zone-row' )
			.first()
			.getByLabel( 'Name on the map' )
	).toHaveValue( 'Fisher Island' );
	await expect( preview.locator( '.mappulse__zone' ) ).toHaveCount( 2 );

	// Style: Brand Tint recolors the areas; a dashed border and the outline effect reach the drawing.
	await page.getByRole( 'button', { name: '2 Style' } ).click();
	await page.getByRole( 'button', { name: /^Brand Tint/ } ).click();
	await expect(
		page.getByRole( 'button', { name: /^Brand Tint/ } )
	).toHaveAttribute( 'aria-pressed', 'true' );
	const tinted = page
		.locator( '.mappulse-preview__map .mappulse__zone path' )
		.first();
	await expect( tinted ).not.toHaveCSS( 'fill', 'rgb(220, 207, 182)', {
		timeout: SLOW,
	} );
	await page.getByLabel( 'Border style' ).selectOption( 'dashed' );
	await expect( tinted ).toHaveCSS( 'stroke-dasharray', /6px,? 4px/, {
		timeout: SLOW,
	} );

	// Zones and links: auto-match links the city to its page; Fisher Island gets its own label, moved right.
	await page.getByRole( 'button', { name: '3 Zones and links' } ).click();
	await expect(
		page.getByRole( 'heading', { name: '0 of 2 areas have a link' } )
	).toBeVisible();
	await page.getByRole( 'button', { name: 'Match areas to pages' } ).click();
	await expect(
		page.getByRole( 'heading', { name: '1 of 2 areas have a link' } )
	).toBeVisible( { timeout: SLOW } );
	const cityRow = page.locator( '.mappulse-link-row', {
		has: page.getByRole( 'heading', { name: CITY, exact: true } ),
	} );
	await expect( cityRow.getByRole( 'link' ) ).toHaveAttribute(
		'href',
		cityPage.link
	);

	// A linked area is a real link in the preview. Clicking it must not leave the builder.
	await page
		.locator( '.mappulse-preview__map a.mappulse__zone' )
		.click( { position: { x: 5, y: 5 }, force: true } );
	await expect( page ).toHaveURL( /page=mappulse-builder/ );
	await expect(
		page.getByRole( 'heading', { name: '1 of 2 areas have a link' } )
	).toBeVisible();

	const islandRow = page.locator( '.mappulse-link-row', {
		has: page.getByRole( 'heading', {
			name: 'Fisher Island',
			exact: true,
		} ),
	} );
	await islandRow.getByLabel( 'Label on the map' ).fill( 'Fisher I.' );
	const islandLabel = page.locator(
		'.mappulse-preview__map .mappulse__label[data-zone="fisher-island"]'
	);
	await expect( islandLabel ).toHaveText( 'FISHER I.', { timeout: SLOW } );
	const before = Number(
		await islandLabel.locator( 'tspan' ).first().getAttribute( 'x' )
	);
	const right = islandRow.getByRole( 'button', {
		name: /Move the label of Fisher Island right/,
	} );
	await right.click();
	await right.click();
	await expect
		.poll(
			async () =>
				Number(
					await islandLabel
						.locator( 'tspan' )
						.first()
						.getAttribute( 'x' )
				),
			{ timeout: SLOW }
		)
		.toBeGreaterThan( before + 10 );
	await expect(
		islandRow.getByRole( 'button', { name: 'Reset position' } )
	).toBeVisible();
	// A pasted address that is not a web address is refused.
	await islandRow
		.getByPlaceholder( 'Search pages, or paste a web address' )
		.fill( 'https://' );
	await islandRow.getByRole( 'button', { name: 'Use this address' } ).click();
	await expect(
		islandRow.getByText( 'That is not a full web address' )
	).toBeVisible();
	await islandRow
		.getByPlaceholder( 'Search pages, or paste a web address' )
		.fill( '' );

	// A hover card for the city: a tagline, one fact and a button with a pasted address.
	await cityRow.getByRole( 'button', { name: 'Hover card: none' } ).click();
	await cityRow
		.getByLabel( 'Tagline' )
		.fill( 'Sand, sun and <b>art deco</b>' );
	await cityRow.getByLabel( 'Fact 1 name' ).fill( 'Median price' );
	await cityRow.getByLabel( 'Fact 1 value' ).fill( '$640,000' );
	await cityRow.getByLabel( 'Button 1 text' ).fill( 'Search homes' );
	await cityRow
		.getByPlaceholder( 'Search pages, or paste a web address' )
		.first()
		.fill( 'https://example.org/idx/?city=here' );
	await cityRow.getByRole( 'button', { name: 'Use this address' } ).click();
	await expect(
		cityRow.getByRole( 'button', { name: 'Hover card: set' } )
	).toBeVisible();
	await expect(
		page.getByText( '1 of 2 areas have a hover card.' )
	).toBeVisible();
	// The preview shows the card as the site will draw it, with the markup typed into it shown as text.
	const previewCard = page.locator(
		'.mappulse-preview__map .mappulse__card:not([hidden])'
	);
	await expect( previewCard ).toContainText( 'Sand, sun and art deco', {
		timeout: SLOW,
	} );
	await expect(
		previewCard.getByRole( 'link', { name: 'Search homes' } )
	).toHaveAttribute( 'href', 'https://example.org/idx/?city=here' );

	const publish = page.getByRole( 'button', {
		name: 'Publish',
		exact: true,
	} );
	await expect( publish ).toBeEnabled( { timeout: SLOW } );
	await publish.click();
	await expect( page.locator( '.mappulse-builder__status' ) ).toHaveText(
		'Published'
	);
	// A published map offers no "Save draft": that would take it off the site.
	await expect(
		page.getByRole( 'button', { name: 'Save draft' } )
	).toHaveCount( 0 );
	await expect(
		page.getByRole( 'button', { name: 'Update', exact: true } )
	).toBeVisible();
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
						title: 'E2E map holder',
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
	// The city is a link to its page, on the map and in the list; the island is not a link.
	await expect(
		page.locator( '.mappulse svg a.mappulse__zone' )
	).toHaveCount( 1 );
	await expect(
		page.locator( '.mappulse svg a.mappulse__zone' )
	).toHaveAttribute( 'href', cityPage.link );
	await expect( page.locator( '.mappulse__list a' ) ).toHaveAttribute(
		'href',
		cityPage.link
	);
	// The style and the hand-set label are on the page.
	await expect(
		page.locator( '.mappulse svg .mappulse__zone path' ).first()
	).not.toHaveCSS( 'fill', 'rgb(220, 207, 182)' );
	await expect(
		page.locator( '.mappulse svg .mappulse__zone path' ).first()
	).toHaveCSS( 'stroke-dasharray', /6px,? 4px/ );
	await expect(
		page.locator(
			'.mappulse svg .mappulse__label[data-zone="fisher-island"]'
		)
	).toHaveText( 'FISHER I.' );
	// No builder code reaches the front end.
	expect(
		await page
			.locator(
				'[src*="mappulse/build"], [href*="mappulse/build"], [href*="leaflet"]'
			)
			.count()
	).toBe( 0 );

	// The hover card on the site. Hidden until the area is pointed at; then inside the window, with its content.
	const card = page.locator( '.mappulse__card' );
	await expect( card ).toHaveCount( 1 );
	await expect( card ).toBeHidden();
	const cityZone = page.locator( '.mappulse svg a.mappulse__zone' );
	// The label sits at a point well inside the area and lets the pointer through, so that is where to point.
	const cityLabel = await page
		.locator(
			`.mappulse svg .mappulse__label[data-zone="${ CITY.toLowerCase().replace(
				/[^a-z0-9]+/g,
				'-'
			) }"]`
		)
		.boundingBox();
	await page.mouse.move(
		cityLabel.x + cityLabel.width / 2,
		cityLabel.y + cityLabel.height / 2
	);
	await expect( card ).toBeVisible();
	await expect( card.getByRole( 'heading', { name: CITY } ) ).toBeVisible();
	await expect( card ).toContainText( 'Sand, sun and art deco' );
	await expect( card ).toContainText( 'Median price' );
	await expect( card ).toContainText( '$640,000' );
	await expect(
		card.getByRole( 'link', { name: 'Search homes' } )
	).toHaveAttribute( 'href', 'https://example.org/idx/?city=here' );
	const box = await card.boundingBox();
	const view = page.viewportSize();
	expect( box.x ).toBeGreaterThanOrEqual( 0 );
	expect( box.y ).toBeGreaterThanOrEqual( 0 );
	expect( box.x + box.width ).toBeLessThanOrEqual( view.width );
	expect( box.y + box.height ).toBeLessThanOrEqual( view.height );
	// Escape puts it away; keyboard focus brings it back, and Tab reaches its button before leaving the area.
	await page.keyboard.press( 'Escape' );
	await expect( card ).toBeHidden();
	await page.mouse.move( 0, 0 );
	await cityZone.focus();
	await expect( card ).toBeVisible();
	await page.keyboard.press( 'Tab' );
	await expect(
		card.getByRole( 'link', { name: 'Search homes' } )
	).toBeFocused();
	await page.keyboard.press( 'Escape' );
	await expect( card ).toBeHidden();
	await expect( cityZone ).toBeFocused();

	// On a phone the first tap opens the card as a sheet and does not follow the link.
	const phone = await browser.newContext( {
		viewport: { width: 390, height: 780 },
		hasTouch: true,
		isMobile: true,
		baseURL,
	} );
	const mobile = await phone.newPage();
	await mobile.goto( link );
	const sheet = mobile.locator( '.mappulse__card' );
	await mobile.locator( '.mappulse__list a' ).tap();
	await expect( sheet ).toBeVisible();
	await expect( sheet ).toHaveClass( /mappulse__card--sheet/ );
	expect( mobile.url() ).toBe( link );
	const sheetBox = await sheet.boundingBox();
	expect( Math.round( sheetBox.y + sheetBox.height ) ).toBe( 780 );
	expect( Math.round( sheetBox.width ) ).toBe( 390 );
	await sheet.getByRole( 'button', { name: 'Close' } ).tap();
	await expect( sheet ).toBeHidden();
	await phone.close();

	// With JavaScript off the area is still a plain link, and the card stays out of the way.
	const plain = await browser.newContext( {
		javaScriptEnabled: false,
		baseURL,
	} );
	const bare = await plain.newPage();
	await bare.goto( link );
	await expect(
		bare.locator( '.mappulse svg a.mappulse__zone' )
	).toHaveAttribute( 'href', cityPage.link );
	await expect( bare.locator( '.mappulse__card' ) ).toBeHidden();
	await plain.close();

	// The link follows the page when its address changes.
	await page.goto( BUILDER );
	await expect(
		page.getByRole( 'heading', { name: 'Find an area' } )
	).toBeVisible();
	const moved = await page.evaluate(
		( id ) =>
			window.wp
				.apiFetch( {
					path: `/wp/v2/pages/${ id }`,
					method: 'POST',
					data: { slug: `moved-${ id }` },
				} )
				.then( ( p ) => p.link ),
		cityPage.id
	);
	await page.goto( link );
	await expect(
		page.locator( '.mappulse svg a.mappulse__zone' )
	).toHaveAttribute( 'href', moved );

	// Reopen: the areas, style, link and label come back from the saved map without searching again.
	await page.goto( `${ BUILDER }&map=${ mapId }` );
	await expect( page.getByLabel( 'Map name' ) ).toHaveValue( title );
	await expect( page.locator( '.mappulse-zone-row' ) ).toHaveCount( 2 );
	await expect(
		page.locator( '.mappulse-preview__map .mappulse__zone' )
	).toHaveCount( 2 );
	await expect( page.locator( '.mappulse-builder__status' ) ).toHaveText(
		'Published'
	);
	await page.getByRole( 'button', { name: '2 Style' } ).click();
	await expect(
		page.getByRole( 'button', { name: /^Brand Tint/ } )
	).toHaveAttribute( 'aria-pressed', 'true' );
	await expect( page.getByLabel( 'Border style' ) ).toHaveValue( 'dashed' );
	await page.getByRole( 'button', { name: '3 Zones and links' } ).click();
	await expect(
		page.getByRole( 'heading', { name: '1 of 2 areas have a link' } )
	).toBeVisible();
	await expect(
		page
			.locator( '.mappulse-link-row', {
				has: page.getByRole( 'heading', {
					name: 'Fisher Island',
					exact: true,
				} ),
			} )
			.getByLabel( 'Label on the map' )
	).toHaveValue( 'Fisher I.' );
	// Nothing was changed by looking, so there is nothing to save.
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

	// For a person to look at: the map as the builder draws it, printed into the log as a small image.
	if ( process.env.MAPPULSE_E2E_SHOT ) {
		await page.goto( `${ BUILDER }&map=${ mapId }` );
		const both = page.locator( '.mappulse-preview__map' );
		await expect( both.locator( '.mappulse__zone' ) ).toHaveCount( 2, {
			timeout: SLOW,
		} );
		const layers = await both
			.locator( 'svg' )
			.evaluate( ( svg ) =>
				[ ...svg.querySelectorAll( ':scope > g[class]' ) ]
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
		console.log( `LAYERS ${ layers } fisher-rings=${ rings }` );
		const shot = await both.screenshot( { type: 'jpeg', quality: 50 } );
		// eslint-disable-next-line no-console
		console.log( `SHOT ${ shot.toString( 'base64' ) }` );
	}
} );
