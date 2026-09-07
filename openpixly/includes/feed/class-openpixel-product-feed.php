<?php
/**
 * WooCommerce → OpenAI product feed.
 *
 * Spec: https://developers.openai.com/commerce/specs/file-upload/products
 * Ads:  https://developers.openai.com/ads/product-feeds
 *
 * - One row per simple product or per variation (group_id = parent, variant_dict).
 * - OpenAI format (item_id, url, image_url, seller_name, is_ads_eligible, ...)
 *   or the Google-compatible profile (id, link, image_link, item_group_id, ...).
 * - CSV / TSV / JSONL, built in batches (inline for "Regenerate now", via
 *   Action Scheduler on a schedule) into wp-content/uploads/openpixly/.
 * - Served at ?openpixel_feed=<secret token>, noindex, no-cache.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OpenPixel_Product_Feed {

	const OPTION_SETTINGS = 'openpixel_feed_settings';
	const OPTION_STATUS   = 'openpixel_feed_status';
	const QUERY_VAR       = 'openpixel_feed';
	const ACTION_BUILD    = 'openpixel_feed_build_batch';
	const ACTION_SCHEDULE = 'openpixel_feed_scheduled_build';
	const BATCH_SIZE      = 200;

	const OPENAI_COLUMNS = array(
		'item_id', 'group_id', 'listing_has_variations', 'variant_dict', 'offer_id',
		'title', 'description', 'url', 'brand', 'seller_name', 'seller_url',
		'image_url', 'additional_image_urls', 'availability', 'price', 'sale_price',
		'condition', 'product_category', 'gtin', 'mpn', 'color', 'size', 'material',
		'weight', 'item_weight_unit', 'dimensions', 'shipping_price', 'is_digital',
		'is_ads_eligible', 'is_eligible_search',
	);

	const GOOGLE_COLUMNS = array(
		'id', 'item_group_id', 'title', 'description', 'link', 'image_link',
		'additional_image_link', 'availability', 'price', 'sale_price', 'brand',
		'gtin', 'mpn', 'identifier_exists', 'condition', 'product_type', 'color',
		'size', 'material', 'shipping_weight', 'custom_label_0',
	);

	public function init() {
		add_action( 'init', array( $this, 'maybe_serve' ), 1 );
		add_action( self::ACTION_BUILD, array( $this, 'build_batch' ), 10, 2 );
		add_action( self::ACTION_SCHEDULE, array( $this, 'start_background_build' ) );
		add_action( 'init', array( $this, 'ensure_schedule' ), 20 );
	}

	/* ---------------------------------------------------------------------
	 * Settings
	 * ------------------------------------------------------------------ */

	public static function get_fields() {
		return array(
			'enabled'          => array(
				'label'       => __( 'Enable product feed', 'openpixly' ),
				'type'        => 'checkbox',
				'default'     => false,
				'description' => __( 'Generates a catalog file from your WooCommerce products and exposes it at a private URL you can give OpenAI.', 'openpixly' ),
			),
			'profile'          => array(
				'label'       => __( 'Schema', 'openpixly' ),
				'type'        => 'select',
				'default'     => 'openai',
				'options'     => array(
					'openai' => __( 'OpenAI format (item_id, url, image_url, is_ads_eligible, ...)', 'openpixly' ),
					'google' => __( 'Google-compatible (id, link, image_link, item_group_id, ...)', 'openpixly' ),
				),
				'description' => __( 'Use the schema OpenAI confirmed for your feed. OpenAI format is the default for new feeds.', 'openpixly' ),
			),
			'format'           => array(
				'label'   => __( 'File format', 'openpixly' ),
				'type'    => 'select',
				'default' => 'csv',
				'options' => array(
					'csv'   => 'CSV',
					'tsv'   => 'TSV',
					'jsonl' => 'JSONL (OpenAI format only)',
				),
			),
			'seller_name'      => array(
				'label'       => __( 'Seller name', 'openpixly' ),
				'type'        => 'text',
				'default'     => '',
				'description' => __( 'Required by the OpenAI format on every row. Defaults to the site title.', 'openpixly' ),
			),
			'brand_fallback'   => array(
				'label'       => __( 'Default brand', 'openpixly' ),
				'type'        => 'text',
				'default'     => '',
				'description' => __( 'Used when a product has no brand (WooCommerce Brands taxonomy). Brand is a required field; rows without one are skipped.', 'openpixly' ),
			),
			'include_out_of_stock' => array(
				'label'   => __( 'Include out-of-stock products', 'openpixly' ),
				'type'    => 'checkbox',
				'default' => true,
			),
			'ads_eligible'     => array(
				'label'       => __( 'Mark products as Ads-eligible', 'openpixly' ),
				'type'        => 'checkbox',
				'default'     => true,
				'description' => __( 'Sets is_ads_eligible = true on every row (OpenAI format). Filter per product with the openpixel_feed_row filter.', 'openpixly' ),
			),
			'schedule'         => array(
				'label'   => __( 'Rebuild schedule', 'openpixly' ),
				'type'    => 'select',
				'default' => 'daily',
				'options' => array(
					'hourly'     => __( 'Hourly', 'openpixly' ),
					'twicedaily' => __( 'Twice daily', 'openpixly' ),
					'daily'      => __( 'Daily', 'openpixly' ),
					'manual'     => __( 'Manual only', 'openpixly' ),
				),
			),
		);
	}

	public static function get_settings() {
		$defaults = array();
		foreach ( self::get_fields() as $key => $field ) {
			$defaults[ $key ] = $field['default'];
		}
		$saved = get_option( self::OPTION_SETTINGS, array() );
		$saved = is_array( $saved ) ? $saved : array();

		$settings = wp_parse_args( $saved, $defaults );
		if ( empty( $settings['token'] ) ) {
			$settings['token'] = self::rotate_token();
		}
		if ( '' === trim( (string) $settings['seller_name'] ) ) {
			$settings['seller_name'] = wp_strip_all_tags( get_bloginfo( 'name' ) );
		}
		if ( 'jsonl' === $settings['format'] && 'google' === $settings['profile'] ) {
			$settings['format'] = 'csv';
		}
		return $settings;
	}

	public static function sanitize_settings( $input ) {
		$input  = is_array( $input ) ? $input : array();
		$saved  = get_option( self::OPTION_SETTINGS, array() );
		$output = array( 'token' => isset( $saved['token'] ) ? $saved['token'] : self::generate_token() );

		foreach ( self::get_fields() as $key => $field ) {
			$value = isset( $input[ $key ] ) ? $input[ $key ] : null;
			switch ( $field['type'] ) {
				case 'checkbox':
					$output[ $key ] = ! empty( $value );
					break;
				case 'select':
					$output[ $key ] = ( null !== $value && array_key_exists( $value, $field['options'] ) ) ? $value : $field['default'];
					break;
				default:
					$output[ $key ] = null === $value ? '' : sanitize_text_field( $value );
			}
		}

		return $output;
	}

	private static function generate_token() {
		return wp_generate_password( 32, false, false );
	}

	public static function rotate_token() {
		$saved          = get_option( self::OPTION_SETTINGS, array() );
		$saved          = is_array( $saved ) ? $saved : array();
		$saved['token'] = self::generate_token();
		update_option( self::OPTION_SETTINGS, $saved, false );
		return $saved['token'];
	}

	public static function get_status() {
		$status = get_option( self::OPTION_STATUS, array() );
		return wp_parse_args(
			is_array( $status ) ? $status : array(),
			array(
				'state'      => 'idle', // idle | building | ready | error
				'started'    => 0,
				'finished'   => 0,
				'rows'       => 0,
				'skipped'    => 0,
				'file'       => '',
				'format'     => '',
				'profile'    => '',
				'message'    => '',
				'next_page'  => 1,
				'tmp_file'   => '',
			)
		);
	}

	private static function set_status( array $patch ) {
		update_option( self::OPTION_STATUS, array_merge( self::get_status(), $patch ), false );
	}

	/* ---------------------------------------------------------------------
	 * URLs & files
	 * ------------------------------------------------------------------ */

	public static function get_feed_url( $download = false ) {
		$settings = self::get_settings();
		$args     = array( self::QUERY_VAR => $settings['token'] );
		if ( $download ) {
			$args['download'] = 1;
		}
		return add_query_arg( $args, home_url( '/' ) );
	}

	private static function get_dir() {
		$uploads = wp_upload_dir();
		$dir     = trailingslashit( $uploads['basedir'] ) . 'openpixly';
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
			// Feed contains the catalog only, but keep the directory unlisted and
			// unreachable directly: the token URL is the only entry point.
			file_put_contents( $dir . '/index.html', '' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			file_put_contents( $dir . '/.htaccess', "Order deny,allow\nDeny from all\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}
		return $dir;
	}

	private static function file_path( $format, $suffix = '' ) {
		$settings = self::get_settings();
		return self::get_dir() . '/feed-' . substr( hash( 'sha256', $settings['token'] ), 0, 16 ) . $suffix . '.' . OpenPixel_Feed_Writer::extension( $format );
	}

	public static function delete_files() {
		$dir = self::get_dir();
		foreach ( glob( $dir . '/feed-*' ) as $file ) {
			wp_delete_file( $file );
		}
		delete_option( self::OPTION_STATUS );
	}

	/**
	 * Serve the feed when ?openpixel_feed=<token> matches.
	 */
	public function maybe_serve() {
		if ( empty( $_GET[ self::QUERY_VAR ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		$settings = self::get_settings();
		$token    = sanitize_text_field( wp_unslash( $_GET[ self::QUERY_VAR ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		nocache_headers();
		header( 'X-Robots-Tag: noindex, nofollow' );

		if ( empty( $settings['enabled'] ) || ! hash_equals( $settings['token'], $token ) ) {
			status_header( 404 );
			exit( 'Not found.' );
		}

		$status = self::get_status();
		if ( 'ready' !== $status['state'] || ! $status['file'] || ! file_exists( $status['file'] ) ) {
			status_header( 503 );
			header( 'Retry-After: 300' );
			exit( 'Feed is not generated yet. Rebuild it from Settings > Pixel Manager > Product feed.' );
		}

		$format   = $status['format'];
		$filename = 'products.' . OpenPixel_Feed_Writer::extension( $format );

		header( 'Content-Type: ' . OpenPixel_Feed_Writer::mime( $format ) . '; charset=utf-8' );
		header( 'Content-Length: ' . filesize( $status['file'] ) );
		header( 'Last-Modified: ' . gmdate( 'D, d M Y H:i:s', (int) $status['finished'] ) . ' GMT' );
		if ( ! empty( $_GET['download'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		}

		readfile( $status['file'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
		exit;
	}

	/* ---------------------------------------------------------------------
	 * Scheduling
	 * ------------------------------------------------------------------ */

	public function ensure_schedule() {
		if ( ! function_exists( 'as_has_scheduled_action' ) ) {
			return;
		}
		$settings = self::get_settings();
		$wanted   = ( ! empty( $settings['enabled'] ) && 'manual' !== $settings['schedule'] ) ? $settings['schedule'] : '';

		$intervals = array( 'hourly' => HOUR_IN_SECONDS, 'twicedaily' => 12 * HOUR_IN_SECONDS, 'daily' => DAY_IN_SECONDS );
		$current   = get_option( 'openpixel_feed_schedule_current', '' );

		if ( $current === $wanted ) {
			return;
		}

		as_unschedule_all_actions( self::ACTION_SCHEDULE, array(), 'openpixly' );
		if ( $wanted ) {
			as_schedule_recurring_action( time() + 60, $intervals[ $wanted ], self::ACTION_SCHEDULE, array(), 'openpixly' );
		}
		update_option( 'openpixel_feed_schedule_current', $wanted, false );
	}

	/**
	 * Kick off a background (Action Scheduler) build.
	 */
	public function start_background_build() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}
		$this->begin();
		if ( function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( self::ACTION_BUILD, array( 1, self::get_status()['tmp_file'] ), 'openpixly' );
		} else {
			$this->build_inline();
		}
	}

	/**
	 * Build the whole feed in this request (admin "Regenerate now").
	 *
	 * @return array status
	 */
	public function build_inline() {
		if ( function_exists( 'wc_set_time_limit' ) ) {
			wc_set_time_limit( 0 );
		}
		$this->begin();
		$page = 1;
		while ( true ) {
			$done = $this->build_batch( $page, self::get_status()['tmp_file'], false );
			if ( $done ) {
				break;
			}
			$page++;
		}
		return self::get_status();
	}

	private function begin() {
		$settings = self::get_settings();
		$tmp      = self::file_path( $settings['format'], '.building' );
		if ( file_exists( $tmp ) ) {
			wp_delete_file( $tmp );
		}
		self::set_status(
			array(
				'state'     => 'building',
				'started'   => time(),
				'finished'  => 0,
				'rows'      => 0,
				'skipped'   => 0,
				'message'   => '',
				'next_page' => 1,
				'tmp_file'  => $tmp,
				'format'    => $settings['format'],
				'profile'   => $settings['profile'],
			)
		);
	}

	/**
	 * Process one page of products. Returns true when the build finished.
	 *
	 * @param int    $page      1-based page.
	 * @param string $tmp_file  Temp file path (from status).
	 * @param bool   $chain     Schedule the next page via Action Scheduler.
	 * @return bool
	 */
	public function build_batch( $page, $tmp_file, $chain = true ) {
		$status   = self::get_status();
		$settings = self::get_settings();

		if ( 'building' !== $status['state'] || $tmp_file !== $status['tmp_file'] ) {
			return true; // stale job
		}

		try {
			$writer = OpenPixel_Feed_Writer::open_append( $tmp_file, $settings['format'], $this->columns( $settings['profile'] ) );

			$query = new WC_Product_Query(
				array(
					'status'   => 'publish',
					'limit'    => self::BATCH_SIZE,
					'page'     => (int) $page,
					'orderby'  => 'ID',
					'order'    => 'ASC',
					'type'     => array( 'simple', 'variable' ),
					'return'   => 'objects',
				)
			);
			$products = $query->get_products();

			$rows = 0;
			$skip = 0;
			foreach ( $products as $product ) {
				foreach ( $this->product_rows( $product, $settings ) as $row ) {
					if ( null === $row ) {
						$skip++;
						continue;
					}
					$writer->write( $row );
					$rows++;
				}
			}
			$writer->close();

			self::set_status(
				array(
					'rows'      => $status['rows'] + $rows,
					'skipped'   => $status['skipped'] + $skip,
					'next_page' => $page + 1,
				)
			);

			if ( count( $products ) < self::BATCH_SIZE ) {
				$this->finish( $tmp_file, $settings );
				return true;
			}

			if ( $chain && function_exists( 'as_enqueue_async_action' ) ) {
				as_enqueue_async_action( self::ACTION_BUILD, array( $page + 1, $tmp_file ), 'openpixly' );
			}
			return false;
		} catch ( Exception $e ) {
			self::set_status( array( 'state' => 'error', 'message' => $e->getMessage(), 'finished' => time() ) );
			return true;
		}
	}

	private function finish( $tmp_file, array $settings ) {
		$final = self::file_path( $settings['format'] );
		if ( ! file_exists( $tmp_file ) || 0 === filesize( $tmp_file ) ) {
			// Zero rows: still produce a well-formed, header-only file.
			$writer = new OpenPixel_Feed_Writer( $tmp_file, $settings['format'], $this->columns( $settings['profile'] ) );
			$writer->write_header();
			$writer->close();
		}
		// Remove old feeds in other formats.
		foreach ( glob( self::get_dir() . '/feed-*' ) as $file ) {
			if ( $file !== $tmp_file ) {
				wp_delete_file( $file );
			}
		}
		if ( ! self::move_file( $tmp_file, $final ) ) {
			self::set_status( array( 'state' => 'error', 'message' => 'Could not move the finished feed into place.', 'finished' => time() ) );
			return;
		}
		self::set_status( array( 'state' => 'ready', 'finished' => time(), 'file' => $final, 'tmp_file' => '', 'message' => '' ) );
		do_action( 'openpixel_feed_built', $final, self::get_status() );
	}

	/**
	 * Move a file using WP_Filesystem, falling back to copy + delete.
	 */
	private static function move_file( $from, $to ) {
		global $wp_filesystem;

		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		if ( WP_Filesystem() && $wp_filesystem && $wp_filesystem->move( $from, $to, true ) ) {
			return true;
		}

		if ( copy( $from, $to ) ) {
			wp_delete_file( $from );
			return true;
		}
		return false;
	}

	/* ---------------------------------------------------------------------
	 * Rows
	 * ------------------------------------------------------------------ */

	private function columns( $profile ) {
		$columns = 'google' === $profile ? self::GOOGLE_COLUMNS : self::OPENAI_COLUMNS;
		return apply_filters( 'openpixel_feed_columns', $columns, $profile );
	}

	/**
	 * @return array[] Rows (null entries = skipped).
	 */
	private function product_rows( WC_Product $product, array $settings ) {
		if ( 'visible' !== $product->get_catalog_visibility() && 'search' !== $product->get_catalog_visibility() ) {
			return array( null );
		}

		if ( $product->is_type( 'variable' ) ) {
			$rows = array();
			foreach ( $product->get_children() as $child_id ) {
				$variation = wc_get_product( $child_id );
				if ( ! $variation || 'publish' !== $variation->get_status() ) {
					continue;
				}
				$rows[] = $this->build_row( $variation, $product, $settings );
			}
			return $rows;
		}

		return array( $this->build_row( $product, null, $settings ) );
	}

	/**
	 * @param WC_Product      $product Simple product or variation.
	 * @param WC_Product|null $parent  Parent for variations.
	 */
	private function build_row( WC_Product $product, $parent, array $settings ) {
		$is_variation = null !== $parent;
		$source       = $is_variation ? $parent : $product; // for description, brand, categories, images fallback

		if ( ! $product->is_purchasable() && ! $is_variation ) {
			return null;
		}
		if ( ! $product->is_in_stock() && empty( $settings['include_out_of_stock'] ) ) {
			return null;
		}

		$currency = get_woocommerce_currency();
		$regular  = $this->money( $product, $product->get_regular_price( 'edit' ), $currency );
		$sale     = $product->is_on_sale( 'edit' ) ? $this->money( $product, $product->get_sale_price( 'edit' ), $currency ) : '';
		if ( '' === $regular ) {
			return null; // price is required
		}

		$brand = $this->brand( $source, $settings );
		if ( '' === $brand ) {
			return null; // brand is required
		}

		$description = $this->plain( $source->get_description() ?: $source->get_short_description() ?: $source->get_name(), 5000 );
		$title       = $this->plain( $product->get_name(), 150 );
		$image       = $this->image_url( $product ) ?: $this->image_url( $source );
		$images      = $this->gallery_urls( $source );
		$url         = $product->get_permalink();
		$item_id     = (string) $product->get_id();
		$group_id    = $is_variation ? (string) $parent->get_id() : '';
		$variant     = $is_variation ? $this->variant_dict( $product ) : array();
		$gtin        = method_exists( $product, 'get_global_unique_id' ) ? preg_replace( '/\D/', '', (string) $product->get_global_unique_id() ) : '';
		if ( ! in_array( strlen( $gtin ), array( 8, 12, 13, 14 ), true ) ) {
			$gtin = '';
		}
		$mpn         = (string) $product->get_sku();
		$weight      = $product->get_weight() ? (string) wc_format_decimal( $product->get_weight(), 3 ) : '';
		$weight_unit = $weight ? $this->weight_unit() : '';
		$dimensions  = $this->dimensions( $product );
		$digital     = $product->is_virtual() || $product->is_downloadable();
		$category    = $this->category_path( $source );

		if ( 'google' === $settings['profile'] ) {
			$row = array(
				'id'                    => $item_id,
				'item_group_id'         => $group_id,
				'title'                 => $title,
				'description'           => $description,
				'link'                  => $url,
				'image_link'            => $image,
				'additional_image_link' => $images,
				'availability'          => $this->availability( $product, true ),
				'price'                 => $regular,
				'sale_price'            => $sale,
				'brand'                 => $brand,
				'gtin'                  => $gtin,
				'mpn'                   => $mpn,
				'identifier_exists'     => ( $gtin || $mpn ) ? 'yes' : 'no',
				'condition'             => 'new',
				'product_type'          => $category,
				'color'                 => $this->pick_variant( $variant, array( 'color', 'colour' ) ),
				'size'                  => $this->pick_variant( $variant, array( 'size' ) ),
				'material'              => $this->pick_variant( $variant, array( 'material' ) ),
				'shipping_weight'       => $weight ? $weight . ' ' . $weight_unit : '',
				'custom_label_0'        => '',
			);
		} else {
			$row = array(
				'item_id'                => $item_id,
				'group_id'               => $group_id,
				'listing_has_variations' => $is_variation ? true : null,
				'variant_dict'           => $variant ?: null,
				'offer_id'               => '',
				'title'                  => $title,
				'description'            => $description,
				'url'                    => $url,
				'brand'                  => $brand,
				'seller_name'            => $settings['seller_name'],
				'seller_url'             => home_url( '/' ),
				'image_url'              => $image,
				'additional_image_urls'  => $images,
				'availability'           => $this->availability( $product, false ),
				'price'                  => $regular,
				'sale_price'             => $sale,
				'condition'              => 'new',
				'product_category'       => $category,
				'gtin'                   => $gtin,
				'mpn'                    => $mpn,
				'color'                  => $this->pick_variant( $variant, array( 'color', 'colour' ) ),
				'size'                   => $this->pick_variant( $variant, array( 'size' ) ),
				'material'               => $this->pick_variant( $variant, array( 'material' ) ),
				'weight'                 => $weight,
				'item_weight_unit'       => $weight_unit,
				'dimensions'             => $dimensions ?: null,
				'shipping_price'         => '',
				'is_digital'             => $digital ? true : null,
				'is_ads_eligible'        => ! empty( $settings['ads_eligible'] ),
				'is_eligible_search'     => true,
			);
		}

		if ( '' === $image ) {
			return null; // image_url is required
		}

		/**
		 * Adjust or drop (return null) a feed row.
		 *
		 * @param array|null $row
		 * @param WC_Product $product
		 * @param WC_Product|null $parent
		 * @param string $profile
		 */
		return apply_filters( 'openpixel_feed_row', $row, $product, $parent, $settings['profile'] );
	}

	private function money( WC_Product $product, $price, $currency ) {
		if ( '' === $price || null === $price ) {
			return '';
		}
		$display = wc_get_price_to_display( $product, array( 'price' => $price ) );
		if ( $display <= 0 ) {
			return '';
		}
		return number_format( (float) $display, OpenPixel_Money::exponent( $currency ), '.', '' ) . ' ' . $currency;
	}

	private function availability( WC_Product $product, $google ) {
		$stock = $product->get_stock_status();
		if ( 'onbackorder' === $stock ) {
			return 'backorder';
		}
		if ( 'instock' === $stock ) {
			return 'in_stock';
		}
		return 'out_of_stock';
	}

	private function brand( WC_Product $product, array $settings ) {
		foreach ( array( 'product_brand', 'pa_brand', 'brand', 'pwb-brand', 'yith_product_brand' ) as $taxonomy ) {
			if ( ! taxonomy_exists( $taxonomy ) ) {
				continue;
			}
			$terms = get_the_terms( $product->get_id(), $taxonomy );
			if ( $terms && ! is_wp_error( $terms ) ) {
				return wp_strip_all_tags( $terms[0]->name );
			}
		}
		return trim( (string) $settings['brand_fallback'] );
	}

	private function plain( $html, $max ) {
		$text = wp_strip_all_tags( strip_shortcodes( (string) $html ), true );
		$text = html_entity_decode( $text, ENT_QUOTES, 'UTF-8' );
		$text = preg_replace( '/\s+/u', ' ', $text );
		$text = trim( $text );
		return mb_strlen( $text ) > $max ? rtrim( mb_substr( $text, 0, $max - 1 ) ) . '…' : $text;
	}

	private function image_url( WC_Product $product ) {
		$id = $product->get_image_id();
		if ( ! $id ) {
			return '';
		}
		$url = wp_get_attachment_image_url( $id, 'full' );
		return $url ? $url : '';
	}

	private function gallery_urls( WC_Product $product ) {
		$urls = array();
		foreach ( $product->get_gallery_image_ids() as $id ) {
			$url = wp_get_attachment_image_url( $id, 'full' );
			if ( $url ) {
				$urls[] = $url;
			}
		}
		return array_slice( $urls, 0, 10 );
	}

	private function variant_dict( WC_Product $variation ) {
		$dict = array();
		foreach ( $variation->get_attributes() as $key => $value ) {
			if ( '' === $value ) {
				continue;
			}
			$name  = wc_attribute_label( str_replace( 'attribute_', '', $key ), $variation );
			$label = $value;
			if ( taxonomy_exists( $key ) ) {
				$term = get_term_by( 'slug', $value, $key );
				if ( $term ) {
					$label = $term->name;
				}
			}
			$dict[ strtolower( trim( $name ) ) ] = (string) $label;
		}
		return $dict;
	}

	private function pick_variant( array $variant, array $keys ) {
		foreach ( $keys as $key ) {
			if ( isset( $variant[ $key ] ) ) {
				return $variant[ $key ];
			}
		}
		return '';
	}

	private function category_path( WC_Product $product ) {
		$terms = get_the_terms( $product->get_id(), 'product_cat' );
		if ( ! $terms || is_wp_error( $terms ) ) {
			return '';
		}
		// Deepest category, expanded to its ancestor path.
		usort( $terms, function ( $a, $b ) { return count( get_ancestors( $b->term_id, 'product_cat' ) ) - count( get_ancestors( $a->term_id, 'product_cat' ) ); } );
		$term  = $terms[0];
		$names = array( $term->name );
		foreach ( get_ancestors( $term->term_id, 'product_cat' ) as $ancestor_id ) {
			$ancestor = get_term( $ancestor_id, 'product_cat' );
			if ( $ancestor && ! is_wp_error( $ancestor ) ) {
				array_unshift( $names, $ancestor->name );
			}
		}
		return implode( ' > ', array_map( 'wp_strip_all_tags', $names ) );
	}

	private function weight_unit() {
		$unit = get_option( 'woocommerce_weight_unit', 'kg' );
		$map  = array( 'kg' => 'kg', 'g' => 'g', 'lbs' => 'lb', 'oz' => 'oz' );
		return isset( $map[ $unit ] ) ? $map[ $unit ] : 'kg';
	}

	private function dimensions( WC_Product $product ) {
		$unit = get_option( 'woocommerce_dimension_unit', 'cm' );
		if ( ! in_array( $unit, array( 'in', 'cm', 'ft', 'm', 'mm' ), true ) ) {
			return array();
		}
		$dims = array();
		foreach ( array( 'length', 'width', 'height' ) as $axis ) {
			$value = $product->{"get_$axis"}();
			if ( '' !== $value && (float) $value > 0 ) {
				$dims[ $axis ] = (string) wc_format_decimal( $value, 2 );
			}
		}
		if ( count( $dims ) < 2 ) {
			return array();
		}
		$dims['unit'] = $unit;
		return $dims;
	}
}
