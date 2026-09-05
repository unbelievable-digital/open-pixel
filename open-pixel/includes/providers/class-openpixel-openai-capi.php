<?php
/**
 * OpenAI Conversions API client.
 * https://developers.openai.com/ads/conversions-api
 *
 * POST https://bzr.openai.com/v1/events?pid=<PIXEL-ID>
 * Authorization: Bearer <API-KEY>
 *
 * Events are delivered asynchronously (Action Scheduler when WooCommerce
 * ships it, WP-Cron otherwise) with a few retries, and logged to the
 * WooCommerce logger under the "open-pixel" source when available.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OpenPixel_OpenAI_CAPI {

	const ENDPOINT           = 'https://bzr.openai.com/v1/events';
	const ACTION_HOOK        = 'openpixel_openai_capi_send';
	const INTEGRATION_SOURCE = 'open-pixel-wordpress';
	const MAX_ATTEMPTS       = 4;

	/** @var OpenPixel_Provider_OpenAI */
	private $provider;

	public function __construct( OpenPixel_Provider_OpenAI $provider ) {
		$this->provider = $provider;
		add_action( self::ACTION_HOOK, array( $this, 'deliver' ), 10, 2 );
	}

	public function is_configured() {
		return (bool) $this->provider->get_setting( 'capi_api_key' ) && $this->provider->get_pixel_ids();
	}

	/**
	 * Queue a normalized server event for async delivery.
	 *
	 * @param array $event Normalized bus event.
	 */
	public function queue_event( array $event ) {
		if ( ! $this->is_configured() ) {
			$this->log( 'Conversions API not configured (missing API key or Pixel ID); dropping event ' . $event['name'] . '.', 'warning' );
			return;
		}

		$capi_event = $this->provider->to_capi_event( $event );
		if ( ! $capi_event ) {
			return;
		}

		$this->schedule( $capi_event, 1, 0 );
	}

	private function schedule( array $capi_event, $attempt, $delay ) {
		$args = array( $capi_event, (int) $attempt );

		if ( function_exists( 'as_schedule_single_action' ) ) {
			as_schedule_single_action( time() + $delay, self::ACTION_HOOK, $args, 'open-pixel' );
			return;
		}

		wp_schedule_single_event( time() + $delay, self::ACTION_HOOK, $args );
	}

	/**
	 * Action Scheduler / WP-Cron callback.
	 *
	 * @param array $capi_event Conversions API event object.
	 * @param int   $attempt    1-based attempt counter.
	 */
	public function deliver( $capi_event, $attempt = 1 ) {
		if ( ! is_array( $capi_event ) ) {
			return;
		}

		$result = $this->send( array( $capi_event ), false );

		if ( is_wp_error( $result ) ) {
			$this->log(
				sprintf( 'Event %s (%s) attempt %d failed: %s', $capi_event['id'], $capi_event['type'], $attempt, $result->get_error_message() ),
				'error'
			);

			if ( $attempt < self::MAX_ATTEMPTS && $this->is_retryable( $result ) ) {
				$this->schedule( $capi_event, $attempt + 1, 5 * MINUTE_IN_SECONDS * $attempt );
			}
			return;
		}

		$this->log( sprintf( 'Event %s (%s) delivered.', $capi_event['id'], $capi_event['type'] ), 'info' );
		do_action( 'openpixel_openai_capi_delivered', $capi_event, $result );
	}

	/**
	 * Send a batch synchronously.
	 *
	 * @param array $events        Up to 1000 Conversions API event objects.
	 * @param bool  $validate_only Validate without saving.
	 * @return array|WP_Error Decoded response body on 2xx, WP_Error otherwise.
	 */
	public function send( array $events, $validate_only = false ) {
		$api_key   = (string) $this->provider->get_setting( 'capi_api_key' );
		$pixel_ids = $this->provider->get_pixel_ids();

		if ( ! $api_key || ! $pixel_ids ) {
			return new WP_Error( 'openpixel_capi_unconfigured', __( 'Conversions API key or Pixel ID missing.', 'open-pixel' ) );
		}

		$body = array(
			'validate_only'      => (bool) $validate_only,
			'integration_source' => self::INTEGRATION_SOURCE,
			'events'             => array_values( $events ),
		);

		$last = null;
		foreach ( $pixel_ids as $pixel_id ) {
			$url = self::ENDPOINT . '?pid=' . rawurlencode( $pixel_id );

			$response = wp_remote_post(
				$url,
				array(
					'timeout' => 15,
					'headers' => array(
						'Authorization' => 'Bearer ' . $api_key,
						'Content-Type'  => 'application/json',
					),
					'body'    => wp_json_encode( $body ),
				)
			);

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$code = (int) wp_remote_retrieve_response_code( $response );
			$raw  = wp_remote_retrieve_body( $response );
			$json = json_decode( $raw, true );

			if ( $code < 200 || $code >= 300 ) {
				$message = is_array( $json ) && isset( $json['error'] )
					? wp_json_encode( $json['error'] )
					: substr( $raw, 0, 500 );

				return new WP_Error(
					'openpixel_capi_http_' . $code,
					sprintf( 'HTTP %d: %s', $code, $message ),
					array( 'status' => $code, 'pixel_id' => $pixel_id )
				);
			}

			$last = is_array( $json ) ? $json : array( 'raw' => $raw );
		}

		return null === $last ? array() : $last;
	}

	private function is_retryable( WP_Error $error ) {
		$data = $error->get_error_data();
		if ( is_array( $data ) && isset( $data['status'] ) ) {
			$status = (int) $data['status'];
			// 4xx (other than 408/429) means the payload is wrong; retrying won't help.
			return $status >= 500 || 408 === $status || 429 === $status;
		}
		return true; // network errors
	}

	public function log( $message, $level = 'info' ) {
		if ( function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->log( $level, $message, array( 'source' => 'open-pixel' ) );
			return;
		}
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( '[open-pixel] ' . strtoupper( $level ) . ': ' . $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
	}
}
