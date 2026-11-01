<?php
/**
 * Block-aware segmenter: block markup, classic HTML, shortcodes and plain text → translatable segments.
 *
 * Text runs (text plus inline tags such as <a>, <strong>, <br>) become one segment each, with inline tags
 * replaced by numbered placeholders (<1>…</1>, <2/>), so the AI never sees or rewrites URLs, classes or
 * attributes. Translatable attributes (alt, title, placeholder, aria-label, button values) and whitelisted
 * block-comment attributes become their own segments. Everything else is kept as literal pieces, so a
 * document rendered without translations is identical to the input.
 *
 * @package Dev3\AiTranslatorLocalizer
 */

namespace Dev3\AiTranslatorLocalizer\Content;

defined( 'ABSPATH' ) || exit;

/**
 * Builds Document field templates.
 */
class Segmenter {

	/**
	 * Inline elements that stay inside a text run.
	 */
	const INLINE = array( 'a', 'abbr', 'b', 'bdi', 'bdo', 'big', 'br', 'cite', 'code', 'data', 'del', 'dfn', 'em', 'font', 'i', 'img', 'ins', 'kbd', 'mark', 'q', 's', 'samp', 'small', 'span', 'strike', 'strong', 'sub', 'sup', 'time', 'tt', 'u', 'var', 'wbr', 'svg', 'math', 'abbr' );

	/**
	 * Elements whose content is kept verbatim.
	 */
	const OPAQUE = array( 'code', 'kbd', 'samp', 'var', 'svg', 'math', 'pre', 'noscript', 'template', 'iframe', 'object', 'head', 'script', 'style', 'textarea' );

	/**
	 * Attributes translated on any element.
	 */
	const ATTRS = array( 'alt', 'title', 'placeholder', 'aria-label', 'aria-description' );

	/**
	 * Document being filled.
	 *
	 * @var Document
	 */
	private $doc;

	/**
	 * Field name.
	 *
	 * @var string
	 */
	private $field;

	/**
	 * Constructor.
	 *
	 * @param Document $doc   Document.
	 * @param string   $field Field name (segment id prefix).
	 */
	public function __construct( Document $doc, $field ) {
		$this->doc   = $doc;
		$this->field = (string) $field;
	}

	/**
	 * Segments a markup field (post content, excerpt, HTML meta) and registers it on the document.
	 *
	 * @param Document $doc   Document.
	 * @param string   $field Field name.
	 * @param string   $html  Value.
	 * @param array    $extra Extra field data.
	 */
	public static function add_html( Document $doc, $field, $html, array $extra = array() ) {
		$self = new self( $doc, $field );
		$doc->add_field( $field, $html, $self->html( (string) $html ), $extra + array( 'kind' => 'html' ) );
	}

	/**
	 * Segments a plain-text field (title, SEO title, attribute names).
	 *
	 * @param Document $doc     Document.
	 * @param string   $field   Field name.
	 * @param string   $text    Value.
	 * @param string   $context Context hint for the AI.
	 * @param array    $extra   Extra field data.
	 */
	public static function add_text( Document $doc, $field, $text, $context = '', array $extra = array() ) {
		$self = new self( $doc, $field );
		$doc->add_field( $field, $text, $self->text( (string) $text, $context ), $extra + array( 'kind' => 'text' ) );
	}

