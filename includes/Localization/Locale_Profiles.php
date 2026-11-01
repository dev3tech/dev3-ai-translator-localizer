<?php
/**
 * Cultural adaptation parameters per target locale.
 *
 * @package Dev3\AiTranslatorLocalizer
 */

namespace Dev3\AiTranslatorLocalizer\Localization;

defined( 'ABSPATH' ) || exit;

/**
 * Stored in the dev3tl_locales option, keyed by WordPress locale (de_DE, fr_FR…).
 */
class Locale_Profiles {

	const OPTION = 'dev3tl_locales';

	/**
	 * English language names by ISO 639-1 code (prompt text; WordPress has no offline list).
	 */
	const LANGUAGES = array(
		'af' => 'Afrikaans',
		'ar' => 'Arabic',
		'az' => 'Azerbaijani',
		'bg' => 'Bulgarian',
		'bn' => 'Bengali',
		'bs' => 'Bosnian',
		'ca' => 'Catalan',
		'cs' => 'Czech',
		'cy' => 'Welsh',
		'da' => 'Danish',
		'de' => 'German',
		'el' => 'Greek',
		'en' => 'English',
		'es' => 'Spanish',
		'et' => 'Estonian',
		'eu' => 'Basque',
		'fa' => 'Persian',
		'fi' => 'Finnish',
		'fr' => 'French',
		'ga' => 'Irish',
		'gl' => 'Galician',
		'gu' => 'Gujarati',
		'he' => 'Hebrew',
		'hi' => 'Hindi',
		'hr' => 'Croatian',
		'hu' => 'Hungarian',
		'hy' => 'Armenian',
		'id' => 'Indonesian',
		'is' => 'Icelandic',
		'it' => 'Italian',
		'ja' => 'Japanese',
		'ka' => 'Georgian',
		'kk' => 'Kazakh',
		'km' => 'Khmer',
		'kn' => 'Kannada',
		'ko' => 'Korean',
		'lt' => 'Lithuanian',
		'lv' => 'Latvian',
		'mk' => 'Macedonian',
		'ml' => 'Malayalam',
		'mn' => 'Mongolian',
		'mr' => 'Marathi',
		'ms' => 'Malay',
		'my' => 'Burmese',
		'nb' => 'Norwegian Bokmål',
		'ne' => 'Nepali',
		'nl' => 'Dutch',
		'nn' => 'Norwegian Nynorsk',
		'pa' => 'Punjabi',
		'pl' => 'Polish',
		'pt' => 'Portuguese',
		'ro' => 'Romanian',
		'ru' => 'Russian',
		'si' => 'Sinhala',
		'sk' => 'Slovak',
		'sl' => 'Slovenian',
		'sq' => 'Albanian',
		'sr' => 'Serbian',
		'sv' => 'Swedish',
		'sw' => 'Swahili',
		'ta' => 'Tamil',
		'te' => 'Telugu',
		'th' => 'Thai',
		'tl' => 'Tagalog',
		'tr' => 'Turkish',
		'uk' => 'Ukrainian',
		'ur' => 'Urdu',
		'uz' => 'Uzbek',
		'vi' => 'Vietnamese',
		'zh' => 'Chinese',
	);

	/**
	 * Formal / informal address forms by language.
	 */
	const FORMALITY = array(
		'de' => array( 'Sie', 'du' ),
		'fr' => array( 'vous', 'tu' ),
		'es' => array( 'usted', 'tú' ),
		'it' => array( 'Lei', 'tu' ),
		'pt' => array( 'o senhor / a senhora', 'você / tu' ),
		'nl' => array( 'u', 'je / jij' ),
		'pl' => array( 'Pan / Pani', 'ty' ),
		'ru' => array( 'Вы', 'ты' ),
		'uk' => array( 'Ви', 'ти' ),
		'cs' => array( 'vy (vykání)', 'ty (tykání)' ),
		'sv' => array( 'ni', 'du' ),
		'da' => array( 'De', 'du' ),
		'nb' => array( 'De', 'du' ),
		'tr' => array( 'siz', 'sen' ),
		'el' => array( 'εσείς', 'εσύ' ),
		'hu' => array( 'Ön / maga', 'te' ),
		'ro' => array( 'dumneavoastră', 'tu' ),
		'ja' => array( 'keigo (丁寧語/尊敬語)', 'casual (タメ口)' ),
		'ko' => array( 'jondaetmal (존댓말)', 'banmal (반말)' ),
		'zh' => array( '您', '你' ),
		'hi' => array( 'आप', 'तुम' ),
	);

	/**
	 * Countries that use imperial / US customary units.
	 */
	const IMPERIAL = array( 'US', 'LR', 'MM' );

