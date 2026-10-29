<?php
/**
 * Serverseitige Aufbereitung der Formularantworten vor der Übertragung.
 *
 * 1. Schlüssel normalisieren (klein, "-" → "_"), damit WP-Feld-IDs und
 *    Nextcloud-Fragenamen unabhängig von Schreibweise zusammenpassen.
 * 2. Werte nach `dataType` des Schemas casten (int/double/bool).
 * 3. Deklarative Regeln aus `submit.rules` des Schemas ausführen
 *    (set / convert / remove). Damit lassen sich formularspezifische
 *    Ableitungen ohne PHP-Code im Schema beschreiben.
 *
 * Regel-Beispiele:
 *   { "set": "fenster", "value": "Kunststofffenster, Isolierverglasung",
 *     "when": { "fenster_typ": "Kunststofffenster" } }
 *   { "set": "baujahr", "from": "baujahr_detail", "type": "int",
 *     "when": { "baujahr": { "gte": 2002 }, "baujahr_detail": { "exists": true } } }
 *   { "convert": "waermeverbrauch", "original": "waermeverbrauch-original", "kwh": "waermeverbrauch-kwh" }
 *   { "remove": [ "aussenwand_typ", "baujahr_detail" ] }
 *
 * Bedingungen: Skalar = Gleichheit (als Text, getrimmt). Objekt mit
 * eq, ne, in, nin, gt, gte, lt, lte, exists. Mehrere Felder = UND.
 * `ne`/`nin` sind auch erfüllt, wenn das Feld fehlt.
 *
 * @package SmartPortalSuite
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SPS_Answer_Transformer {

	/**
	 * Schlüssel normalisieren.
	 *
	 * @param string $key Schlüssel.
	 * @return string
	 */
	public static function norm( $key ) {
		$key = strtolower( trim( (string) $key ) );
		$key = str_replace( array( '-', ' ' ), '_', $key );
		return preg_replace( '/_+/', '_', $key );
	}

	/**
	 * Nur Übersetzungsregeln anwenden: { "set": k, "value": neu, "when": { k: alt } }.
	 *
	 * Für bereits aufbereitete Fallback-Leads: Diese Regeln sind wiederholbar,
	 * die übrigen (Ableitungen aus Rohfeldern) nicht.
	 *
	 * @param array      $answers Aufbereitete Antworten.
	 * @param array|null $schema  Formular-Schema.
	 * @return array
	 */
	public static function apply_value_translations( array $answers, $schema = null ) {
		if ( ! is_array( $schema ) || empty( $schema['submit']['rules'] ) || ! is_array( $schema['submit']['rules'] ) ) {
			return $answers;
		}
		foreach ( $schema['submit']['rules'] as $rule ) {
			if ( ! is_array( $rule ) || empty( $rule['set'] ) || ! array_key_exists( 'value', $rule ) || empty( $rule['when'] ) || ! is_array( $rule['when'] ) || 1 !== count( $rule['when'] ) ) {
				continue;
			}
			$key = self::norm( $rule['set'] );
			$old = current( $rule['when'] );
			if ( self::norm( key( $rule['when'] ) ) !== $key || ! is_scalar( $old ) ) {
				continue;
			}
			if ( isset( $answers[ $key ] ) && is_scalar( $answers[ $key ] ) && trim( (string) $answers[ $key ] ) === trim( (string) $old ) ) {
				$answers[ $key ] = $rule['value'];
			}
		}
		return $answers;
	}

	/**
	 * Antworten aufbereiten.
	 *
	 * @param array      $answers Bereinigte Antworten aus dem Frontend.
	 * @param array|null $schema  Formular-Schema.
	 * @return array Normalisierte, abgeleitete Antworten.
	 */
	public static function apply( array $answers, $schema = null ) {
		$out = array();
		foreach ( $answers as $key => $value ) {
			$out[ self::norm( $key ) ] = $value;
		}

		if ( ! is_array( $schema ) ) {
			return $out;
		}

		$out = self::cast_types( $out, $schema );

		$rules = isset( $schema['submit']['rules'] ) && is_array( $schema['submit']['rules'] ) ? $schema['submit']['rules'] : array();
		foreach ( $rules as $rule ) {
			if ( is_array( $rule ) ) {
				$out = self::apply_rule( $out, $rule, $schema );
			}
		}

		return $out;
	}

	/**
	 * Alle Schritte inkl. Gruppen-/Zeilenfelder flach auflisten.
	 *
	 * @param array $schema Schema.
	 * @return array norm(id) => step/field.
	 */
	public static function flatten_steps( $schema ) {
		$flat  = array();
		$stack = isset( $schema['steps'] ) && is_array( $schema['steps'] ) ? $schema['steps'] : array();

		while ( ! empty( $stack ) ) {
			$item = array_shift( $stack );
			if ( ! is_array( $item ) ) {
				continue;
			}
			if ( ! empty( $item['id'] ) ) {
				$flat[ self::norm( $item['id'] ) ] = $item;
			}
			if ( ! empty( $item['fields'] ) && is_array( $item['fields'] ) ) {
				foreach ( $item['fields'] as $f ) {
					$stack[] = $f;
				}
			}
		}

		return $flat;
	}

	/**
	 * Werte gemäß dataType casten.
	 *
	 * @param array $answers Antworten.
	 * @param array $schema  Schema.
	 * @return array
	 */
	protected static function cast_types( array $answers, array $schema ) {
		foreach ( self::flatten_steps( $schema ) as $key => $step ) {
			if ( ! array_key_exists( $key, $answers ) || empty( $step['dataType'] ) ) {
				continue;
			}
			$answers[ $key ] = self::cast( $answers[ $key ], $step['dataType'] );
		}
		return $answers;
	}

	/**
	 * Einzelwert casten (wie castValue() im alten Frontend).
	 *
	 * @param mixed  $value Wert.
	 * @param string $type  int|double|float|number|bool|string.
	 * @return mixed
	 */
	public static function cast( $value, $type ) {
		if ( is_array( $value ) || null === $value || '' === $value ) {
			return $value;
		}
		switch ( strtolower( (string) $type ) ) {
			case 'int':
			case 'integer':
				return is_numeric( $value ) ? (int) (float) $value : $value; // wie parseInt()
			case 'double':
			case 'float':
			case 'number':
				return is_numeric( $value ) ? (float) $value : $value;
			case 'bool':
			case 'boolean':
				return in_array( strtolower( (string) $value ), array( '1', 'true', 'yes', 'ja', 'on' ), true );
			default:
				return $value;
		}
	}

	/**
	 * Eine Regel anwenden.
	 *
	 * @param array $answers Antworten.
	 * @param array $rule    Regel.
	 * @param array $schema  Schema.
	 * @return array
	 */
	protected static function apply_rule( array $answers, array $rule, array $schema ) {
		if ( isset( $rule['when'] ) && ! self::matches( $answers, (array) $rule['when'] ) ) {
			return $answers;
		}

		if ( isset( $rule['remove'] ) ) {
			foreach ( (array) $rule['remove'] as $key ) {
				unset( $answers[ self::norm( $key ) ] );
			}
			return $answers;
		}

		if ( isset( $rule['convert'] ) ) {
			return self::apply_conversion( $answers, $rule, $schema );
		}

		if ( isset( $rule['set'] ) ) {
			$target = self::norm( $rule['set'] );
			if ( array_key_exists( 'value', $rule ) ) {
				$value = $rule['value'];
			} elseif ( isset( $rule['from'] ) ) {
				$from = self::norm( $rule['from'] );
				if ( ! array_key_exists( $from, $answers ) ) {
					return $answers;
				}
				$value = $answers[ $from ];
			} else {
				return $answers;
			}
			if ( ! empty( $rule['type'] ) ) {
				$value = self::cast( $value, $rule['type'] );
			}
			$answers[ $target ] = $value;
		}

		return $answers;
	}

	/**
	 * Bedingungen prüfen (UND-Verknüpfung).
	 *
	 * @param array $answers    Antworten.
	 * @param array $conditions Feld => Bedingung.
	 * @return bool
	 */
	public static function matches( array $answers, array $conditions ) {
		foreach ( $conditions as $field => $cond ) {
			$key    = self::norm( $field );
			$exists = array_key_exists( $key, $answers ) && null !== $answers[ $key ] && '' !== $answers[ $key ];
			$value  = $exists ? $answers[ $key ] : null;

			if ( ! is_array( $cond ) || self::is_list( $cond ) ) {
				$cond = is_array( $cond ) ? array( 'in' => $cond ) : array( 'eq' => $cond );
			}

			foreach ( $cond as $op => $expected ) {
				if ( ! self::check( $op, $exists, $value, $expected ) ) {
					return false;
				}
			}
		}
		return true;
	}

	/**
	 * Einzelnen Operator prüfen.
	 *
	 * @param string $op       Operator.
	 * @param bool   $exists   Feld vorhanden.
	 * @param mixed  $value    Wert.
	 * @param mixed  $expected Vergleichswert.
	 * @return bool
	 */
	protected static function check( $op, $exists, $value, $expected ) {
		switch ( $op ) {
			case 'exists':
				return (bool) $expected === $exists;
			case 'eq':
				return $exists && self::same( $value, $expected );
			case 'ne':
				return ! $exists || ! self::same( $value, $expected );
			case 'in':
				if ( ! $exists ) {
					return false;
				}
				foreach ( (array) $expected as $e ) {
					if ( self::same( $value, $e ) ) {
						return true;
					}
				}
				return false;
			case 'nin':
				if ( ! $exists ) {
					return true;
				}
				foreach ( (array) $expected as $e ) {
					if ( self::same( $value, $e ) ) {
						return false;
					}
				}
				return true;
			case 'gt':
			case 'gte':
			case 'lt':
			case 'lte':
				if ( ! $exists || ! is_numeric( $value ) || ! is_numeric( $expected ) ) {
					return false;
				}
				$a = (float) $value;
				$b = (float) $expected;
				if ( 'gt' === $op ) {
					return $a > $b;
				}
				if ( 'gte' === $op ) {
					return $a >= $b;
				}
				if ( 'lt' === $op ) {
					return $a < $b;
				}
				return $a <= $b;
			default:
				return false;
		}
	}

	/**
	 * Werte als Text vergleichen.
	 *
	 * @param mixed $a Wert.
	 * @param mixed $b Wert.
	 * @return bool
	 */
	protected static function same( $a, $b ) {
		if ( is_array( $a ) ) {
			foreach ( $a as $item ) {
				if ( self::same( $item, $b ) ) {
					return true;
				}
			}
			return false;
		}
		if ( is_bool( $a ) || is_bool( $b ) ) {
			return (bool) $a === (bool) $b;
		}
		if ( is_numeric( $a ) && is_numeric( $b ) ) {
			return (float) $a === (float) $b;
		}
		return trim( (string) $a ) === trim( (string) $b );
	}

	/**
	 * Ist das Array eine Liste?
	 *
	 * @param array $arr Array.
	 * @return bool
	 */
	protected static function is_list( array $arr ) {
		return array() === $arr || array_keys( $arr ) === range( 0, count( $arr ) - 1 );
	}

	/**
	 * Einheiten-Umrechnung (z. B. Wärmeverbrauch) anhand der `conversion`-
	 * Konfiguration des Schritts. Erzeugt Originalwert ("1.531 Liter") und kWh.
	 *
	 * @param array $answers Antworten.
	 * @param array $rule    Regel.
	 * @param array $schema  Schema.
	 * @return array
	 */
	protected static function apply_conversion( array $answers, array $rule, array $schema ) {
		$source = self::norm( $rule['convert'] );
		if ( ! array_key_exists( $source, $answers ) || ! is_numeric( $answers[ $source ] ) ) {
			return $answers;
		}

		$steps = self::flatten_steps( $schema );
		$conv  = isset( $steps[ $source ]['conversion'] ) ? $steps[ $source ]['conversion'] : array();
		$kwh   = (float) $answers[ $source ];

		$factor = 1.0;
		$unit   = isset( $rule['unit'] ) ? (string) $rule['unit'] : 'kWh';

		if ( ! empty( $conv['dependsOn'] ) && ! empty( $conv['factors'] ) ) {
			$dep_key = self::norm( $conv['dependsOn'] );
			$dep_val = isset( $answers[ $dep_key ] ) ? (string) $answers[ $dep_key ] : '';
			if ( '' !== $dep_val && isset( $conv['factors'][ $dep_val ] ) ) {
				$cfg  = $conv['factors'][ $dep_val ];
				$unit = isset( $cfg['unit'] ) ? (string) $cfg['unit'] : $unit;
				if ( ! empty( $cfg['factorField'] ) ) {
					$ff     = self::norm( $cfg['factorField'] );
					$factor = ( isset( $answers[ $ff ] ) && is_numeric( $answers[ $ff ] ) && (float) $answers[ $ff ] > 0 )
						? (float) $answers[ $ff ]
						: ( isset( $cfg['defaultFactor'] ) ? (float) $cfg['defaultFactor'] : 1.0 );
				} elseif ( isset( $cfg['factor'] ) && (float) $cfg['factor'] > 0 ) {
					$factor = (float) $cfg['factor'];
				}
			}
		}

		if ( ! empty( $rule['original'] ) ) {
			$answers[ self::norm( $rule['original'] ) ] = self::format_de( $kwh / $factor ) . ' ' . $unit;
		}
		if ( ! empty( $rule['kwh'] ) ) {
			$answers[ self::norm( $rule['kwh'] ) ] = (int) round( $kwh );
		}

		return $answers;
	}

	/**
	 * Zahl im deutschen Format ohne Nachkommastellen (wie Intl de-DE).
	 *
	 * @param float $value Zahl.
	 * @return string
	 */
	public static function format_de( $value ) {
		return number_format( round( (float) $value ), 0, ',', '.' );
	}
}
