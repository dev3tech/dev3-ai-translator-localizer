<?php
/**
 * Segmenter: exact round trips and structure-preserving reassembly.
 *
 * @package Dev3\AiTranslatorLocalizer
 */

use Dev3\AiTranslatorLocalizer\Content\Document;
use Dev3\AiTranslatorLocalizer\Content\Renderer;
use Dev3\AiTranslatorLocalizer\Content\Segmenter;

class SegmenterTest extends WP_UnitTestCase {

	/**
	 * Nested blocks with every tricky construct.
	 *
	 * @return string
	 */
	public static function nested_blocks() {
		return '<!-- wp:group {"layout":{"type":"constrained"},"style":{"spacing":{"padding":"2rem"}}} -->
<div class="wp-block-group" style="padding:2rem"><!-- wp:columns -->
<div class="wp-block-columns"><!-- wp:column -->
<div class="wp-block-column"><!-- wp:heading {"level":3,"className":"is-style-x"} -->
<h3 class="wp-block-heading is-style-x">Built for <em>serious</em> work</h3>
<!-- /wp:heading -->

<!-- wp:paragraph {"fontSize":"large"} -->
<p class="has-large-font-size">The desk rises to 4 feet in 20 seconds. See <a href="https://example.com/spec?a=1&amp;b=2#x" target="_blank" rel="noreferrer noopener" title="Full specification">the spec sheet</a> [tooltip text="hi"]now[/tooltip].<br>Ships in 2–3&nbsp;days &amp; free.</p>
<!-- /wp:paragraph --></div>
<!-- /wp:column -->

<!-- wp:column -->
<div class="wp-block-column"><!-- wp:image {"id":12,"sizeSlug":"large","linkDestination":"none"} -->
<figure class="wp-block-image size-large"><img src="https://example.com/desk.jpg" alt="Oak standing desk in a bright office" class="wp-image-12"/><figcaption class="wp-element-caption">Our <strong>Oak</strong> edition</figcaption></figure>
<!-- /wp:image -->

<!-- wp:list -->
<ul class="wp-block-list"><!-- wp:list-item -->
<li>Height range: 25–50 inches</li>
<!-- /wp:list-item -->

<!-- wp:list-item -->
<li>Run <code>npm i desk</code> to install</li>
<!-- /wp:list-item --></ul>
<!-- /wp:list -->

<!-- wp:buttons -->
<div class="wp-block-buttons"><!-- wp:button {"backgroundColor":"vivid-red"} -->
<div class="wp-block-button"><a class="wp-block-button__link has-vivid-red-background-color wp-element-button" href="/shop">Shop now</a></div>
<!-- /wp:button --></div>
<!-- /wp:buttons --></div>
<!-- /wp:column --></div>
<!-- /wp:columns -->

<!-- wp:search {"label":"Search products","buttonText":"Find","placeholder":"Desk, chair…"} /-->

<!-- wp:shortcode -->
[contact-form-7 id="12" title="Contact"]
<!-- /wp:shortcode -->

<!-- wp:html -->
<div data-x=\'{"a":1}\' translate="no">Brand™ stays</div><script>var t = "do not touch";</script>
<!-- /wp:html -->

<!-- wp:code -->
<pre class="wp-block-code"><code>if ( a &lt; b ) { echo "Hello"; }</code></pre>
<!-- /wp:code --></div>
<!-- /wp:group -->';
	}

	/**
	 * Classic editor content.
	 *
	 * @return string
	 */
	public static function classic() {
		return "Welcome to our store. We ship worldwide.\n\n[caption id=\"attachment_5\" align=\"alignnone\" width=\"300\"]<img class=\"size-medium\" src=\"/a.jpg\" alt=\"Red mug\" width=\"300\" height=\"200\" /> A red mug[/caption]\n\n<h2>Why us?</h2>\n<ul>\n<li>Fast <strong>shipping</strong></li>\n<li>14-day returns</li>\n</ul>\n<!-- more -->\nVisit https://example.com today!\n<p style='color:red' title='Note'>Unclosed <b>bold\n<input type=\"submit\" value=\"Send message\"> <input type=\"text\" placeholder=\"Your name\">";
	}

