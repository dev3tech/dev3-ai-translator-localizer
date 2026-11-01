<?php
/**
 * Detects measurements and currency amounts and pre-computes conversions for the prompt.
 *
 * The model is told to use these exact numbers instead of doing arithmetic itself.
 *
 * @package Dev3\AiTranslatorLocalizer
 */

namespace Dev3\AiTranslatorLocalizer\Localization;

defined( 'ABSPATH' ) || exit;

/**
 * Imperial ↔ metric hints and currency hints.
 */
class Unit_Converter {

	/**
	 * Imperial units: pattern => [ factor, metric unit, label ]. Order matters (longest first).
	 */
	const IMPERIAL = array(
		'(?i:square f(?:ee|oo)t)|sq\.? ?ft|ft²'        => array( 0.09290304, 'm²', 'sq ft' ),
		'(?i:square inch(?:es)?)|sq\.? ?in|in²'        => array( 6.4516, 'cm²', 'sq in' ),
		'(?i:square miles?)|sq\.? ?mi|mi²'             => array( 2.589988, 'km²', 'sq mi' ),
		'(?i:fluid ounces?)|fl\.? ?oz'                 => array( 29.5735, 'ml', 'fl oz' ),
		'(?i:miles per hour)|mph'                      => array( 1.609344, 'km/h', 'mph' ),
		'(?i:miles per gallon)|mpg'                    => array( 0.425144, 'km/l', 'mpg' ),
		'(?i:inch(?:es)?)|in\.|″'                      => array( 2.54, 'cm', 'in' ),
		'(?i:feet|foot)|ft\.?|′'                       => array( 0.3048, 'm', 'ft' ),
		'(?i:yards?)|yd\.?'                            => array( 0.9144, 'm', 'yd' ),
		'(?i:miles?)|mi\.?'                            => array( 1.609344, 'km', 'mi' ),
		'(?i:ounces?)|oz\.?'                           => array( 28.349523, 'g', 'oz' ),
		'(?i:pounds?)|lbs?\.?'                         => array( 0.45359237, 'kg', 'lb' ),
		'(?i:stones?)'                                 => array( 6.35029, 'kg', 'st' ),
		'(?i:gallons?)|gal\.?'                         => array( 3.785411, 'l', 'gal' ),
		'(?i:quarts?)|qt\.?'                           => array( 0.946353, 'l', 'qt' ),
		'(?i:pints?)'                                  => array( 0.473176, 'l', 'pt' ),
		'(?i:cups?)'                                   => array( 236.588, 'ml', 'cup' ),
		'(?i:acres?)'                                  => array( 0.404686, 'ha', 'acre' ),
		'°F|° F|ºF|(?i:degrees Fahrenheit|Fahrenheit)' => array( 'F', '°C', '°F' ),
	);

	/**
	 * Metric units for metric → imperial.
	 */
	const METRIC = array(
		'(?i:square met(?:er|re)s?)|m²|sq\.? ?m'   => array( 10.7639, 'sq ft', 'm²' ),
		'(?i:kilomet(?:er|re)s per hour)|km/h|kph' => array( 0.621371, 'mph', 'km/h' ),
		'(?i:millimet(?:er|re)s?)|mm'              => array( 0.0393701, 'in', 'mm' ),
		'(?i:centimet(?:er|re)s?)|cm'              => array( 0.393701, 'in', 'cm' ),
		'(?i:kilomet(?:er|re)s?)|km'               => array( 0.621371, 'mi', 'km' ),
		'(?i:met(?:er|re)s?)|m'                    => array( 3.28084, 'ft', 'm' ),
		'(?i:kilograms?|kilos?)|kg'                => array( 2.20462, 'lb', 'kg' ),
		'(?i:grams?)|g'                            => array( 0.035274, 'oz', 'g' ),
		'(?i:millilit(?:er|re)s?)|ml'              => array( 0.033814, 'fl oz', 'ml' ),
		'(?i:lit(?:er|re)s?)|l'                    => array( 0.264172, 'gal', 'l' ),
		'(?i:hectares?)|ha'                        => array( 2.47105, 'acres', 'ha' ),
		'°C|° C|ºC|(?i:degrees Celsius|Celsius)'   => array( 'C', '°F', '°C' ),
	);

	/**
	 * Currency symbols/codes.
	 */
	const CURRENCIES = array(
		'USD' => array( '\$', 'US\$', 'USD', 'dollars?' ),
		'EUR' => array( '€', 'EUR', 'euros?' ),
		'GBP' => array( '£', 'GBP', 'pounds sterling' ),
		'JPY' => array( '¥', 'JPY', 'yen' ),
		'INR' => array( '₹', 'INR', 'rupees?' ),
		'CHF' => array( 'CHF' ),
		'CAD' => array( 'C\$', 'CAD' ),
		'AUD' => array( 'A\$', 'AUD' ),
	);

