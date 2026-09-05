<?php
/**
 * OpenAI pixel provider.
 *
 * OpenAI's tracking pixel is distributed as a snippet from the OpenAI
 * business/ads dashboard. Rather than guess at (and hard-code) an exact
 * script that can change without notice, this provider lets the site
 * owner paste that snippet in directly and takes care of placement
 * (head/footer) and safe output for them.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OAIP_Provider_OpenAI extends OAIP_Provider {

	public function get_id() {
		return 'openai';
	}

	public function get_label() {
		return __( 'OpenAI Pixel', 'openai-pixel' );
	}

	public function get_description() {
		return __( 'Paste the pixel/tracking snippet from your OpenAI business dashboard below. Use %PIXEL_ID% anywhere in the snippet and it will be replaced with the Pixel ID field.', 'openai-pixel' );
	}

	public function get_defaults() {
		$defaults          = parent::get_defaults();
		$defaults['pixel_id'] = '';
		return $defaults;
	}

	public function sanitize( $input ) {
		$sanitized             = parent::sanitize( $input );
		$sanitized['pixel_id'] = isset( $input['pixel_id'] ) ? sanitize_text_field( $input['pixel_id'] ) : '';
		return $sanitized;
	}

	public function render( $settings ) {
		if ( empty( $settings['code'] ) ) {
			return;
		}

		$code = $settings['code'];
		if ( ! empty( $settings['pixel_id'] ) ) {
			$code = str_replace( '%PIXEL_ID%', $settings['pixel_id'], $code );
		}

		$settings['code'] = $code;
		parent::render( $settings );
	}
}
