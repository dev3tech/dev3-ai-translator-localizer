<?php
/**
 * Loss-free HTML / block-markup / shortcode tokenizer.
 *
 * Concatenating the `raw` of every token always reproduces the input byte for byte, which is what
 * lets the segmenter reassemble content exactly.
 *
 * @package Dev3\AiTranslatorLocalizer
 */

namespace Dev3\AiTranslatorLocalizer\Content;

defined( 'ABSPATH' ) || exit;

/**
 * Splits markup into text, tag, comment, block-delimiter, shortcode and raw tokens.
 */
class Html_Tokenizer {

	/**
	 * Elements whose content is never parsed as markup.
	 */
	const RAW_TEXT = array( 'script', 'style', 'textarea', 'title', 'xmp' );

	/**
	 * Void elements.
	 */
	const VOID = array( 'area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'param', 'source', 'track', 'wbr' );

	/**
	 * Block delimiter grammar (identical to WP_Block_Parser).
	 */
	const BLOCK_RE = '/<!--\s+(?P<closer>\/)?wp:(?P<namespace>[a-z][a-z0-9_-]*\/)?(?P<name>[a-z][a-z0-9_-]*)\s+(?P<attrs>{(?:(?:[^}]+|}+(?=})|(?!}\s+\/?-->).)*+)?}\s+)?(?P<void>\/)?-->/sA';

	/**
	 * Tokenizes a string.
	 *
	 * @param string $html Markup.
	 * @param bool   $shortcodes Recognise [shortcode] tokens.
	 * @return array[] Tokens: { type, raw, name?, closing?, self?, block? }.
	 */
	public static function tokenize( $html, $shortcodes = true ) {
		$html   = (string) $html;
		$len    = strlen( $html );
		$pos    = 0;
		$tokens = array();
		$text   = '';

		while ( $pos < $len ) {
			$ch = $html[ $pos ];
			if ( '<' === $ch ) {
				$token = self::read_angle( $html, $pos );
				if ( $token ) {
					if ( '' !== $text ) {
						$tokens[] = array(
							'type' => 'text',
							'raw'  => $text,
						);
						$text     = '';
					}
					$tokens[] = $token;
					$pos     += strlen( $token['raw'] );
					// Raw-text elements: swallow content up to the matching close tag.
					if ( 'tag' === $token['type'] && ! $token['closing'] && ! $token['self'] && in_array( $token['name'], self::RAW_TEXT, true ) ) {
						$close = stripos( $html, '</' . $token['name'], $pos );
						$end   = false === $close ? $len : $close;
						if ( $end > $pos ) {
							$tokens[] = array(
								'type' => 'raw',
								'raw'  => substr( $html, $pos, $end - $pos ),
							);
						}
						$pos = $end;
					}
					continue;
				}
			} elseif ( '[' === $ch && $shortcodes ) {
				if ( preg_match( '/\[(\/)?([a-zA-Z][a-zA-Z0-9_-]*)((?:"[^"]*"|\'[^\']*\'|[^\]"\'\[])*)\]/A', $html, $m, 0, $pos ) ) {
					if ( '' !== $text ) {
						$tokens[] = array(
							'type' => 'text',
							'raw'  => $text,
						);
						$text     = '';
					}
					$tokens[] = array(
						'type'    => 'shortcode',
						'raw'     => $m[0],
						'name'    => strtolower( $m[2] ),
						'closing' => '/' === $m[1],
					);
					$pos     += strlen( $m[0] );
					continue;
				}
			}
			// Plain text up to the next interesting character.
			$next  = strcspn( $html, $shortcodes ? '<[' : '<', $pos + 1 ) + $pos + 1;
			$text .= substr( $html, $pos, $next - $pos );
			$pos   = $next;
		}
		if ( '' !== $text ) {
			$tokens[] = array(
				'type' => 'text',
				'raw'  => $text,
			);
		}
		return $tokens;
	}

	/**
	 * Reads a token starting with "<".
	 *
	 * @param string $html Markup.
	 * @param int    $pos  Offset.
	 * @return array|null
	 */
	private static function read_angle( $html, $pos ) {
		if ( 0 === substr_compare( $html, '<!--', $pos, 4 ) ) {
			if ( preg_match( self::BLOCK_RE, $html, $m, 0, $pos ) ) {
				return array(
					'type'    => 'block',
					'raw'     => $m[0],
					'name'    => ( empty( $m['namespace'] ) ? 'core/' : $m['namespace'] ) . $m['name'],
					'closing' => ! empty( $m['closer'] ),
					'self'    => ! empty( $m['void'] ),
				);
			}
			$end = strpos( $html, '-->', $pos + 4 );
			return array(
				'type' => 'comment',
				'raw'  => false === $end ? substr( $html, $pos ) : substr( $html, $pos, $end + 3 - $pos ),
			);
		}
		if ( 0 === substr_compare( $html, '<![CDATA[', $pos, 9 ) ) {
			$end = strpos( $html, ']]>', $pos );
			return array(
				'type' => 'comment',
				'raw'  => false === $end ? substr( $html, $pos ) : substr( $html, $pos, $end + 3 - $pos ),
			);
		}
		if ( preg_match( '/<(\/?)([a-zA-Z][a-zA-Z0-9:-]*)((?:"[^"]*"|\'[^\']*\'|[^\'">])*)>/A', $html, $m, 0, $pos ) ) {
			$name = strtolower( $m[2] );
			return array(
				'type'    => 'tag',
				'raw'     => $m[0],
				'name'    => $name,
				'closing' => '/' === $m[1],
				'self'    => '/' !== $m[1] && ( in_array( $name, self::VOID, true ) || '/' === substr( rtrim( $m[3] ), -1 ) ),
				'attrs'   => $m[3],
			);
		}
		if ( preg_match( '/<![a-zA-Z][^>]*>/A', $html, $m, 0, $pos ) ) {
			return array(
				'type' => 'comment',
				'raw'  => $m[0],
			);
		}
		return null;
	}

	/**
	 * Finds attributes in a tag token with their byte offsets inside `raw`.
	 *
	 * @param string $raw Tag markup.
	 * @return array[] { name, value (raw, still entity-encoded), offset, length }
	 */
	public static function attributes( $raw ) {
		$out = array();
		if ( ! preg_match( '/^<[a-zA-Z][a-zA-Z0-9:-]*/', $raw, $head ) ) {
			return $out;
		}
		$offset = strlen( $head[0] );
		if ( preg_match_all( '/([^\s"\'>\/=]+)(?:\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'>]+)))?/', $raw, $all, PREG_SET_ORDER | PREG_OFFSET_CAPTURE, $offset ) ) {
			foreach ( $all as $m ) {
				$value = null;
				foreach ( array( 2, 3, 4 ) as $group ) {
					if ( isset( $m[ $group ] ) && -1 !== $m[ $group ][1] ) {
						$value = $m[ $group ];
						break;
					}
				}
				$out[] = array(
					'name'   => strtolower( $m[1][0] ),
					'value'  => $value ? $value[0] : '',
					'offset' => $value ? $value[1] : -1,
					'length' => $value ? strlen( $value[0] ) : 0,
				);
			}
		}
		return $out;
	}
}
