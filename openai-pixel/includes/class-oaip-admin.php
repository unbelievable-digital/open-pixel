<?php
/**
 * Settings > Pixel Manager.
 *
 * Renders every registered provider's declarative fields, so a new
 * provider gets a settings section without touching this file.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OAIP_Admin {

	const PAGE_SLUG = 'oaip-pixel-manager';

	/** @var OAIP_Core */
	private $core;

	public function __construct( OAIP_Core $core ) {
		$this->core = $core;
	}

	public function init() {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_post_oaip_capi_test', array( $this, 'handle_capi_test' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( OAIP_PLUGIN_FILE ), array( $this, 'plugin_action_links' ) );
	}

	public function add_menu() {
		add_options_page(
			__( 'Pixel Manager', 'openai-pixel' ),
			__( 'Pixel Manager', 'openai-pixel' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	public function plugin_action_links( $links ) {
		$url = admin_url( 'options-general.php?page=' . self::PAGE_SLUG );
		array_unshift( $links, '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'openai-pixel' ) . '</a>' );
		return $links;
	}

	public function enqueue_assets( $hook ) {
		if ( 'settings_page_' . self::PAGE_SLUG !== $hook ) {
			return;
		}
		wp_enqueue_style( 'oaip-admin', OAIP_PLUGIN_URL . 'assets/css/admin.css', array(), OAIP_VERSION );
	}

	public function register_settings() {
		register_setting(
			'oaip_settings_group',
			OAIP_OPTION_KEY,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_settings' ),
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

	/* ---------------------------------------------------------------------
	 * Conversions API test (validate_only = true)
	 * ------------------------------------------------------------------ */

	public function handle_capi_test() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'openai-pixel' ) );
		}
		check_admin_referer( 'oaip_capi_test' );

		$provider = $this->core->get_provider( 'openai' );
		$redirect = admin_url( 'options-general.php?page=' . self::PAGE_SLUG );

		if ( ! $provider instanceof OAIP_Provider_OpenAI ) {
			wp_safe_redirect( $redirect );
			exit;
		}

		$event = $provider->to_capi_event(
			array(
				'name'         => 'purchase',
				'event_id'     => 'oaip_test_' . time(),
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
			set_transient( 'oaip_admin_notice', array( 'type' => 'error', 'text' => $result->get_error_message() ), 60 );
		} else {
			set_transient(
				'oaip_admin_notice',
				array(
					'type' => 'success',
					'text' => __( 'Conversions API accepted the test event (validate_only). Credentials and payload are valid.', 'openai-pixel' ),
				),
				60
			);
		}

		wp_safe_redirect( $redirect );
		exit;
	}

	/* ---------------------------------------------------------------------
	 * Page
	 * ------------------------------------------------------------------ */

	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$notice = get_transient( 'oaip_admin_notice' );
		if ( $notice ) {
			delete_transient( 'oaip_admin_notice' );
			printf(
				'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
				esc_attr( $notice['type'] ),
				esc_html( $notice['text'] )
			);
		}
		?>
		<div class="wrap oaip-wrap">
			<h1><?php esc_html_e( 'Pixel Manager', 'openai-pixel' ); ?></h1>

			<form method="post" action="options.php">
				<?php settings_fields( 'oaip_settings_group' ); ?>

				<?php foreach ( $this->core->get_providers() as $provider ) : ?>
					<?php $this->render_provider( $provider ); ?>
				<?php endforeach; ?>

				<?php submit_button(); ?>
			</form>

			<?php $this->render_capi_test(); ?>
			<?php $this->render_status(); ?>
		</div>
		<?php
	}

	private function render_provider( OAIP_Provider $provider ) {
		$id       = $provider->get_id();
		$settings = $provider->get_settings();
		?>
		<h2 class="title"><?php echo esc_html( $provider->get_label() ); ?></h2>
		<?php if ( $provider->get_description() ) : ?>
			<p class="description"><?php echo esc_html( $provider->get_description() ); ?></p>
		<?php endif; ?>

		<?php
		$current_section = null;
		$open            = false;

		foreach ( $provider->get_fields() as $key => $field ) {
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

			$this->render_field( $id, $key, $field, isset( $settings[ $key ] ) ? $settings[ $key ] : '' );
		}

		if ( $open ) {
			echo '</table>';
		}
	}

	private function render_field( $provider_id, $key, array $field, $value ) {
		$name    = OAIP_OPTION_KEY . '[' . $provider_id . '][' . $key . ']';
		$dom_id  = 'oaip-' . $provider_id . '-' . $key;
		$type    = isset( $field['type'] ) ? $field['type'] : 'text';
		$label   = isset( $field['label'] ) ? $field['label'] : $key;
		$desc    = isset( $field['description'] ) ? $field['description'] : '';
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
							esc_html__( 'Yes', 'openai-pixel' )
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
		if ( ! $provider instanceof OAIP_Provider_OpenAI || ! $provider->get_setting( 'capi_enabled' ) ) {
			return;
		}
		?>
		<h2 class="title"><?php esc_html_e( 'Test the Conversions API', 'openai-pixel' ); ?></h2>
		<p><?php esc_html_e( 'Sends one order_created event with validate_only = true. Nothing is recorded; OpenAI only checks the key and payload.', 'openai-pixel' ); ?></p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="oaip_capi_test" />
			<?php wp_nonce_field( 'oaip_capi_test' ); ?>
			<?php submit_button( __( 'Send test event', 'openai-pixel' ), 'secondary', 'submit', false ); ?>
		</form>
		<?php
	}

	private function render_status() {
		$wc_active = class_exists( 'WooCommerce' );
		$as_active = function_exists( 'as_schedule_single_action' );
		?>
		<h2 class="title"><?php esc_html_e( 'Status', 'openai-pixel' ); ?></h2>
		<ul class="oaip-status">
			<li><?php echo $wc_active ? '✅' : '➖'; ?> <?php esc_html_e( 'WooCommerce', 'openai-pixel' ); ?>: <?php echo $wc_active ? esc_html__( 'active — product, cart, checkout and order events are tracked.', 'openai-pixel' ) : esc_html__( 'not active — only page_viewed and registration events are tracked.', 'openai-pixel' ); ?></li>
			<li><?php echo $as_active ? '✅' : '➖'; ?> <?php esc_html_e( 'Action Scheduler', 'openai-pixel' ); ?>: <?php echo $as_active ? esc_html__( 'available — server-side events are queued with retries.', 'openai-pixel' ) : esc_html__( 'not available — WP-Cron is used instead.', 'openai-pixel' ); ?></li>
			<li>ℹ️ <?php esc_html_e( 'If your site enforces a Content Security Policy, allow script-src https://bzrcdn.openai.com, connect-src https://bzr.openai.com https://bzrcdn.openai.com and img-src https://bzr.openai.com.', 'openai-pixel' ); ?></li>
		</ul>
		<?php
	}
}
