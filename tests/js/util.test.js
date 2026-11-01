import {
	placeholders,
	placeholdersMatch,
	tokenize,
	decodeEntities,
	parseHash,
	formatNumber,
	reusePercent,
	glossaryToCsv,
	formatDateExample,
	formatNumberExample,
} from '../../src/admin/util';
import { restUrl } from '../../src/core3/config';

describe( 'placeholders', () => {
	it( 'collects and sorts placeholders', () => {
		expect( placeholders( 'a <2/> <1>b</1>' ) ).toEqual( [ '</1>', '<1>', '<2/>' ] );
	} );
	it( 'validates reviewer edits', () => {
		expect( placeholdersMatch( 'See <1>guide</1>', 'Siehe <1>Anleitung</1>' ) ).toBe( true );
		expect( placeholdersMatch( 'See <1>guide</1>', 'Siehe Anleitung' ) ).toBe( false );
		expect( placeholdersMatch( 'Alt', 'Alt <1>', 'attr' ) ).toBe( true );
	} );
	it( 'tokenizes for display', () => {
		expect( tokenize( 'A <1>b</1>' ) ).toEqual( [
			{ type: 'text', value: 'A ' },
			{ type: 'tag', value: '<1>' },
			{ type: 'text', value: 'b' },
			{ type: 'tag', value: '</1>' },
		] );
	} );
} );

describe( 'formatting', () => {
	it( 'decodes entities', () => {
		expect( decodeEntities( 'Tom &amp; Jerry&nbsp;&#8217;s' ) ).toBe( 'Tom & Jerry ’s' );
	} );
	it( 'parses hash routes', () => {
		expect( parseHash( '#/review?post=12&lang=de_DE', [ 'dashboard', 'review' ] ) ).toEqual( { tab: 'review', query: { post: '12', lang: 'de_DE' } } );
		expect( parseHash( '#/nope', [ 'dashboard', 'review' ] ).tab ).toBe( 'dashboard' );
	} );
	it( 'formats numbers and reuse', () => {
		expect( formatNumber( 1234 ) ).toBe( '1,234' );
		expect( formatNumber( 2500000 ) ).toBe( '2.5M' );
		expect( reusePercent( { reused: 3, total: 4 } ) ).toBe( 75 );
		expect( reusePercent( null ) ).toBe( 0 );
	} );
	it( 'builds glossary CSV', () => {
		const csv = glossaryToCsv( [ { term: 'Cart, big', dnt: false, case_sensitive: true, notes: '', translations: { de_DE: 'Warenkorb' } } ], [ 'de_DE' ] );
		expect( csv ).toBe( 'term,dnt,case_sensitive,notes,de_DE\n"Cart, big",no,yes,,Warenkorb' );
	} );
	it( 'shows locale examples', () => {
		expect( formatDateExample( 'd.m.Y' ) ).toBe( '14.03.2026' );
		expect( formatDateExample( 'm/d/Y' ) ).toBe( '03/14/2026' );
		expect( formatNumberExample( ',', '.' ) ).toBe( '1.234.567,89' );
	} );
	it( 'builds REST URLs for plain permalinks', () => {
		expect( restUrl( 'jobs/1?page=2', '/index.php?rest_route=/dev3tl/v1/' ) ).toBe( '/index.php?rest_route=/dev3tl/v1/jobs/1&page=2' );
	} );
} );
