/**
 * The Style step: presets, colors, label type, borders and the hover effect.
 */
import {
	Button,
	SelectControl,
	RangeControl,
	RadioControl,
	Notice,
} from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
import ColorField from './color-field';
import { PRESETS, applyPreset } from './presets';
import { readable, contrastRatio } from './contrast';
import MapPreview from '../areas/map-preview';

const GROUPS = [
	{
		title: __( 'Areas', 'mappulse' ),
		fields: [
			[ 'zone', __( 'Area', 'mappulse' ) ],
			[ 'zoneStroke', __( 'Area border', 'mappulse' ) ],
			[ 'zoneHover', __( 'Area on hover', 'mappulse' ) ],
			[ 'label', __( 'Label', 'mappulse' ) ],
			[ 'labelHover', __( 'Label on hover', 'mappulse' ) ],
		],
	},
	{
		title: __( 'Surroundings', 'mappulse' ),
		fields: [
			[ 'land', __( 'Land', 'mappulse' ) ],
			[ 'context', __( 'Nearby areas', 'mappulse' ) ],
			[ 'contextStroke', __( 'Nearby area borders', 'mappulse' ) ],
		],
	},
	{
		title: __( 'Water and roads', 'mappulse' ),
		fields: [
			[ 'water', __( 'Water', 'mappulse' ) ],
			[ 'coast', __( 'Coastline', 'mappulse' ) ],
			[ 'waterLabel', __( 'Water names', 'mappulse' ) ],
			[ 'road', __( 'Road', 'mappulse' ) ],
			[ 'roadCasing', __( 'Road edge', 'mappulse' ) ],
		],
	},
];

const SWATCHES = [ 'zone', 'zoneHover', 'label', 'land', 'water' ];

