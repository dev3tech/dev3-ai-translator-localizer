<?php
/**
 * Glossary enforcement (prompt + post-check + one retry) and cultural parameters.
 *
 * @package Dev3\AiTranslatorLocalizer
 */

use Dev3\AiTranslatorLocalizer\Content\Document;
use Dev3\AiTranslatorLocalizer\Content\Segmenter;
use Dev3\AiTranslatorLocalizer\Data\Glossary;
use Dev3\AiTranslatorLocalizer\Data\Translation_Memory;
use Dev3\AiTranslatorLocalizer\Localization\Locale_Profiles;
use Dev3\AiTranslatorLocalizer\Localization\Unit_Converter;
use Dev3\AiTranslatorLocalizer\Sync\Sync_Engine;
use Dev3\AiTranslatorLocalizer\Translation\Prompt_Builder;
use Dev3\AiTranslatorLocalizer\Translation\Translator;

class GlossaryCulturalTest extends Dev3tl_Test_Case {

	/**
	 * Document with one text segment.
	 *
	 * @param string $text Text.
	 * @return Document
	 */
	private function doc( $text ) {
		$doc = new Document();
		Segmenter::add_html( $doc, 'content', '<p>' . $text . '</p>' );
		return $doc;
	}

	public function test_dnt_violation_triggers_one_retry_and_is_fixed() {
		Glossary::save(
			array(
				'term'           => 'CloudDesk Pro',
				'dnt'            => true,
				'case_sensitive' => true,
			)
		);
		$this->mock = new Dev3tl_Mock_Gemini(
			static function ( $text, $item, $attempt ) {
				// First answer translates the brand (violation); the retry keeps it.
				return 1 === $attempt ? str_replace( 'CloudDesk Pro', 'WolkenTisch Profi', '[DE] ' . $text ) : '[DE] ' . $text;
			}
		);
		$doc = $this->doc( 'Meet the CloudDesk Pro, our best desk.' );
		$res = Sync_Engine::resolve( $doc, 'en_US', 'de_DE', array() );

		$this->assertCount( 2, $this->mock->requests );
		$this->assertStringContainsString( '"CloudDesk Pro"', $this->mock->requests[0]['system'] );
		$this->assertStringContainsString( 'Never translate', $this->mock->requests[0]['system'] );
		$this->assertStringContainsString( 'must stay untranslated', $this->mock->requests[1]['prompt'] );
		$this->assertCount( 1, $this->mock->requests[1]['items'] );
		$translation = array_values( $res['translations'] )[0];
		$this->assertSame( '[DE] Meet the CloudDesk Pro, our best desk.', $translation );
		$this->assertSame( 'machine', array_values( $res['status'] )[0] );
	}

	public function test_persistent_violation_is_flagged_for_review() {
		Glossary::save(
			array(
				'term'         => 'checkout',
				'translations' => array( 'de_DE' => 'Kasse' ),
			)
		);
		$this->mock = new Dev3tl_Mock_Gemini(
			static function () {
				return 'Zum Bezahlvorgang';
			}
		);
		$doc = $this->doc( 'Go to checkout' );
		$res = Sync_Engine::resolve( $doc, 'en_US', 'de_DE', array() );
		$this->assertCount( 2, $this->mock->requests );
		$this->assertStringContainsString( '"checkout" → "Kasse"', $this->mock->requests[0]['system'] );
		$this->assertSame( 'review', array_values( $res['status'] )[0] );
		$this->assertSame( 1, $res['stats']['review'] );
		$this->assertSame( 'review', Translation_Memory::lookup( array( Document::hash( 'Go to checkout' ) ), 'en_US', 'de_DE' )[ Document::hash( 'Go to checkout' ) ]['status'] );
	}

	public function test_violations_helper_and_case_rules() {
		$terms = array(
			array(
				'term'           => 'iPhone',
				'dnt'            => true,
				'case_sensitive' => true,
				'translations'   => array(),
			),
			array(
				'term'           => 'cart',
				'dnt'            => false,
				'case_sensitive' => false,
				'translations'   => array( 'fr' => 'panier' ),
			),
		);
		$this->assertSame( array(), Glossary::violations( 'Buy an iPhone. Add to Cart.', 'Achetez un iPhone. Ajouter au Panier.', 'fr_FR', $terms ) );
		$this->assertCount( 2, Glossary::violations( 'Buy an iPhone. Add to cart.', 'Achetez un IPHONE. Ajouter au chariot.', 'fr_FR', $terms ) );
		$this->assertSame( array(), Glossary::violations( 'Smartphones', 'Smartphones', 'fr_FR', $terms ) ); // Word boundaries.
	}

