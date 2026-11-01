<?php
/**
 * TranslatePress adapter.
 *
 * TranslatePress has no translation posts: the same post is rendered in every language and each rendered
 * string is looked up in its dictionary. So this adapter works in "strings" mode — it renders the post the
 * way the front end does, lets TranslatePress' parser list the strings, pre-registers paragraphs with inline
 * markup as TranslatePress "translation blocks" (so a sentence with a link stays one segment), and writes
 * the AI translations into the dictionary with TranslatePress' own query class.
 *
 * @package Dev3\AiTranslatorLocalizer
 */

namespace Dev3\AiTranslatorLocalizer\Providers;

use Dev3\AiTranslatorLocalizer\Content\Html_Tokenizer;
use Dev3\AiTranslatorLocalizer\Content\Segmenter;
use Dev3\AiTranslatorLocalizer\Localization\Locale_Profiles;

defined( 'ABSPATH' ) || exit;

/**
 * Strings-mode provider.
 */
class TranslatePress_Provider extends Base_Provider {

	/**
	 * Elements TranslatePress can treat as one translation block (its merge rules' top parents).
	 */
	const BLOCK_PARENTS = array( 'p', 'li', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'td', 'th', 'figcaption', 'blockquote', 'dt', 'dd', 'label', 'caption', 'summary' );

	/**
	 * Node types that carry URLs, not text.
	 */
	const SKIP_TYPES = array( 'image_src', 'a_href', 'video_src', 'video_poster', 'video_source_src', 'audio_src', 'audio_source_src', 'picture_image_src', 'picture_source_srcset', 'image_srcset', 'iframe_src', 'source_src', 'post-type-base', 'term', 'taxonomy' );

	/**
	 * Backend.
	 *
	 * @var TranslatePress_Backend
	 */
	private $backend;

	/**
	 * Constructor.
	 *
	 * @param object|null $backend Backend (tests inject a double).
	 */
	public function __construct( $backend = null ) {
		$this->backend = $backend ? $backend : new TranslatePress_Backend();
	}

	/**
	 * Id.
	 *
	 * @return string
	 */
	public function id() {
		return 'translatepress';
	}

	/**
	 * Label.
	 *
	 * @return string
	 */
	public function label() {
		return 'TranslatePress';
	}

	/**
	 * Active.
	 *
	 * @return bool
	 */
	public function is_active() {
		return $this->backend->available();
	}

	/**
	 * Strings mode.
	 *
	 * @return string
	 */
	public function mode() {
		return 'strings';
	}

	/**
	 * Languages (TranslatePress codes are WordPress locales).
	 *
	 * @return array[]
	 */
	public function languages() {
		if ( ! $this->is_active() ) {
			return array();
		}
		$settings = $this->backend->settings();
		$codes    = isset( $settings['translation-languages'] ) ? (array) $settings['translation-languages'] : array();
		$default  = isset( $settings['default-language'] ) ? (string) $settings['default-language'] : '';
		if ( $default && ! in_array( $default, $codes, true ) ) {
			array_unshift( $codes, $default );
		}
		$names = $this->backend->language_names( $codes );
		$out   = array();
		foreach ( $codes as $code ) {
			$out[] = array(
				'code'    => (string) $code,
				'locale'  => (string) $code,
				'name'    => isset( $names[ $code ] ) ? (string) $names[ $code ] : preg_replace( '/\s*\(.*\)$/', '', Locale_Profiles::language_name( $code ) ),
				'default' => $code === $default,
			);
		}
		return $out;
	}

	/**
	 * Default language.
	 *
	 * @return string
	 */
	public function default_language() {
		$settings = $this->backend->settings();
		return isset( $settings['default-language'] ) ? (string) $settings['default-language'] : '';
	}

	/**
	 * Every post is written in the default language.
	 *
	 * @param int $post_id Post.
	 * @return string
	 */
	public function post_language( $post_id ) {
		unset( $post_id );
		return $this->default_language();
	}

	/**
	 * Public post types are translatable.
	 *
	 * @param string $post_type Type.
	 * @return bool
	 */
	public function is_translatable_post_type( $post_type ) {
		$object = get_post_type_object( $post_type );
		return $object && ( $object->public || 'product' === $post_type );
	}

	/**
	 * Terms are rendered strings too.
	 *
	 * @param string $taxonomy Taxonomy.
	 * @return bool
	 */
	public function is_translatable_taxonomy( $taxonomy ) {
		unset( $taxonomy );
		return false;
	}

	/**
	 * The "translation" is the same post.
	 *
	 * @param int    $post_id Post.
	 * @param string $lang    Code.
	 * @return int
	 */
	public function get_translation( $post_id, $lang ) {
		unset( $lang );
		return (int) $post_id;
	}

