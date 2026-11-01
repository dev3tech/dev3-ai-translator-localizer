<?php
/**
 * Provider adapters: Polylang (real), WPML (hook stubs), TranslatePress (backend double).
 *
 * @package Dev3\AiTranslatorLocalizer
 */

use Dev3\AiTranslatorLocalizer\Providers\Polylang_Provider;
use Dev3\AiTranslatorLocalizer\Providers\Providers;
use Dev3\AiTranslatorLocalizer\Providers\TranslatePress_Backend;
use Dev3\AiTranslatorLocalizer\Providers\TranslatePress_Provider;
use Dev3\AiTranslatorLocalizer\Providers\Wpml_Provider;
use Dev3\AiTranslatorLocalizer\Sync\Review;
use Dev3\AiTranslatorLocalizer\Sync\Sync_Engine;

/**
 * Minimal in-memory WPML implementing the documented hooks.
 */
class Dev3tl_Fake_Wpml {

	/**
	 * Element id + type => [ trid, lang ].
	 *
	 * @var array
	 */
	public $elements = array();

	/**
	 * Next trid.
	 *
	 * @var int
	 */
	private $trid = 100;

	/**
	 * Registered strings.
	 *
	 * @var array
	 */
	public $strings = array();

	/**
	 * Installs the hooks.
	 */
	public function install() {
		add_filter(
			'wpml_active_languages',
			static function () {
				return array(
					'en' => array(
						'code'            => 'en',
						'default_locale'  => 'en_US',
						'translated_name' => 'English',
					),
					'de' => array(
						'code'            => 'de',
						'default_locale'  => 'de_DE',
						'translated_name' => 'German',
					),
					'fr' => array(
						'code'            => 'fr',
						'default_locale'  => 'fr_FR',
						'translated_name' => 'French',
					),
				);
			}
		);
		add_filter(
			'wpml_default_language',
			static function () {
				return 'en';
			}
		);
		add_filter( 'wpml_is_translated_post_type', '__return_true' );
		add_filter( 'wpml_is_translated_taxonomy', '__return_true' );
		add_filter(
			'wpml_post_language_details',
			function ( $value, $id ) {
				$key = $id . '|post_' . get_post_type( $id );
				return isset( $this->elements[ $key ] ) ? array( 'language_code' => $this->elements[ $key ][1] ) : $value;
			},
			10,
			2
		);
		add_filter(
			'wpml_element_language_code',
			function ( $value, $args ) {
				$key = $args['element_id'] . '|' . $args['element_type'];
				return isset( $this->elements[ $key ] ) ? $this->elements[ $key ][1] : $value;
			},
			10,
			2
		);
		add_filter(
			'wpml_element_trid',
			function ( $value, $id, $type ) {
				$key = $id . '|' . $type;
				if ( ! isset( $this->elements[ $key ] ) ) {
					$this->elements[ $key ] = array( ++$this->trid, 'en' );
				}
				return $this->elements[ $key ][0];
			},
			10,
			3
		);
		add_action(
			'wpml_set_element_language_details',
			function ( $args ) {
				$this->elements[ $args['element_id'] . '|' . $args['element_type'] ] = array( $args['trid'], $args['language_code'] );
			}
		);
		add_filter(
			'wpml_object_id',
			function ( $id, $type, $original, $lang ) {
				$prefix = taxonomy_exists( $type ) ? 'tax_' : 'post_';
				$el     = 'tax_' === $prefix ? (int) get_term( $id, $type )->term_taxonomy_id : $id;
				$key    = $el . '|' . $prefix . $type;
				if ( ! isset( $this->elements[ $key ] ) ) {
					return $original ? $id : null;
				}
				$trid = $this->elements[ $key ][0];
				foreach ( $this->elements as $k => $v ) {
					if ( $v[0] === $trid && $v[1] === $lang ) {
						$found = (int) strtok( $k, '|' );
						if ( 'tax_' === $prefix ) {
							$term = get_term_by( 'term_taxonomy_id', $found, $type );
							return $term ? $term->term_id : null;
						}
						return $found;
					}
				}
				return $original ? $id : null;
			},
			10,
			4
		);
		add_action(
			'wpml_register_single_string',
			function ( $context, $name, $value ) {
				$this->strings[ $context . '|' . $name ] = $value;
			},
			10,
			3
		);
	}
}

