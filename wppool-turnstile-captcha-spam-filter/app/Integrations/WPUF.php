<?php
/**
 * Main plugin class.
 *
 * @since   1.0.0
 * @package EasyCloudflareTurnstile
 */

namespace EasyCloudflareTurnstile\Integrations;

// if direct access than exit the file.
defined( 'ABSPATH' ) || exit;

/**
 * Manages WPUF turnstile integration.
 *
 * @since 1.0.1
 */
class WPUF {

	/**
	 * Contains turnstile context id.
	 *
	 * @var number
	 */
	private $turnstile_context_id;

	/**
	 * The Array of Allowed Tags
	 *
	 * @var array
	 */
	private $allowed_tags;

	/**
	 * Form Name
	 *
	 * @var string
	 */
	private $name;

	/**
	 * Return Multiple form ID
	 *
	 * @var array|mixed
	 */
	private $form_ids;

	/**
	 * Counting the Form
	 *
	 * @var int
	 */
	private $form_counter;


	/**
	 * Class constructor.
	 *
	 * @since 1.0.0
	 */
	public function __construct()
	{
		if ( ! class_exists( 'WP_User_Frontend' ) || ! wp_turnstile()->settings->get( 'site_key' ) || ! wp_turnstile()->settings->get( 'secret_key' ) ) {
			return;
		}

		$wpuf = wp_turnstile()->integrations->get( 'wpuf' );
		$this->name = wp_turnstile()->integrations->get_name( 'wpuf' );

		if ( ! wp_validate_boolean( $wpuf ) ) {
			return;
		}

		$this->turnstile_context_id = wp_rand();
		$this->form_ids = [];
		$this->form_counter = 0;

		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_scripts' ] );
		add_action( 'wpuf_reg_form_bottom', [ $this, 'render' ] );
		add_action( 'wpuf_login_form_bottom', [ $this, 'render_login' ] );
		add_action( 'wpuf_add_post_form_bottom', [ $this, 'render' ], 10, 2 );
		add_action( 'wpuf_form_submission_restriction', [ $this, 'verify' ], 10, 2 );
	}

	/**
	 * Enqueue turnstile challenges script on the footer.
	 *
	 *  @param  integrar $form_id ID for every form.
	 *
	 * @return void
	 */
	public function enqueue_scripts( $form_id )
	{
		// Only register and add the loader script once per page.
		if ( ! wp_script_is( 'ect-wpuf-turnstile-challenges', 'registered' ) ) {
			wp_register_script(
				'ect-wpuf-turnstile-challenges',
				false,
				[],
				wp_turnstile()->api_version,
				true
			);
			
			// Add the Turnstile loader script only once.
			$loader_script = '(function() {
				window.ectWPUFTurnstileLoader = window.ectWPUFTurnstileLoader || {
					loaded: false,
					callbacks: [],
					
					loadScript: function() {
						if (this.loaded || window.turnstile) {
							this.executeCallbacks();
							return;
						}
						
						var script = document.createElement("script");
						script.src = "https://challenges.cloudflare.com/turnstile/v0/api.js";
						script.async = true;
						script.onload = () => {
							this.loaded = true;
							this.executeCallbacks();
						};
						document.head.appendChild(script);
					},
					
					addCallback: function(callback) {
						if (window.turnstile) {
							callback();
						} else {
							this.callbacks.push(callback);
							if (!this.loaded) this.loadScript();
						}
					},
					
					executeCallbacks: function() {
						while (this.callbacks.length > 0) {
							var callback = this.callbacks.shift();
							callback();
						}
					}
				};
			})();';
			