	/**
	 * Plain text → pieces (one segment when it contains words).
	 *
	 * @param string $text    Text.
	 * @param string $context Context.
	 * @return array Pieces.
	 */
	public function text( $text, $context = '' ) {
		if ( ! self::has_words( $text ) ) {
			return array( $text );
		}
		$lead  = strlen( $text ) - strlen( ltrim( $text ) );
		$core  = trim( $text );
		$trail = strlen( $text ) - $lead - strlen( $core );
		$vars  = array();
		$n     = 0;
		$mask  = preg_replace_callback(
			'/%%[A-Za-z0-9_]+%%|%[a-z][a-z0-9_]*%|\{\{[^{}]{1,60}\}\}|%(?:\d+\$)?[sd]|\[[^\[\]]{1,200}\]|<\d+\/?>/',
			static function ( $m ) use ( &$vars, &$n ) {
				++$n;
				$vars[ $n ] = $m[0];
				return '<' . $n . '/>';
			},
			$core
		);
		if ( ! self::has_words( preg_replace( '/<\d+\/>/', '', $mask ) ) ) {
			return array( $text );
		}
		$id     = $this->doc->add(
			array(
				'id'      => $this->doc->next_id( $this->field ),
				'kind'    => 'text',
				'source'  => $mask,
				'raw'     => $core,
				'vars'    => $vars,
				'field'   => $this->field,
				'context' => $context,
			)
		);
		$pieces = array();
		if ( $lead ) {
			$pieces[] = substr( $text, 0, $lead );
		}
		$pieces[] = array( 'seg' => $id );
		if ( $trail ) {
			$pieces[] = substr( $text, -$trail );
		}
		return $pieces;
	}

	/**
	 * Markup → pieces.
	 *
	 * @param string $html Markup.
	 * @return array Pieces.
	 */
	public function html( $html ) {
		$tokens  = $this->group_opaque( self::split_paragraph_breaks( Html_Tokenizer::tokenize( $html ) ) );
		$pieces  = array();
		$run     = array();
		$context = array(
			'tag'   => '',
			'block' => '',
		);
		$blocks  = array();
		$tags    = array();

		foreach ( $tokens as $token ) {
			switch ( $token['type'] ) {
				case 'text':
				case 'shortcode':
				case 'opaque_inline':
					$run[] = $token;
					break;
				case 'tag':
					if ( in_array( $token['name'], self::INLINE, true ) ) {
						$run[] = $token;
						break;
					}
					$this->flush( $run, $pieces, $context );
					$this->push_tag( $token, $pieces, $token['name'] );
					if ( ! $token['closing'] && ! $token['self'] ) {
						$tags[] = $token['name'];
					} elseif ( $token['closing'] ) {
						$at = array_search( $token['name'], array_reverse( $tags, true ), true );
						if ( false !== $at ) {
							$tags = array_slice( $tags, 0, $at );
						}
					}
					$context['tag'] = $tags ? end( $tags ) : '';
					break;
				case 'block':
					$this->flush( $run, $pieces, $context );
					$pieces[] = $this->delimiter( $token );
					if ( ! $token['closing'] && ! $token['self'] ) {
						$blocks[] = $token['name'];
					} elseif ( $token['closing'] ) {
						array_pop( $blocks );
					}
					$context['block'] = $blocks ? end( $blocks ) : '';
					break;
				default:
					// Comments, raw text, block-level opaque groups, paragraph breaks.
					$this->flush( $run, $pieces, $context );
					$pieces[] = $token['raw'];
			}
		}
		$this->flush( $run, $pieces, $context );
		return self::merge_literals( $pieces );
	}

	/**
	 * Blank lines separate paragraphs in classic content (wpautop), so they end a run.
	 *
	 * @param array $tokens Tokens.
	 * @return array
	 */
	private static function split_paragraph_breaks( array $tokens ) {
		$out = array();
		foreach ( $tokens as $token ) {
			if ( 'text' !== $token['type'] || ! preg_match( '/\n[ \t]*\n/', $token['raw'] ) ) {
				$out[] = $token;
				continue;
			}
			$parts = preg_split( '/(\n[ \t]*\n\s*)/', $token['raw'], -1, PREG_SPLIT_DELIM_CAPTURE );
			foreach ( $parts as $i => $part ) {
				if ( '' === $part ) {
					continue;
				}
				$out[] = array(
					'type' => $i % 2 ? 'break' : 'text',
					'raw'  => $part,
				);
			}
		}
		return $out;
	}

