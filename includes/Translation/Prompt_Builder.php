<?php
/**
 * Builds translation + cultural-adaptation prompts.
 *
 * @package Dev3\AiTranslatorLocalizer
 */

namespace Dev3\AiTranslatorLocalizer\Translation;

use Dev3\AiTranslatorLocalizer\Data\Glossary;
use Dev3\AiTranslatorLocalizer\Localization\Locale_Profiles;

defined( 'ABSPATH' ) || exit;

/**
 * System instruction (rules) + user prompt (JSON items).
 */
class Prompt_Builder {

	const ITEMS_MARKER = 'ITEMS_JSON:';

	/**
	 * System instruction.
	 *
	 * @param string $source_locale Source locale.
	 * @param string $target_locale Target locale.
	 * @param array  $profile       Cultural profile.
	 * @param array  $terms         Relevant glossary terms.
	 * @return string
	 */
	public static function system( $source_locale, $target_locale, array $profile, array $terms ) {
		$src     = Locale_Profiles::language_name( $source_locale );
		$tgt     = Locale_Profiles::language_name( $target_locale );
		$lang    = Locale_Profiles::lang( $target_locale );
		$country = Locale_Profiles::country( $target_locale );
		$rules   = array();

		$rules[] = 'Return a JSON array with exactly one object per input item: {"id": <same id>, "translation": <text>}. Never skip, merge or split items.';
		$rules[] = 'Placeholders such as <1>, </1> and <2/> stand for markup (links, bold text, line breaks, shortcodes). Keep every placeholder exactly once and keep opening/closing pairs together; you may move them to fit natural word order. Never invent placeholders and never output real HTML tags.';
		$rules[] = 'Keep URLs, e-mail addresses, code, file names, SKUs and HTML entities such as &amp; or &nbsp; intact.';
		$rules[] = 'Items with context "button", "a", "label", "option" or an attribute (alt, title, placeholder, aria-label) must stay short and clear; headings must stay headings. Image alt text describes the image.';
		$rules[] = 'If an item is a proper noun, a number or already written in ' . $tgt . ', return it unchanged.';

		// Formality.
		if ( 'auto' !== $profile['formality'] ) {
			$forms   = isset( Locale_Profiles::FORMALITY[ $lang ] ) ? Locale_Profiles::FORMALITY[ $lang ] : null;
			$formal  = 'formal' === $profile['formality'];
			$rules[] = $formal
				? 'Address the reader formally' . ( $forms ? ' (use "' . $forms[0] . '", never "' . $forms[1] . '")' : '' ) . ', consistently in every item.'
				: 'Address the reader informally' . ( $forms ? ' (use "' . $forms[1] . '", never "' . $forms[0] . '")' : '' ) . ', consistently in every item.';
		}

		// Units.
		if ( 'metric' === $profile['units'] ) {
			$rules[] = 'Convert imperial / US customary measurements (inches, feet, miles, pounds, ounces, gallons, °F…) to metric units. When an item lists "conversions", use those exact values (you may round them naturally, e.g. 1.83 m → 1.8 m).' . ( ! empty( $profile['units_keep_original'] ) ? ' Keep the original measurement in parentheses after the converted one.' : '' );
		} elseif ( 'imperial' === $profile['units'] ) {
			$rules[] = 'Convert metric measurements to US customary units (inches, feet, miles, pounds, ounces, gallons, °F). When an item lists "conversions", use those exact values, rounded naturally.' . ( ! empty( $profile['units_keep_original'] ) ? ' Keep the original measurement in parentheses after the converted one.' : '' );
		} else {
			$rules[] = 'Keep all measurements and units exactly as in the source.';
		}

		// Currency.
		if ( 'convert' === $profile['currency_mode'] && ! empty( $profile['currency'] ) && (float) $profile['currency_rate'] > 0 ) {
			$rules[] = 'Convert prices and currency amounts to ' . $profile['currency'] . ' using the "conversions" given with the item (rate ' . $profile['currency_rate'] . '), rounded to a natural price, and write them the way ' . $tgt . ' readers expect.';
		} elseif ( 'localize' === $profile['currency_mode'] ) {
			$rules[] = 'Keep currency amounts and currencies unchanged, but write them the way ' . $tgt . ' readers expect (symbol position, spacing, separators).';
		} else {
			$rules[] = 'Keep prices, amounts and currency symbols exactly as in the source (do not convert currencies).';
		}

		// Dates & numbers.
		$rules[] = 'Write dates in the ' . Locale_Profiles::describe_date( $profile['date_format'] ) . ' pattern when the source contains a full date, and format numbers like ' . Locale_Profiles::number_example( $profile ) . '.';

		// Idioms.
		$rules[] = ! empty( $profile['idioms'] )
			? 'Do not translate literally: replace idioms, metaphors, puns, sports or holiday references and other culture-specific expressions with natural equivalents for readers in ' . ( $country ? $country : $tgt ) . ', and adapt marketing copy so it reads as if written natively.'
			: 'Translate idioms and culture-specific references faithfully; do not substitute them.';

		// Glossary.
		$dnt    = array();
		$forced = array();
		foreach ( $terms as $term ) {
			$value = Glossary::forced( $term, $target_locale );
			if ( '' !== $value ) {
				$forced[] = '"' . $term['term'] . '" → "' . $value . '"';
			} elseif ( $term['dnt'] ) {
				$dnt[] = '"' . $term['term'] . '"';
			}
		}
		if ( $dnt ) {
			$rules[] = 'Never translate, transliterate, inflect or re-case these protected terms; copy them exactly: ' . implode( ', ', $dnt ) . '.';
		}
		if ( $forced ) {
			$rules[] = 'Always use these mandatory glossary translations: ' . implode( '; ', $forced ) . '.';
		}
		if ( '' !== trim( (string) $profile['tone'] ) ) {
			$rules[] = 'Brand voice: ' . trim( $profile['tone'] );
		}
		if ( '' !== trim( (string) $profile['instructions'] ) ) {
			$rules[] = 'Additional instructions from the site owner: ' . trim( $profile['instructions'] );
		}

		$text = 'You are a senior translator and localization specialist. Translate website content from ' . $src . ' into ' . $tgt
			. ' for readers in ' . ( $country ? $country : $tgt ) . ", adapting it culturally so it reads naturally.\n\nRules:\n";
		foreach ( $rules as $i => $rule ) {
			$text .= ( $i + 1 ) . '. ' . $rule . "\n";
		}
		/**
		 * Filters the translation system instruction.
		 *
		 * @param string $text          Instruction.
		 * @param string $source_locale Source locale.
		 * @param string $target_locale Target locale.
		 * @param array  $profile       Profile.
		 */
		return (string) apply_filters( 'dev3tl_system_prompt', $text, $source_locale, $target_locale, $profile );
	}

