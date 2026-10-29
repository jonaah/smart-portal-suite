<?php
/**
 * Nextcloud Forms API v3 Service
 *
 * Submission-Format (Forms API v3):
 *   POST ocs/v2.php/apps/forms/api/v3/forms/{formId}/submissions
 *   { "answers": { "<questionId>": [ <werte> ] } }
 * Auch Textantworten sind Arrays; Auswahlfragen erwarten Options-IDs;
 * Dateifragen Objekte aus dem Upload-Endpunkt.
 *
 * @package SmartPortalSuite\Nextcloud
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SPS_NC_Forms {

	/**
	 * Fragetypen mit Antwortoptionen.
	 */
	const OPTION_TYPES = array( 'multiple', 'multiple_unique', 'dropdown' );

	/**
	 * Client für die Submission (Benutzer oder Service-Account).
	 *
	 * @var SPS_NC_Client
	 */
	protected $client;

	/**
	 * Client für lesende Zugriffe auf Formulardefinitionen (immer Service-Account).
	 *
	 * @var SPS_NC_Client
	 */
	protected $service_client;

	/**
	 * Constructor.
	 *
	 * @param SPS_NC_Client|null $client Client für die Submission.
	 */
	public function __construct( $client = null ) {
		$this->service_client = SPS_NC_Client::service();
		$this->client         = $client ? $client : $this->service_client;
	}

	/**
	 * @return SPS_NC_Client
	 */
	public function get_client() {
		return $this->client;
	}

	/* ─────────────────────────── Formular finden ─────────────────────────── */

	/**
	 * Nextcloud-Formular-ID zu einem SPS-Schema ermitteln.
	 *
	 * Reihenfolge: Einstellung (wp sps configure --forms) → `nc_form_id` im Schema
	 * → Titelsuche (`nc_form_title`, `form_type`, `title`).
	 *
	 * @param array|string $schema_or_title Schema oder Titel.
	 * @return int|WP_Error
	 */
	public function resolve_form_id( $schema_or_title ) {
		if ( is_array( $schema_or_title ) ) {
			$schema  = $schema_or_title;
			$form_id = isset( $schema['form_id'] ) ? sanitize_key( $schema['form_id'] ) : '';

			$map = SPS_Settings::get_form_id_map();
			foreach ( array( $form_id, str_replace( '-', '_', $form_id ), str_replace( '_', '-', $form_id ) ) as $k ) {
				if ( '' !== $k && ! empty( $map[ $k ] ) ) {
					return (int) $map[ $k ];
				}
			}

			if ( ! empty( $schema['nc_form_id'] ) && is_numeric( $schema['nc_form_id'] ) ) {
				return (int) $schema['nc_form_id'];
			}

			$titles = array();
			foreach ( array( 'nc_form_title', 'form_type', 'title' ) as $k ) {
				if ( ! empty( $schema[ $k ] ) ) {
					$titles[] = (string) $schema[ $k ];
				}
			}
		} else {
			$titles = array( (string) $schema_or_title );
		}

		$titles = array_values( array_unique( array_filter( $titles ) ) );
		if ( empty( $titles ) ) {
			return new WP_Error( 'sps_form_unknown', 'Kein Nextcloud-Formular zugeordnet (weder ID noch Titel).' );
		}

		$forms = $this->list_forms();
		if ( is_wp_error( $forms ) ) {
			return $forms;
		}

		foreach ( $titles as $title ) {
			foreach ( $forms as $form ) {
				if ( isset( $form['id'], $form['title'] ) && 0 === strcasecmp( trim( $form['title'] ), trim( $title ) ) ) {
					return (int) $form['id'];
				}
			}
		}

		return new WP_Error(
			'not_found',
			sprintf( 'Kein Nextcloud-Formular mit Titel "%s" für Service-Account gefunden. Formular-ID per "wp sps configure --forms=<form_id>=<id>" setzen.', implode( '" / "', $titles ) )
		);
	}

	/**
	 * Alle Formulare, die der Service-Account besitzt oder die mit ihm geteilt sind.
	 *
	 * @return array|WP_Error Liste aus array( 'id', 'title', ... ).
	 */
	public function list_forms() {
		$all = array();
		foreach ( array( 'owned', 'shared' ) as $type ) {
			$res = SPS_NC_Client::parse_ocs( $this->service_client->request( 'ocs/v2.php/apps/forms/api/v3/forms?type=' . $type ) );
			if ( is_wp_error( $res ) ) {
				if ( 'owned' === $type ) {
					return $res;
				}
				continue;
			}
			if ( isset( $res['forms'] ) && is_array( $res['forms'] ) ) {
				$res = $res['forms'];
			}
			foreach ( (array) $res as $form ) {
				if ( is_array( $form ) && isset( $form['id'] ) ) {
					$all[ (int) $form['id'] ] = $form;
				}
			}
		}
		return array_values( $all );
	}

	/**
	 * Vollständige Formulardefinition (inkl. Fragen, Freigaben).
	 *
	 * @param int  $form_id   Form ID.
	 * @param bool $use_cache Cache verwenden.
	 * @return array|WP_Error
	 */
	public function get_form( $form_id, $use_cache = true ) {
		$key = 'sps_nc_form_' . (int) $form_id;
		if ( $use_cache ) {
			$cached = get_transient( $key );
			if ( is_array( $cached ) ) {
				return $cached;
			}
		}

		$res = SPS_NC_Client::parse_ocs( $this->service_client->request( 'ocs/v2.php/apps/forms/api/v3/forms/' . (int) $form_id ) );
		if ( is_wp_error( $res ) ) {
			return $res;
		}

		set_transient( $key, $res, 15 * MINUTE_IN_SECONDS );
		return $res;
	}

	/**
	 * Fragen eines Formulars.
	 *
	 * @param int  $form_id   Form ID.
	 * @param bool $use_cache Cache verwenden.
	 * @return array|WP_Error
	 */
	public function get_questions( $form_id, $use_cache = true ) {
		$form = $this->get_form( $form_id, $use_cache );
		if ( is_wp_error( $form ) ) {
			return $form;
		}
		return isset( $form['questions'] ) && is_array( $form['questions'] ) ? $form['questions'] : array();
	}

	/**
	 * Cache eines Formulars löschen.
	 *
	 * @param int $form_id Form ID.
	 */
	public static function flush_cache( $form_id ) {
		delete_transient( 'sps_nc_form_' . (int) $form_id );
	}

	/* ─────────────────────────── Zuordnung ─────────────────────────── */

	/**
	 * Antworten den Nextcloud-Fragen zuordnen.
	 *
	 * Zuordnung je Frage über den technischen Namen (`name`):
	 *  1. explizit über `submit.map` im Schema ({"nc-name": "feld" | ["feld1","feld2"]})
	 *  2. gleicher Name (Groß-/Kleinschreibung und "-"/"_" egal)
	 *  3. zusammengesetzt: "plz_ort" → Antworten "plz" + "ort", mit Leerzeichen verbunden
	 *
	 * @param array      $questions Fragen aus der Forms-API.
	 * @param array      $answers   Mit SPS_Answer_Transformer aufbereitete Antworten.
	 * @param array|null $schema    Schema (für Options-Texte und `submit.map`).
	 * @return array {
	 *     @type array $answers  questionId (string) => array Werte (ohne Dateien)
	 *     @type array $files    questionId (string) => Antwortschlüssel der Dateien
	 *     @type array $report   Zeilen je Frage (name, id, type, source, status, value)
	 *     @type array $warnings Warnungen
	 * }
	 */
	public function build_submission( array $questions, array $answers, $schema = null ) {
		$answers = $this->normalize_keys( $answers );
		$choices = $this->choice_text_lookup( $schema );
		$map     = array();
		if ( is_array( $schema ) && ! empty( $schema['submit']['map'] ) && is_array( $schema['submit']['map'] ) ) {
			foreach ( $schema['submit']['map'] as $nc_name => $src ) {
				$map[ SPS_Answer_Transformer::norm( $nc_name ) ] = array_map( array( 'SPS_Answer_Transformer', 'norm' ), (array) $src );
			}
		}

		$result = array(
			'answers'  => array(),
			'files'    => array(),
			'report'   => array(),
			'warnings' => array(),
		);

		foreach ( $questions as $q ) {
			if ( ! is_array( $q ) || ! isset( $q['id'] ) ) {
				continue;
			}
			$qid      = (string) $q['id'];
			$type     = isset( $q['type'] ) ? (string) $q['type'] : 'short';
			$name     = isset( $q['name'] ) ? trim( (string) $q['name'] ) : '';
			$required = ! empty( $q['isRequired'] );
			$row      = array(
				'id'       => $qid,
				'name'     => $name,
				'type'     => $type,
				'required' => $required,
				'source'   => '',
				'status'   => '',
				'value'    => '',
			);

			if ( '' === $name ) {
				$row['status'] = 'kein technischer Name';
				if ( $required ) {
					$result['warnings'][] = sprintf( 'Pflichtfrage #%s "%s" hat keinen technischen Namen und kann nicht befüllt werden.', $qid, isset( $q['text'] ) ? $q['text'] : '' );
				}
				$result['report'][] = $row;
				continue;
			}

			$key = SPS_Answer_Transformer::norm( $name );

			// Dateifragen: Upload erfolgt separat.
			// Ob tatsächlich Dateien vorliegen, entscheidet der Aufrufer.
			if ( 'file' === $type ) {
				$src                     = isset( $map[ $key ][0] ) ? $map[ $key ][0] : $key;
				$result['files'][ $qid ] = $src;
				$row['source']           = $src;
				$row['status']           = 'Datei';
				$result['report'][]      = $row;
				continue;
			}

			list( $value, $source ) = $this->find_value( $key, $answers, $map );

			if ( null === $value ) {
				$row['status'] = $required ? 'FEHLT (Pflicht)' : 'leer';
				if ( $required ) {
					$result['warnings'][] = sprintf( 'Pflichtfrage "%s" ohne Antwort.', $name );
				}
				$result['report'][] = $row;
				continue;
			}

			$row['source'] = $source;
			$source_keys   = explode( '+', $source );

			if ( in_array( $type, self::OPTION_TYPES, true ) ) {
				$values = $this->match_options( $q, $value, $choices, $source_keys );
				if ( empty( $values ) ) {
					$row['status']        = 'keine passende Option';
					$row['value']         = $this->stringify( $value, $choices, $source_keys );
					$result['warnings'][] = sprintf( 'Antwort "%s" passt zu keiner Option der Frage "%s".', $row['value'], $name );
					$result['report'][]   = $row;
					continue;
				}
				$result['answers'][ $qid ] = $values;
				$row['value']              = implode( ', ', $values );
			} else {
				$text                      = $this->stringify( $value, $choices, $source_keys, false );
				$result['answers'][ $qid ] = array( $text );
				$row['value']              = $text;
			}

			$row['status']      = 'ok';
			$result['report'][] = $row;
		}

		return $result;
	}

	/**
	 * Antwortschlüssel normalisieren (falls nicht bereits geschehen).
	 *
	 * @param array $answers Antworten.
	 * @return array
	 */
	protected function normalize_keys( array $answers ) {
		$out = array();
		foreach ( $answers as $k => $v ) {
			$out[ SPS_Answer_Transformer::norm( $k ) ] = $v;
		}
		return $out;
	}

	/**
	 * Wert für einen NC-Fragenamen suchen.
	 *
	 * @param string $key     Normalisierter NC-Name.
	 * @param array  $answers Antworten.
	 * @param array  $map     Explizite Zuordnung.
	 * @return array array( Wert|null, Quelle )
	 */
	protected function find_value( $key, array $answers, array $map ) {
		if ( isset( $map[ $key ] ) ) {
			return $this->collect( $map[ $key ], $answers );
		}

		if ( array_key_exists( $key, $answers ) && ! $this->is_empty( $answers[ $key ] ) ) {
			return array( $answers[ $key ], $key );
		}

		if ( false !== strpos( $key, '_' ) ) {
			return $this->collect( explode( '_', $key ), $answers );
		}

		return array( null, '' );
	}

	/**
	 * Mehrere Antworten sammeln (zusammengesetztes Feld).
	 *
	 * @param string[] $keys    Antwortschlüssel.
	 * @param array    $answers Antworten.
	 * @return array array( Wert|null, Quelle )
	 */
	protected function collect( array $keys, array $answers ) {
		if ( 1 === count( $keys ) ) {
			$k = $keys[0];
			return ( isset( $answers[ $k ] ) && ! $this->is_empty( $answers[ $k ] ) ) ? array( $answers[ $k ], $k ) : array( null, '' );
		}

		$parts = array();
		$used  = array();
		foreach ( $keys as $k ) {
			if ( isset( $answers[ $k ] ) && ! $this->is_empty( $answers[ $k ] ) && ! is_array( $answers[ $k ] ) ) {
				$parts[] = $this->scalar_to_string( $answers[ $k ] );
				$used[]  = $k;
			}
		}
		if ( empty( $parts ) ) {
			return array( null, '' );
		}
		return array( implode( ' ', $parts ), implode( '+', $used ) );
	}

	/**
	 * Leerer Wert? (0 und "0" sind NICHT leer.)
	 *
	 * @param mixed $v Wert.
	 * @return bool
	 */
	protected function is_empty( $v ) {
		if ( null === $v || '' === $v ) {
			return true;
		}
		if ( is_array( $v ) ) {
			return 0 === count( array_filter( $v, function ( $x ) {
				return null !== $x && '' !== $x;
			} ) );
		}
		return false;
	}

	/**
	 * Lookup Schritt → (Wert|ID) → Anzeigetext aus dem Schema.
	 *
	 * @param array|null $schema Schema.
	 * @return array norm(step id) => array( (string) value => text )
	 */
	protected function choice_text_lookup( $schema ) {
		$lookup = array();
		if ( ! is_array( $schema ) ) {
			return $lookup;
		}
		foreach ( SPS_Answer_Transformer::flatten_steps( $schema ) as $key => $step ) {
			if ( empty( $step['choices'] ) || ! is_array( $step['choices'] ) ) {
				continue;
			}
			foreach ( $step['choices'] as $choice ) {
				if ( ! is_array( $choice ) ) {
					$lookup[ $key ][ (string) $choice ] = (string) $choice;
					continue;
				}
				if ( ! isset( $choice['text'] ) ) {
					continue;
				}
				$text = (string) $choice['text'];
				foreach ( array( 'value', 'id' ) as $k ) {
					if ( isset( $choice[ $k ] ) && '' !== (string) $choice[ $k ] ) {
						$lookup[ $key ][ $this->value_key( $choice[ $k ] ) ] = $text;
					}
				}
				$lookup[ $key ][ $text ] = $text;
			}
		}
		return $lookup;
	}

	/**
	 * Vergleichsschlüssel für Werte (125 == "125" == 125.0).
	 *
	 * @param mixed $v Wert.
	 * @return string
	 */
	protected function value_key( $v ) {
		if ( is_numeric( $v ) ) {
			return (string) ( 0 + $v );
		}
		return trim( (string) $v );
	}

	/**
	 * Antwort(en) den Optionen einer Auswahlfrage zuordnen.
	 *
	 * @param array    $q           Frage.
	 * @param mixed    $value       Antwort.
	 * @param array    $choices     Text-Lookup.
	 * @param string[] $source_keys Quellschlüssel.
	 * @return array Options-IDs (bzw. "system-other-answer:..." wenn erlaubt).
	 */
	protected function match_options( array $q, $value, array $choices, array $source_keys ) {
		$options = isset( $q['options'] ) && is_array( $q['options'] ) ? $q['options'] : array();
		$items   = is_array( $value ) ? $value : array( $value );
		$allow   = ! empty( $q['extraSettings']['allowOtherAnswer'] );
		$out     = array();

		foreach ( $items as $item ) {
			if ( $this->is_empty( $item ) ) {
				continue;
			}
			$candidates = array( $this->scalar_to_string( $item ) );
			foreach ( $source_keys as $sk ) {
				$vk = $this->value_key( $item );
				if ( isset( $choices[ $sk ][ $vk ] ) ) {
					$candidates[] = $choices[ $sk ][ $vk ];
				}
			}

			$found = null;
			foreach ( $candidates as $cand ) {
				foreach ( $options as $opt ) {
					if ( isset( $opt['id'], $opt['text'] ) && $this->text_equals( $opt['text'], $cand ) ) {
						$found = (int) $opt['id'];
						break 2;
					}
				}
			}

			if ( null !== $found ) {
				$out[] = $found;
			} elseif ( $allow ) {
				$out[] = 'system-other-answer:' . end( $candidates );
			}
		}

		return array_values( array_unique( $out, SORT_REGULAR ) );
	}

	/**
	 * Texte tolerant vergleichen (Groß-/Kleinschreibung, Leerzeichen).
	 *
	 * @param string $a Text.
	 * @param string $b Text.
	 * @return bool
	 */
	protected function text_equals( $a, $b ) {
		$n = function ( $s ) {
			$s = preg_replace( '/\s+/u', ' ', trim( (string) $s ) );
			return function_exists( 'mb_strtolower' ) ? mb_strtolower( $s, 'UTF-8' ) : strtolower( $s );
		};
		return $n( $a ) === $n( $b );
	}

	/**
	 * Wert als Text für Freitext-Fragen.
	 *
	 * @param mixed    $value       Wert.
	 * @param array    $choices     Text-Lookup.
	 * @param string[] $source_keys Quellschlüssel.
	 * @param bool     $use_text    Auswahlwerte immer in Anzeigetext umwandeln.
	 * @return string
	 */
	protected function stringify( $value, array $choices, array $source_keys, $use_text = true ) {
		if ( is_array( $value ) ) {
			$parts = array();
			foreach ( $value as $item ) {
				if ( $this->is_empty( $item ) || is_array( $item ) ) {
					continue;
				}
				$text = $this->scalar_to_string( $item );
				foreach ( $source_keys as $sk ) {
					$vk = $this->value_key( $item );
					if ( isset( $choices[ $sk ][ $vk ] ) ) {
						$text = $choices[ $sk ][ $vk ];
						break;
					}
				}
				$parts[] = $text;
			}
			return implode( ', ', $parts );
		}

		$text = $this->scalar_to_string( $value );
		if ( $use_text ) {
			foreach ( $source_keys as $sk ) {
				$vk = $this->value_key( $value );
				if ( isset( $choices[ $sk ][ $vk ] ) ) {
					return $choices[ $sk ][ $vk ];
				}
			}
		}
		return $text;
	}

	/**
	 * Skalar als Text.
	 *
	 * @param mixed $v Wert.
	 * @return string
	 */
	protected function scalar_to_string( $v ) {
		if ( is_bool( $v ) ) {
			return $v ? 'Ja' : 'Nein';
		}
		if ( is_float( $v ) ) {
			return rtrim( rtrim( sprintf( '%.6F', $v ), '0' ), '.' );
		}
		return trim( (string) $v );
	}

	/* ─────────────────────────── Übertragung ─────────────────────────── */

	/**
	 * Dateien zu einer Dateifrage hochladen.
	 *
	 * @param int   $nc_form_id  Form ID.
	 * @param int   $question_id Question ID.
	 * @param array $files       Liste aus array( 'path', 'name', 'type' ).
	 * @return array|WP_Error Antwortwerte für die Submission.
	 */
	public function upload_files( $nc_form_id, $question_id, array $files ) {
		// Upload-Tokens sind an die Nextcloud-Session gebunden: Upload und Absenden teilen die Cookies.
		$this->client->keep_session( true );
		if ( empty( $files ) ) {
			return array();
		}
		$res = SPS_NC_Client::parse_ocs(
			$this->client->upload_multipart(
				sprintf( 'ocs/v2.php/apps/forms/api/v3/forms/%d/submissions/files/%d', (int) $nc_form_id, (int) $question_id ),
				'files[]',
				$files
			)
		);
		if ( is_wp_error( $res ) ) {
			return $res;
		}

		$values = array();
		foreach ( (array) $res as $item ) {
			if ( is_array( $item ) && isset( $item['uploadedFileId'] ) ) {
				$values[] = $item;
			}
		}
		if ( count( $values ) !== count( $files ) ) {
			return new WP_Error( 'nc_upload_incomplete', sprintf( 'Upload unvollständig: %d von %d Dateien bestätigt.', count( $values ), count( $files ) ) );
		}
		return $values;
	}

	/**
	 * Submission an die Forms API v3.
	 *
	 * @param int   $nc_form_id Form ID.
	 * @param array $answers    questionId => array Werte.
	 * @return array|WP_Error
	 */
	public function submit_form( $nc_form_id, array $answers ) {
		$payload = array( 'answers' => (object) $answers );

		$res = $this->client->request(
			sprintf( 'ocs/v2.php/apps/forms/api/v3/forms/%d/submissions', (int) $nc_form_id ),
			'POST',
			wp_json_encode( $payload )
		);

		return SPS_NC_Client::parse_ocs( $res );
	}

	/**
	 * Abwärtskompatibel: alte Signatur (liefert nur die Antworten).
	 *
	 * @deprecated Verwende build_submission().
	 * @param array $nc_questions Fragen.
	 * @param array $wp_answers   Antworten.
	 * @return array
	 */
	public function map_answers( $nc_questions, $wp_answers ) {
		$built = $this->build_submission( (array) $nc_questions, (array) $wp_answers );
		return $built['answers'];
	}
}