/**
 * Array-based TranslatePress backend.
 */
class Dev3tl_Fake_Trp_Backend {

	/**
	 * Dictionary: lang => original => row.
	 *
	 * @var array
	 */
	public $dict = array();

	/**
	 * Id counter.
	 *
	 * @var int
	 */
	private $id = 0;

	/**
	 * Available.
	 *
	 * @return bool
	 */
	public function available() {
		return true;
	}

	/**
	 * Settings.
	 *
	 * @return array
	 */
	public function settings() {
		return array(
			'default-language'      => 'en_US',
			'translation-languages' => array( 'en_US', 'de_DE' ),
		);
	}

	/**
	 * Names.
	 *
	 * @param array $codes Codes.
	 * @return array
	 */
	public function language_names( array $codes ) {
		return array_combine( $codes, array_map( 'strtoupper', $codes ) );
	}

	/**
	 * Mimics TranslatePress: translation blocks first, then remaining text nodes; alt attributes.
	 *
	 * @param string $html HTML.
	 * @param string $lang Lang.
	 * @return array
	 */
	public function extract( $html, $lang ) {
		$out    = array();
		$blocks = array();
		foreach ( isset( $this->dict[ $lang ] ) ? $this->dict[ $lang ] : array() as $original => $row ) {
			if ( 1 === $row['block_type'] && false !== strpos( $html, $original ) ) {
				$blocks[] = $original;
				$out[]    = array(
					'original' => $original,
					'type'     => 'block',
				);
				$html     = str_replace( $original, '', $html );
			}
		}
		preg_match_all( '/alt="([^"]+)"/', $html, $m );
		foreach ( $m[1] as $alt ) {
			$out[] = array(
				'original' => $alt,
				'type'     => 'image_alt',
			);
		}
		preg_match_all( '/src="([^"]+)"/', $html, $m );
		foreach ( $m[1] as $src ) {
			$out[] = array(
				'original' => $src,
				'type'     => 'image_src',
			);
		}
		foreach ( preg_split( '/<[^>]+>/', $html ) as $text ) {
			$text = trim( $text );
			if ( '' !== $text ) {
				$out[] = array(
					'original' => $text,
					'type'     => 'text',
				);
			}
		}
		$this->insert( array_column( $out, 'original' ), $lang );
		return $out;
	}

	/**
	 * Rows.
	 *
	 * @param array  $originals Originals.
	 * @param string $lang      Lang.
	 * @return array
	 */
	public function rows( array $originals, $lang ) {
		return array_intersect_key( isset( $this->dict[ $lang ] ) ? $this->dict[ $lang ] : array(), array_flip( $originals ) );
	}

	/**
	 * Insert.
	 *
	 * @param array  $originals  Originals.
	 * @param string $lang       Lang.
	 * @param int    $block_type Block type.
	 */
	public function insert( array $originals, $lang, $block_type = 0 ) {
		foreach ( $originals as $original ) {
			if ( ! isset( $this->dict[ $lang ][ $original ] ) ) {
				$this->dict[ $lang ][ $original ] = array(
					'id'         => ++$this->id,
					'original'   => $original,
					'translated' => '',
					'status'     => 0,
					'block_type' => (int) $block_type,
				);
			}
		}
	}

	/**
	 * Update.
	 *
	 * @param array  $rows Rows.
	 * @param string $lang Lang.
	 * @return bool
	 */
	public function update( array $rows, $lang ) {
		foreach ( $rows as $row ) {
			$this->dict[ $lang ][ $row['original'] ] = array_merge( $this->dict[ $lang ][ $row['original'] ], $row );
		}
		return true;
	}

	/**
	 * URL.
	 *
	 * @param string $url  URL.
	 * @param string $lang Lang.
	 * @return string
	 */
	public function url( $url, $lang ) {
		return str_replace( home_url( '/' ), home_url( '/' . strtolower( substr( $lang, 0, 2 ) ) . '/' ), $url );
	}