	/**
	 * Collapses opaque elements (code, pre, svg, translate="no", .notranslate) into single tokens.
	 *
	 * @param array $tokens Tokens.
	 * @return array
	 */
	private function group_opaque( array $tokens ) {
		$out   = array();
		$count = count( $tokens );
		for ( $i = 0; $i < $count; $i++ ) {
			$token = $tokens[ $i ];
			if ( 'tag' !== $token['type'] || $token['closing'] || $token['self'] || ! self::is_opaque( $token ) ) {
				$out[] = $token;
				continue;
			}
			$depth = 1;
			$raw   = $token['raw'];
			$j     = $i + 1;
			for ( ; $j < $count && $depth > 0; $j++ ) {
				$t    = $tokens[ $j ];
				$raw .= $t['raw'];
				if ( 'tag' === $t['type'] && $t['name'] === $token['name'] && ! $t['self'] ) {
					$depth += $t['closing'] ? -1 : 1;
				}
			}
			if ( $depth > 0 ) {
				$out[] = $token; // Unclosed: keep the tag alone.
				continue;
			}
			$out[] = array(
				'type' => in_array( $token['name'], self::INLINE, true ) ? 'opaque_inline' : 'opaque',
				'raw'  => $raw,
				'name' => $token['name'],
			);
			$i     = $j - 1;
		}
		return $out;
	}

