<?php
/**
 * Settings > Pixel Manager admin screen.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OAIP_Admin {

	/** @var OAIP_Core */
	private $core;

	public function __construct( OAIP_Core $core ) {
		$this->core = $core;
	}

	public function init() {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	public function add_menu() {
		add_options_page(
			__( 'Pixel Manager', 'openai-pixel' ),
			__( 'Pixel Manager', 'openai-pixel' ),
			'manage_options',
			'oaip-pixel-manager',
			array( $this, 'render_page' )
		);
	}

	public function enqueue_assets( $hook ) {
		if ( 'settings_page_oaip-pixel-manager' !== $hook ) {
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

	/**
	 * Sanitize the whole options array by delegating each provider's
	 * slice to that provider's own sanitize() method.
	 *
	 * @param array $input Raw $_POST value for OAIP_OPTION_KEY.
	 * @return array
	 */
	public function sanitize_settings( $input ) {
		$input    = is_array( $input ) ? $input : array();
		$output   = array();
		$providers = $this->core->get_providers();

		foreach ( $providers as $provider ) {
			$provider_input       = isset( $input[ $provider->get_id() ] ) ? $input[ $provider->get_id() ] : array();
			$output[ $provider->get_id() ] = $provider->sanitize( $provider_input );
		}

		return $output;
	}

	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$all_settings = get_option( OAIP_OPTION_KEY, array() );
		$providers    = $this->core->get_providers();
		?>
		<div class="wrap oaip-wrap">
			<h1><?php esc_html_e( 'Pixel Manager', 'openai-pixel' ); ?></h1>
			<p><?php esc_html_e( 'Add tracking pixels to your site. OpenAI is the first provider; more (Meta, Google, ...) can be registered via the oaip_pixel_providers filter.', 'openai-pixel' ); ?></p>

			<form method="post" action="options.php">
				<?php settings_fields( 'oaip_settings_group' ); ?>

				<?php foreach ( $providers as $provider ) :
					$id       = $provider->get_id();
					$defaults = $provider->get_defaults();
					$settings = wp_parse_args(
						isset( $all_settings[ $id ] ) ? $all_settings[ $id ] : array(),
						$defaults
					);
					?>
					<h2 class="title"><?php echo esc_html( $provider->get_label() ); ?></h2>
					<?php if ( $provider->get_description() ) : ?>
						<p class="description"><?php echo esc_html( $provider->get_description() ); ?></p>
					<?php endif; ?>

					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><?php esc_html_e( 'Enabled', 'openai-pixel' ); ?></th>
							<td>
								<label>
									<input
										type="checkbox"
										name="<?php echo esc_attr( OAIP_OPTION_KEY ); ?>[<?php echo esc_attr( $id ); ?>][enabled]"
										value="1"
										<?php checked( ! empty( $settings['enabled'] ) ); ?>
									/>
									<?php esc_html_e( 'Output this pixel on the site', 'openai-pixel' ); ?>
								</label>
							</td>
						</tr>

						<?php if ( array_key_exists( 'pixel_id', $settings ) ) : ?>
						<tr>
							<th scope="row">
								<label for="oaip-<?php echo esc_attr( $id ); ?>-pixel-id"><?php esc_html_e( 'Pixel ID', 'openai-pixel' ); ?></label>
							</th>
							<td>
								<input
									type="text"
									id="oaip-<?php echo esc_attr( $id ); ?>-pixel-id"
									class="regular-text"
									name="<?php echo esc_attr( OAIP_OPTION_KEY ); ?>[<?php echo esc_attr( $id ); ?>][pixel_id]"
									value="<?php echo esc_attr( $settings['pixel_id'] ); ?>"
								/>
								<p class="description"><?php esc_html_e( 'Optional. Available in the snippet below as %PIXEL_ID%.', 'openai-pixel' ); ?></p>
							</td>
						</tr>
						<?php endif; ?>

						<tr>
							<th scope="row"><?php esc_html_e( 'Placement', 'openai-pixel' ); ?></th>
							<td>
								<select name="<?php echo esc_attr( OAIP_OPTION_KEY ); ?>[<?php echo esc_attr( $id ); ?>][position]">
									<option value="head" <?php selected( $settings['position'], 'head' ); ?>><?php esc_html_e( 'Head (before </head>)', 'openai-pixel' ); ?></option>
									<option value="footer" <?php selected( $settings['position'], 'footer' ); ?>><?php esc_html_e( 'Footer (before </body>)', 'openai-pixel' ); ?></option>
								</select>
							</td>
						</tr>

						<tr>
							<th scope="row">
								<label for="oaip-<?php echo esc_attr( $id ); ?>-code"><?php esc_html_e( 'Pixel code', 'openai-pixel' ); ?></label>
							</th>
							<td>
								<textarea
									id="oaip-<?php echo esc_attr( $id ); ?>-code"
									class="large-text code"
									rows="8"
									name="<?php echo esc_attr( OAIP_OPTION_KEY ); ?>[<?php echo esc_attr( $id ); ?>][code]"
								><?php echo esc_textarea( $settings['code'] ); ?></textarea>
								<p class="description"><?php esc_html_e( 'Paste the full snippet, including <script> tags, exactly as provided by the pixel provider.', 'openai-pixel' ); ?></p>
							</td>
						</tr>
					</table>
				<?php endforeach; ?>

				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}
}