	/**
	 * Trim.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	public function trim( $text ) {
		return trim( $text );
	}
}

class ProvidersTest extends Dev3tl_Test_Case {

	public function test_polylang_adapter_real() {
		if ( ! $this->polylang() ) {
			$this->markTestSkipped( 'Polylang not loadable.' );
		}
		$p = new Polylang_Provider();
		$this->assertTrue( $p->is_active() );
		$this->assertSame( 'en', $p->default_language() );
		$this->assertSame( array( 'en', 'de', 'fr' ), wp_list_pluck( $p->languages(), 'code' ) );
		$this->assertSame( 'de_DE', $p->locale( 'de' ) );
		$this->assertSame( array( 'de', 'fr' ), wp_list_pluck( $p->target_languages(), 'code' ) );
		$this->assertTrue( $p->is_translatable_post_type( 'post' ) );
		$this->assertTrue( $p->is_translatable_taxonomy( 'category' ) );

		$src = $this->source_post( '<p>Hi</p>' );
		$this->assertSame( 'en', $p->post_language( $src ) );
		$this->assertSame( 0, $p->get_translation( $src, 'de' ) );
		$de = $p->create_translation(
			$src,
			'de',
			array(
				'post_type'   => 'page',
				'post_title'  => 'Hallo',
				'post_status' => 'publish',
			)
		);
		$fr = $p->create_translation(
			$src,
			'fr',
			array(
				'post_type'   => 'page',
				'post_title'  => 'Salut',
				'post_status' => 'publish',
			)
		);
		$this->assertSame( $de, $p->get_translation( $src, 'de' ) );
		$this->assertSame( $fr, $p->get_translation( $de, 'fr' ) ); // Same group.
		$this->assertSame( 'fr', $p->post_language( $fr ) );

		$term = get_term( self::factory()->category->create( array( 'name' => 'Desks' ) ), 'category' );
		pll_set_term_language( $term->term_id, 'en' );
		$de_term = $p->create_term_translation( $term, 'de', array( 'name' => 'Schreibtische' ) );
		$this->assertSame( $de_term, $p->get_term_translation( $term->term_id, 'category', 'de' ) );
		$this->assertSame( 'de', pll_get_term_language( $de_term ) );

		$this->assertTrue( $p->translate_string( 'My Site', 'Meine Seite', 'de', array( 'name' => 'blogname' ) ) );
		$this->assertSame( 'Meine Seite', pll_translate_string( 'My Site', 'de' ) );
	}

	public function test_wpml_adapter_with_stubs() {
		$wpml = new Dev3tl_Fake_Wpml();
		$wpml->install();
		$p = new Wpml_Provider();
		$this->assertTrue( $p->is_active() );
		$this->assertSame( 'en', $p->default_language() );
		$this->assertSame( 'de_DE', $p->locale( 'de' ) );
		$this->assertSame( 'German', $p->language( 'de' )['name'] );

		$src = self::factory()->post->create( array( 'post_title' => 'Hello' ) );
		$this->assertSame( 0, $p->get_translation( $src, 'de' ) );
		$de = $p->create_translation(
			$src,
			'de',
			array(
				'post_type'  => 'post',
				'post_title' => 'Hallo',
			)
		);
		$this->assertSame( $de, $p->get_translation( $src, 'de' ) );
		$this->assertSame( 'de', $p->post_language( $de ) );
		$this->assertSame( $wpml->elements[ $src . '|post_post' ][0], $wpml->elements[ $de . '|post_post' ][0] ); // Same trid.

		$term    = get_term( self::factory()->tag->create( array( 'name' => 'Oak' ) ), 'post_tag' );
		$de_term = $p->create_term_translation( $term, 'de', array( 'name' => 'Eiche' ) );
		$this->assertSame( 'de', $wpml->elements[ get_term( $de_term )->term_taxonomy_id . '|tax_post_tag' ][1] );
		$this->assertSame( $de_term, $p->get_term_translation( $term->term_id, 'post_tag', 'de' ) );

		$this->assertFalse( $p->translate_string( 'My Site', 'Meine Seite', 'de', array( 'name' => 'blogname' ) ) ); // No String Translation module.
		$this->assertSame( 'My Site', $wpml->strings['DEV3 AI Translator|blogname'] );

		// Full pipeline through the WPML adapter.
		Providers::$override = $p;
		$this->admin();
		$this->mock = new Dev3tl_Mock_Gemini();
		$post       = self::factory()->post->create(
			array(
				'post_title'   => 'Desk',
				'post_content' => '<p>Tall desk.</p>',
				'post_excerpt' => '',
			)
		);
		$stats      = Sync_Engine::sync( $post, 'fr' );
		$this->assertSame( 'done', $stats['status'] );
		$this->assertSame( $stats['target_id'], $p->get_translation( $post, 'fr' ) );
		$this->assertSame( '<p>[T] Tall desk.</p>', get_post( $stats['target_id'] )->post_content );
	}

	public function test_translatepress_adapter_with_backend_double() {
		$backend = new Dev3tl_Fake_Trp_Backend();
		$p       = new TranslatePress_Provider( $backend );
		$this->assertSame( 'strings', $p->mode() );
		$this->assertSame( 'en_US', $p->default_language() );
		$this->assertSame( array( 'de_DE' ), wp_list_pluck( $p->target_languages(), 'code' ) );

		$this->assertSame(
			array( 'Read <a href="/g">the guide</a> today.', 'Nested <b>x</b>' ),
			$p->translation_blocks( '<div><p>Read <a href="/g">the guide</a> today.</p><p>Plain.</p><ul><li><p>Nested <b>x</b></p></li></ul></div>' )
		);

		Providers::$override = $p;
		$this->admin();
		$this->mock = new Dev3tl_Mock_Gemini(
			static function ( $text ) {
				return '[DE] ' . $text;
			}
		);
		$post  = self::factory()->post->create(
			array(
				'post_title'   => 'Desk guide',
				'post_content' => '<p>Read <a href="/g">the guide</a> today.</p><p>Plain text.</p><img src="/a.jpg" alt="Oak desk">',
				'post_excerpt' => '',
			)
		);
		$stats = Sync_Engine::sync( $post, 'de_DE' );
		$this->assertSame( 'done', $stats['status'], wp_json_encode( $stats ) );
		$dict = $backend->dict['de_DE'];
		$this->assertSame( 1, $dict['Read <a href="/g">the guide</a> today.']['block_type'] ); // Registered as a translation block.
		$this->assertSame( '[DE] Read <a href="/g">the guide</a> today.', $dict['Read <a href="/g">the guide</a> today.']['translated'] );
		$this->assertSame( '[DE] Plain text.', $dict['Plain text.']['translated'] );
		$this->assertSame( '[DE] Oak desk', $dict['Oak desk']['translated'] );
		$this->assertSame( '[DE] Desk guide', $dict['Desk guide']['translated'] );
		$this->assertSame( 1, $dict['Plain text.']['status'] );
		$this->assertSame( '', $dict['/a.jpg']['translated'] ); // URLs are not translated.
		$this->assertStringContainsString( '/de/', $p->url( $post, 'de_DE' ) );

		// Delta: unchanged strings reuse the memory; a reviewer edit is written as human-reviewed.
		$this->mock->requests = array();
		$again                = Sync_Engine::sync( $post, 'de_DE' );
		$this->assertSame( 0, $again['sent'] );
		$this->assertSame( $again['total'], $again['reused'] );

		$review = Review::get( $post, 'de_DE' );
		$plain  = null;
		foreach ( $review['segments'] as $segment ) {
			if ( 'Plain text.' === $segment['source'] ) {
				$plain = $segment;
			}
		}
		Review::save(
			$post,
			'de_DE',
			array(
				array(
					'hash'        => $plain['hash'],
					'translation' => 'Einfacher Text.',
				),
			)
		);
		$this->assertSame( 'Einfacher Text.', $backend->dict['de_DE']['Plain text.']['translated'] );
		$this->assertSame( 2, $backend->dict['de_DE']['Plain text.']['status'] );

		// Rows reviewed in TranslatePress' own editor are not overwritten by machine output.
		$backend->dict['de_DE']['Oak desk']['translated'] = 'Eichentisch (TP-Editor)';
		$backend->dict['de_DE']['Oak desk']['status']     = TranslatePress_Backend::HUMAN_REVIEWED;
		Sync_Engine::sync( $post, 'de_DE', array( 'force' => true ) );
		$this->assertSame( 'Eichentisch (TP-Editor)', $backend->dict['de_DE']['Oak desk']['translated'] );
	}
}
