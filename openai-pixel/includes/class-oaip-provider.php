<?php
/**
 * Base class every pixel provider (OpenAI, and later Meta, Google, ...) extends.
 *
 * Keeping providers as small, self-contained classes is what lets new
 * pixels get added later without touching the core or the admin screen.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

abstract class OAIP_Provider {

	/**
	 * Unique, stable key used as the settings array index. e.g. "openai".
	 *
	 * @return string
	 */
	abstract public function get_id();

	/**
	 * Human readable name shown in the admin UI. e.g. "OpenAI Pixel".
	 *
	 * @return string
	 */
	abstract public function get_label();

	/**
	 * Short helper text shown under the provider's settings section.
	 *
	 * @return string
	 */
	public function get_description() {
		return '';
	}

	/**
	 * Default values for this provider's settings.
	 *
	 * @return array
	 */
	public function get_defaults() {
		return array(
			'enabled'  => false,
			'position' => 'head',
			'code'     => '',
		);
	}

	/**
	 * Sanitize this provider's settings before they are saved.
	 *
	 * @param array $input Raw input for this provider.
	 * @return array Sanitized settings for this provider.
	 */
	public function sanitize( $input ) {
		$defaults = $this->get_defaults();
		$input    = is_array( $input ) ? $input : array();

		$position = isset( $input['position'] ) ? $input['position'] : $defaults['position'];
		if ( ! in_array( $position, array( 'head', 'footer' ), true ) ) {
			$position = $defaults['position'];
		}

		$code = isset( $input['code'] ) ? (string) $input['code'] : '';
		if ( ! current_user_can( 'unfiltered_html' ) ) {
			// Anyone without unfiltered_html only gets plain, script-free markup.
			$code = wp_kses_post( $code );
		}

		return array(
			'enabled'  => ! empty( $input['enabled'] ),
			'position' => $position,
			'code'     => $code,
		);
	}

	/**
	 * Output the pixel code for the given settings. Providers may override
	 * this if they need to do more than print the stored snippet verbatim
	 * (e.g. wrap it, inject an ID into a template).
	 *
	 * @param array $settings This provider's saved settings.
	 */
	public function render( $settings ) {
		if ( empty( $settings['code'] ) ) {
			return;
		}

		echo "\n<!-- {$this->get_label()} (via OpenAI Pixel plugin) -->\n";
		// Intentionally unescaped: this is the tracking snippet the site
		// owner pasted in via a manage_options-gated settings screen.
		echo $settings['code']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo "\n<!-- / {$this->get_label()} -->\n";
	}
}
