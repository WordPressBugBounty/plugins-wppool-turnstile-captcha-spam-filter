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
 * Manages Gravity Form turnstile integration.
 *
 * @since 1.0.1
 */
class GravityForm {

	/**
	 * Contains turnstile context id.
	 *
	 * @var number
	 */
	private $turnstile_context_id;

	/**
	 * Contains html render field
	 *
	 * @var string
	 */
	private $html;

	/**
	 * Allowed tags for HTML
	 *
	 * @var mixed
	 */
	private $allowed_tags;

	/**
	 * Placement of ECT widget
	 *
	 * @var mixed
	 */
	private $ect_placement;

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
		if ( ! class_exists( 'GFForms' ) || ! wp_turnstile()->settings->get( 'site_key' ) || ! wp_turnstile()->settings->get( 'secret_key' )) {
			return;
		}

		$this->plugin = wp_turnstile()->integrations->get( 'gravityforms' );

		if ( ! wp_validate_boolean( $this->plugin )) {
			return;
		}

		$this->turnstile_context_id = wp_rand();
		$this->form_ids = [];
		$this->form_counter = 0;

		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_scripts' ] );
		add_filter( 'gform_pre_submission', [ $this, 'verify' ] );
		add_filter( 'gform_submit_button', [ $this, 'render_turnstile_before_submit_button' ], 10, 2 );
	}

	/**
	 * Turnstile integration field.
	 *
	 * @param  mixed $button submission button rendering.
	 * @param  mixed $form form for rendering value.
	 *
	 * @return mixed|html
	 */
	public function render_turnstile_before_submit_button( $button, $form )
	{
		// Get Disable ID Option.
		$disabled_ids = ! empty( get_option( 'ect_disabled_ids' ) ) ? get_option( 'ect_disabled_ids' ) : '';
		$gravity_id_disable = ( isset( $disabled_ids['gravityforms'] ) && ! empty( $disabled_ids['gravityforms'] ) ) ? sanitize_text_field( $disabled_ids['gravityforms'] ) : '';
		if ( ! empty( $gravity_id_disable )) {
			$disable_id = explode( ',', $gravity_id_disable );
			if (in_array( $form['id'], $disable_id, true )) {
				return $button;
			}
		}

		$this->form_counter++;
		$form_id = $this->form_counter;
		$this->form_ids[] = $form_id;

		$turnstile_field = sprintf(
			'<div class="ect-turnstile-container" id="ect-gvform-turnstile-container-%s-%s" data-sitekey="%s" data-theme="%s" data-submit-button="%s" data-size="%s"></div>',
			$this->turnstile_context_id,
			$form_id,
			wp_turnstile()->settings->get( 'site_key' ),
			wp_turnstile()->settings->get( 'theme', 'light' ),
			esc_attr( wp_validate_boolean( wp_turnstile()->settings->get( 'button_access', 'false' ) ) ),
			'normal',
		);
		$this->html = sprintf( '<div class="ginput_container">%s</div><div class="clearfix"></div>', $turnstile_field );
		$this->allowed_tags = wp_kses_allowed_html( 'post' );
		$this->allowed_tags['style'] = $this->allowed_tags;
		$this->enqueue_scripts( $form_id );

		if (wp_validate_boolean( wp_turnstile()->settings->get( 'button_access', 'false' ) )) {
			$this->html .= '<style>
                        .gform_wrapper input[type=submit] {
                            pointer-events: none;
                            opacity: .5;
                        }
                    </style>';
		}
		// GET Placement Option.
		$this->ect_placement = get_option( 'ect_placement' );
		$this->ect_placement = ( isset( $this->ect_placement ) && is_array( $this->ect_placement ) ) ? sanitize_text_field( $this->ect_placement['gravityforms'] ) : '';
		if ($this->ect_placement) {
			if ('before' === $this->ect_placement) {
				return wp_kses( $this->html, $this->allowed_tags ) . $button;
			} elseif ('after' === $this->ect_placement) {
				return $button . wp_kses( $this->html, $this->allowed_tags );
			}
		} else {
			return wp_kses( $this->html, $this->allowed_tags ) . $button;
		}
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
		if ( ! wp_script_is( 'ect-gvform-turnstile-challenges', 'registered' ) ) {
			wp_register_script(
				'ect-gvform-turnstile-challenges',
				false,
				[],
				wp_turnstile()->api_version,
				true
			);
			
			// Add the Turnstile loader script only once.
			$loader_script = '(function() {
				window.ectGravityFormsTurnstileLoader = window.ectGravityFormsTurnstileLoader || {
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
			
			wp_add_inline_script( 'ect-gvform-turnstile-challenges', $loader_script, 'before' );
		}

		$site_key = wp_turnstile()->settings->get( 'site_key' );
		if ( $this->form_ids ) {
			$context_id = esc_js( $this->turnstile_context_id );
			$site_key_escaped = esc_js( $site_key );
			
			// Add form-specific rendering script.
			$render_script = 'ectGravityFormsTurnstileLoader.addCallback(function() {';
			
			foreach ( $this->form_ids as $form_id ) {
				$form_id_escaped = esc_js( $form_id );
				$container_id = "ect-gvform-turnstile-container-{$context_id}-{$form_id_escaped}";
				
				$render_script .= "
					if (document.getElementById('{$container_id}') && !document.getElementById('{$container_id}').hasAttribute('data-rendered')) {
						turnstile.render('#{$container_id}', {
							sitekey: '{$site_key_escaped}',
							callback: function(token) {
								var forms = document.querySelectorAll('.gform_wrapper input[type=submit]');
								forms.forEach(function(form){
									form.style.pointerEvents = 'auto';
									form.style.opacity = '1';
								});
								var footer = document.querySelectorAll('.gform_footer');
								footer.forEach(function(foo){foo.style.display = 'block'});
							}
						});
						document.getElementById('{$container_id}').setAttribute('data-rendered', 'true');
					}";
			}
			
			$render_script .= '});';
			
			wp_add_inline_script( 'ect-gvform-turnstile-challenges', $render_script );
			wp_enqueue_script( 'ect-gvform-turnstile-challenges' );
		}
	}

	/**
	 * Verify gravity Form form submit submissions.
	 *
	 * @param  mixed $form The submit request.
	 * @return mixed          Ongoing request flow.
	 */
	public function verify( $form )
	{
		$submission_field_id = null;
		foreach ($form['fields'] as &$field) {
			if ('submit' === $field->type) {
				$submission_field_id = $field->id;
			}
		}

		if ( ! isset( $_POST['cf-turnstile-response'] ) ) { //phpcs:ignore
			return;
		}

		$token = sanitize_text_field( $_POST['cf-turnstile-response'] ); //phpcs:ignore.
		$response = wp_turnstile()->helpers->validate_turnstile( $token );

		if ( ! ( isset( $response['success'] ) && wp_validate_boolean( $response['success'] ) )) {
			$error_message = wp_turnstile()->settings->get( 'error_msg', __( 'Invalid Turnstile', 'wppool-turnstile-captcha-spam-filter' ) );
			$validation_message = esc_html( $error_message );

			foreach ($form['fields'] as &$field) {
				if ($submission_field_id === $field->id) {
					$field->failed_validation = true;
					$field->validation_message = $validation_message;
					break;
				}
			}

			if (isset( $response['error-codes'] ) && ! empty( $response['error-codes'] )) {
				$error_code = $response['error-codes'][0] ?? null; //phpcs:ignore.
				$message    = wp_turnstile()->common->get_error_message( $error_code );
				$error_message = 'Error Message: ' . $message;
				gform_add_validation_error( '', $error_message );
			}
			$result['is_valid'] = false;
			$result['validation_message'] = $validation_message;
			$result['form'] = $form;

			return $result;
		}
	}
}