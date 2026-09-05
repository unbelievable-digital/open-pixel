<?php
/**
 * Normalization + SHA-256 hashing of user identifiers, following the rules
 * in https://developers.openai.com/ads/measurement-pixel#send-user-data.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OAIP_Hash {

	/**
	 * Lowercase 64-char hex SHA-256 of a UTF-8 string.
	 *
	 * @param string $value Already-normalized value.
	 * @return string
	 */
	public static function sha256( $value ) {
		return hash( 'sha256', (string) $value );
	}

	/**
	 * Email: trim, lowercase.
	 */
	public static function email( $email ) {
		$email = strtolower( trim( (string) $email ) );
		return ( $email && is_email( $email ) ) ? self::sha256( $email ) : '';
	}

	/**
	 * Phone: strip whitespace, parentheses, periods, hyphens; drop leading "+"
	 * and leading zeroes; must be 8–15 digits afterwards.
	 */
	public static function phone( $phone ) {
		$digits = preg_replace( '/[\s().\-]/', '', (string) $phone );
		$digits = ltrim( $digits, '+' );
		$digits = ltrim( $digits, '0' );

		if ( ! preg_match( '/^[0-9]{8,15}$/', $digits ) ) {
			return '';
		}

		return self::sha256( $digits );
	}

	/**
	 * External ID: trim only, preserve case.
	 */
	public static function external_id( $id ) {
		$id = trim( (string) $id );
		return $id ? self::sha256( $id ) : '';
	}

	/**
	 * Names: lowercase, remove all whitespace and ASCII punctuation, keep
	 * non-ASCII characters (José -> josé, O'Connor -> oconnor).
	 */
	public static function name( $name ) {
		$name = (string) $name;
		$name = function_exists( 'mb_strtolower' ) ? mb_strtolower( $name, 'UTF-8' ) : strtolower( $name );
		$name = preg_replace( '/[\s!-\/:-@\[-`{-~]/u', '', $name );

		return $name ? self::sha256( $name ) : '';
	}

	/**
	 * Build the Pixel-shaped `user` object (singular keys) from raw values.
	 * Returns only the keys that had usable data.
	 *
	 * @param array $raw Keys: email, phone, external_id, first_name, last_name,
	 *                   country, city, region, postal_code.
	 * @return array
	 */
	public static function pixel_user( array $raw ) {
		$user = array();

		$map = array(
			'email'       => array( 'email_sha256', 'email' ),
			'phone'       => array( 'phone_number_sha256', 'phone' ),
			'external_id' => array( 'external_id_sha256', 'external_id' ),
			'first_name'  => array( 'first_name_sha256', 'name' ),
			'last_name'   => array( 'last_name_sha256', 'name' ),
		);

		foreach ( $map as $key => $spec ) {
			if ( empty( $raw[ $key ] ) ) {
				continue;
			}
			$hashed = call_user_func( array( __CLASS__, $spec[1] ), $raw[ $key ] );
			if ( $hashed ) {
				$user[ $spec[0] ] = $hashed;
			}
		}

		if ( ! empty( $raw['country'] ) && preg_match( '/^[A-Za-z]{2}$/', $raw['country'] ) ) {
			$user['country'] = strtoupper( $raw['country'] );
		}
		if ( ! empty( $raw['city'] ) ) {
			$user['city'] = mb_substr( trim( $raw['city'] ), 0, 128 );
		}
		if ( ! empty( $raw['region'] ) ) {
			$user['region'] = mb_substr( trim( $raw['region'] ), 0, 128 );
		}
		if ( ! empty( $raw['postal_code'] ) ) {
			$user['postal_code'] = mb_substr( trim( $raw['postal_code'] ), 0, 32 );
		}

		return $user;
	}

	/**
	 * Build the Conversions-API-shaped `user` object (plural list keys).
	 *
	 * @param array $raw Same keys as pixel_user() plus: ip_address, user_agent, obref.
	 * @return array
	 */
	public static function capi_user( array $raw ) {
		$single = self::pixel_user( $raw );
		$user   = array();

		$plural = array(
			'email_sha256'        => 'emails_sha256',
			'phone_number_sha256' => 'phone_numbers_sha256',
			'external_id_sha256'  => 'external_ids_sha256',
			'first_name_sha256'   => 'first_names_sha256',
			'last_name_sha256'    => 'last_names_sha256',
			'country'             => 'countries',
			'city'                => 'cities',
			'region'              => 'regions',
			'postal_code'         => 'postal_codes',
		);

		foreach ( $plural as $from => $to ) {
			if ( isset( $single[ $from ] ) ) {
				$user[ $to ] = array( $single[ $from ] );
			}
		}

		foreach ( array( 'ip_address', 'user_agent', 'obref' ) as $key ) {
			if ( ! empty( $raw[ $key ] ) ) {
				$user[ $key ] = (string) $raw[ $key ];
			}
		}

		return $user;
	}
}