	/**
	 * Builds a document.
	 *
	 * @param string $html HTML.
	 * @return Document
	 */
	private function doc( $html ) {
		$doc = new Document();
		Segmenter::add_html( $doc, 'content', $html );
		return $doc;
	}

	public function test_identity_round_trip_is_exact() {
		foreach ( array( self::nested_blocks(), self::classic(), '', 'Plain', "<p>a</p>\n\n<p>b &amp; c</p>", '<p>x <1> y</p>' ) as $html ) {
			$doc = $this->doc( $html );
			$this->assertSame( $html, $doc->render( array() )['content'] );
			// "Translating" every segment to itself is also exact.
			$same = array();
			foreach ( $doc->segments as $id => $segment ) {
				$same[ $id ] = $segment['source'];
			}
			$this->assertSame( $html, $doc->render( $same )['content'] );
		}
	}

	public function test_segments_of_nested_blocks() {
		$doc     = $this->doc( self::nested_blocks() );
		$sources = array_column( $doc->segments, 'source' );
		$this->assertContains( 'Built for <1>serious</1> work', $sources );
		$this->assertContains( 'The desk rises to 4 feet in 20 seconds. See <1>the spec sheet</1> <2/>now<3/>.<4/>Ships in 2–3&nbsp;days &amp; free.', $sources );
		$this->assertContains( 'Full specification', $sources ); // title attribute.
		$this->assertContains( 'Oak standing desk in a bright office', $sources ); // alt.
		$this->assertContains( 'Our <1>Oak</1> edition', $sources );
		$this->assertContains( 'Height range: 25–50 inches', $sources );
		$this->assertContains( 'Run <1/> to install', $sources ); // inline code is opaque.
		$this->assertContains( 'Shop now', $sources ); // wrapping <a> moved outside the segment.
		$this->assertContains( 'Search products', $sources ); // block attribute.
		$this->assertContains( 'Find', $sources );
		$this->assertContains( 'Desk, chair…', $sources );
		foreach ( $sources as $source ) {
			$this->assertStringNotContainsString( 'https://', $source );
			$this->assertStringNotContainsString( 'Brand™', $source );
			$this->assertStringNotContainsString( 'do not touch', $source );
			$this->assertStringNotContainsString( 'Hello', $source );
			$this->assertStringNotContainsString( 'contact-form-7', $source );
		}
		$contexts = array_column( $doc->segments, 'context', 'source' );
		$this->assertSame( 'h3 in core/heading', $contexts['Built for <1>serious</1> work'] );
	}

	public function test_translated_reassembly_keeps_markup_and_blocks() {
		$html = self::nested_blocks();
		$doc  = $this->doc( $html );
		$map  = array(
			'Built for <1>serious</1> work' => 'Gebaut für <1>ernsthafte</1> Arbeit',
			'The desk rises to 4 feet in 20 seconds. See <1>the spec sheet</1> <2/>now<3/>.<4/>Ships in 2–3&nbsp;days &amp; free.' => 'Der Tisch fährt in 20 Sekunden auf 1,2 m. Siehe <1>Datenblatt</1> <2/>jetzt<3/>.<4/>Versand in 2–3 Tagen & gratis <b>!</b>',
			'Full specification'            => 'Vollständige "Spezifikation"',
			'Shop now'                      => 'Jetzt kaufen',
			'Search products'               => 'Produkte suchen',
			'Run <1/> to install'           => 'Führe <1/> aus',
		);
		$tr   = array();
		foreach ( $doc->segments as $id => $segment ) {
			if ( isset( $map[ $segment['source'] ] ) ) {
				$tr[ $id ] = $map[ $segment['source'] ];
			}
		}
		$out = $doc->render( $tr )['content'];
		$this->assertStringContainsString( '<h3 class="wp-block-heading is-style-x">Gebaut für <em>ernsthafte</em> Arbeit</h3>', $out );
		$this->assertStringContainsString( '<a href="https://example.com/spec?a=1&amp;b=2#x" target="_blank" rel="noreferrer noopener" title="Vollständige &quot;Spezifikation&quot;">Datenblatt</a> [tooltip text="hi"]jetzt[/tooltip].<br>Versand in 2–3 Tagen &amp; gratis !</p>', $out );
		$this->assertStringContainsString( '<a class="wp-block-button__link has-vivid-red-background-color wp-element-button" href="/shop">Jetzt kaufen</a>', $out );
		$this->assertStringContainsString( '<li>Führe <code>npm i desk</code> aus</li>', $out );
		$this->assertStringContainsString( '"label":"Produkte suchen"', $out );
		$this->assertStringContainsString( '<script>var t = "do not touch";</script>', $out );
		// Block structure is unchanged.
		$names = static function ( $blocks ) use ( &$names ) {
			$out = array();
			foreach ( $blocks as $b ) {
				$out[] = $b['blockName'];
				$out   = array_merge( $out, $names( $b['innerBlocks'] ) );
			}
			return $out;
		};
		$this->assertSame( $names( parse_blocks( $html ) ), $names( parse_blocks( $out ) ) );
		$find   = static function ( $blocks ) use ( &$find ) {
			foreach ( $blocks as $block ) {
				if ( 'core/search' === $block['blockName'] ) {
					return $block;
				}
				$inner = $find( $block['innerBlocks'] );
				if ( $inner ) {
					return $inner;
				}
			}
			return null;
		};
		$search = $find( parse_blocks( $out ) );
		$this->assertSame( 'Produkte suchen', $search['attrs']['label'] );
		$this->assertSame( 'Find', $search['attrs']['buttonText'] );
	}

