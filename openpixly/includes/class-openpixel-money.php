<?php
/**
 * Money helpers.
 *
 * OpenAI expects `amount` as an integer in the ISO 4217 minor unit for the
 * given currency (12999 for $129.99). The exponent depends on the currency,
 * not on the store's display decimals, so we keep our own table for the
 * currencies that are not 2-decimal.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OpenPixel_Money {

	/** Currencies with zero minor-unit digits. */
	const ZERO_DECIMAL = array(
		'BIF', 'CLP', 'DJF', 'GNF', 'ISK', 'JPY', 'KMF', 'KRW', 'PYG', 'RWF',
		'UGX', 'UYI', 'VND', 'VUV', 'XAF', 'XOF', 'XPF',
	);

	/** Currencies with three minor-unit digits. */
	const THREE_DECIMAL = array( 'BHD', 'IQD', 'JOD', 'KWD', 'LYD', 'OMR', 'TND' );

	/**
	 * Number of minor-unit digits for a currency code.
	 *
	 * @param string $currency ISO 4217 code.
	 * @return int
	 */
	public static function exponent( $currency ) {
		$currency = strtoupper( (string) $currency );

		if ( in_array( $currency, self::ZERO_DECIMAL, true ) ) {
			return 0;
		}
		if ( in_array( $currency, self::THREE_DECIMAL, true ) ) {
			return 3;
		}

		return (int) apply_filters( 'openpixel_currency_exponent', 2, $currency );
	}

	/**
	 * Convert a major-unit amount (25.99) into minor units (2599).
	 *
	 * @param float|string $amount   Major-unit amount.
	 * @param string       $currency ISO 4217 code.
	 * @return int
	 */
	public static function to_minor( $amount, $currency ) {
		$amount = (float) $amount;
		$factor = pow( 10, self::exponent( $currency ) );

		return (int) round( $amount * $factor );
	}

	/**
	 * Uppercase 3-letter currency code, or empty string if it doesn't look valid.
	 *
	 * @param string $currency
	 * @return string
	 */
	public static function normalize_currency( $currency ) {
		$currency = strtoupper( trim( (string) $currency ) );
		return preg_match( '/^[A-Z]{3}$/', $currency ) ? $currency : '';
	}
}
