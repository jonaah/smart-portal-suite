<?php
/**
 * Datei-Uploads ohne Multipart.
 *
 * Manche Hoster (z. B. Manitu) sperren multipart/form-data per WAF. Das Frontend
 * lädt deshalb jede Datei einzeln als Rohdaten hoch:
 *
 *   POST admin-ajax.php?action=sps_upload_file&nonce=…&form_id=…&field=…&name=…
 *   Content-Type: application/octet-stream
 *   Body: Dateiinhalt
 *
 * Der Server prüft Nonce, Endung, Inhaltstyp und Größe, legt die Datei im
 * Staging-Verzeichnis ab und gibt ein Token zurück. Beim Absenden enthält das
 * JSON nur noch die Tokens. Nicht abgeholte Dateien löscht ein Cron nach
 * STAGING_TTL Sekunden.
 *
 * Staging-Verzeichnis: Konstante SPS_STAGING_DIR, sonst das Temp-Verzeichnis
 * von PHP (außerhalb des Webroots), nur notfalls uploads/sps-staging mit
 * Zugriffssperre. Dateien heißen <zufälliges Token>.bin.
 *
 * @package SmartPortalSuite
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SPS_Upload_Staging {

	/**
	 * AJAX-Aktion.
	 */
	const ACTION = 'sps_upload_file';

	/**
	 * Cron-Hook Aufräumen.
	 */
	const CRON_HOOK = 'sps_cleanup_staging';

	/**
	 * Maximale Dateigröße (Bytes).
	 */
	const MAX_SIZE = 10485760; // 10 MB

	/**
	 * Lebensdauer nicht abgeholter Dateien (Sekunden).
	 */
	const STAGING_TTL = 7200;

	/**
	 * Maximale Uploads pro IP und Stunde.
	 */
	const RATE_LIMIT = 60;

	/**
	 * Maximale Dateien pro Übermittlung.
	 */
	const MAX_FILES_PER_SUBMIT = 30;

	/**
	 * Erlaubte Endungen und zugehörige Inhaltstypen (finfo).
	 */
	const ALLOWED = array(
		'pdf'  => array( 'application/pdf' ),
		'jpg'  => array( 'image/jpeg' ),
		'jpeg' => array( 'image/jpeg' ),
		'png'  => array( 'image/png' ),
		'webp' => array( 'image/webp' ),
		'heic' => array( 'image/heic', 'image/heif', 'image/heic-sequence', 'image/heif-sequence', 'application/octet-stream' ),
		'heif' => array( 'image/heic', 'image/heif', 'application/octet-stream' ),
		'doc'  => array( 'application/msword', 'application/cdfv2', 'application/vnd.ms-office', 'application/x-ole-storage', 'application/octet-stream' ),
		'docx' => array( 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip', 'application/octet-stream' ),
	);

	/**
	 * Inhaltstyp, der gespeichert und weitergegeben wird.
	 */
	const CANONICAL_TYPE = array(
		'pdf'  => 'application/pdf',
		'jpg'  => 'image/jpeg',
		'jpeg' => 'image/jpeg',
		'png'  => 'image/png',
		'webp' => 'image/webp',
		'heic' => 'image/heic',
		'heif' => 'image/heif',
		'doc'  => 'application/msword',
		'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
	);

	/**
	 * Hooks registrieren.
	 */
	public static function init() {
		add_action( 'wp_ajax_' . self::ACTION, array( __CLASS__, 'handle_upload' ) );
		add_action( 'wp_ajax_nopriv_' . self::ACTION, array( __CLASS__, 'handle_upload' ) );
		add_action( self::CRON_HOOK, array( __CLASS__, 'cleanup' ) );
		add_action( 'init', array( __CLASS__, 'schedule' ) );
	}

	/**
	 * Aufräumen stündlich einplanen.
	 */
	public static function schedule() {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + 600, 'hourly', self::CRON_HOOK );
		}
	}

	/**
	 * Planung entfernen.
	 */
	public static function unschedule() {
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	/* ─────────────────────────── Verzeichnis ─────────────────────────── */

	/**
	 * Staging-Verzeichnis (mit abschließendem Slash), wird bei Bedarf angelegt und gesperrt.
	 *
	 * @return string|WP_Error
	 */
	public static function dir() {
		static $cached = null;
		if ( null !== $cached ) {
			return $cached;
		}

		$candidates = array();
		if ( defined( 'SPS_STAGING_DIR' ) && SPS_STAGING_DIR ) {
			$candidates[] = trailingslashit( SPS_STAGING_DIR );
		} else {
			// 1. Temp-Verzeichnis von PHP (außerhalb des Webroots, wie bei $_FILES).
			$tmp = (string) ini_get( 'upload_tmp_dir' );
			if ( '' === $tmp || ! @is_writable( $tmp ) ) {
				$tmp = function_exists( 'get_temp_dir' ) ? get_temp_dir() : sys_get_temp_dir();
			}
			if ( '' !== $tmp ) {
				$candidates[] = trailingslashit( $tmp ) . 'sps-staging-' . substr( md5( ABSPATH ), 0, 12 ) . '/';
			}
			// 2. Fallback: uploads/sps-staging (mit Zugriffssperre).
			$uploads      = wp_upload_dir( null, false );
			$candidates[] = trailingslashit( $uploads['basedir'] ) . 'sps-staging/';
		}

		$dir = '';
		foreach ( $candidates as $candidate ) {
			if ( ! is_dir( $candidate ) ) {
				$old = umask( 0077 );
				@mkdir( $candidate, 0700, true );
				umask( $old );
			}
			if ( is_dir( $candidate ) && is_writable( $candidate ) && ( count( $candidates ) === 1 || ! self::path_in_webroot( $candidate ) || end( $candidates ) === $candidate ) ) {
				$dir = $candidate;
				break;
			}
		}

		if ( '' === $dir ) {
			return new WP_Error( 'sps_staging_dir', 'Kein beschreibbares Staging-Verzeichnis: ' . implode( ', ', $candidates ) );
		}

		// Zugriff über den Webserver sperren (falls im Webroot).
		if ( ! file_exists( $dir . '.htaccess' ) ) {
			@file_put_contents( $dir . '.htaccess', "Require all denied\n<IfModule !mod_authz_core.c>\nOrder allow,deny\nDeny from all\n</IfModule>\n" );
		}
		if ( ! file_exists( $dir . 'index.php' ) ) {
			@file_put_contents( $dir . 'index.php', "<?php\n// Silence is golden.\n" );
		}

		$cached = $dir;
		return $dir;
	}

	/**
	 * Liegt ein Pfad im Webroot?
	 *
	 * @param string $path Pfad.
	 * @return bool
	 */
	protected static function path_in_webroot( $path ) {
		$root = realpath( ABSPATH );
		$real = realpath( $path );
		return $root && $real && 0 === strpos( $real, $root );
	}

	/**
	 * Liegt das Staging-Verzeichnis im Webroot?
	 *
	 * @return bool
	 */
	public static function is_in_webroot() {
		$dir = self::dir();
		if ( is_wp_error( $dir ) ) {
			return false;
		}
		return self::path_in_webroot( $dir );
	}

	/* ─────────────────────────── Upload ─────────────────────────── */

	/**
	 * Fehlerantwort.
	 *
	 * @param string $message Meldung.
	 * @param int    $status  HTTP-Status.
	 * @param string $code    Fehlercode.
	 */
	protected static function fail( $message, $status = 400, $code = '' ) {
		$data = array( 'message' => $message );
		if ( '' !== $code ) {
			$data['code'] = $code;
		}
		wp_send_json_error( $data, $status );
	}

	/**
	 * AJAX: Rohdaten-Upload einer Datei.
	 */
	public static function handle_upload() {
		nocache_headers();

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Nonce wird direkt geprüft.
		$nonce   = isset( $_GET['nonce'] ) ? sanitize_text_field( wp_unslash( $_GET['nonce'] ) ) : '';
		$form_id = isset( $_GET['form_id'] ) ? sanitize_key( wp_unslash( $_GET['form_id'] ) ) : '';
		$field   = isset( $_GET['field'] ) ? sanitize_key( wp_unslash( $_GET['field'] ) ) : '';
		$name    = isset( $_GET['name'] ) ? sanitize_file_name( wp_unslash( $_GET['name'] ) ) : '';
		// phpcs:enable

		if ( ! wp_verify_nonce( $nonce, SPS_Ajax_Handler::NONCE_ACTION ) ) {
			self::fail( __( 'Sicherheitsüberprüfung fehlgeschlagen. Bitte laden Sie die Seite neu.', 'smart-portal-suite' ), 403, 'invalid_nonce' );
		}
		if ( '' === $field || '' === $name || '' === $form_id ) {
			self::fail( __( 'Unvollständige Upload-Angaben.', 'smart-portal-suite' ) );
		}

		// Formular muss existieren und das Feld als Upload-Feld enthalten.
		$schema = SPS_Form_Renderer::get_instance()->load_schema( $form_id );
		if ( ! $schema || ! self::schema_has_upload_field( $schema, $field ) ) {
			self::fail( __( 'Dieses Formular nimmt hier keine Dateien an.', 'smart-portal-suite' ) );
		}
		if ( SPS_Form_Renderer::requires_login( $schema ) && ! is_user_logged_in() ) {
			self::fail( __( 'Bitte melden Sie sich an.', 'smart-portal-suite' ), 403 );
		}

		// Rate-Limit je IP.
		$ip_key = 'sps_upl_' . md5( isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : '' );
		$count  = (int) get_transient( $ip_key );
		if ( $count >= self::RATE_LIMIT ) {
			self::fail( __( 'Zu viele Uploads. Bitte später erneut versuchen.', 'smart-portal-suite' ), 429 );
		}
		set_transient( $ip_key, $count + 1, HOUR_IN_SECONDS );

		$ext = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );
		if ( ! isset( self::ALLOWED[ $ext ] ) ) {
			self::fail( __( 'Dieser Dateityp ist nicht erlaubt.', 'smart-portal-suite' ) );
		}

		$max = self::max_size();
		$len = isset( $_SERVER['CONTENT_LENGTH'] ) ? (int) $_SERVER['CONTENT_LENGTH'] : 0;
		if ( $len > $max ) {
			self::fail( sprintf( __( 'Datei ist zu groß (max. %s).', 'smart-portal-suite' ), size_format( $max ) ), 413 );
		}

		$dir = self::dir();
		if ( is_wp_error( $dir ) ) {
			SPS_Diagnostics::log( $dir->get_error_message(), 'error' );
			self::fail( __( 'Upload derzeit nicht möglich.', 'smart-portal-suite' ), 500 );
		}

		$token = bin2hex( random_bytes( 16 ) );
		$path  = $dir . $token . '.bin';

		$stored = self::store_body( $path, $max );
		if ( is_wp_error( $stored ) ) {
			@unlink( $path );
			self::fail( $stored->get_error_message(), 'sps_too_large' === $stored->get_error_code() ? 413 : 400 );
		}

		$type = self::detect_type( $path );
		if ( ! in_array( $type, self::ALLOWED[ $ext ], true ) ) {
			@unlink( $path );
			SPS_Diagnostics::log( sprintf( 'Upload abgelehnt: "%s" hat Inhaltstyp %s.', $name, $type ), 'warning' );
			self::fail( __( 'Der Dateiinhalt passt nicht zur Dateiendung.', 'smart-portal-suite' ) );
		}

		$meta = array(
			'token'   => $token,
			'form_id' => $form_id,
			'field'   => $field,
			'name'    => $name,
			'type'    => self::CANONICAL_TYPE[ $ext ],
			'size'    => (int) $stored,
			'user_id' => get_current_user_id(),
			'created' => time(),
		);
		if ( false === file_put_contents( $dir . $token . '.json', wp_json_encode( $meta ) ) ) {
			@unlink( $path );
			self::fail( __( 'Upload derzeit nicht möglich.', 'smart-portal-suite' ), 500 );
		}

		wp_send_json_success( array(
			'token' => $token,
			'name'  => $name,
			'size'  => (int) $stored,
		) );
	}

	/**
	 * Maximale Dateigröße unter Berücksichtigung von post_max_size.
	 *
	 * @return int
	 */
	public static function max_size() {
		$post_max = function_exists( 'wp_convert_hr_to_bytes' ) ? wp_convert_hr_to_bytes( (string) ini_get( 'post_max_size' ) ) : 0;
		$max      = (int) apply_filters( 'sps_upload_max_size', self::MAX_SIZE );
		return $post_max > 0 ? min( $max, $post_max ) : $max;
	}

	/**
	 * Request-Body in Datei schreiben (mit Größenbegrenzung).
	 *
	 * @param string $path Zielpfad.
	 * @param int    $max  Maximale Größe.
	 * @return int|WP_Error Geschriebene Bytes.
	 */
	protected static function store_body( $path, $max ) {
		$in  = fopen( 'php://input', 'rb' );
		$out = fopen( $path, 'wb' );
		if ( ! $in || ! $out ) {
			return new WP_Error( 'sps_io', __( 'Upload derzeit nicht möglich.', 'smart-portal-suite' ) );
		}

		$bytes = 0;
		while ( ! feof( $in ) ) {
			$chunk = fread( $in, 65536 );
			if ( false === $chunk ) {
				break;
			}
			$bytes += strlen( $chunk );
			if ( $bytes > $max ) {
				fclose( $in );
				fclose( $out );
				return new WP_Error( 'sps_too_large', sprintf( __( 'Datei ist zu groß (max. %s).', 'smart-portal-suite' ), size_format( $max ) ) );
			}
			fwrite( $out, $chunk );
		}
		fclose( $in );
		fclose( $out );

		if ( 0 === $bytes ) {
			return new WP_Error( 'sps_empty', __( 'Leere Datei oder Upload zu groß für den Server.', 'smart-portal-suite' ) );
		}
		return $bytes;
	}

	/**
	 * Inhaltstyp per finfo.
	 *
	 * @param string $path Datei.
	 * @return string
	 */
	protected static function detect_type( $path ) {
		if ( function_exists( 'finfo_open' ) ) {
			$fi = finfo_open( FILEINFO_MIME_TYPE );
			if ( $fi ) {
				$type = finfo_file( $fi, $path );
				finfo_close( $fi );
				if ( $type ) {
					return strtolower( $type );
				}
			}
		}
		if ( function_exists( 'mime_content_type' ) ) {
			$type = mime_content_type( $path );
			if ( $type ) {
				return strtolower( $type );
			}
		}
		return 'application/octet-stream';
	}

	/**
	 * Hat das Schema ein Upload-Feld mit dieser ID?
	 *
	 * @param array  $schema Schema.
	 * @param string $field  Feld-ID (sanitize_key).
	 * @return bool
	 */
	public static function schema_has_upload_field( array $schema, $field ) {
		foreach ( SPS_Answer_Transformer::flatten_steps( $schema ) as $step ) {
			if ( isset( $step['id'], $step['type'] ) && 'upload' === $step['type'] && sanitize_key( $step['id'] ) === $field ) {
				return true;
			}
		}
		return false;
	}

	/* ─────────────────────────── Abholen ─────────────────────────── */

	/**
	 * Tokens aus dem Absende-JSON in Dateilisten auflösen.
	 *
	 * @param mixed  $tokens  field => token[] (aus dem JSON).
	 * @param string $form_id Formular.
	 * @return array|WP_Error field => Liste aus array( 'tmp_name', 'name', 'type', 'size', 'token' ).
	 */
	public static function resolve( $tokens, $form_id ) {
		if ( empty( $tokens ) ) {
			return array();
		}
		if ( ! is_array( $tokens ) ) {
			return new WP_Error( 'sps_tokens', 'Ungültige Datei-Angaben.' );
		}

		$dir = self::dir();
		if ( is_wp_error( $dir ) ) {
			return $dir;
		}

		$files = array();
		$count = 0;
		foreach ( $tokens as $field => $list ) {
			$field = sanitize_key( $field );
			foreach ( (array) $list as $token ) {
				$token = (string) $token;
				if ( ! preg_match( '/^[a-f0-9]{32}$/', $token ) ) {
					return new WP_Error( 'sps_tokens', 'Ungültiges Datei-Token.' );
				}
				if ( ++$count > self::MAX_FILES_PER_SUBMIT ) {
					return new WP_Error( 'sps_tokens', 'Zu viele Dateien.' );
				}
				$meta_file = $dir . $token . '.json';
				$bin       = $dir . $token . '.bin';
				$meta      = file_exists( $meta_file ) ? json_decode( (string) file_get_contents( $meta_file ), true ) : null;

				if ( ! is_array( $meta ) || ! is_readable( $bin ) ) {
					return new WP_Error( 'sps_tokens', 'Hochgeladene Datei nicht mehr vorhanden. Bitte erneut auswählen.' );
				}
				if ( (int) $meta['user_id'] !== get_current_user_id() || $meta['form_id'] !== sanitize_key( $form_id ) || $meta['field'] !== $field ) {
					return new WP_Error( 'sps_tokens', 'Datei-Token gehört nicht zu diesem Formular.' );
				}

				$files[ $field ][] = array(
					'tmp_name' => $bin,
					'name'     => $meta['name'],
					'type'     => $meta['type'],
					'size'     => (int) $meta['size'],
					'token'    => $token,
				);
			}
		}
		return $files;
	}

	/**
	 * Abgeholte Dateien löschen.
	 *
	 * @param array $files Ergebnis von resolve().
	 */
	public static function release( array $files ) {
		$dir = self::dir();
		if ( is_wp_error( $dir ) ) {
			return;
		}
		foreach ( $files as $items ) {
			foreach ( (array) $items as $f ) {
				if ( ! empty( $f['token'] ) && preg_match( '/^[a-f0-9]{32}$/', $f['token'] ) ) {
					@unlink( $dir . $f['token'] . '.bin' );
					@unlink( $dir . $f['token'] . '.json' );
				}
			}
		}
	}

	/**
	 * Cron: veraltete Dateien löschen.
	 *
	 * @param int|null $ttl Alter in Sekunden.
	 * @return int Anzahl gelöschter Uploads.
	 */
	public static function cleanup( $ttl = null ) {
		$dir = self::dir();
		if ( is_wp_error( $dir ) ) {
			return 0;
		}
		$ttl     = null === $ttl ? self::STAGING_TTL : (int) $ttl;
		$limit   = time() - $ttl;
		$removed = 0;
		foreach ( (array) glob( $dir . '*.bin' ) as $bin ) {
			if ( filemtime( $bin ) < $limit ) {
				@unlink( $bin );
				@unlink( substr( $bin, 0, -4 ) . '.json' );
				$removed++;
			}
		}
		foreach ( (array) glob( $dir . '*.json' ) as $json ) {
			if ( filemtime( $json ) < $limit && ! file_exists( substr( $json, 0, -5 ) . '.bin' ) ) {
				@unlink( $json );
			}
		}
		return $removed;
	}
}
