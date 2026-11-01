/**
 * Pure helpers for the admin app (unit-tested in tests/js).
 */

export const PLACEHOLDER_RE = /<\/?\d+\/?>/g;

/**
 * Sorted list of placeholders in a masked string.
 *
 * @param {string} text Masked text.
 * @return {string[]} Placeholders.
 */
export function placeholders( text ) {
	return ( String( text || '' ).match( PLACEHOLDER_RE ) || [] ).sort();
}

/**
 * Whether a reviewer translation keeps exactly the placeholders of the source.
 *
 * @param {string} source      Masked source.
 * @param {string} translation Edited translation.
 * @param {string} kind        Segment kind (attributes have none).
 * @return {boolean} Valid.
 */
export function placeholdersMatch( source, translation, kind = 'html' ) {
	if ( kind === 'attr' ) {
		return true;
	}
	return placeholders( source ).join( ' ' ) === placeholders( translation ).join( ' ' );
}

/**
 * Splits masked text into text and placeholder tokens for display.
 *
 * @param {string} text Masked text.
 * @return {Array<{type: string, value: string}>} Tokens.
 */
export function tokenize( text ) {
	const out = [];
	const str = String( text || '' );
	let last = 0;
	str.replace( PLACEHOLDER_RE, ( match, offset ) => {
		if ( offset > last ) {
			out.push( { type: 'text', value: str.slice( last, offset ) } );
		}
		out.push( { type: 'tag', value: match } );
		last = offset + match.length;
		return match;
	} );
	if ( last < str.length ) {
		out.push( { type: 'text', value: str.slice( last ) } );
	}
	return out;
}

/**
 * Decodes the few entities the segmenter leaves in text, for display only.
 *
 * @param {string} text Text.
 * @return {string} Decoded.
 */
export function decodeEntities( text ) {
	return String( text || '' )
		.replace( /&nbsp;/g, ' ' )
		.replace( /&quot;/g, '"' )
		.replace( /&#0?39;|&apos;/g, "'" )
		.replace( /&lt;/g, '<' )
		.replace( /&gt;/g, '>' )
		.replace( /&#(\d+);/g, ( m, n ) => String.fromCharCode( parseInt( n, 10 ) ) )
		.replace( /&amp;/g, '&' );
}

/**
 * "#/review?post=12&lang=de" → { tab: 'review', query: { post: '12', lang: 'de' } }.
 *
 * @param {string} hash  location.hash.
 * @param {string[]} tabs Valid tab ids.
 * @return {{tab: string, query: Object}} Route.
 */
export function parseHash( hash, tabs ) {
	const clean = String( hash || '' ).replace( /^#\/?/, '' );
	const [ id, qs ] = clean.split( '?' );
	const query = {};
	( qs || '' ).split( '&' ).filter( Boolean ).forEach( ( pair ) => {
		const [ k, v = '' ] = pair.split( '=' );
		query[ decodeURIComponent( k ) ] = decodeURIComponent( v );
	} );
	return { tab: tabs.includes( id ) ? id : tabs[ 0 ], query };
}

/**
 * Compact number (1234 → "1,234", 1234567 → "1.2M").
 *
 * @param {number} n Number.
 * @return {string} Formatted.
 */
export function formatNumber( n ) {
	const v = Number( n ) || 0;
	if ( Math.abs( v ) >= 1e6 ) {
		return ( v / 1e6 ).toFixed( 1 ).replace( /\.0$/, '' ) + 'M';
	}
	return v.toLocaleString( 'en-US' );
}

/**
 * Percent of segments reused.
 *
 * @param {Object} stats { reused, total }.
 * @return {number} 0..100.
 */
export function reusePercent( stats ) {
	if ( ! stats || ! stats.total ) {
		return 0;
	}
	return Math.round( ( 100 * ( stats.reused || 0 ) ) / stats.total );
}

/**
 * Glossary terms → CSV (term, dnt, case_sensitive, notes, then one column per locale).
 *
 * @param {Array}    terms   Terms.
 * @param {string[]} locales Locales.
 * @return {string} CSV.
 */
export function glossaryToCsv( terms, locales ) {
	const esc = ( v ) => {
		const s = String( v === undefined || v === null ? '' : v );
		return /[",\n]/.test( s ) ? '"' + s.replace( /"/g, '""' ) + '"' : s;
	};
	const head = [ 'term', 'dnt', 'case_sensitive', 'notes', ...locales ];
	const rows = terms.map( ( t ) => [
		t.term,
		t.dnt ? 'yes' : 'no',
		t.case_sensitive ? 'yes' : 'no',
		t.notes || '',
		...locales.map( ( l ) => ( t.translations && t.translations[ l ] ) || '' ),
	] );
	return [ head, ...rows ].map( ( r ) => r.map( esc ).join( ',' ) ).join( '\n' );
}

/**
 * Example of a PHP date format for the locale screen.
 *
 * @param {string} format PHP format (d, j, m, n, Y, y).
 * @param {Date}   date   Date.
 * @return {string} Example.
 */
export function formatDateExample( format, date = new Date( 2026, 2, 14 ) ) {
	const pad = ( n ) => String( n ).padStart( 2, '0' );
	const map = {
		d: pad( date.getDate() ),
		j: String( date.getDate() ),
		m: pad( date.getMonth() + 1 ),
		n: String( date.getMonth() + 1 ),
		Y: String( date.getFullYear() ),
		y: String( date.getFullYear() ).slice( 2 ),
	};
	return String( format || '' ).replace( /[djmnYy]/g, ( c ) => map[ c ] );
}

/**
 * Example of the number format.
 *
 * @param {string} decimal   Decimal separator.
 * @param {string} thousands Thousands separator.
 * @return {string} "1.234.567,89".
 */
export function formatNumberExample( decimal, thousands ) {
	return '1' + thousands + '234' + thousands + '567' + decimal + '89';
}
