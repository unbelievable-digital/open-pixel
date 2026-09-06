<?php
/**
 * Settings > Pixel Manager.
 *
 * Tab "Pixels": every registered provider's declarative fields, so a new
 * provider gets a settings section without touching this file.
 * Tab "Product feed": WooCommerce catalog feed for OpenAI Ads.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OpenPixel_Admin {

	const PAGE_SLUG = 'openpixel-pixel-manager';

	/** @var OpenPixel_Core */
	private $core;

	public function __construct( OpenPixel_Core $core ) {
		$this->core = $core;
	}

	public function init() {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_post_openpixel_capi_test', array( $this, 'handle_capi_test' ) );
		add_action( 'admin_post_openpixel_feed_rebuild', array( $this, 'handle_feed_rebuild' ) );
		add_action( 'admin_post_openpixel_feed_rotate', array( $this, 'handle_feed_rotate' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( OPENPIXEL_PLUGIN_FILE ), array( $this, 'plugin_action_links' ) );
	}

	public function add_menu() {
		add_options_page(
			__( 'Pixel Manager', 'open-pixel' ),
			__( 'Pixel Manager', 'open-pixel' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	public function plugin_action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( $this->page_url() ) . '">' . esc_html__( 'Settings', 'open-pixel' ) . '</a>' );
		return $links;
	}

	private function page_url( $tab = '' ) {
		$url = admin_url( 'options-general.php?page=' . self::PAGE_SLUG );
		return $tab ? add_query_arg( 'tab', $tab, $url ) : $url;
	}

	public function enqueue_assets( $hook ) {
		if ( 'settings_page_' . self::PAGE_SLUG !== $hook ) {
			return;
		}
		wp_enqueue_style( 'openpixel-admin', OPENPIXEL_PLUGIN_URL . 'assets/css/admin.css', array(), OPENPIXEL_VERSION );
	}

	public function register_settings() {
		register_setting(
			'openpixel_settings_group',
			OPENPIXEL_OPTION_KEY,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_settings' ),
				'default'           => array(),
			)
		);

		register_setting(
			'openpixel_feed_group',
			OpenPixel_Product_Feed::OPTION_SETTINGS,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( 'OpenPixel_Product_Feed', 'sanitize_settings' ),
				'default'           => array(),
			)
		);
	}

	public function sanitize_settings( $input ) {
		$input  = is_array( $input ) ? $input : array();
		$output = array();

		foreach ( $this->core->get_providers() as $id => $provider ) {
			$provider_input = isset( $input[ $id ] ) ? $input[ $id ] : array();
			$output[ $id ]  = $provider->sanitize( $provider_input );
		}

		return $output;
	}

	private function notice( $type, $text ) {
		set_transient( 'openpixel_admin_notice', array( 'type' => $type, 'text' => $text ), 60 );
	}

	/* ---------------------------------------------------------------------
	 * Actions
	 * ------------------------------------------------------------------ */

	public function handle_capi_test() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'open-pixel' ) );
		}
		check_admin_referer( 'openpixel_capi_test' );

		$provider = $this->core->get_provider( 'openai' );

		if ( $provider instanceof OpenPixel_Provider_OpenAI ) {
			$event = $provider->to_capi_event(
				array(
					'name'         => 'purchase',
					'event_id'     => 'openpixel_test_' . time(),
					'value'        => 1.00,
					'currency'     => 'USD',
					'items'        => array(
						array( 'id' => 'test', 'name' => 'Test product', 'quantity' => 1, 'price' => 1.00, 'group_id' => '', 'variant' => array() ),
					),
					'content_type' => 'product',
					'plan_id'      => '',
					'custom_name'  => '',
					'user'         => array(),
					'channel'      => 'server',
					'context'      => array( 'source_url' => home_url( '/' ), 'action_source' => 'web' ),
				)
			);

			$result = $provider->capi()->send( array( $event ), true );

			if ( is_wp_error( $result ) ) {
				$this->notice( 'error', $result->get_error_message() );
			} else {
				$this->notice( 'success', __( 'Conversions API accepted the test event (validate_only). Credentials and payload are valid.', 'open-pixel' ) );
			}
		}

		wp_safe_redirect( $this->page_url() );
		exit;
	}

	public function handle_feed_rebuild() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'open-pixel' ) );
		}
		check_admin_referer( 'openpixel_feed_rebuild' );

		if ( ! class_exists( 'WooCommerce' ) ) {
			$this->notice( 'error', __( 'WooCommerce is not active.', 'open-pixel' ) );
		} else {
			$status = $this->core->get_feed()->build_inline();
			if ( 'ready' === $status['state'] ) {
				$this->notice(
					'success',
					sprintf(
						/* translators: 1: row count, 2: skipped count */
						__( 'Feed rebuilt: %1$d rows written, %2$d products skipped (missing brand, price or image).', 'open-pixel' ),
						$status['rows'],
						$status['skipped']
					)
				);
			} else {
				$this->notice( 'error', $status['message'] ? $status['message'] : __( 'Feed build failed.', 'open-pixel' ) );
			}
		}

		wp_safe_redirect( $this->page_url( 'feed' ) );
		exit;
	}

	public function handle_feed_rotate() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'open-pixel' ) );
		}
		check_admin_referer( 'openpixel_feed_rotate' );

		OpenPixel_Product_Feed::delete_files();
		OpenPixel_Product_Feed::rotate_token();
		$this->notice( 'success', __( 'Feed URL rotated. The old URL no longer works; rebuild the feed and give OpenAI the new URL.', 'open-pixel' ) );

		wp_safe_redirect( $this->page_url( 'feed' ) );
		exit;
	}

	/* ---------------------------------------------------------------------
	 * Page
	 * ------------------------------------------------------------------ */

	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'pixels'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! in_array( $tab, array( 'pixels', 'feed' ), true ) ) {
			$tab = 'pixels';
		}

		$notice = get_transient( 'openpixel_admin_notice' );
		if ( $notice ) {
			delete_transient( 'openpixel_admin_notice' );
			printf(
				'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
				esc_attr( $notice['type'] ),
				esc_html( $notice['text'] )
			);
		}
		?>
		<div class="wrap openpixel-wrap">
			<h1><?php esc_html_e( 'Pixel Manager', 'open-pixel' ); ?></h1>

			<nav class="nav-tab-wrapper">
				<a href="<?php echo esc_url( $this->page_url() ); ?>" class="nav-tab <?php echo 'pixels' === $tab ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'Pixels', 'open-pixel' ); ?></a>
				<a href="<?php echo esc_url( $this->page_url( 'feed' ) ); ?>" class="nav-tab <?php echo 'feed' === $tab ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'Product feed', 'open-pixel' ); ?></a>
			</nav>

			<?php if ( 'feed' === $tab ) : ?>
				<?php $this->render_feed_tab(); ?>
			<?php else : ?>
				<form method="post" action="options.php">
					<?php settings_fields( 'openpixel_settings_group' ); ?>
					<?php foreach ( $this->core->get_providers() as $provider ) : ?>
						<?php $this->render_provider( $provider ); ?>
					<?php endforeach; ?>
					<?php submit_button(); ?>
				</form>
				<?php $this->render_capi_test(); ?>
				<?php $this->render_status(); ?>
			<?php endif; ?>
		</div>
		<?php
	}

	private function render_provider( OpenPixel_Provider $provider ) {
		$id       = $provider->get_id();
		$settings = $provider->get_settings();
		?>
		<h2 class="title"><?php echo esc_html( $provider->get_label() ); ?></h2>
		<?php if ( $provider->get_description() ) : ?>
			<p class="description"><?php echo esc_html( $provider->get_description() ); ?></p>
		<?php endif; ?>
		<?php $this->render_fields( OPENPIXEL_OPTION_KEY . '[' . $id . ']', 'openpixel-' . $id, $provider->get_fields(), $settings ); ?>
		<?php
	}

	/**
	 * Render declarative fields grouped by optional "section".
	 *
	 * @param string $name_prefix e.g. "openpixel_settings[openai]"
	 * @param string $id_prefix   e.g. "openpixel-openai"
	 * @param array  $fields      key => field definition
	 * @param array  $values      key => current value
	 */
	private function render_fields( $name_prefix, $id_prefix, array $fields, array $values ) {
		$current_section = null;
		$open            = false;

		foreach ( $fields as $key => $field ) {
			if ( ! empty( $field['section'] ) && $field['section'] !== $current_section ) {
				if ( $open ) {
					echo '</table>';
				}
				$current_section = $field['section'];
				echo '<h3>' . esc_html( $current_section ) . '</h3>';
				$open = false;
			}

			if ( ! $open ) {
				echo '<table class="form-table" role="presentation">';
				$open = true;
			}

			$this->render_field( $name_prefix . '[' . $key . ']', $id_prefix . '-' . $key, $field, isset( $values[ $key ] ) ? $values[ $key ] : '' );
		}

		if ( $open ) {
			echo '</table>';
		}
	}

	private function render_field( $name, $dom_id, array $field, $value ) {
		$type  = isset( $field['type'] ) ? $field['type'] : 'text';
		$label = isset( $field['label'] ) ? $field['label'] : $name;
		$desc  = isset( $field['description'] ) ? $field['description'] : '';
		?>
		<tr>
			<th scope="row">
				<?php if ( 'checkbox' === $type ) : ?>
					<?php echo esc_html( $label ); ?>
				<?php else : ?>
					<label for="<?php echo esc_attr( $dom_id ); ?>"><?php echo esc_html( $label ); ?></label>
				<?php endif; ?>
			</th>
			<td>
				<?php
				switch ( $type ) {
					case 'checkbox':
						printf(
							'<label><input type="checkbox" id="%1$s" name="%2$s" value="1" %3$s /> %4$s</label>',
							esc_attr( $dom_id ),
							esc_attr( $name ),
							checked( ! empty( $value ), true, false ),
							esc_html__( 'Yes', 'open-pixel' )
						);
						break;

					case 'select':
						echo '<select id="' . esc_attr( $dom_id ) . '" name="' . esc_attr( $name ) . '">';
						foreach ( (array) $field['options'] as $opt_value => $opt_label ) {
							printf(
								'<option value="%1$s" %2$s>%3$s</option>',
								esc_attr( $opt_value ),
								selected( $value, $opt_value, false ),
								esc_html( $opt_label )
							);
						}
						echo '</select>';
						break;

					case 'textarea':
						printf(
							'<textarea id="%1$s" name="%2$s" class="large-text code" rows="6">%3$s</textarea>',
							esc_attr( $dom_id ),
							esc_attr( $name ),
							esc_textarea( $value )
						);
						break;

					case 'password':
						printf(
							'<input type="password" id="%1$s" name="%2$s" value="" class="regular-text" autocomplete="new-password" placeholder="%3$s" />',
							esc_attr( $dom_id ),
							esc_attr( $name ),
							esc_attr( $value ? str_repeat( '•', 12 ) : '' )
						);
						break;

					default:
						printf(
							'<input type="text" id="%1$s" name="%2$s" value="%3$s" class="regular-text" />',
							esc_attr( $dom_id ),
							esc_attr( $name ),
							esc_attr( $value )
						);
				}
				?>
				<?php if ( $desc ) : ?>
					<p class="description"><?php echo esc_html( $desc ); ?></p>
				<?php endif; ?>
			</td>
		</tr>
		<?php
	}

	private function render_capi_test() {
		$provider = $this->core->get_provider( 'openai' );
		if ( ! $provider instanceof OpenPixel_Provider_OpenAI || ! $provider->get_setting( 'capi_enabled' ) ) {
			return;
		}
		?>
		<h2 class="title"><?php esc_html_e( 'Test the Conversions API', 'open-pixel' ); ?></h2>
		<p><?php esc_html_e( 'Sends one order_created event with validate_only = true. Nothing is recorded; OpenAI only checks the key and payload.', 'open-pixel' ); ?></p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="openpixel_capi_test" />
			<?php wp_nonce_field( 'openpixel_capi_test' ); ?>
			<?php submit_button( __( 'Send test event', 'open-pixel' ), 'secondary', 'submit', false ); ?>
		</form>
		<?php
	}

	private function render_status() {
		$wc_active = class_exists( 'WooCommerce' );
		$as_active = function_exists( 'as_schedule_single_action' );
		?>
		<h2 class="title"><?php esc_html_e( 'Status', 'open-pixel' ); ?></h2>
		<ul class="openpixel-status">
			<li><?php echo $wc_active ? '✅' : '➖'; ?> <?php esc_html_e( 'WooCommerce', 'open-pixel' ); ?>: <?php echo $wc_active ? esc_html__( 'active — product, cart, checkout and order events are tracked.', 'open-pixel' ) : esc_html__( 'not active — only page_viewed and registration events are tracked.', 'open-pixel' ); ?></li>
			<li><?php echo $as_active ? '✅' : '➖'; ?> <?php esc_html_e( 'Action Scheduler', 'open-pixel' ); ?>: <?php echo $as_active ? esc_html__( 'available — server-side events are queued with retries.', 'open-pixel' ) : esc_html__( 'not available — WP-Cron is used instead.', 'open-pixel' ); ?></li>
			<li>ℹ️ <?php esc_html_e( 'If your site enforces a Content Security Policy, allow script-src https://bzrcdn.openai.com, connect-src https://bzr.openai.com https://bzrcdn.openai.com and img-src https://bzr.openai.com.', 'open-pixel' ); ?></li>
		</ul>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * Product feed tab
	 * ------------------------------------------------------------------ */

	private function render_feed_tab() {
		$settings = OpenPixel_Product_Feed::get_settings();
		$status   = OpenPixel_Product_Feed::get_status();
		$wc       = class_exists( 'WooCommerce' );
		?>
		<p><?php esc_html_e( 'Builds a product catalog file from WooCommerce in the OpenAI product feed format so ChatGPT Ads can run product-feed campaigns. Give OpenAI the private URL below, or download the file and upload it to the SFTP location shown in Ads Manager > Feeds.', 'open-pixel' ); ?></p>

		<?php if ( ! $wc ) : ?>
			<div class="notice notice-warning inline"><p><?php esc_html_e( 'WooCommerce is not active; the product feed needs WooCommerce products.', 'open-pixel' ); ?></p></div>
		<?php endif; ?>

		<form method="post" action="options.php">
			<?php settings_fields( 'openpixel_feed_group' ); ?>
			<?php $this->render_fields( OpenPixel_Product_Feed::OPTION_SETTINGS, 'openpixel-feed', OpenPixel_Product_Feed::get_fields(), $settings ); ?>
			<?php submit_button(); ?>
		</form>

		<h2 class="title"><?php esc_html_e( 'Feed', 'open-pixel' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Private feed URL', 'open-pixel' ); ?></th>
				<td>
					<input type="text" class="large-text code" readonly value="<?php echo esc_attr( OpenPixel_Product_Feed::get_feed_url() ); ?>" onclick="this.select();" />
					<p class="description"><?php esc_html_e( 'Anyone with this URL can read your catalog. Rotate it if it leaks.', 'open-pixel' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Status', 'open-pixel' ); ?></th>
				<td>
					<?php
					switch ( $status['state'] ) {
						case 'ready':
							/* translators: 1: rows, 2: skipped, 3: date, 4: format, 5: profile */
							$ready_text = esc_html__( 'Ready — %1$d rows, %2$d skipped, built %3$s (%4$s, %5$s schema).', 'open-pixel' );
							printf(
								$ready_text, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
								(int) $status['rows'],
								(int) $status['skipped'],
								esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $status['finished'] ) ),
								esc_html( strtoupper( $status['format'] ) ),
								esc_html( $status['profile'] )
							);
							break;
						case 'building':
							/* translators: %d: number of rows written so far */
							$building_text = esc_html__( 'Building in the background — %d rows so far.', 'open-pixel' );
							printf( $building_text, (int) $status['rows'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
							break;
						case 'error':
							echo esc_html__( 'Error: ', 'open-pixel' ) . esc_html( $status['message'] );
							break;
						default:
							esc_html_e( 'Not built yet.', 'open-pixel' );
					}
					?>
				</td>
			</tr>
		</table>

		<p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;margin-right:8px">
				<input type="hidden" name="action" value="openpixel_feed_rebuild" />
				<?php wp_nonce_field( 'openpixel_feed_rebuild' ); ?>
				<?php submit_button( __( 'Rebuild now', 'open-pixel' ), 'primary', 'submit', false, $wc ? array() : array( 'disabled' => 'disabled' ) ); ?>
			</form>
			<?php if ( 'ready' === $status['state'] ) : ?>
				<a class="button" href="<?php echo esc_url( OpenPixel_Product_Feed::get_feed_url( true ) ); ?>"><?php esc_html_e( 'Download', 'open-pixel' ); ?></a>
			<?php endif; ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;margin-left:8px" onsubmit="return confirm('<?php echo esc_js( __( 'Rotate the feed URL? The current URL stops working immediately.', 'open-pixel' ) ); ?>');">
				<input type="hidden" name="action" value="openpixel_feed_rotate" />
				<?php wp_nonce_field( 'openpixel_feed_rotate' ); ?>
				<?php submit_button( __( 'Rotate URL', 'open-pixel' ), 'delete', 'submit', false ); ?>
			</form>
		</p>

		<h3><?php esc_html_e( 'What goes in the feed', 'open-pixel' ); ?></h3>
		<ul class="openpixel-status">
			<li><?php esc_html_e( 'Published simple products and every published variation of variable products (variations share group_id and carry variant_dict).', 'open-pixel' ); ?></li>
			<li><?php esc_html_e( 'Required fields: item_id, title, description, url, brand, seller_name, image_url, availability, price. Products missing brand, price or image are skipped and counted.', 'open-pixel' ); ?></li>
			<li><?php esc_html_e( 'Prices use your tax display settings, formatted as "79.99 USD". Sale prices are included when active.', 'open-pixel' ); ?></li>
			<li><?php esc_html_e( 'Product IDs match the ids sent in pixel events, so product-set filters and product insights line up.', 'open-pixel' ); ?></li>
		</ul>
		<?php
	}
}
