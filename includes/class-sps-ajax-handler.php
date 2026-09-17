<?php
/**
 * AJAX Handler for Form Submissions
 *
 * @package SmartPortalSuite
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SPS_Ajax_Handler {

	/**
	 * AJAX action name.
	 */
	const ACTION_SUBMIT = 'sps_submit_form';

	/**
	 * Nonce action name.
	 */
	const NONCE_ACTION = 'sps_form_nonce';

	/**
	 * Singleton instance.
	 *
	 * @var SPS_Ajax_Handler|null
	 */
	private static $instance = null;

	/**
	 * Get singleton instance.
	 *
	 * @return SPS_Ajax_Handler
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		add_action( 'wp_ajax_' . self::ACTION_SUBMIT, array( $this, 'handle_submit' ) );
		add_action( 'wp_ajax_nopriv_' . self::ACTION_SUBMIT, array( $this, 'handle_submit' ) );
	}

	/**
	 * Handle AJAX form submission.
	 */
	public function handle_submit() {
		// Read JSON payload
		$payload = json_decode( file_get_contents( 'php://input' ), true );
		if ( ! $payload ) {
			// Fallback to standard POST if not JSON
			$payload = $_POST;
		}

		// 1. CSRF Verification
		$nonce = isset( $payload['nonce'] ) ? $payload['nonce'] : '';
		if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
			wp_send_json_error( array( 'message' => __( 'Sicherheitsüberprüfung fehlgeschlagen.', 'smart-portal-suite' ) ), 403 );
		}

		// 2. Bot / Honeypot Check (Not fully implemented in FE yet, but preparing)
		if ( ! empty( $payload['sps_hp'] ) ) {
			wp_send_json_error( array( 'message' => __( 'Spam erkannt.', 'smart-portal-suite' ) ), 400 );
		}

		$form_type = isset( $payload['form_type'] ) ? sanitize_text_field( $payload['form_type'] ) : '';
		$answers = isset( $payload['answers'] ) ? (array) $payload['answers'] : array();

		if ( empty( $form_type ) || empty( $answers ) ) {
			wp_send_json_error( array( 'message' => __( 'Fehlende Formulardaten.', 'smart-portal-suite' ) ), 400 );
		}

		// Process Nextcloud Submission
		$nc_forms = new SPS_NC_Forms();
		$webdav = new SPS_NC_WebDAV();
		$fallback_triggered = false;
		$error_message = '';
		$nc_form_id = '';
		
		// 1. Resolve Form ID
		$form_id_result = $nc_forms->resolve_form_id( $form_type );
		if ( is_wp_error( $form_id_result ) ) {
			SPS_Diagnostics::log_error( 'Failed to resolve form ID for: ' . $form_type );
			$fallback_triggered = true;
			$error_message = 'Formular ID Konnte nicht aufgelöst werden.';
		} else {
			$nc_form_id = $form_id_result;
			// 2. Get Questions
			$questions = $nc_forms->get_questions( $nc_form_id );
			if ( is_wp_error( $questions ) || empty( $questions ) ) {
				SPS_Diagnostics::log_error( 'Failed to load questions for form ID: ' . $nc_form_id );
				$fallback_triggered = true;
				$error_message = 'Formulardefinition konnte nicht geladen werden.';
			} else {
				// 3. Map Answers
				$mapped_answers = $nc_forms->map_answers( $questions, $answers );
				if ( empty( $mapped_answers ) ) {
					SPS_Diagnostics::log_error( 'Answer mapping resulted in empty payload.' );
					$fallback_triggered = true;
					$error_message = 'Mapping ergab leeres Payload.';
				} else {
					// 4. Submit
					$submit_result = $nc_forms->submit_form( $nc_form_id, $mapped_answers );
					if ( is_wp_error( $submit_result ) ) {
						SPS_Diagnostics::log_error( 'Failed to submit form: ' . $submit_result->get_error_message() );
						$fallback_triggered = true;
						$error_message = 'Submission Fehler: ' . $submit_result->get_error_message();
					}
				}
			}
		}

		// WebDAV Fallback if API fails
		if ( $fallback_triggered ) {
			$fallback_result = $webdav->store_lead_fallback( $answers, sanitize_title( $form_type ) );
			if ( is_wp_error( $fallback_result ) ) {
				SPS_Diagnostics::log_error( 'WebDAV Fallback failed: ' . $fallback_result->get_error_message() );
				wp_send_json_error( array( 'message' => __( 'Es gab einen Fehler bei der Übermittlung. Bitte versuchen Sie es später erneut.', 'smart-portal-suite' ) ), 500 );
			}
			
			// If fallback succeeds, we can still show a success message to the user!
			wp_send_json_success( array(
				'message' => __( 'Formulardaten erfolgreich empfangen (Fallback).', 'smart-portal-suite' ),
				'form_id' => $form_type,
			) );
		}

		wp_send_json_success( array(
			'message' => __( 'Formulardaten erfolgreich übermittelt.', 'smart-portal-suite' ),
			'form_id' => $nc_form_id,
		) );
	}
}