	/**
	 * Currency by country.
	 */
	const CURRENCY = array(
		'US' => 'USD',
		'GB' => 'GBP',
		'CA' => 'CAD',
		'AU' => 'AUD',
		'NZ' => 'NZD',
		'IN' => 'INR',
		'JP' => 'JPY',
		'CN' => 'CNY',
		'KR' => 'KRW',
		'BR' => 'BRL',
		'MX' => 'MXN',
		'CH' => 'CHF',
		'SE' => 'SEK',
		'NO' => 'NOK',
		'DK' => 'DKK',
		'PL' => 'PLN',
		'CZ' => 'CZK',
		'HU' => 'HUF',
		'RO' => 'RON',
		'TR' => 'TRY',
		'RU' => 'RUB',
		'UA' => 'UAH',
		'AE' => 'AED',
		'SA' => 'SAR',
		'IL' => 'ILS',
		'ZA' => 'ZAR',
		'SG' => 'SGD',
		'TH' => 'THB',
		'ID' => 'IDR',
		'VN' => 'VND',
	);

	/**
	 * Eurozone countries.
	 */
	const EURO = array( 'AT', 'BE', 'CY', 'DE', 'EE', 'ES', 'FI', 'FR', 'GR', 'HR', 'IE', 'IT', 'LT', 'LU', 'LV', 'MT', 'NL', 'PT', 'SI', 'SK' );

	/**
	 * Default country for a bare language code.
	 */
	const DEFAULT_COUNTRY = array(
		'de' => 'DE',
		'fr' => 'FR',
		'es' => 'ES',
		'it' => 'IT',
		'pt' => 'PT',
		'nl' => 'NL',
		'pl' => 'PL',
		'en' => 'US',
		'ja' => 'JP',
		'zh' => 'CN',
		'ko' => 'KR',
		'sv' => 'SE',
		'da' => 'DK',
		'nb' => 'NO',
		'fi' => 'FI',
		'cs' => 'CZ',
		'ru' => 'RU',
		'tr' => 'TR',
		'el' => 'GR',
		'hu' => 'HU',
		'ro' => 'RO',
		'uk' => 'UA',
		'ar' => 'SA',
		'he' => 'IL',
		'hi' => 'IN',
	);

	/**
	 * Language part of a locale.
	 *
	 * @param string $locale Locale.
	 * @return string
	 */
	public static function lang( $locale ) {
		return strtolower( (string) preg_replace( '/[_-].*$/', '', (string) $locale ) );
	}

	/**
	 * Country part of a locale.
	 *
	 * @param string $locale Locale.
	 * @return string
	 */
	public static function country( $locale ) {
		if ( preg_match( '/[_-]([A-Za-z]{2})(?:[_-]|$)/', (string) $locale, $m ) ) {
			return strtoupper( $m[1] );
		}
		$lang = self::lang( $locale );
		return isset( self::DEFAULT_COUNTRY[ $lang ] ) ? self::DEFAULT_COUNTRY[ $lang ] : '';
	}

	/**
	 * English name for a locale ("German (Switzerland)" style is not needed: "German, de_CH").
	 *
	 * @param string $locale Locale.
	 * @return string
	 */
	public static function language_name( $locale ) {
		$lang = self::lang( $locale );
		$name = isset( self::LANGUAGES[ $lang ] ) ? self::LANGUAGES[ $lang ] : $lang;
		return $name . ' (' . $locale . ')';
	}

	/**
	 * Sensible defaults for a locale.
	 *
	 * @param string $locale Locale.
	 * @return array
	 */
	public static function defaults( $locale ) {
		$country = self::country( $locale );
		$lang    = self::lang( $locale );
		$comma   = ! in_array( $lang, array( 'en', 'ja', 'zh', 'ko', 'he', 'th', 'hi', 'ms', 'tl' ), true ) && ! ( 'CH' === $country );
		$date    = 'Y-m-d';
		if ( 'US' === $country ) {
			$date = 'm/d/Y';
		} elseif ( in_array( $lang, array( 'de', 'pl', 'ru', 'cs', 'fi', 'nb', 'da', 'tr', 'uk', 'ro', 'sk' ), true ) ) {
			$date = 'd.m.Y';
		} elseif ( in_array( $lang, array( 'fr', 'es', 'it', 'pt', 'en', 'el', 'nl', 'ar', 'he', 'hi' ), true ) ) {
			$date = 'd/m/Y';
		} elseif ( in_array( $lang, array( 'ja', 'zh', 'ko', 'hu', 'sv', 'lt' ), true ) ) {
			$date = 'Y-m-d';
		}
		if ( in_array( $country, self::EURO, true ) ) {
			$currency = 'EUR';
		} else {
			$currency = isset( self::CURRENCY[ $country ] ) ? self::CURRENCY[ $country ] : '';
		}
		return array(
			'formality'           => isset( self::FORMALITY[ $lang ] ) ? 'formal' : 'auto',
			'units'               => in_array( $country, self::IMPERIAL, true ) ? 'imperial' : 'metric',
			'units_keep_original' => false,
			'currency_mode'       => 'keep',
			'currency'            => $currency,
			'currency_rate'       => 0,
			'date_format'         => $date,
			'decimal_separator'   => $comma ? ',' : '.',
			'thousands_separator' => $comma ? ( in_array( $lang, array( 'fr', 'sv', 'nb', 'fi', 'cs', 'pl', 'ru', 'uk', 'sk', 'hu' ), true ) ? ' ' : '.' ) : ',',
			'idioms'              => true,
			'tone'                => '',
			'instructions'        => '',
		);
	}