	/**
	 * Not supported in strings mode.
	 *
	 * @param int    $source_id Source.
	 * @param string $lang      Code.
	 * @param array  $postarr   Data.
	 * @return \WP_Error
	 */
	public function create_translation( $source_id, $lang, array $postarr ) {
		unset( $source_id, $lang, $postarr );
		return new \WP_Error( 'dev3tl_strings_mode', __( 'TranslatePress stores translations as strings, not posts.', 'dev3-ai-translator-localizer' ) );
	}

	/**
	 * No-op.
	 *
	 * @param int    $source_id Source.
	 * @param int    $target_id Target.
	 * @param string $lang      Code.
	 */
	public function link_translations( $source_id, $target_id, $lang ) {
		unset( $source_id, $target_id, $lang );
	}

	/**
	 * Not supported.
	 *
	 * @param int    $term_id  Term.
	 * @param string $taxonomy Taxonomy.
	 * @param string $lang     Code.
	 * @return int
	 */
	public function get_term_translation( $term_id, $taxonomy, $lang ) {
		unset( $term_id, $taxonomy, $lang );
		return 0;
	}

	/**
	 * Not supported.
	 *
	 * @param \WP_Term $term Term.
	 * @param string   $lang Code.
	 * @param array    $args Args.
	 * @return \WP_Error
	 */
	public function create_term_translation( $term, $lang, array $args ) {
		unset( $term, $lang, $args );
		return new \WP_Error( 'dev3tl_strings_mode', __( 'TranslatePress translates term names as strings.', 'dev3-ai-translator-localizer' ) );
	}

	/**
	 * Writes one dictionary string.
	 *
	 * @param string $original    Original.
	 * @param string $translation Translation.
	 * @param string $lang        Code.
	 * @param array  $context     Context (status => machine|human).
	 * @return bool
	 */
	public function translate_string( $original, $translation, $lang, array $context = array() ) {
		$saved = $this->save_strings(
			array(
				(string) $original => array(
					'translation' => (string) $translation,
					'human'       => ! empty( $context['human'] ),
				),
			),
			$lang
		);
		return $saved > 0;
	}

	/**
	 * URL of a post in a language.
	 *
	 * @param int    $post_id Post.
	 * @param string $lang    Code.
	 * @return string
	 */
	public function url( $post_id, $lang ) {
		return $this->backend->url( (string) get_permalink( (int) $post_id ), $lang );
	}

