<?php
/**
 * A segmented piece of content: fields (templates) plus the translatable segments they reference.
 *
 * @package Dev3\AiTranslatorLocalizer
 */

namespace Dev3\AiTranslatorLocalizer\Content;

defined( 'ABSPATH' ) || exit;

/**
 * Field templates are lists of pieces (literal strings or segment references); rendering a template with no
 * translations returns the original value byte for byte.
 */
class Document {

	/**
	 * Segments by id: { id, kind (html|text|attr), source (masked), raw, field, context, hash, vars? }.
	 *
	 * @var array
	 */
	public $segments = array();

	/**
	 * Fields: name => { template (pieces), original, kind, meta? }.
	 *
	 * @var array
	 */
	public $fields = array();

	/**
	 * Per-field counters for ids.
	 *
	 * @var array
	 */
	private $counters = array();

	/**
	 * Next stable id inside a field.
	 *
	 * @param string $field Field.
	 * @return string
	 */
	public function next_id( $field ) {
		$this->counters[ $field ] = isset( $this->counters[ $field ] ) ? $this->counters[ $field ] + 1 : 1;
		return $field . '#' . $this->counters[ $field ];
	}

	/**
	 * Registers a segment.
	 *
	 * @param array $segment Segment.
	 * @return string Id.
	 */
	public function add( array $segment ) {
		$segment['hash']                  = self::hash( $segment['source'] );
		$this->segments[ $segment['id'] ] = $segment;
		return $segment['id'];
	}

	/**
	 * Adds a field.
	 *
	 * @param string $name     Field name.
	 * @param string $original Original value.
	 * @param array  $template Pieces.
	 * @param array  $extra    Extra data (label, meta key…).
	 */
	public function add_field( $name, $original, array $template, array $extra = array() ) {
		$this->fields[ $name ] = array_merge(
			$extra,
			array(
				'original' => (string) $original,
				'template' => $template,
			)
		);
	}

	/**
	 * Renders every field.
	 *
	 * @param array $translations Segment id => masked translation.
	 * @return array name => value
	 */
	public function render( array $translations ) {
		$out = array();
		foreach ( $this->fields as $name => $field ) {
			$out[ $name ] = Renderer::render( $field['template'], $this->segments, $translations );
		}
		return $out;
	}

	/**
	 * Segment ids belonging to a field.
	 *
	 * @param string $field Field.
	 * @return string[]
	 */
	public function ids_for( $field ) {
		$ids = array();
		foreach ( $this->segments as $id => $segment ) {
			if ( $segment['field'] === $field ) {
				$ids[] = $id;
			}
		}
		return $ids;
	}

	/**
	 * Whitespace-normalised source used for the translation-memory hash.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	public static function normalize( $text ) {
		$text = preg_replace( '/\s+/u', ' ', trim( (string) $text ) );
		return null === $text ? trim( (string) $text ) : $text;
	}

	/**
	 * Translation-memory key.
	 *
	 * @param string $text Masked source.
	 * @return string sha1
	 */
	public static function hash( $text ) {
		return sha1( self::normalize( $text ) );
	}
}