	/**
	 * All stored profiles.
	 *
	 * @return array locale => profile
	 */
	public static function stored() {
		$stored = get_option( self::OPTION, array() );
		return is_array( $stored ) ? $stored : array();
	}

	/**
	 * Profile for a locale (defaults merged with stored values).
	 *
	 * @param string $locale Locale.
	 * @return array
	 */
	public static function get( $locale ) {
		$stored  = self::stored();
		$profile = array_merge( self::defaults( $locale ), isset( $stored[ $locale ] ) && is_array( $stored[ $locale ] ) ? $stored[ $locale ] : array() );
		/**
		 * Filters the cultural profile of a target locale.
		 *
		 * @param array  $profile Profile.
		 * @param string $locale  Locale.
		 */
		return apply_filters( 'dev3tl_locale_profile', $profile, $locale );
	}

	/**
	 * Sanitises and saves one profile.
	 *
	 * @param string $locale Locale.
	 * @param array  $input  Raw input.
	 * @return array Saved profile.
	 */
	public static function save( $locale, array $input ) {
		$locale  = preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $locale );
		$current = self::get( $locale );
		$clean   = $current;
		$choices = array(
			'formality'     => array( 'formal', 'informal', 'auto' ),
			'units'         => array( 'metric', 'imperial', 'keep' ),
			'currency_mode' => array( 'keep', 'convert', 'localize' ),
		);
		foreach ( $choices as $key => $allowed ) {
			if ( isset( $input[ $key ] ) && in_array( $input[ $key ], $allowed, true ) ) {
				$clean[ $key ] = $input[ $key ];
			}
		}
		foreach ( array( 'idioms', 'units_keep_original' ) as $flag ) {
			if ( isset( $input[ $flag ] ) ) {
				$clean[ $flag ] = (bool) $input[ $flag ];
			}
		}
		if ( isset( $input['currency'] ) ) {
			$clean['currency'] = strtoupper( substr( preg_replace( '/[^A-Za-z]/', '', (string) $input['currency'] ), 0, 3 ) );
		}
		if ( isset( $input['currency_rate'] ) ) {
			$clean['currency_rate'] = max( 0, round( (float) $input['currency_rate'], 6 ) );
		}
		if ( isset( $input['date_format'] ) ) {
			$clean['date_format'] = substr( sanitize_text_field( (string) $input['date_format'] ), 0, 20 );
		}
		foreach ( array( 'decimal_separator', 'thousands_separator' ) as $sep ) {
			if ( isset( $input[ $sep ] ) ) {
				$value         = (string) $input[ $sep ];
				$clean[ $sep ] = in_array( $value, array( '.', ',', ' ', "'", '' ), true ) ? $value : $current[ $sep ];
			}
		}
		foreach ( array(
			'tone'         => 300,
			'instructions' => 2000,
		) as $key => $max ) {
			if ( isset( $input[ $key ] ) ) {
				$clean[ $key ] = mb_substr( sanitize_textarea_field( (string) $input[ $key ] ), 0, $max );
			}
		}
		$stored            = self::stored();
		$stored[ $locale ] = $clean;
		update_option( self::OPTION, $stored, false );
		return $clean;
	}

	/**
	 * Human description of a PHP date format for the prompt ("DD.MM.YYYY").
	 *
	 * @param string $format PHP date format.
	 * @return string
	 */
	public static function describe_date( $format ) {
		return strtr(
			(string) $format,
			array(
				'd' => 'DD',
				'j' => 'D',
				'm' => 'MM',
				'n' => 'M',
				'Y' => 'YYYY',
				'y' => 'YY',
				'F' => 'MonthName',
				'M' => 'Mon',
			)
		);
	}

	/**
	 * Example number in the locale format.
	 *
	 * @param array $profile Profile.
	 * @return string
	 */
	public static function number_example( array $profile ) {
		return number_format( 1234567.89, 2, (string) $profile['decimal_separator'], (string) $profile['thousands_separator'] );
	}
}