	/**
	 * Front-end HTML of the parts of a post we translate.
	 *
	 * @param \WP_Post $post Post.
	 * @return string
	 */
	public function render_post( $post ) {
		$previous_post   = isset( $GLOBALS['post'] ) ? $GLOBALS['post'] : null;
		$GLOBALS['post'] = $post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		setup_postdata( $post );
		$title   = apply_filters( 'the_title', $post->post_title, $post->ID ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		$content = apply_filters( 'the_content', $post->post_content ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		$excerpt = '' !== trim( $post->post_excerpt ) ? apply_filters( 'the_excerpt', $post->post_excerpt ) : ''; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		if ( $previous_post ) {
			$GLOBALS['post'] = $previous_post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
			setup_postdata( $previous_post );
		} else {
			wp_reset_postdata();
		}
		$html = '<h1 class="entry-title">' . $title . '</h1><div class="entry-content">' . $content . '</div>';
		if ( '' !== $excerpt ) {
			$html .= '<div class="entry-summary">' . $excerpt . '</div>';
		}
		/**
		 * Filters the HTML whose strings are sent to TranslatePress for a post.
		 *
		 * @param string   $html HTML.
		 * @param \WP_Post $post Post.
		 */
		return (string) apply_filters( 'dev3tl_translatepress_post_html', $html, $post );
	}

	/**
	 * Inline-markup paragraphs that should be one TranslatePress translation block.
	 *
	 * @param string $html Rendered HTML.
	 * @return string[] Originals (trimmed inner HTML).
	 */
	public function translation_blocks( $html ) {
		$tokens = Html_Tokenizer::tokenize( $html, false );
		$stack  = array();
		$found  = array();
		foreach ( $tokens as $i => $token ) {
			if ( 'tag' !== $token['type'] ) {
				continue;
			}
			$inline = in_array( $token['name'], Segmenter::INLINE, true );
			if ( ! $token['closing'] && ! $token['self'] && in_array( $token['name'], self::BLOCK_PARENTS, true ) ) {
				foreach ( $stack as $k => $open ) {
					$stack[ $k ]['valid'] = false; // A parent containing a block child is not a leaf.
				}
				$stack[] = array(
					'name'   => $token['name'],
					'start'  => $i + 1,
					'valid'  => true,
					'inline' => false,
				);
				continue;
			}
			if ( ! $stack ) {
				continue;
			}
			$top = count( $stack ) - 1;
			if ( $inline ) {
				if ( 'br' !== $token['name'] ) {
					$stack[ $top ]['inline'] = true;
				}
				continue;
			}
			if ( $token['closing'] && $token['name'] === $stack[ $top ]['name'] ) {
				$entry = array_pop( $stack );
				if ( $entry['valid'] && $entry['inline'] ) {
					$inner = '';
					for ( $j = $entry['start']; $j < $i; $j++ ) {
						$inner .= $tokens[ $j ]['raw'];
					}
					$inner = $this->backend->trim( $inner );
					if ( Segmenter::has_words( wp_strip_all_tags( $inner ) ) ) {
						$found[ $inner ] = true;
					}
				}
				continue;
			}
			$stack[ $top ]['valid'] = false;
		}
		return array_keys( $found );
	}

	/**
	 * Strings of a post as TranslatePress sees them.
	 *
	 * @param \WP_Post $post Post.
	 * @param string   $lang Code.
	 * @return array[] { original, type, kind (html|text) }
	 */
	public function strings_for_post( $post, $lang ) {
		$html   = $this->render_post( $post );
		$blocks = $this->translation_blocks( $html );
		if ( $blocks ) {
			$existing = $this->backend->rows( $blocks, $lang );
			$missing  = array_values( array_diff( $blocks, array_keys( $existing ) ) );
			if ( $missing ) {
				$this->backend->insert( $missing, $lang, TranslatePress_Backend::BLOCK_ACTIVE );
			}
		}
		$out = array();
		foreach ( $this->backend->extract( $html, $lang ) as $string ) {
			if ( in_array( $string['type'], self::SKIP_TYPES, true ) || false !== strpos( $string['type'], 'src' ) || false !== strpos( $string['type'], 'href' ) ) {
				continue;
			}
			$original = (string) $string['original'];
			if ( isset( $out[ $original ] ) || ! Segmenter::has_words( wp_strip_all_tags( $original ) ) || Segmenter::is_url( $original ) ) {
				continue;
			}
			$text_types       = array( 'image_alt', 'title', 'placeholder', 'submit', 'aria-label', 'meta_desc', 'page_title', 'og_title', 'og_desc' );
			$out[ $original ] = array(
				'original' => $original,
				'type'     => $string['type'],
				'kind'     => in_array( $string['type'], $text_types, true ) && false === strpos( $original, '<' ) ? 'text' : 'html',
			);
		}
		return array_values( $out );
	}

	/**
	 * Writes translations into the dictionary.
	 *
	 * Rows a translator reviewed in TranslatePress' own editor (status "human reviewed") are left alone
	 * unless our own reviewer approved the new text.
	 *
	 * @param array  $map  original => { translation, human }.
	 * @param string $lang Code.
	 * @return int Rows written.
	 */
	public function save_strings( array $map, $lang ) {
		if ( ! $map ) {
			return 0;
		}
		$originals = array_map( 'strval', array_keys( $map ) );
		$rows      = $this->backend->rows( $originals, $lang );
		$missing   = array_values( array_diff( $originals, array_keys( $rows ) ) );
		if ( $missing ) {
			$this->backend->insert( $missing, $lang, TranslatePress_Backend::BLOCK_REGULAR );
			$rows = $this->backend->rows( $originals, $lang );
		}
		$update = array();
		foreach ( $map as $original => $entry ) {
			$original = (string) $original;
			if ( ! isset( $rows[ $original ] ) ) {
				continue;
			}
			$row   = $rows[ $original ];
			$human = ! empty( $entry['human'] );
			if ( TranslatePress_Backend::HUMAN_REVIEWED === (int) $row['status'] && ! $human && (string) $row['translated'] !== (string) $entry['translation'] ) {
				continue;
			}
			$wanted = $human ? TranslatePress_Backend::HUMAN_REVIEWED : TranslatePress_Backend::MACHINE_TRANSLATED;
			if ( (string) $row['translated'] === (string) $entry['translation'] && (int) $row['status'] === $wanted ) {
				continue;
			}
			$update[] = array(
				'id'         => (int) $row['id'],
				'original'   => $original,
				'translated' => (string) $entry['translation'],
				'status'     => $human ? TranslatePress_Backend::HUMAN_REVIEWED : TranslatePress_Backend::MACHINE_TRANSLATED,
				'block_type' => isset( $row['block_type'] ) ? (int) $row['block_type'] : 0,
			);
		}
		if ( $update ) {
			$this->backend->update( $update, $lang );
		}
		return count( $update );
	}
}