			wp_add_inline_script( 'ect-wpuf-turnstile-challenges', $loader_script, 'before' );
		}

		$site_key = wp_turnstile()->settings->get( 'site_key' );
		if ( $this->form_ids ) {
			$context_id = esc_js( $this->turnstile_context_id );
			$site_key_escaped = esc_js( $site_key );
			
			// Add form-specific rendering script.
			$render_script = 'ectWPUFTurnstileLoader.addCallback(function() {';
			
			foreach ( $this->form_ids as $form_id ) {
				$form_id_escaped = esc_js( $form_id );
				$container_id = "ect-turnstile-container-{$context_id}-{$form_id_escaped}";
				
				$render_script .= "
					if (document.getElementById('{$container_id}') && !document.getElementById('{$container_id}').hasAttribute('data-rendered')) {
						turnstile.render('#{$container_id}', {
							sitekey: '{$site_key_escaped}',
							callback: function(token) {
								var form = document.querySelectorAll('.wpuf-login-form input[type=submit]');
								var postForm = document.querySelectorAll('.wpuf-form-add .wpuf-submit-button');
								
								if(form){
									form.forEach(function(element) {
										element.style.pointerEvents = 'auto';
										element.style.opacity = '1';
									});
								}
								
								if(postForm){
									postForm.forEach(function(element) {
										element.style.pointerEvents = 'auto';
										element.style.opacity = '1';
									});
								}
							}
						});
						document.getElementById('{$container_id}').setAttribute('data-rendered', 'true');
					}";
			}
			
			$render_script .= '});';
			
			wp_add_inline_script( 'ect-wpuf-turnstile-challenges', $render_script );
			wp_enqueue_script( 'ect-wpuf-turnstile-challenges' );
		}
	}

	/**
	 * Verify wp user frontend form submit submissions.
	 *
	 * @since 1.0.2
	 *
	 * @return mixed           Ongoing request flow.
	 */
	public function verify()
	{
		$message = wp_turnstile()->settings->get( 'error_msg', __( 'Please verify you are human', 'wppool-turnstile-captcha-spam-filter' ) );
		
		if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'turnstile_verify_nonce' ) ) {
			wp_die( esc_html__( 'Security check failed', 'wppool-turnstile-captcha-spam-filter' ) );
		}
		
		if ( ! isset( $_POST['cf-turnstile-response'] ) ) {
			return;
		}

		$token = sanitize_text_field( $_POST['cf-turnstile-response'] ); // phpcs:ignore.
		$response = wp_turnstile()->helpers->validate_turnstile( $token );
		if ( ! ( isset( $response['success'] ) && wp_validate_boolean( $response['success'] ) ) ) {
			$error_code = $response['error-codes'][0] ?? null;
			$message    = wp_turnstile()->common->get_error_message( $error_code );
			echo wp_json_encode( $message );
		}
	}

	/**
	 * Turnstile integration shortcode.
	 *
	 * @since 1.0.2
	 */
	public function render_login()
	{
		$this->form_counter++;
		$form_id = $this->form_counter;
		$this->form_ids[] = $form_id;

		$html = sprintf(
			'<div id="ect-turnstile-container-%s-%s" data-sitekey="%s" data-theme="%s" data-submit-button="%s" data-action="%s"></div>',
			$this->turnstile_context_id,
			$form_id,
			esc_attr( wp_turnstile()->settings->get( 'site_key' ) ),
			esc_attr( wp_turnstile()->settings->get( 'theme', 'light' ) ),
			esc_attr( wp_validate_boolean( wp_turnstile()->settings->get( 'button_access', 'false' ) ) ),
			esc_attr( $this->name )
		);

		if ( wp_validate_boolean( wp_turnstile()->settings->get( 'button_access', 'false' ) ) ) {
			$html .= '<style>
				.wpuf-login-form input[type=submit] {
					pointer-events: none;
					opacity: .5;
				}
				.wpuf-form-add .wpuf-submit-button{
					pointer-events: none;
					opacity: .5;
				}
			</style>';
		}

		$this->allowed_tags = wp_kses_allowed_html( 'post' );
		$this->allowed_tags['style'] = $this->allowed_tags;
		$this->enqueue_scripts( $form_id );

		echo wp_kses( $html, $this->allowed_tags );
	}

	/**
	 * Turnstile integration shortcode.
	 *
	 * @since 1.0.2
	 *
	 * @return void
	 */
	public function render()
	{
		$this->form_counter++;
		$form_id = $this->form_counter;
		$this->form_ids[] = $form_id;

		$html = sprintf(
			'<div class="ect-turnstile-container ect-turnstile-wpuf" id="ect-turnstile-container-%s-%s" data-sitekey="%s" data-theme="%s" data-submit-button="%s" data-action="%s" data-size="%s"></div>',
			$this->turnstile_context_id,
			$form_id,
			esc_attr( wp_turnstile()->settings->get( 'site_key' ) ),
			esc_attr( wp_turnstile()->settings->get( 'theme', 'light' ) ),
			esc_attr( wp_validate_boolean( wp_turnstile()->settings->get( 'button_access', 'false' ) ) ),
			esc_attr( $this->name ),
			'normal'
		);

		if ( wp_validate_boolean( wp_turnstile()->settings->get( 'button_access', 'false' ) ) ) {
			$html .= '<style>
				.wpuf-login-form input[type=submit] {
					pointer-events: none;
					opacity: .5;
				}
				.wpuf-form-add .wpuf-submit-button{
					pointer-events: none;
					opacity: .5;
				}

				.ect-turnstile-wpuf{
					margin-left: 30%;
				}				
				
				@media(max-width: 480px){
				.ect-turnstile-wpuf{
					margin-left: 2%;
				}
			}
			</style>';
		}

		$this->allowed_tags = wp_kses_allowed_html( 'post' );
		$this->allowed_tags['style'] = $this->allowed_tags;
		$this->enqueue_scripts( $form_id );

		echo wp_kses( $html, $this->allowed_tags );
	}
}