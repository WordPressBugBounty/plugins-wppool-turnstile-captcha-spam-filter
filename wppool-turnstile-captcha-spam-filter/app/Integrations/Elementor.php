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
 * Manages Elementor turnstile integration.
 *
 * @since 1.0.1
 */
class Elementor {



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
	 * Return Multiple form ID
	 *
	 * @var array|mixed
	 */
	private $form_ids;

	/**
	 * The Plugin
	 *
	 * @var array
	 */
	private $plugin;

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
		if ( ! wp_turnstile()->settings->get( 'site_key' ) || ! wp_turnstile()->settings->get( 'secret_key' ) ) {
			return;
		}

		$this->plugin = wp_turnstile()->integrations->get( 'elementor' );

		if ( ! wp_validate_boolean( $this->plugin ) ) {
			return;
		}

		$this->turnstile_context_id = wp_rand();
		$this->form_ids = [];
		$this->form_counter = 0;

		add_action( 'elementor/widget/render_content', [ $this, 'easy_turnstile_elementor_form' ], 10, 2 );
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_scripts' ] );
		add_action( 'elementor_pro/forms/validation', [ $this, 'verify' ], 10, 2 );
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
		if ( ! wp_script_is( 'ect-elementor-turnstile-challenges', 'registered' ) ) {
			wp_register_script(
				'ect-elementor-turnstile-challenges',
				false,
				[],
				wp_turnstile()->api_version,
				true
			);
			
			// Add the Turnstile loader script only once.
			$loader_script = '(function() {
				window.ectElementorTurnstileLoader = window.ectElementorTurnstileLoader || {
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
			
			wp_add_inline_script( 'ect-elementor-turnstile-challenges', $loader_script, 'before' );
		}

		$site_key = wp_turnstile()->settings->get( 'site_key' );
		if ( $this->form_ids ) {
			$context_id = esc_js( $this->turnstile_context_id );
			$site_key_escaped = esc_js( $site_key );
			
			// Add form-specific rendering script.
			$render_script = 'ectElementorTurnstileLoader.addCallback(function() {';
			
			foreach ( $this->form_ids as $form_id ) {
				$form_id_escaped = esc_js( $form_id );
				$container_id = "ect-turnstile-container-{$context_id}-{$form_id_escaped}";
				
				$render_script .= "
					if (document.getElementById('{$container_id}') && !document.getElementById('{$container_id}').hasAttribute('data-rendered')) {
						turnstile.render('#{$container_id}', {
							sitekey: '{$site_key_escaped}',
							callback: function(token) {
								var forms = document.querySelectorAll('.elementor-field-type-submit button[type=submit]');
								if(forms){
									forms.forEach(function(form){
										form.style.pointerEvents = 'auto';
										form.style.opacity = '1';
									});
								}

								var submitBtn = document.querySelectorAll('.elementor-field-type-submit');
								if(submitBtn){
									submitBtn.forEach(function(submit){
										submit.style.display = 'block';
									});
								}
							}			
						});
						document.getElementById('{$container_id}').setAttribute('data-rendered', 'true');
					}";
			}
			
			$render_script .= '});';
			
			wp_add_inline_script( 'ect-elementor-turnstile-challenges', $render_script );
			wp_enqueue_script( 'ect-elementor-turnstile-challenges' );
		}
	}

	/**
	 * Render Elementor form submit submissions.
	 *
	 * @param mixed|html $content total form DOM element.
	 * @param object     $widget    form objects.
	 * @return mixed|html render the total form
	 */
	public function easy_turnstile_elementor_form( $content, $widget )
	{
		$attr = [ 'form', 'login' ];
		if ( in_array( $widget->get_name(), $attr, true ) ) {
			$this->form_counter++;
			$form_id = $this->form_counter;
			$this->form_ids[] = $form_id;
			$html = sprintf(
				'<div class="ect-turnstile-container" id="ect-turnstile-container-%s-%s" data-sitekey="%s" data-theme="%s" data-submit-button="%s" data-retry="auto" data-retry-interval="1000" data-action="%s" data-size="%s"></div>',
				$this->turnstile_context_id,
				$form_id,
				esc_attr( wp_turnstile()->settings->get( 'site_key' ) ),
				esc_attr( wp_turnstile()->settings->get( 'theme', 'light' ) ),
				esc_attr( wp_validate_boolean( wp_turnstile()->settings->get( 'button_access', 'false' ) ) ),
				'elementor-' . $form_id,
				'normal',
			);

			if ( wp_validate_boolean( wp_turnstile()->settings->get( 'button_access', 'false' ) ) ) {
				$html .= '<style>.elementor-field-type-submit button[type=submit]{ pointer-events: none; opacity: .5 ;}
				</style>';
			}
			$this->allowed_tags = wp_kses_allowed_html( 'post' );
			$this->allowed_tags['style'] = $this->allowed_tags;
			$this->enqueue_scripts( $form_id );
			// matches with Button submit of content.
			$pattern = '/(<button[^>]*type="submit"[^>]*>.*?<\/button>)/is';
			if ( preg_match( $pattern, $content, $matches ) ) {
				$submit_button = $matches[0];
				$content = str_replace( $submit_button, wp_kses( $html, $this->allowed_tags ) . '' . $submit_button, $content );
			}
			return $content;
		} else {
			return $content;
		}
	}


	/**
	 * Verify Elementor form submissions.
	 *
	 * @since 2.1.0
	 *
	 * @param object $record Form fields.
	 * @param object $ajax_handler Form related data.
	 * @return mixed verification of the form.
	 */
	public function verify( $record, $ajax_handler ) 	{ // phpcs:ignore.
		$message  = wp_turnstile()->settings->get( 'error_msg', __( 'Please verify you are human', 'wppool-turnstile-captcha-spam-filter' ) );
		$fields = $record->get_field([
			'id' => 'ticket_id',
		]);
		if ('POST' === $_SERVER['REQUEST_METHOD'] && isset($_POST['cf-turnstile-response']) && !empty($_POST['cf-turnstile-response'])) { // phpcs:ignore
			$token    = sanitize_text_field($_POST['cf-turnstile-response']); // phpcs:ignore
			$response = wp_turnstile()->helpers->validate_turnstile( $token );

			if ( ! ( isset( $response['success'] ) && wp_validate_boolean( $response['success'] ) ) ) {
				$field = current( $fields );
				$error_code = $response['error-codes'][0] ?? null;
				$message   = wp_turnstile()->common->get_error_message( $error_code );
				$ajax_handler->add_error_message( $message );
				$ajax_handler->add_error( $field['id'], esc_html__( 'Invalid Turnstile.', 'wppool-turnstile-captcha-spam-filter' ) );
				$ajax_handler->is_success = false;
			}
		} else {
			$ajax_handler->add_error_message( $message );
			$ajax_handler->add_error( 'invalid_turnstile', $message );
			$ajax_handler->is_success = false;
		}
	}
}