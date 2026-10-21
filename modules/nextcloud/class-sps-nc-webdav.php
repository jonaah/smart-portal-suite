<?php
/**
 * Nextcloud WebDAV Client für die Fallback-Ablage
 *
 * Jeder Lead bekommt einen eigenen Ordner im Basisverzeichnis des
 * Service-Accounts:
 *   <base>/lead_<formular>_<Ymd_His>_<rand>/lead.json
 *   <base>/lead_.../files/<feld>/<datei>
 * Erfolgreich nachgereichte Leads werden nach <base>/_uebertragen/ verschoben.
 *
 * Jeder Schreibzugriff wird über den HTTP-Statuscode geprüft. Ein Lead gilt
 * nur dann als gespeichert, wenn Nextcloud 2xx zurückgibt.
 *
 * @package SmartPortalSuite\Nextcloud
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SPS_NC_WebDAV {

	/**
	 * Unterordner für nachgereichte Leads.
	 */
	const DONE_DIR = '_uebertragen';

	/**
	 * HTTP Client (immer Service-Account).
	 *
	 * @var SPS_NC_Client
	 */
	protected $client;

	/**
	 * Basisverzeichnis relativ zum Home des Service-Accounts.
	 *
	 * @var string
	 */
	protected $base_dir;

	/**
	 * Constructor.
	 *
	 * @param SPS_NC_Client|null $client Optional client instance.
	 */
	public function __construct( $client = null ) {
		$this->client   = $client ? $client : SPS_NC_Client::service();
		$this->base_dir = trim( (string) SPS_Settings::get_setting( 'webdav_base_dir', 'SPS_Leads' ), '/' );
		if ( '' === $this->base_dir ) {
			$this->base_dir = 'SPS_Leads';
		}
	}

	/**
	 * @return string Basisverzeichnis.
	 */
	public function get_base_dir() {
		return $this->base_dir;
	}

	/**
	 * WebDAV-Pfad (URL-kodiert) zu einem relativen Pfad im Home des Service-Accounts.
	 *
	 * @param string $relative Relativer Pfad.
	 * @return string
	 */
	protected function dav_path( $relative ) {
		$segments = array_filter( explode( '/', trim( $relative, '/' ) ), 'strlen' );
		$encoded  = array_map( 'rawurlencode', $segments );
		return 'remote.php/dav/files/' . rawurlencode( $this->client->get_username() ) . '/' . implode( '/', $encoded );
	}

	/**
	 * HTTP-Status als WP_Error auswerten.
	 *
	 * @param array|WP_Error $res     Antwort.
	 * @param int[]          $ok      Akzeptierte Codes.
	 * @param string         $context Kontext für die Meldung.
	 * @return true|WP_Error
	 */
	protected function check( $res, array $ok, $context ) {
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		if ( in_array( $code, $ok, true ) ) {
			return true;
		}
		return new WP_Error( 'nc_webdav_error', sprintf( '%s: HTTP %d', $context, $code ), array( 'http' => $code ) );
	}

	/**
	 * Ordner (rekursiv) anlegen. Vorhandene Ordner sind kein Fehler.
	 *
	 * @param string $relative Relativer Pfad.
	 * @return true|WP_Error
	 */
	public function ensure_dir( $relative ) {
		$path = '';
		foreach ( array_filter( explode( '/', trim( $relative, '/' ) ), 'strlen' ) as $segment ) {
			$path .= '/' . $segment;
			// 201 = angelegt, 405 = existiert bereits.
			$ok = $this->check( $this->client->request( $this->dav_path( $path ), 'MKCOL' ), array( 201, 405 ), 'Ordner ' . $path );
			if ( is_wp_error( $ok ) ) {
				return $ok;
			}
		}
		return true;
	}

	/**
	 * Existiert ein Pfad?
	 *
	 * @param string $relative Relativer Pfad.
	 * @return bool|WP_Error
	 */
	public function exists( $relative ) {
		$res = $this->client->request( $this->dav_path( $relative ), 'PROPFIND', null, array( 'Depth' => '0', 'Content-Type' => 'application/xml' ) );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		if ( 207 === $code ) {
			return true;
		}
		if ( 404 === $code ) {
			return false;
		}
		return new WP_Error( 'nc_webdav_error', sprintf( 'PROPFIND %s: HTTP %d', $relative, $code ) );
	}

	/**
	 * Datei schreiben.
	 *
	 * @param string $relative Relativer Pfad.
	 * @param string $content  Inhalt.
	 * @param string $type     Content-Type.
	 * @return true|WP_Error
	 */
	public function put( $relative, $content, $type = 'application/octet-stream' ) {
		return $this->check(
			$this->client->request( $this->dav_path( $relative ), 'PUT', (string) $content, array( 'Content-Type' => $type ) ),
			array( 200, 201, 204 ),
			'Upload ' . $relative
		);
	}

	/**
	 * Datei lesen.
	 *
	 * @param string $relative Relativer Pfad.
	 * @return string|WP_Error
	 */
	public function get( $relative ) {
		$res = $this->client->request( $this->dav_path( $relative ), 'GET', null, array( 'Accept' => '*/*' ) );
		$ok  = $this->check( $res, array( 200 ), 'Download ' . $relative );
		return is_wp_error( $ok ) ? $ok : wp_remote_retrieve_body( $res );
	}

	/**
	 * Verschieben.
	 *
	 * @param string $from Relativer Quellpfad.
	 * @param string $to   Relativer Zielpfad.
	 * @return true|WP_Error
	 */
	public function move( $from, $to ) {
		$dest = $this->client->get_base_url() . '/' . $this->dav_path( $to );
		return $this->check(
			$this->client->request( $this->dav_path( $from ), 'MOVE', null, array( 'Destination' => $dest, 'Overwrite' => 'F' ) ),
			array( 201, 204 ),
			'Verschieben ' . $from
		);
	}

	/**
	 * Einträge eines Ordners (eine Ebene).
	 *
	 * @param string $relative Relativer Pfad.
	 * @return array|WP_Error Liste aus array( 'name' => ..., 'is_dir' => bool ).
	 */
	public function list_dir( $relative ) {
		$res = $this->client->request( $this->dav_path( $relative ), 'PROPFIND', '<?xml version="1.0"?><d:propfind xmlns:d="DAV:"><d:prop><d:resourcetype/></d:prop></d:propfind>', array(
			'Depth'        => '1',
			'Content-Type' => 'application/xml',
		) );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		if ( 404 === $code ) {
			return array();
		}
		if ( 207 !== $code ) {
			return new WP_Error( 'nc_webdav_error', sprintf( 'PROPFIND %s: HTTP %d', $relative, $code ) );
		}

		$body    = wp_remote_retrieve_body( $res );
		$entries = array();
		if ( preg_match_all( '#<d:response>(.*?)</d:response>#s', $body, $m ) ) {
			$self = rtrim( '/' . $this->dav_path( $relative ), '/' );
			foreach ( $m[1] as $resp ) {
				if ( ! preg_match( '#<d:href>(.*?)</d:href>#s', $resp, $h ) ) {
					continue;
				}
				$href = rtrim( rawurldecode( html_entity_decode( $h[1] ) ), '/' );
				if ( substr( $href, - strlen( rawurldecode( $self ) ) ) === rawurldecode( $self ) ) {
					continue; // Ordner selbst.
				}
				$entries[] = array(
					'name'   => basename( $href ),
					'is_dir' => false !== strpos( $resp, '<d:collection' ),
				);
			}
		}
		return $entries;
	}

	/**
	 * Lead als Fallback ablegen (inkl. hochgeladener Dateien).
	 *
	 * @param array  $lead    Lead-Daten (form_id, answers, raw_answers, reason, …).
	 * @param array  $files   feld => Liste aus array( 'tmp_name', 'name', 'type', 'size' ).
	 * @param string $form_id Formular-ID für den Ordnernamen.
	 * @return string|WP_Error Relativer Pfad des Lead-Ordners.
	 */
	public function store_lead( array $lead, array $files = array(), $form_id = 'lead' ) {
		$folder = sprintf(
			'%s/lead_%s_%s_%s',
			$this->base_dir,
			sanitize_key( $form_id ),
			gmdate( 'Ymd_His' ),
			strtolower( wp_generate_password( 6, false, false ) )
		);

		$ok = $this->ensure_dir( $folder );
		if ( is_wp_error( $ok ) ) {
			return $ok;
		}

		$lead['files'] = array();
		foreach ( $files as $field => $items ) {
			foreach ( (array) $items as $idx => $file ) {
				if ( empty( $file['tmp_name'] ) || ! is_readable( $file['tmp_name'] ) ) {
					continue;
				}
				$name = sprintf( '%02d_%s', $idx + 1, sanitize_file_name( isset( $file['name'] ) ? $file['name'] : 'datei' ) );
				$rel  = 'files/' . sanitize_key( $field ) . '/' . $name;

				$ok = $this->ensure_dir( $folder . '/files/' . sanitize_key( $field ) );
				if ( ! is_wp_error( $ok ) ) {
					$ok = $this->put( $folder . '/' . $rel, file_get_contents( $file['tmp_name'] ), ! empty( $file['type'] ) ? $file['type'] : 'application/octet-stream' );
				}
				if ( is_wp_error( $ok ) ) {
					return $ok;
				}
				$lead['files'][ $field ][] = array(
					'name' => isset( $file['name'] ) ? $file['name'] : $name,
					'path' => $rel,
					'type' => isset( $file['type'] ) ? $file['type'] : '',
					'size' => isset( $file['size'] ) ? (int) $file['size'] : 0,
				);
			}
		}

		$json = wp_json_encode( $lead, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		$ok   = $this->put( $folder . '/lead.json', $json, 'application/json' );
		if ( is_wp_error( $ok ) ) {
			return $ok;
		}

		return $folder;
	}

	/**
	 * Abwärtskompatibel.
	 *
	 * @deprecated Verwende store_lead().
	 * @param array  $lead_data Daten.
	 * @param string $form_id   Formular.
	 * @return string|WP_Error
	 */
	public function store_lead_fallback( array $lead_data, $form_id = 'lead' ) {
		return $this->store_lead( array(
			'version'      => 2,
			'submitted_at' => current_time( 'mysql' ),
			'form_id'      => $form_id,
			'answers'      => $lead_data,
		), array(), $form_id );
	}

	/**
	 * Offene Leads (Ordner mit lead.json im Basisverzeichnis).
	 *
	 * @return string[]|WP_Error Relative Ordnerpfade.
	 */
	public function list_pending_leads() {
		$entries = $this->list_dir( $this->base_dir );
		if ( is_wp_error( $entries ) ) {
			return $entries;
		}
		$out = array();
		foreach ( $entries as $e ) {
			if ( $e['is_dir'] && 0 === strpos( $e['name'], 'lead_' ) ) {
				$out[] = $this->base_dir . '/' . $e['name'];
			}
		}
		sort( $out );
		return $out;
	}

	/**
	 * Lead als erledigt markieren.
	 *
	 * @param string $folder Relativer Lead-Ordner.
	 * @return true|WP_Error
	 */
	public function mark_done( $folder ) {
		$ok = $this->ensure_dir( $this->base_dir . '/' . self::DONE_DIR );
		if ( is_wp_error( $ok ) ) {
			return $ok;
		}
		return $this->move( $folder, $this->base_dir . '/' . self::DONE_DIR . '/' . basename( $folder ) );
	}
}