	public function test_placeholder_mismatch_is_retried_then_repaired() {
		$this->mock = new Dev3tl_Mock_Gemini(
			static function ( $text ) {
				return str_replace( array( '<1>', '</1>' ), '', '[DE] ' . $text ); // Always drops the link placeholder.
			}
		);
		$doc = new Document();
		Segmenter::add_html( $doc, 'content', '<p>Read <a href="/a">the guide</a> now.</p>' );
		$res = Sync_Engine::resolve( $doc, 'en_US', 'de_DE', array() );
		$this->assertCount( 2, $this->mock->requests );
		$out = $doc->render( $res['translations'] )['content'];
		$this->assertSame( '<p>[DE] Read the guide now.</p>', $out ); // Valid HTML, flagged for review.
		$this->assertSame( 'review', array_values( $res['status'] )[0] );
		$this->assertSame( 'a <1>b </1><2/>', Translator::repair( 'a <1>b <3/>', 'a <1>b</1> <2/>' ) );
		$this->assertSame( 'x y', Translator::repair( 'x </1>y<1>', 'a <1>b</1>' ) ); // Reversed pair is dropped.
	}

	public function test_unit_conversion_hints() {
		$hints = Unit_Converter::hints( 'The desk is 6 feet wide, weighs 55 lbs, bake at 350°F, a 12-inch shelf, 5 in stock, 3 1/2 miles away.', 'metric' );
		$this->assertContains( '6 feet = 1.83 m', $hints );
		$this->assertContains( '55 lbs = 24.9 kg', $hints );
		$this->assertContains( '350°F = 177 °C', $hints );
		$this->assertContains( '12-inch = 30.5 cm', $hints );
		$this->assertContains( '3 1/2 miles = 5.63 km', $hints );
		foreach ( $hints as $hint ) {
			$this->assertStringNotContainsString( '5 in', $hint ); // "5 in stock" is not a measurement.
		}
		$this->assertSame( array( '2 km = 1.24 mi', '20 °C = 68 °F' ), Unit_Converter::hints( 'Walk 2 km at 20 °C.', 'imperial' ) );
		$this->assertSame( array(), Unit_Converter::hints( 'The desk is 6 feet wide.', 'keep' ) );
	}

	public function test_profiles_and_prompt_carry_cultural_parameters() {
		$de = Locale_Profiles::get( 'de_DE' );
		$this->assertSame( 'formal', $de['formality'] );
		$this->assertSame( 'metric', $de['units'] );
		$this->assertSame( 'EUR', $de['currency'] );
		$this->assertSame( 'd.m.Y', $de['date_format'] );
		$this->assertSame( ',', $de['decimal_separator'] );
		$this->assertSame( 'imperial', Locale_Profiles::get( 'en_US' )['units'] );

		Locale_Profiles::save(
			'fr_FR',
			array(
				'formality'     => 'informal',
				'currency_mode' => 'convert',
				'currency'      => 'eur',
				'currency_rate' => 0.92,
				'idioms'        => false,
				'instructions'  => 'Use "boutique" instead of "magasin".',
			)
		);
		$fr     = Locale_Profiles::get( 'fr_FR' );
		$system = Prompt_Builder::system( 'en_US', 'fr_FR', $fr, array() );
		$this->assertStringContainsString( 'French (fr_FR)', $system );
		$this->assertStringContainsString( 'use "tu", never "vous"', $system );
		$this->assertStringContainsString( 'Convert imperial', $system );
		$this->assertStringContainsString( 'Convert prices and currency amounts to EUR', $system );
		$this->assertStringContainsString( 'DD/MM/YYYY', $system );
		$this->assertStringContainsString( '1 234 567,89', $system );
		$this->assertStringContainsString( 'Translate idioms and culture-specific references faithfully', $system );
		$this->assertStringContainsString( 'Use "boutique" instead of "magasin".', $system );
		$this->assertStringContainsString( 'use "Sie"', Prompt_Builder::system( 'en_US', 'de_DE', $de, array() ) );
		$this->assertSame( array( '$20 ≈ 18.40 EUR' ), Unit_Converter::currency_hints( 'Only $20 today', $fr ) );
	}

	public function test_conversions_are_sent_with_the_item() {
		$this->mock = new Dev3tl_Mock_Gemini();
		Sync_Engine::resolve( $this->doc( 'Our desk is 6 feet wide.' ), 'en_US', 'de_DE', array() );
		$item = $this->mock->requests[0]['items'][0];
		$this->assertSame( array( '6 feet = 1.83 m' ), $item['conversions'] );
		$this->assertSame( 'p', $item['context'] );
		$this->assertStringContainsString( 'Address the reader formally (use "Sie", never "du")', $this->mock->requests[0]['system'] );
	}

	public function test_batching_respects_limits_and_ids_are_stable() {
		\Dev3\AiTranslatorLocalizer\Core3\Core\Settings_Store::update(
			array(
				'batch_size'  => 2,
				'batch_chars' => 500,
			)
		);
		$this->mock = new Dev3tl_Mock_Gemini();
		$doc        = new Document();
		Segmenter::add_html( $doc, 'content', '<p>One</p><p>Two</p><p>Three</p><p>Four</p><p>Five</p>' );
		$res = Sync_Engine::resolve( $doc, 'en_US', 'de_DE', array() );
		$this->assertCount( 3, $this->mock->requests );
		$this->assertSame( array( 's1', 's2' ), array_column( $this->mock->requests[0]['items'], 'id' ) );
		$this->assertSame( '[T] Five', $res['translations']['content#5'] );
		$this->assertSame( 2, $this->mock->requests[0]['body']['generationConfig']['responseSchema']['maxItems'] );
	}
}
