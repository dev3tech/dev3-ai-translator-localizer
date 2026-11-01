<?php
/**
 * Reassembles templates with translations.
 *
 * @package Dev3\AiTranslatorLocalizer
 */

namespace Dev3\AiTranslatorLocalizer\Content;

defined( 'ABSPATH' ) || exit;

/**
 * Untranslated (or unchanged) segments are written back from their raw source, so a template rendered
 * without translations reproduces the input exactly.
 */
class Renderer {

	const PLACEHOLDER = '/<(\/?)(\d+)(\/?)>/';

	/**
	 * Renders pieces.
	 *
	 * @param array $pieces       Pieces.
	 * @param array $segments     Segments by id.
	 * @param array $translations Segment id => masked translation.
	 * @return string
	 */
	public static function render( array $pieces, array $segments, array $translations ) {
		$out = '';
		foreach ( $pieces as $piece ) {
			if ( is_string( $piece ) ) {
				$out .= $piece;
			} elseif ( isset( $piece['run'] ) ) {
				$out .= self::run( $piece, $segments, $translations );
			} elseif ( isset( $piece['seg'] ) ) {
				$out .= self::simple( $segments[ $piece['seg'] ], $translations );
			} elseif ( isset( $piece['delim'] ) ) {
				$out .= self::delimiter( $piece, $segments, $translations );
			}
		}
		return $out;
	}

	/**
	 * Translation for a segment when it differs from the source.
	 *
	 * @param array $segment      Segment.
	 * @param array $translations Translations.
	 * @return string|null
	 */
	private static function changed( array $segment, array $translations ) {
		if ( ! isset( $translations[ $segment['id'] ] ) ) {
			return null;
		}
		$t = (string) $translations[ $segment['id'] ];
		return ( '' === trim( $t ) || $t === $segment['source'] ) ? null : $t;
	}

	/**
	 * Text or attribute segment.
	 *
	 * @param array $segment      Segment.
	 * @param array $translations Translations.
	 * @return string
	 */
	private static function simple( array $segment, array $translations ) {
		$t = self::changed( $segment, $translations );
		if ( null === $t ) {
			return $segment['raw'];
		}
		if ( 'attr' === $segment['kind'] ) {
			return esc_attr( wp_strip_all_tags( $t ) );
		}
		$vars = isset( $segment['vars'] ) ? $segment['vars'] : array();
		return preg_replace_callback(
			self::PLACEHOLDER,
			static function ( $m ) use ( $vars ) {
				return ( '' === $m[1] && isset( $vars[ (int) $m[2] ] ) ) ? $vars[ (int) $m[2] ] : '';
			},
			$t
		);
	}

	/**
	 * Text run with inline tags.
	 *
	 * @param array $piece        Run piece.
	 * @param array $segments     Segments.
	 * @param array $translations Translations.
	 * @return string
	 */
	private static function run( array $piece, array $segments, array $translations ) {
		$segment = $segments[ $piece['run'] ];
		$t       = self::changed( $segment, $translations );
		$open    = array();
		$dirty   = null !== $t;
		foreach ( $piece['tags'] as $n => $tag ) {
			$open[ $n ] = self::render( $tag['open'], $segments, $translations );
			$dirty      = $dirty || self::tag_changed( $tag['open'], $segments, $translations );
		}
		if ( ! $dirty ) {
			return $segment['raw'];
		}
		$masked = null === $t ? $segment['source'] : self::escape_text( $t );
		$tags   = $piece['tags'];
		return preg_replace_callback(
			self::PLACEHOLDER,
			static function ( $m ) use ( $open, $tags ) {
				$n = (int) $m[2];
				if ( ! isset( $tags[ $n ] ) ) {
					return '';
				}
				if ( '/' === $m[1] ) {
					return null === $tags[ $n ]['close'] ? '' : $tags[ $n ]['close'];
				}
				return $open[ $n ];
			},
			$masked
		);
	}

	/**
	 * Whether a tag's attribute segments are translated.
	 *
	 * @param array $pieces       Tag pieces.
	 * @param array $segments     Segments.
	 * @param array $translations Translations.
	 * @return bool
	 */
	private static function tag_changed( array $pieces, array $segments, array $translations ) {
		foreach ( $pieces as $piece ) {
			if ( is_array( $piece ) && isset( $piece['seg'] ) && null !== self::changed( $segments[ $piece['seg'] ], $translations ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Block delimiter with translated attributes.
	 *
	 * @param array $piece        Delimiter piece.
	 * @param array $segments     Segments.
	 * @param array $translations Translations.
	 * @return string
	 */
	private static function delimiter( array $piece, array $segments, array $translations ) {
		$attrs   = $piece['attrs'];
		$changed = false;
		foreach ( $piece['paths'] as $entry ) {
			$value = self::render( $entry['template'], $segments, $translations );
			if ( $value === $entry['original'] ) {
				continue;
			}
			$changed = true;
			$ref     = &$attrs;
			foreach ( $entry['path'] as $key ) {
				$ref = &$ref[ $key ];
			}
			$ref = $value;
			unset( $ref );
		}
		if ( ! $changed ) {
			return $piece['raw'];
		}
		return $piece['prefix'] . serialize_block_attributes( $attrs ) . $piece['suffix'];
	}

	/**
	 * Makes model output safe as HTML text: strips invented tags, escapes stray < > and bare ampersands,
	 * leaving our numbered placeholders intact.
	 *
	 * @param string $text Masked translation.
	 * @return string
	 */
	public static function escape_text( $text ) {
		$parts = preg_split( '/(<\/?\d+\/?>)/', (string) $text, -1, PREG_SPLIT_DELIM_CAPTURE );
		foreach ( $parts as $i => $part ) {
			if ( $i % 2 ) {
				continue;
			}
			$part        = preg_replace( '/<\/?[a-zA-Z][^>]*>/', '', $part );
			$part        = preg_replace( '/&(?!(?:[a-zA-Z][a-zA-Z0-9]{1,31}|#\d{1,7}|#x[0-9a-fA-F]{1,6});)/', '&amp;', $part );
			$parts[ $i ] = str_replace( array( '<', '>' ), array( '&lt;', '&gt;' ), $part );
		}
		return implode( '', $parts );
	}

	/**
	 * Placeholder multiset of a masked string (for validation).
	 *
	 * @param string $text Masked text.
	 * @return string[] Sorted list.
	 */
	public static function placeholders( $text ) {
		preg_match_all( self::PLACEHOLDER, (string) $text, $m );
		$list = $m[0];
		sort( $list );
		return $list;
	}
}
