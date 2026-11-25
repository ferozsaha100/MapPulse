/**
 * Editor script for the MapPulse Map block.
 *
 * Hand-written without JSX so the plugin needs no build step yet. It moves into the
 * wp-scripts build together with the map builder.
 */
( function ( wp ) {
	'use strict';

	var el = wp.element.createElement;
	var __ = wp.i18n.__;
	var components = wp.components;
	var maps = ( window.mappulseBlock && window.mappulseBlock.maps ) || [];

	var layouts = [
		{ label: __( 'List on the left', 'mappulse' ), value: 'list-left' },
		{ label: __( 'List on the right', 'mappulse' ), value: 'list-right' },
		{ label: __( 'List below the map', 'mappulse' ), value: 'list-below' },
		{ label: __( 'Map only', 'mappulse' ), value: 'map-only' }
	];

	var mapOptions = [ { label: __( 'Choose a map', 'mappulse' ), value: 0 } ].concat(
		maps.map( function ( map ) {
			return { label: map.title, value: map.id };
		} )
	);

	wp.blocks.registerBlockType( 'agentview/mappulse', {
		edit: function ( props ) {
			var attributes = props.attributes;
			var setAttributes = props.setAttributes;

			function mapPicker() {
				return el( components.SelectControl, {
					label: __( 'Map', 'mappulse' ),
					value: attributes.mapId,
					options: mapOptions,
					onChange: function ( value ) {
						setAttributes( { mapId: parseInt( value, 10 ) || 0 } );
					},
					__nextHasNoMarginBottom: true,
					__next40pxDefaultSize: true
				} );
			}

			var inspector = el(
				wp.blockEditor.InspectorControls,
				null,
				el(
					components.PanelBody,
					{ title: __( 'Map', 'mappulse' ) },
					mapPicker(),
					el( components.SelectControl, {
						label: __( 'Layout', 'mappulse' ),
						value: attributes.layout,
						options: layouts,
						onChange: function ( value ) {
							setAttributes( { layout: value } );
						},
						__nextHasNoMarginBottom: true,
						__next40pxDefaultSize: true
					} ),
					el( components.ToggleControl, {
						label: __( 'Hover cards', 'mappulse' ),
						help: __( 'Cards are set per area in the map builder.', 'mappulse' ),
						checked: attributes.cards !== false,
						onChange: function ( value ) {
							setAttributes( { cards: value } );
						},
						__nextHasNoMarginBottom: true
					} ),
					el( components.ToggleControl, {
						label: __( 'Show the area list', 'mappulse' ),
						checked: attributes.showList,
						onChange: function ( value ) {
							setAttributes( { showList: value } );
						},
						__nextHasNoMarginBottom: true
					} ),
					el( components.TextControl, {
						label: __( 'Heading', 'mappulse' ),
						value: attributes.heading,
						onChange: function ( value ) {
							setAttributes( { heading: value } );
						},
						__nextHasNoMarginBottom: true,
						__next40pxDefaultSize: true
					} ),
					el( components.TextareaControl, {
						label: __( 'Intro', 'mappulse' ),
						value: attributes.intro,
						onChange: function ( value ) {
							setAttributes( { intro: value } );
						},
						__nextHasNoMarginBottom: true
					} )
				)
			);

			var body = attributes.mapId
				? el( wp.serverSideRender, { block: 'agentview/mappulse', attributes: attributes } )
				: el(
					components.Placeholder,
					{
						icon: 'location-alt',
						label: __( 'MapPulse Map', 'mappulse' ),
						instructions: maps.length
							? __( 'Choose the map to show here.', 'mappulse' )
							: __( 'No maps yet. Import one under MapPulse, Import.', 'mappulse' )
					},
					mapPicker()
				);

			return el( 'div', wp.blockEditor.useBlockProps(), inspector, body );
		},

		save: function () {
			return null;
		}
	} );
}( window.wp ) );