	/**
	 * User prompt with the items.
	 *
	 * @param array $items { id, text, context?, conversions? }.
	 * @param array $fixes id => list of problems from a previous attempt.
	 * @return string
	 */
	public static function user( array $items, array $fixes = array() ) {
		$text = 'Translate the "text" of each item. ';
		if ( $fixes ) {
			$text .= "Your previous answer for these items broke the rules; fix exactly these problems:\n";
			foreach ( $fixes as $id => $problems ) {
				$text .= '- ' . $id . ': ' . implode( '; ', $problems ) . "\n";
			}
		}
		return $text . "\n" . self::ITEMS_MARKER . "\n" . wp_json_encode( array_values( $items ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
	}

	/**
	 * Response schema (responseSchema) for a batch.
	 *
	 * @param int $count      Items.
	 * @param int $max_length Max translation length.
	 * @return array
	 */
	public static function schema( $count, $max_length ) {
		return array(
			'type'     => 'ARRAY',
			'minItems' => (int) $count,
			'maxItems' => (int) $count,
			'items'    => array(
				'type'             => 'OBJECT',
				'properties'       => array(
					'id'          => array(
						'type'      => 'STRING',
						'maxLength' => 12,
					),
					'translation' => array(
						'type'      => 'STRING',
						'maxLength' => (int) $max_length,
					),
				),
				'required'         => array( 'id', 'translation' ),
				'propertyOrdering' => array( 'id', 'translation' ),
			),
		);
	}
}