	const NUMBER = '(\d{1,3}(?:,\d{3})+(?:\.\d+)?|\d+(?:\.\d+)?|\d+\s+\d\/\d|\d\/\d)';

	/**
	 * Measurement conversion hints for a text.
	 *
	 * @param string $text   Source text (placeholders stripped).
	 * @param string $target Target system: metric|imperial|keep.
	 * @return string[] e.g. "6 feet = 1.83 m".
	 */
	public static function hints( $text, $target ) {
		if ( 'keep' === $target ) {
			return array();
		}
		$table = 'metric' === $target ? self::IMPERIAL : self::METRIC;
		$out   = array();
		$text  = html_entity_decode( (string) $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		foreach ( $table as $pattern => $spec ) {
			$re = '~(?<![\w.,])' . self::NUMBER . '(?:\s?|-)(' . $pattern . ')(?![\p{L}])~u';
			if ( ! preg_match_all( $re, $text, $all, PREG_SET_ORDER ) ) {
				continue;
			}
			foreach ( $all as $m ) {
				$value = self::parse_number( $m[1] );
				if ( null === $value ) {
					continue;
				}
				$converted = self::convert( $value, $spec[0] );
				$key       = $m[0];
				if ( isset( $out[ $key ] ) ) {
					continue;
				}
				$out[ $key ] = trim( $m[0] ) . ' = ' . self::format( $converted ) . ' ' . $spec[1];
			}
			$text = preg_replace( $re, ' ', $text ); // Do not match the same quantity twice.
		}
		return array_values( $out );
	}

	/**
	 * Currency hints.
	 *
	 * @param string $text    Text.
	 * @param array  $profile Locale profile.
	 * @return string[]
	 */
	public static function currency_hints( $text, array $profile ) {
		if ( 'convert' !== $profile['currency_mode'] || empty( $profile['currency'] ) || (float) $profile['currency_rate'] <= 0 ) {
			return array();
		}
		$out  = array();
		$text = html_entity_decode( (string) $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		foreach ( self::CURRENCIES as $code => $symbols ) {
			if ( $code === $profile['currency'] ) {
				continue;
			}
			$sym = implode( '|', $symbols );
			$re  = '~(?:(?:' . $sym . ')\s?' . self::NUMBER . '|' . self::NUMBER . '\s?(?:' . $sym . '))(?![\p{L}\d])~u';
			if ( ! preg_match_all( $re, $text, $all, PREG_SET_ORDER ) ) {
				continue;
			}
			foreach ( $all as $m ) {
				$number = '' !== $m[1] ? $m[1] : ( isset( $m[2] ) ? $m[2] : '' );
				$value  = self::parse_number( $number );
				if ( null === $value ) {
					continue;
				}
				$out[ $m[0] ] = trim( $m[0] ) . ' ≈ ' . number_format( $value * (float) $profile['currency_rate'], 2, '.', '' ) . ' ' . $profile['currency'];
			}
		}
		return array_values( $out );
	}

	/**
	 * Parses "1,234.5", "3 1/2", "1/2".
	 *
	 * @param string $raw Raw.
	 * @return float|null
	 */
	public static function parse_number( $raw ) {
		$raw = trim( (string) $raw );
		if ( preg_match( '/^(\d+)\s+(\d)\/(\d)$/', $raw, $m ) ) {
			return (float) $m[1] + ( (int) $m[3] ? (int) $m[2] / (int) $m[3] : 0 );
		}
		if ( preg_match( '/^(\d)\/(\d)$/', $raw, $m ) ) {
			return (int) $m[2] ? (int) $m[1] / (int) $m[2] : null;
		}
		$raw = str_replace( ',', '', $raw );
		return is_numeric( $raw ) ? (float) $raw : null;
	}

	/**
	 * Applies a factor (or temperature formula).
	 *
	 * @param float        $value  Value.
	 * @param float|string $factor Factor, or 'F' / 'C'.
	 * @return float
	 */
	public static function convert( $value, $factor ) {
		if ( 'F' === $factor ) {
			return ( $value - 32 ) * 5 / 9;
		}
		if ( 'C' === $factor ) {
			return $value * 9 / 5 + 32;
		}
		return $value * (float) $factor;
	}

	/**
	 * Rounds sensibly (3 significant digits, max 2 decimals).
	 *
	 * @param float $value Value.
	 * @return string
	 */
	public static function format( $value ) {
		$abs = abs( $value );
		if ( $abs >= 100 ) {
			$decimals = 0;
		} elseif ( $abs >= 10 ) {
			$decimals = 1;
		} else {
			$decimals = 2;
		}
		$str = number_format( $value, $decimals, '.', '' );
		return false !== strpos( $str, '.' ) ? rtrim( rtrim( $str, '0' ), '.' ) : $str;
	}
}
