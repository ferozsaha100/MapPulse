import { PRESETS, COLOR_KEYS, applyPreset } from '../style/presets';
import { contrastRatio, readable } from '../style/contrast';

const DEFAULTS = {
	water: '#DCE8EA',
	coast: '#B4C9CF',
	land: '#F6F1E6',
	context: '#EEE7D8',
	contextStroke: '#FFFFFF',
	zone: '#DCCFB6',
	zoneStroke: '#FFFFFF',
	zoneHover: '#1F3A5F',
	label: '#2B2F36',
	labelHover: '#FFFFFF',
	waterLabel: '#3F6470',
	road: '#FFFFFF',
	roadCasing: '#BDB198',
	labelFont: 'Inter, Helvetica, Arial, sans-serif',
	labelSize: 11.5,
	preset: 'atlas',
	zoneStrokeWidth: 1.6,
	zoneStrokeStyle: 'solid',
	hoverEffect: 'fill',
	brand: '',
};
const HEX = /^#[0-9A-F]{6}$/;

describe( 'presets', () => {
	it( 'lists Atlas and Brand Tint', () => {
		expect( Object.keys( PRESETS ) ).toEqual( [ 'atlas', 'brand-tint' ] );
		expect( COLOR_KEYS ).toHaveLength( 13 );
	} );

	it( 'Atlas is the built-in look, and keeps the font, size and border the person chose', () => {
		const mine = {
			...DEFAULTS,
			zone: '#000000',
			labelFont: 'Georgia, serif',
			labelSize: 14,
			zoneStrokeStyle: 'dashed',
		};
		expect( applyPreset( 'atlas', mine, '', DEFAULTS ) ).toEqual( {
			...DEFAULTS,
			labelFont: 'Georgia, serif',
			labelSize: 14,
			zoneStrokeStyle: 'dashed',
		} );
	} );

	it( 'Brand Tint derives every color from one brand color', () => {
		const style = applyPreset(
			'brand-tint',
			DEFAULTS,
			'#1f3a5f',
			DEFAULTS
		);
		for ( const key of COLOR_KEYS ) {
			expect( style[ key ] ).toMatch( HEX );
		}
		expect( style.preset ).toBe( 'brand-tint' );
		expect( style.brand ).toBe( '#1F3A5F' );
		expect( style.zoneHover ).toBe( '#1F3A5F' );
		expect( style.zone ).not.toBe( DEFAULTS.zone );
		expect( readable( style ).ok ).toBe( true );
	} );

	it( 'stays valid and readable at the extremes', () => {
		for ( const brand of [ '#FFFFFF', '#000000', '#FFFF00', '#7F7F7F' ] ) {
			const style = applyPreset(
				'brand-tint',
				DEFAULTS,
				brand,
				DEFAULTS
			);
			for ( const key of COLOR_KEYS ) {
				expect( style[ key ] ).toMatch( HEX );
			}
			expect( readable( style ).ok ).toBe( true );
		}
	} );

	it( 'keeps the areas clearly apart from the water and the land around them', () => {
		for ( const brand of [
			'#1F3A5F',
			'#7A1F3D',
			'#0B6E4F',
			'#C8A24A',
			'#FFFFFF',
			'#000000',
		] ) {
			const style = applyPreset(
				'brand-tint',
				DEFAULTS,
				brand,
				DEFAULTS
			);
			expect( contrastRatio( style.zone, style.water ) ).toBeGreaterThan(
				1.5
			);
			expect( contrastRatio( style.zone, style.land ) ).toBeGreaterThan(
				1.5
			);
			expect(
				contrastRatio( style.zone, style.context )
			).toBeGreaterThan( 1.35 );
		}
	} );

	it( 'falls back to Atlas for an unknown preset or a bad brand color', () => {
		expect( applyPreset( 'other', DEFAULTS, '', DEFAULTS ).preset ).toBe(
			'atlas'
		);
		expect(
			applyPreset( 'brand-tint', DEFAULTS, 'blue', DEFAULTS ).brand
		).toMatch( HEX );
	} );
} );

describe( 'contrast', () => {
	it( 'measures the WCAG ratio', () => {
		expect( contrastRatio( '#000000', '#FFFFFF' ) ).toBeCloseTo( 21, 5 );
		expect( contrastRatio( '#FFFFFF', '#FFFFFF' ) ).toBeCloseTo( 1, 5 );
		expect( contrastRatio( '#777', '#FFFFFF' ) ).toBeCloseTo( 4.48, 1 );
		expect( contrastRatio( 'nope', '#FFFFFF' ) ).toBe( 1 );
	} );

	it( 'reports label colors nobody can read', () => {
		expect( readable( DEFAULTS ) ).toMatchObject( { ok: true } );
		const pale = readable( { ...DEFAULTS, label: '#E0D6C0' } );
		expect( pale.ok ).toBe( false );
		expect( pale.label ).toBeLessThan( 4.5 );
		expect( readable( { ...DEFAULTS, labelHover: '#223355' } ).ok ).toBe(
			false
		);
		// With the outline effect the label never sits on the hover color.
		expect(
			readable( {
				...DEFAULTS,
				labelHover: '#223355',
				hoverEffect: 'outline',
			} ).ok
		).toBe( true );
	} );
} );
