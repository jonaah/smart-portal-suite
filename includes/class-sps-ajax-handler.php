<?php
/**
 * AJAX Handler for Form Submissions
 *
 * Handles submission validation, honeypot/anti-bot protection, recursive sanitization,
 * file uploads, and Nextcloud Forms / WebDAV fallback integration.
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
		$payload = $this->parse_request_payload();

		// 1. CSRF Nonce Verification
		$nonce = isset( $payload['nonce'] ) ? $payload['nonce'] : '';
		if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
			wp_send_json_error( array( 'message' => __( 'Sicherheitsüberprüfung fehlgeschlagen. Bitte laden Sie die Seite neu.', 'smart-portal-suite' ) ), 403 );
		}

		// 2. Anti-Bot: Honeypot Check
		if ( ! empty( $payload['sps_hp'] ) ) {
			SPS_Diagnostics::log_error( 'Spam submission rejected by honeypot.' );
			wp_send_json_error( array( 'message' => __( 'Spam erkannt.', 'smart-portal-suite' ) ), 400 );
		}

		// 3. Anti-Bot: Minimum submission duration (must be > 1.5 seconds)
		if ( isset( $payload['sps_duration_ms'] ) && intval( $payload['sps_duration_ms'] ) < 1500 ) {
			SPS_Diagnostics::log_error( 'Spam submission rejected: submitted too quickly (< 1.5s).' );
			wp_send_json_error( array( 'message' => __( 'Formular zu schnell abgesendet.', 'smart-portal-suite' ) ), 400 );
		}

		$form_type = isset( $payload['form_type'] ) ? sanitize_text_field( $payload['form_type'] ) : '';
		$form_id   = isset( $payload['form_id'] ) ? sanitize_key( $payload['form_id'] ) : '';
		$raw_answers = isset( $payload['answers'] ) ? $payload['answers'] : array();

		// Answers may be a JSON string if submitted via FormData
		if ( is_string( $raw_answers ) ) {
			$decoded = json_decode( wp_unslash( $raw_answers ), true );
			if ( is_array( $decoded ) ) {
				$raw_answers = $decoded;
			}
		}

		if ( empty( $form_type ) && ! empty( $form_id ) ) {
			$form_type = $form_id;
		}

		if ( empty( $form_type ) || empty( $raw_answers ) || ! is_array( $raw_answers ) ) {
			wp_send_json_error( array( 'message' => __( 'Fehlende oder unvollständige Formulardaten.', 'smart-portal-suite' ) ), 400 );
		}

		// 4. Input Sanitization
		$answers = $this->sanitize_answers( $raw_answers );

		// 5. Handle File Uploads (if any)
		$uploaded_files = $this->process_uploaded_files();
		if ( ! empty( $uploaded_files ) ) {
			foreach ( $uploaded_files as $field_key => $file_items ) {
				$answers[ $field_key . '_files' ] = $file_items;
			}
		}

		// 6. Process Nextcloud Submission
		$nc_forms           = new SPS_NC_Forms();
		$webdav             = new SPS_NC_WebDAV();
		$fallback_triggered = false;
		$error_message = '';
		$nc_form_id = '';

		SPS_Diagnostics::log( sprintf( 'Formularübertragung gestartet: %s', $form_type ), 'info' );
		
		// 1. Resolve Form ID
		$form_id_result = $nc_forms->resolve_form_id( $form_type );
		if ( is_wp_error( $form_id_result ) ) {
			$error_message = 'Formular ID konnte nicht aufgelöst werden: ' . $form_id_result->get_error_message();
			SPS_Diagnostics::log( $error_message, 'warning' );
			$fallback_triggered = true;
		} else {
			$nc_form_id = $form_id_result;
			$questions  = $nc_forms->get_questions( $nc_form_id );

			if ( is_wp_error( $questions ) || empty( $questions ) ) {
				$error_message = 'Formulardefinition konnte nicht geladen werden (ID: ' . $nc_form_id . ')';
				SPS_Diagnostics::log( $error_message, 'warning' );
				$fallback_triggered = true;
			} else {
				$mapped_answers = $nc_forms->map_answers( $questions, $answers );
				if ( empty( $mapped_answers ) ) {
					$error_message = 'Antwort-Mapping ergab leeres Payload.';
					SPS_Diagnostics::log( $error_message, 'warning' );
					$fallback_triggered = true;
				} else {
					$submit_result = $nc_forms->submit_form( $nc_form_id, $mapped_answers );
					if ( is_wp_error( $submit_result ) ) {
						$error_message = 'Forms API Submission fehlgeschlagen: ' . $submit_result->get_error_message();
						SPS_Diagnostics::log( $error_message, 'warning' );
						$fallback_triggered = true;
					}
				}
			}
		}

		// WebDAV Fallback if API fails
		if ( $fallback_triggered ) {
			SPS_Diagnostics::log( sprintf( 'Leite WebDAV-Fallback für "%s" ein (Grund: %s)', $form_type, $error_message ), 'warning' );

			$fallback_result = $webdav->store_lead_fallback( $answers, sanitize_title( $form_type ) );
			if ( is_wp_error( $fallback_result ) ) {
				$fatal_err = 'WebDAV Fallback fehlgeschlagen: ' . $fallback_result->get_error_message();
				SPS_Diagnostics::log( $fatal_err, 'error' );
				wp_send_json_error( array( 'message' => __( 'Es gab einen Fehler bei der Übermittlung. Bitte versuchen Sie es später erneut.', 'smart-portal-suite' ) ), 500 );
			}
			
			SPS_Diagnostics::log( sprintf( 'Lead für "%s" erfolgreich per WebDAV gespeichert.', $form_type ), 'info' );

			// Admin Benachrichtigung per E-Mail senden
			$admin_email = SPS_Settings::get_setting( 'admin_email' );
			if ( ! empty( $admin_email ) && is_email( $admin_email ) ) {
				$subject = sprintf( '[%s] Warnung: Nextcloud Forms Fallback aktiv (%s)', get_bloginfo( 'name' ), $form_type );
				$body    = sprintf(
					"Hallo Administrator,\n\neine Formularübermittlung für \"%s\" konnte nicht direkt an die Nextcloud Forms API übergeben werden.\n\nGrund: %s\n\nDie Lead-Daten wurden sicher als JSON im WebDAV-Fallback-Verzeichnis abgelegt.\n\nZeitpunkt: %s\nWebsite: %s",
					$form_type,
					$error_message,
					current_time( 'mysql' ),
					home_url()
				);
				wp_mail( $admin_email, $subject, $body );
			}

			// If fallback succeeds, we can still show a success message to the user!
			wp_send_json_success( array(
				'message' => __( 'Formulardaten erfolgreich empfangen (Fallback).', 'smart-portal-suite' ),
				'form_id' => $form_type,
			) );
		}

		SPS_Diagnostics::log( sprintf( 'Formular "%s" erfolgreich an Nextcloud Forms übertragen (Form ID: %s).', $form_type, $nc_form_id ), 'info' );

		wp_send_json_success( array(
			'message' => __( 'Formulardaten erfolgreich übermittelt.', 'smart-portal-suite' ),
			'form_id' => $nc_form_id,
		) );
	}

	/**
	 * Parse request payload whether sent as JSON or multipart/form-data.
	 *
	 * @return array
	 */
	private function parse_request_payload() {
		$raw_input = file_get_contents( 'php://input' );
		$json_data = json_decode( $raw_input, true );

		if ( is_array( $json_data ) && ! empty( $json_data ) ) {
			return $json_data;
		}

		return ! empty( $_POST ) ? $_POST : array();
	}

	/**
	 * Deeply sanitize an array of answers.
	 *
	 * @param array $data Input answers.
	 * @return array Sanitized answers.
	 */
	private function sanitize_answers( $data ) {
		$sanitized = array();

		foreach ( $data as $key => $value ) {
			$clean_key = sanitize_key( $key );

			if ( is_array( $value ) ) {
				$sanitized[ $clean_key ] = $this->sanitize_answers( $value );
			} elseif ( is_bool( $value ) ) {
				$sanitized[ $clean_key ] = $value;
			} elseif ( is_numeric( $value ) ) {
				$sanitized[ $clean_key ] = $value;
			} elseif ( is_string( $value ) ) {
				if ( is_email( $value ) ) {
					$sanitized[ $clean_key ] = sanitize_email( $value );
				} else {
					$sanitized[ $clean_key ] = sanitize_textarea_field( $value );
				}
			} else {
				$sanitized[ $clean_key ] = sanitize_text_field( (string) $value );
			}
		}

		return $sanitized;
	}

	/**
	 * Process uploaded files from $_FILES securely.
	 *
	 * @return array File info array.
	 */
	private function process_uploaded_files() {
		if ( empty( $_FILES ) ) {
			return array();
		}

		$processed = array();
		$max_size  = 10 * 1024 * 1024; // 10 MB
		$allowed   = array( 'pdf', 'jpg', 'jpeg', 'png', 'webp', 'heic', 'doc', 'docx' );

		foreach ( $_FILES as $field_key => $files_data ) {
			if ( ! is_array( $files_data['name'] ) ) {
				$names     = array( $files_data['name'] );
				$types     = array( $files_data['type'] );
				$tmp_names = array( $files_data['tmp_name'] );
				$errors    = array( $files_data['error'] );
				$sizes     = array( $files_data['size'] );
			} else {
				$names     = $files_data['name'];
				$types     = $files_data['type'];
				$tmp_names = $files_data['tmp_name'];
				$errors    = $files_data['error'];
				$sizes     = $files_data['size'];
			}

			$field_files = array();

			foreach ( $names as $idx => $orig_name ) {
				if ( $errors[ $idx ] !== UPLOAD_ERR_OK || empty( $tmp_names[ $idx ] ) ) {
					continue;
				}

				if ( $sizes[ $idx ] > $max_size ) {
					SPS_Diagnostics::log_error( "File {$orig_name} exceeded max size." );
					continue;
				}

				$ext = strtolower( pathinfo( $orig_name, PATHINFO_EXTENSION ) );
				if ( ! in_array( $ext, $allowed, true ) ) {
					SPS_Diagnostics::log_error( "File type .{$ext} not allowed." );
					continue;
				}

				$clean_name = sanitize_file_name( $orig_name );

				$field_files[] = array(
					'name'     => $clean_name,
					'size'     => intval( $sizes[ $idx ] ),
					'type'     => sanitize_mime_type( $types[ $idx ] ),
					'tmp_name' => $tmp_names[ $idx ],
				);
			}

			if ( ! empty( $field_files ) ) {
				$clean_field = str_replace( 'files_', '', sanitize_key( $field_key ) );
				$processed[ $clean_field ] = $field_files;
			}
		}

		return $processed;
	}
}