export default function StyleStep( { settings, state, dispatch, preview } ) {
	const { style, styleDefaults } = state;
	const colors = settings.siteColors || [];
	const set = ( patch ) => dispatch( { type: 'SET_STYLE', patch } );
	const check = readable( style );

	// The font in use is always offered, even when the site no longer lists it.
	const fonts = [ ...( settings.siteFonts || [] ) ];
	if (
		style.labelFont &&
		! fonts.some( ( font ) => font.family === style.labelFont )
	) {
		fonts.unshift( { name: style.labelFont, family: style.labelFont } );
	}

	return (
		<div className="mappulse-builder__body">
			<div className="mappulse-builder__side">
				<section
					className="mappulse-panel"
					aria-labelledby="mappulse-presets-title"
				>
					<h2 id="mappulse-presets-title">
						{ __( 'Preset', 'mappulse' ) }
					</h2>
					<div className="mappulse-presets">
						{ Object.entries( PRESETS ).map(
							( [ name, preset ] ) => {
								const sample = applyPreset(
									name,
									style,
									style.brand,
									styleDefaults
								);
								return (
									<button
										key={ name }
										type="button"
										className={
											'mappulse-preset' +
											( style.preset === name
												? ' is-chosen'
												: '' )
										}
										aria-pressed={ style.preset === name }
										onClick={ () =>
											dispatch( {
												type: 'APPLY_PRESET',
												name,
												brand: style.brand,
											} )
										}
									>
										<span
											className="mappulse-preset__swatches"
											aria-hidden="true"
										>
											{ SWATCHES.map( ( key ) => (
												<span
													key={ key }
													style={ {
														background:
															sample[ key ],
													} }
												/>
											) ) }
										</span>
										<strong>{ preset.label }</strong>
										<span>{ preset.description }</span>
									</button>
								);
							}
						) }
					</div>
					{ style.preset === 'brand-tint' && (
						<ColorField
							label={ __( 'Brand color', 'mappulse' ) }
							value={ style.brand }
							colors={ colors }
							onChange={ ( brand ) =>
								dispatch( {
									type: 'APPLY_PRESET',
									name: 'brand-tint',
									brand,
									// Colors already changed by hand stay as they are.
									keep: true,
								} )
							}
						/>
					) }
					<Button
						variant="link"
						onClick={ () =>
							dispatch( {
								type: 'APPLY_PRESET',
								name: style.preset,
								brand: style.brand,
							} )
						}
					>
						{ __( 'Reset colors to this preset', 'mappulse' ) }
					</Button>
				</section>

				{ GROUPS.map( ( group ) => (
					<section key={ group.title } className="mappulse-panel">
						<h2>{ group.title }</h2>
						<div className="mappulse-colors">
							{ group.fields.map( ( [ key, label ] ) => (
								<ColorField
									key={ key }
									label={ label }
									value={ style[ key ] }
									colors={ colors }
									onChange={ ( color ) =>
										set( { [ key ]: color } )
									}
								/>
							) ) }
						</div>
						{ group.fields[ 0 ][ 0 ] === 'zone' && ! check.ok && (
							<Notice status="warning" isDismissible={ false }>
								{ check.label < 4.5
									? sprintf(
											/* translators: %s: contrast ratio, such as 2.1. */
											__(
												'The label is hard to read on the area color (contrast %s to 1; 4.5 or more reads well).',
												'mappulse'
											),
											check.label.toFixed( 1 )
									  )
									: sprintf(
											/* translators: %s: contrast ratio, such as 2.1. */
											__(
												'The label is hard to read on the hover color (contrast %s to 1; 4.5 or more reads well).',
												'mappulse'
											),
											check.hover.toFixed( 1 )
									  ) }
							</Notice>
						) }
					</section>
				) ) }

				<section
					className="mappulse-panel"
					aria-labelledby="mappulse-cardstyle-title"
				>
					<h2 id="mappulse-cardstyle-title">
						{ __( 'Hover card', 'mappulse' ) }
					</h2>
					<div className="mappulse-colors">
						<ColorField
							label={ __( 'Card', 'mappulse' ) }
							value={ style.cardBackground }
							colors={ colors }
							onChange={ ( color ) =>
								set( { cardBackground: color } )
							}
						/>
						<ColorField
							label={ __( 'Card text', 'mappulse' ) }
							value={ style.cardText }
							colors={ colors }
							onChange={ ( color ) => set( { cardText: color } ) }
						/>
						<ColorField
							label={
								style.cardButton
									? __( 'Button', 'mappulse' )
									: __(
											'Button (same as the hover color)',
											'mappulse'
									  )
							}
							value={ style.cardButton || style.zoneHover }
							colors={ colors }
							onChange={ ( color ) =>
								set( { cardButton: color } )
							}
						/>
						<ColorField
							label={ __( 'Button text', 'mappulse' ) }
							value={ style.cardButtonText }
							colors={ colors }
							onChange={ ( color ) =>
								set( { cardButtonText: color } )
							}
						/>
					</div>
					{ contrastRatio( style.cardText, style.cardBackground ) <
						4.5 && (
						<Notice status="warning" isDismissible={ false }>
							{ __(
								'The card text is hard to read on the card color.',
								'mappulse'
							) }
						</Notice>
					) }
				</section>

				<section
					className="mappulse-panel"
					aria-labelledby="mappulse-type-title"
				>
					<h2 id="mappulse-type-title">
						{ __( 'Labels and borders', 'mappulse' ) }
					</h2>
					<SelectControl
						label={ __( 'Label font', 'mappulse' ) }
						help={ __(
							'The font is used by name. It shows on the site when your theme already loads it.',
							'mappulse'
						) }
						value={ style.labelFont }
						options={ fonts.map( ( font ) => ( {
							value: font.family,
							label: font.name,
						} ) ) }
						onChange={ ( labelFont ) => set( { labelFont } ) }
						__nextHasNoMarginBottom
						__next40pxDefaultSize
					/>
					<RangeControl
						label={ __( 'Label size', 'mappulse' ) }
						value={ Number( style.labelSize ) }
						min={ 8 }
						max={ 20 }
						step={ 0.5 }
						onChange={ ( labelSize ) =>
							set( {
								labelSize: labelSize || styleDefaults.labelSize,
							} )
						}
						__nextHasNoMarginBottom
						__next40pxDefaultSize
					/>
					<RangeControl
						label={ __( 'Border width', 'mappulse' ) }
						value={ Number( style.zoneStrokeWidth ) }
						min={ 0 }
						max={ 4 }
						step={ 0.2 }
						onChange={ ( zoneStrokeWidth ) =>
							set( {
								zoneStrokeWidth:
									zoneStrokeWidth ??
									styleDefaults.zoneStrokeWidth,
							} )
						}
						__nextHasNoMarginBottom
						__next40pxDefaultSize
					/>
					<SelectControl
						label={ __( 'Border style', 'mappulse' ) }
						value={ style.zoneStrokeStyle }
						options={ [
							{
								value: 'solid',
								label: __( 'Solid', 'mappulse' ),
							},
							{
								value: 'dashed',
								label: __( 'Dashed', 'mappulse' ),
							},
							{
								value: 'dotted',
								label: __( 'Dotted', 'mappulse' ),
							},
						] }
						onChange={ ( zoneStrokeStyle ) =>
							set( { zoneStrokeStyle } )
						}
						__nextHasNoMarginBottom
						__next40pxDefaultSize
					/>
					<RadioControl
						label={ __( 'When an area is pointed at', 'mappulse' ) }
						selected={ style.hoverEffect }
						options={ [
							{
								value: 'fill',
								label: __(
									'Fill it with the hover color',
									'mappulse'
								),
							},
							{
								value: 'outline',
								label: __(
									'Outline it in the hover color',
									'mappulse'
								),
							},
						] }
						onChange={ ( hoverEffect ) => set( { hoverEffect } ) }
					/>
				</section>
			</div>
			<MapPreview preview={ preview } empty={ false } />
		</div>
	);
}
