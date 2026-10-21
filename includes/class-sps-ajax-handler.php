<?php
/**
 * AJAX Handler for Form Submissions
 *
 * Handles submission validation, honeypot/anti-bot protection, recursive sanitization
 * and file uploads. Die Übertragung selbst erledigt SPS_Submission.
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

		// Frischer Nonce für gecachte Formularseiten.
		add_action( 'wp_ajax_sps_refresh_nonce', array( $this, 'refresh_nonce' ) );
		add_action( 'wp_ajax_nopriv_sps_refresh_nonce', array( $this, 'refresh_nonce' ) );
	}

	/**
	 * Liefert einen frischen Formular-Nonce (nicht cachen).
	 */
	public function refresh_nonce() {
		nocache_headers();
		wp_send_json_success( array( 'nonce' => wp_create_nonce( self::NONCE_ACTION ) ) );
	}

	/**
	 * Handle AJAX form submission.
	 */
	public function handle_submit() {
		$payload = $this->parse_request_payload();

		// 1. CSRF Nonce Verification
		$nonce = isset( $payload['nonce'] ) ? $payload['nonce'] : '';
		if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
			wp_send_json_error( array(
				'message' => __( 'Sicherheitsüberprüfung fehlgeschlagen. Bitte laden Sie die Seite neu.', 'smart-portal-suite' ),
				'code'    => 'invalid_nonce',
			), 403 );
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

		$form_id     = isset( $payload['form_id'] ) ? sanitize_key( $payload['form_id'] ) : '';
		$form_type   = isset( $payload['form_type'] ) ? sanitize_text_field( $payload['form_type'] ) : '';
		$raw_answers = isset( $payload['answers'] ) ? $payload['answers'] : array();

		// Answers may be a JSON string if submitted via FormData
		if ( is_string( $raw_answers ) ) {
			$decoded = json_decode( wp_unslash( $raw_answers ), true );
			if ( is_array( $decoded ) ) {
				$raw_answers = $decoded;
			}
		}

		// Schema immer serverseitig laden – Zuordnung, Formular-ID und Regeln kommen von dort.
		$renderer = SPS_Form_Renderer::get_instance();
		$schema   = '' !== $form_id ? $renderer->load_schema( $form_id ) : null;
		if ( ! $schema && '' !== $form_type ) {
			$schema = $renderer->load_schema( sanitize_key( sanitize_title( $form_type ) ) );
		}

		if ( ! is_array( $schema ) ) {
			SPS_Diagnostics::log( sprintf( 'Übermittlung abgelehnt: Schema "%s" nicht gefunden.', $form_id ? $form_id : $form_type ), 'warning' );
			wp_send_json_error( array( 'message' => __( 'Unbekanntes Formular.', 'smart-portal-suite' ) ), 400 );
		}

		if ( empty( $raw_answers ) || ! is_array( $raw_answers ) ) {
			wp_send_json_error( array( 'message' => __( 'Fehlende oder unvollständige Formulardaten.', 'smart-portal-suite' ) ), 400 );
		}

		if ( ! empty( $schema['requires_login'] ) && ! is_user_logged_in() ) {
			wp_send_json_error( array( 'message' => __( 'Bitte melden Sie sich an, um dieses Formular abzusenden.', 'smart-portal-suite' ) ), 403 );
		}

		// 4. Input Sanitization
		$answers = $this->sanitize_answers( $raw_answers );

		// 5. Datei-Uploads (bleiben bis Request-Ende als tmp-Datei erhalten)
		$uploaded_files = $this->process_uploaded_files();

		$meta = array();
		if ( ! empty( $payload['file_descriptions'] ) ) {
			$desc = is_string( $payload['file_descriptions'] ) ? json_decode( wp_unslash( $payload['file_descriptions'] ), true ) : $payload['file_descriptions'];
			if ( is_array( $desc ) ) {
				$meta['file_descriptions'] = $this->sanitize_answers( $desc );
			}
		}

		$schema_id = sanitize_key( isset( $schema['form_id'] ) ? $schema['form_id'] : $form_id );
		SPS_Diagnostics::log( sprintf( 'Formularübertragung gestartet: %s', $schema_id ), 'info' );

		// 6. Nextcloud Forms, sonst WebDAV-Fallback
		$result = SPS_Submission::process( $schema, $answers, $uploaded_files, get_current_user_id(), $meta );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => __( 'Es gab einen Fehler bei der Übermittlung. Bitte versuchen Sie es später erneut oder kontaktieren Sie uns direkt.', 'smart-portal-suite' ) ), 500 );
		}

		wp_send_json_success( array(
			'message' => __( 'Formulardaten erfolgreich übermittelt.', 'smart-portal-suite' ),
			'form_id' => $schema_id,
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
