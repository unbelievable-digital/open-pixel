<?php
/**
 * Registers pixel providers and prints their code on the front end.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OAIP_Core {

	/** @var OAIP_Provider[] */
	private $providers = array();

	public function init() {
		$this->register_providers();

		add_action( 'wp_head', array( $this, 'output_head' ), 5 );
		add_action( 'wp_footer', array( $this, 'output_footer' ), 20 );
	}

	private function register_providers() {
		$providers = array(
			new OAIP_Provider_OpenAI(),
		);

		/**
		 * Add more pixel providers (Meta, Google, TikTok, ...) without
		 * touching this plugin's core files.
		 *
		 * @param OAIP_Provider[] $providers
		 */
		$providers = apply_filters( 'oaip_pixel_providers', $providers );

		foreach ( $providers as $provider ) {
			if ( $provider instanceof OAIP_Provider ) {
				$this->providers[ $provider->get_id() ] = $provider;
			}
		}
	}

	/**
	 * @return OAIP_Provider[]
	 */
	public function get_providers() {
		return $this->providers;
	}

	private function get_all_settings() {
		return get_option( OAIP_OPTION_KEY, array() );
	}

	private function get_provider_settings( OAIP_Provider $provider ) {
		$all      = $this->get_all_settings();
		$defaults = $provider->get_defaults();
		$saved    = isset( $all[ $provider->get_id() ] ) ? $all[ $provider->get_id() ] : array();

		return wp_parse_args( $saved, $defaults );
	}

	public function output_head() {
		$this->output_by_position( 'head' );
	}

	public function output_footer() {
		$this->output_by_position( 'footer' );
	}

	private function output_by_position( $position ) {
		foreach ( $this->providers as $provider ) {
			$settings = $this->get_provider_settings( $provider );

			if ( empty( $settings['enabled'] ) ) {
				continue;
			}

			if ( $settings['position'] !== $position ) {
				continue;
			}

			/**
			 * Let a site owner disable a pixel on specific requests
			 * (e.g. logged-in admins, a cookie-consent gate) without
			 * editing the plugin.
			 */
			if ( ! apply_filters( 'oaip_should_render_pixel', true, $provider->get_id(), $settings ) ) {
				continue;
			}

			$provider->render( $settings );
		}
	}
}