	public function test_classic_content_shortcodes_and_attributes() {
		$doc     = $this->doc( self::classic() );
		$sources = array_column( $doc->segments, 'source' );
		$this->assertContains( 'Welcome to our store. We ship worldwide.', $sources );
		$this->assertContains( 'A red mug', $sources ); // [caption] and <img> stay outside.
		$this->assertContains( 'Red mug', $sources ); // alt.
		$this->assertContains( 'Why us?', $sources );
		$this->assertContains( 'Fast <1>shipping</1>', $sources );
		$this->assertContains( 'Send message', $sources ); // submit value.
		$this->assertContains( 'Your name', $sources ); // placeholder.
		$this->assertContains( 'Note', $sources ); // single-quoted title.
		$this->assertNotContains( 'https://example.com', $sources );
		$tr = array();
		foreach ( $doc->segments as $id => $segment ) {
			$tr[ $id ] = 'Red mug' === $segment['source'] ? "Tasse 'rot'" : strtoupper( $segment['source'] );
		}
		$out = $doc->render( $tr )['content'];
		$this->assertStringContainsString( '[caption id="attachment_5" align="alignnone" width="300"]<img class="size-medium" src="/a.jpg" alt="Tasse &#039;rot&#039;" width="300" height="200" /> A RED MUG[/caption]', $out );
		$this->assertStringContainsString( "<p style='color:red' title='NOTE'>", $out );
		$this->assertStringContainsString( 'value="SEND MESSAGE"', $out );
	}

	public function test_text_fields_mask_seo_variables() {
		$doc = new Document();
		Segmenter::add_text( $doc, 'meta', '%%title%% %%sep%% Best desks %d [year]' );
		$segment = array_values( $doc->segments )[0];
		$this->assertSame( '<1/> <2/> Best desks <3/> <4/>', $segment['source'] );
		$out = $doc->render( array( $segment['id'] => '<1/> <2/> Beste Tische <3/> <4/> <9/>' ) );
		$this->assertSame( '%%title%% %%sep%% Beste Tische %d [year] ', $out['meta'] );
		$doc = new Document();
		Segmenter::add_text( $doc, 'x', '%%title%% - 2024' );
		$this->assertCount( 0, $doc->segments );
	}

	public function test_escape_and_placeholders() {
		$this->assertSame( 'A &amp; B &lt; C <1>x</1> &copy;', Renderer::escape_text( 'A & B < C <1>x</1> <span>&copy;</span>' ) );
		$this->assertSame( array( '</1>', '<1>', '<2/>' ), Renderer::placeholders( 'a <1>b</1> <2/>' ) );
	}

	public function test_hash_normalises_whitespace() {
		$this->assertSame( Document::hash( "Hello   world\n" ), Document::hash( 'Hello world' ) );
		$this->assertNotSame( Document::hash( 'Hello world' ), Document::hash( 'Hello World' ) );
	}
}