	/**
	 * Opaque element test.
	 *
	 * @param array $token Tag token.
	 * @return bool
	 */
	private static function is_opaque( array $token ) {
		if ( in_array( $token['name'], self::OPAQUE, true ) ) {
			return true;
		}
		$attrs = isset( $token['attrs'] ) ? $token['attrs'] : '';
		if ( '' === $attrs ) {
			return false;
		}
		foreach ( Html_Tokenizer::attributes( $token['raw'] ) as $attr ) {
			if ( ( 'translate' === $attr['name'] && 'no' === strtolower( $attr['value'] ) ) || 'data-no-translation' === $attr['name'] ) {
				return true;
			}
			if ( 'class' === $attr['name'] && preg_match( '/(^|\s)(notranslate|dev3tl-notranslate)(\s|$)/', $attr['value'] ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Tag → pieces, with translatable attribute values as segments.
	 *
	 * @param array  $token  Tag token.
	 * @param array  $pieces Pieces (by reference).
	 * @param string $context Context.
	 */
	private function push_tag( array $token, array &$pieces, $context ) {
		foreach ( $this->tag_pieces( $token, $context ) as $piece ) {
			$pieces[] = $piece;
		}
	}

	/**
	 * Tag pieces.
	 *
	 * @param array  $token   Tag token.
	 * @param string $context Context.
	 * @return array
	 */
	private function tag_pieces( array $token, $context ) {
		if ( 'tag' !== $token['type'] || $token['closing'] || empty( $token['attrs'] ) ) {
			return array( $token['raw'] );
		}
		$raw    = $token['raw'];
		$attrs  = Html_Tokenizer::attributes( $raw );
		$names  = array_column( $attrs, 'name' );
		$type   = '';
		$at     = array_search( 'type', $names, true );
		$type   = false === $at ? '' : strtolower( $attrs[ $at ]['value'] );
		$pieces = array();
		$cursor = 0;
		foreach ( $attrs as $attr ) {
			$wanted = in_array( $attr['name'], self::ATTRS, true )
				|| ( 'value' === $attr['name'] && 'input' === $token['name'] && in_array( $type, array( 'submit', 'button', 'reset' ), true ) )
				|| ( 'label' === $attr['name'] && in_array( $token['name'], array( 'track', 'optgroup', 'option' ), true ) );
			/**
			 * Filters whether an HTML attribute value is translated.
			 *
			 * @param bool   $wanted Translate it.
			 * @param string $name   Attribute name.
			 * @param string $tag    Element name.
			 */
			$wanted = apply_filters( 'dev3tl_translate_html_attribute', $wanted, $attr['name'], $token['name'] );
			if ( ! $wanted || $attr['offset'] < 0 ) {
				continue;
			}
			$decoded = html_entity_decode( $attr['value'], ENT_QUOTES | ENT_HTML5, 'UTF-8' );
			if ( ! self::has_words( $decoded ) || self::is_url( $decoded ) ) {
				continue;
			}
			$pieces[] = substr( $raw, $cursor, $attr['offset'] - $cursor );
			$pieces[] = array(
				'seg' => $this->doc->add(
					array(
						'id'      => $this->doc->next_id( $this->field ),
						'kind'    => 'attr',
						'source'  => $decoded,
						'raw'     => $attr['value'],
						'field'   => $this->field,
						'context' => $token['name'] . '@' . $attr['name'] . ( $context ? ' in ' . $context : '' ),
					)
				),
			);
			$cursor   = $attr['offset'] + $attr['length'];
		}
		if ( ! $pieces ) {
			return array( $raw );
		}
		$pieces[] = substr( $raw, $cursor );
		return $pieces;
	}

	/**
	 * Ends the current text run.
	 *
	 * @param array $run     Run tokens (reset by reference).
	 * @param array $pieces  Pieces (by reference).
	 * @param array $context Context.
	 */
	private function flush( array &$run, array &$pieces, array $context ) {
		if ( ! $run ) {
			return;
		}
		$items = $run;
		$run   = array();

		$text = '';
		foreach ( $items as $item ) {
			if ( 'text' === $item['type'] ) {
				$text .= $item['raw'];
			}
		}
		$decoded = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		if ( ! self::has_words( $decoded ) || self::is_url( trim( $decoded ) ) ) {
			foreach ( $items as $item ) {
				$this->push_tag( $item, $pieces, $context['tag'] );
			}
			return;
		}

		// Trim leading/trailing items that carry no words.
		$start = 0;
		$end   = count( $items ) - 1;
		while ( $start <= $end && self::trimmable( $items[ $start ], true ) ) {
			++$start;
		}
		while ( $end >= $start && self::trimmable( $items[ $end ], false ) ) {
			--$end;
		}
		// Unwrap a tag pair that encloses the whole run (<a>Buy now</a> → "Buy now").
		while ( $end > $start && 'tag' === $items[ $start ]['type'] && ! $items[ $start ]['closing'] && ! $items[ $start ]['self']
			&& 'tag' === $items[ $end ]['type'] && $items[ $end ]['closing'] && $items[ $end ]['name'] === $items[ $start ]['name']
			&& self::encloses( $items, $start, $end ) ) {
			++$start;
			--$end;
			while ( $start <= $end && self::trimmable( $items[ $start ], true ) ) {
				++$start;
			}
			while ( $end >= $start && self::trimmable( $items[ $end ], false ) ) {
				--$end;
			}
		}
		if ( $end < $start ) {
			foreach ( $items as $item ) {
				$this->push_tag( $item, $pieces, $context['tag'] );
			}
			return;
		}
		for ( $i = 0; $i < $start; $i++ ) {
			$this->push_tag( $items[ $i ], $pieces, $context['tag'] );
		}
		$core = array_slice( $items, $start, $end - $start + 1 );
		$tail = array_slice( $items, $end + 1 );

		// Whitespace at the edges of the first/last text item stays outside.
		$lead_ws  = '';
		$trail_ws = '';
		if ( 'text' === $core[0]['type'] ) {
			$trimmed        = ltrim( $core[0]['raw'] );
			$lead_ws        = substr( $core[0]['raw'], 0, strlen( $core[0]['raw'] ) - strlen( $trimmed ) );
			$core[0]['raw'] = $trimmed;
		}
		$last = count( $core ) - 1;
		if ( 'text' === $core[ $last ]['type'] ) {
			$trimmed              = rtrim( $core[ $last ]['raw'] );
			$trail_ws             = substr( $core[ $last ]['raw'], strlen( $trimmed ) );
			$core[ $last ]['raw'] = $trimmed;
		}

		// Pair inline tags.
		$pairs = array();
		$stack = array();
		foreach ( $core as $i => $item ) {
			if ( 'tag' !== $item['type'] || $item['self'] ) {
				continue;
			}
			if ( ! $item['closing'] ) {
				$stack[] = $i;
				continue;
			}
			for ( $s = count( $stack ) - 1; $s >= 0; $s-- ) {
				if ( $core[ $stack[ $s ] ]['name'] === $item['name'] ) {
					$pairs[ $stack[ $s ] ] = $i;
					$pairs[ $i ]           = $stack[ $s ];
					array_splice( $stack, $s );
					break;
				}
			}
		}

		$n      = 0;
		$number = array();
		$mask   = '';
		$raw    = '';
		$tags   = array();
		foreach ( $core as $i => $item ) {
			$raw .= $item['raw'];
			if ( 'text' === $item['type'] ) {
				$mask .= $item['raw'];
				continue;
			}
			if ( 'tag' === $item['type'] && $item['closing'] && isset( $pairs[ $i ] ) ) {
				$k                   = $number[ $pairs[ $i ] ];
				$tags[ $k ]['close'] = $item['raw'];
				$mask               .= '</' . $k . '>';
				continue;
			}
			++$n;
			$number[ $i ] = $n;
			$tags[ $n ]   = array(
				'open'  => $this->tag_pieces( $item, $context['tag'] ),
				'close' => null,
			);
			$mask        .= isset( $pairs[ $i ] ) ? '<' . $n . '>' : '<' . $n . '/>';
		}

		if ( '' !== $lead_ws ) {
			$pieces[] = $lead_ws;
		}
		$pieces[] = array(
			'run'  => $this->doc->add(
				array(
					'id'      => $this->doc->next_id( $this->field ),
					'kind'    => 'html',
					'source'  => $mask,
					'raw'     => $raw,
					'field'   => $this->field,
					'context' => trim( $context['tag'] . ( $context['block'] ? ' in ' . $context['block'] : '' ) ),
				)
			),
			'tags' => $tags,
		);
		if ( '' !== $trail_ws ) {
			$pieces[] = $trail_ws;
		}
		foreach ( $tail as $item ) {
			$this->push_tag( $item, $pieces, $context['tag'] );
		}
	}

	/**
	 * Whether the opening tag at $start is closed exactly at $end.
	 *
	 * @param array $items Items.
	 * @param int   $start Opening index.
	 * @param int   $end   Closing index.
	 * @return bool
	 */
	private static function encloses( array $items, $start, $end ) {
		$depth = 0;
		$name  = $items[ $start ]['name'];
		for ( $i = $start; $i <= $end; $i++ ) {
			$item = $items[ $i ];
			if ( 'tag' !== $item['type'] || $item['self'] || $item['name'] !== $name ) {
				continue;
			}
			$depth += $item['closing'] ? -1 : 1;
			if ( 0 === $depth && $i < $end ) {
				return false;
			}
		}
		return 0 === $depth;
	}

	/**
	 * Whether a run item can move outside the segment.
	 *
	 * @param array $item    Item.
	 * @param bool  $leading Leading edge.
	 * @return bool
	 */
	private static function trimmable( array $item, $leading ) {
		if ( 'text' === $item['type'] ) {
			return '' === trim( $item['raw'] );
		}
		if ( 'shortcode' === $item['type'] || 'opaque_inline' === $item['type'] ) {
			return true;
		}
		if ( 'tag' === $item['type'] ) {
			return $item['self'] || ( $leading ? $item['closing'] : ! $item['closing'] );
		}
		return false;
	}

	/**
	 * Block delimiter → piece with translatable attributes.
	 *
	 * @param array $token Block token.
	 * @return array|string
	 */
	private function delimiter( array $token ) {
		if ( $token['closing'] || ! preg_match( Html_Tokenizer::BLOCK_RE, $token['raw'], $m, PREG_OFFSET_CAPTURE ) || empty( $m['attrs'][0] ) ) {
			return $token['raw'];
		}
		$json  = rtrim( $m['attrs'][0] );
		$attrs = json_decode( $json, true );
		if ( ! is_array( $attrs ) ) {
			return $token['raw'];
		}
		/**
		 * Block attribute keys whose string values are translated (at any depth).
		 *
		 * @param string[] $keys       Keys.
		 * @param string   $block_name Block name.
		 */
		$keys  = (array) apply_filters( 'dev3tl_block_attribute_keys', array( 'alt', 'caption', 'title', 'label', 'text', 'content', 'placeholder', 'buttonText', 'description', 'heading', 'subheading', 'citation', 'question', 'answer', 'linkLabel', 'submitButtonText' ), $token['name'] );
		$paths = array();
		$this->walk_attrs( $attrs, array(), $keys, $token['name'], $paths );
		if ( ! $paths ) {
			return $token['raw'];
		}
		$offset = $m['attrs'][1];
		return array(
			'delim'  => true,
			'raw'    => $token['raw'],
			'prefix' => substr( $token['raw'], 0, $offset ),
			'suffix' => substr( $token['raw'], $offset + strlen( $json ) ),
			'attrs'  => $attrs,
			'paths'  => $paths,
		);
	}

	/**
	 * Finds translatable attribute strings.
	 *
	 * @param array  $value Attributes (sub)tree.
	 * @param array  $path  Current path.
	 * @param array  $keys  Wanted keys.
	 * @param string $block Block name.
	 * @param array  $paths Found paths (by reference): { path, original, template }.
	 */
	private function walk_attrs( array $value, array $path, array $keys, $block, array &$paths ) {
		foreach ( $value as $key => $child ) {
			$here = array_merge( $path, array( $key ) );
			if ( is_array( $child ) ) {
				$this->walk_attrs( $child, $here, $keys, $block, $paths );
				continue;
			}
			if ( ! is_string( $child ) || ! in_array( (string) $key, $keys, true ) || ! self::has_words( $child ) || self::is_url( $child ) ) {
				continue;
			}
			$pieces  = preg_match( '/[<&]/', $child ) ? $this->html( $child ) : $this->text( $child, $block . '.' . $key );
			$paths[] = array(
				'path'     => $here,
				'original' => $child,
				'template' => $pieces,
			);
		}
	}

	/**
	 * Joins adjacent string pieces.
	 *
	 * @param array $pieces Pieces.
	 * @return array
	 */
	private static function merge_literals( array $pieces ) {
		$out = array();
		foreach ( $pieces as $piece ) {
			$last = count( $out ) - 1;
			if ( is_string( $piece ) && $last >= 0 && is_string( $out[ $last ] ) ) {
				$out[ $last ] .= $piece;
			} elseif ( '' !== $piece ) {
				$out[] = $piece;
			}
		}
		return $out;
	}

	/**
	 * Contains at least one letter.
	 *
	 * @param string $text Text.
	 * @return bool
	 */
	public static function has_words( $text ) {
		return (bool) preg_match( '/\p{L}/u', (string) $text );
	}

	/**
	 * Looks like a bare URL or e-mail address.
	 *
	 * @param string $text Text.
	 * @return bool
	 */
	public static function is_url( $text ) {
		$text = trim( (string) $text );
		return (bool) preg_match( '#^(?:(?:https?:)?//|www\.|mailto:|tel:)\S+$#i', $text ) || (bool) preg_match( '/^[^\s@]+@[^\s@]+\.[a-z]{2,}$/i', $text );
	}
}
