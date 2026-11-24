import { clampOffset } from '../links/label-offset';

// A one-line label 60 wide and 16 high, placed at the middle of a 1000 by 800 frame.
const placed = { x: 500, y: 400, size: 10, lines: [ 'ABCDEFG' ] };
const frame = { width: 1000, height: 800 };

describe( 'clampOffset', () => {
	it( 'leaves a move inside the frame as it is', () => {
		expect( clampOffset( placed, frame, { dx: 40, dy: -30 } ) ).toEqual( {
			dx: 40,
			dy: -30,
		} );
	} );

	it( 'stops at the frame edge, so the stored offset is the one drawn', () => {
		const right = clampOffset( placed, frame, { dx: 5000, dy: 0 } );
		expect( right.dx ).toBeLessThan( 500 );
		expect( right.dx ).toBeGreaterThan( 440 );
		// One step back moves the label at once: nothing invisible has piled up.
		expect(
			clampOffset( placed, frame, { dx: right.dx - 8, dy: 0 } ).dx
		).toBe( right.dx - 8 );
		expect(
			clampOffset( placed, frame, { dx: 0, dy: -5000 } ).dy
		).toBeGreaterThan( -400 );
		expect( clampOffset( placed, frame, { dx: -5000, dy: 5000 } ) ).toEqual(
			{ dx: -right.dx, dy: expect.any( Number ) }
		);
	} );

	it( 'rounds to a tenth and copes with a missing label', () => {
		expect( clampOffset( placed, frame, { dx: 1.26, dy: 0.04 } ) ).toEqual(
			{ dx: 1.3, dy: 0 }
		);
		expect( clampOffset( null, frame, { dx: 9, dy: 9 } ) ).toEqual( {
			dx: 9,
			dy: 9,
		} );
	} );
} );
