<?php
/**
 * Formato dos modelos:
 *   # Título / ## Seção / - item de lista / linha em branco separa parágrafos
 *   {{Campo}}  preenchido pelo estúdio ao criar o contrato
 *   [[Campo]]  preenchido pelo casal antes de assinar
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const RIL_STUDIO_RE = '/\{\{\s*([^{}]+?)\s*\}\}/u';
const RIL_CLIENT_RE = '/\[\[\s*([^\[\]]+?)\s*\]\]/u';

function ril_auto_fields() {
	return array( 'studio_name', 'client_names', 'wedding_date', 'total', 'deposit', 'balance', 'today' );
}

function ril_unique_fields( $body, $re ) {
	preg_match_all( $re, $body, $m );
	$out = array();
	foreach ( $m[1] as $name ) {
		$name = trim( $name );
		if ( ! in_array( $name, $out, true ) ) {
			$out[] = $name;
		}
	}
	return $out;
}

/** "Coverage hours: 6 hours | 9 hours" → nome "Coverage hours" e opções. */
function ril_parse_field( $raw ) {
	$raw = trim( $raw );
	$pos = strpos( $raw, ':' );
	if ( false !== $pos && false !== strpos( $raw, '|' ) ) {
		$opts = array_values( array_filter( array_map( 'trim', explode( '|', substr( $raw, $pos + 1 ) ) ), 'strlen' ) );
		return array( 'name' => trim( substr( $raw, 0, $pos ) ), 'options' => $opts );
	}
	return array( 'name' => $raw, 'options' => array() );
}

/** Campos do estúdio: nome => opções (lista vazia = campo livre). */
function ril_studio_fields( $body ) {
	preg_match_all( RIL_STUDIO_RE, $body, $m );
	$out = array();
	foreach ( $m[1] as $raw ) {
		$f = ril_parse_field( $raw );
		if ( in_array( $f['name'], ril_auto_fields(), true ) ) {
			continue;
		}
		if ( ! isset( $out[ $f['name'] ] ) || ( ! $out[ $f['name'] ] && $f['options'] ) ) {
			$out[ $f['name'] ] = $f['options'];
		}
	}
	return $out;
}

function ril_studio_field_names( $body ) {
	return array_keys( ril_studio_fields( $body ) );
}

function ril_client_field_names( $body ) {
	return ril_unique_fields( $body, RIL_CLIENT_RE );
}

function ril_fill_studio( $body, $values ) {
	return preg_replace_callback( RIL_STUDIO_RE, function ( $m ) use ( $values ) {
		$k = ril_parse_field( $m[1] )['name'];
		return isset( $values[ $k ] ) && '' !== $values[ $k ] ? $values[ $k ] : $m[0];
	}, $body );
}

function ril_fill_client( $body, $values ) {
	return preg_replace_callback( RIL_CLIENT_RE, function ( $m ) use ( $values ) {
		$k = trim( $m[1] );
		return isset( $values[ $k ] ) ? $values[ $k ] : '';
	}, $body );
}

function ril_has_unfilled( $body ) {
	return (bool) preg_match( RIL_STUDIO_RE, $body );
}

/** Divide o texto em blocos: h1, h2, li, p. */
function ril_blocks( $text ) {
	$blocks = array();
	$para   = array();
	$flush  = function () use ( &$para, &$blocks ) {
		if ( $para ) {
			$blocks[] = array( 'type' => 'p', 'text' => implode( "\n", $para ) );
		}
		$para = array();
	};
	foreach ( explode( "\n", str_replace( "\r\n", "\n", $text ) ) as $raw ) {
		$line = rtrim( $raw );
		if ( '' === trim( $line ) ) {
			$flush();
		} elseif ( 0 === strpos( $line, '## ' ) ) {
			$flush();
			$blocks[] = array( 'type' => 'h2', 'text' => trim( substr( $line, 3 ) ) );
		} elseif ( 0 === strpos( $line, '# ' ) ) {
			$flush();
			$blocks[] = array( 'type' => 'h1', 'text' => trim( substr( $line, 2 ) ) );
		} elseif ( preg_match( '/^[-•]\s+/u', $line ) ) {
			$flush();
			$blocks[] = array( 'type' => 'li', 'text' => preg_replace( '/^[-•]\s+/u', '', $line ) );
		} else {
			$para[] = $line;
		}
	}
	$flush();
	return $blocks;
}

/** Tipo do campo pelo nome: "date" → calendário, "time" → horário, "phone" → telefone. */
function ril_field_type( $name ) {
	if ( preg_match( '/\bdate\b/i', $name ) ) {
		return 'date';
	}
	if ( preg_match( '/\btime\b/i', $name ) ) {
		return 'time';
	}
	if ( preg_match( '/phone/i', $name ) ) {
		return 'tel';
	}
	if ( preg_match( '/e-?mail/i', $name ) ) {
		return 'email';
	}
	return 'text';
}

/** Valor digitado → texto do contrato ("2027-06-12" → "June 12, 2027", "16:30" → "4:30 pm"). */
function ril_format_client_value( $name, $value ) {
	$type = ril_field_type( $name );
	if ( 'date' === $type && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) {
		return ril_long_date( $value );
	}
	if ( 'time' === $type && preg_match( '/^(\d{1,2}):(\d{2})/', $value, $m ) ) {
		$h = (int) $m[1];
		return ( 0 === $h % 12 ? 12 : $h % 12 ) . ':' . $m[2] . ' ' . ( $h < 12 ? 'am' : 'pm' );
	}
	return $value;
}

/** Campo da data do casamento (o que pode virar soft hold). */
function ril_hold_field( $body ) {
	$first_date = '';
	foreach ( ril_client_field_names( $body ) as $name ) {
		if ( 'date' === ril_field_type( $name ) ) {
			if ( preg_match( '/wedding|event/i', $name ) ) {
				return $name;
			}
			if ( ! $first_date ) {
				$first_date = $name;
			}
		}
	}
	return $first_date;
}

/** Campos do mesmo bloco da data (horário, locais): ficam opcionais no soft hold. */
function ril_event_fields( $body ) {
	$hold = ril_hold_field( $body );
	if ( ! $hold ) {
		return array();
	}
	foreach ( ril_blocks( $body ) as $b ) {
		$names = ril_client_field_names( $b['text'] );
		if ( in_array( $hold, $names, true ) ) {
			return array_values( array_diff( $names, array( $hold ) ) );
		}
	}
	return array();
}

function ril_month_label( $ym ) {
	return preg_match( '/^\d{4}-\d{2}$/', (string) $ym ) ? gmdate( 'F Y', strtotime( $ym . '-15 12:00:00 UTC' ) ) : (string) $ym;
}

/** HTML da opção "só sabemos o mês" (vai logo depois do campo da data). */
function ril_hold_toggle_html( $ctx ) {
	$on    = ! empty( $ctx['softhold'] );
	$month = isset( $ctx['hold_month'] ) ? (int) $ctx['hold_month'] : 0;
	$year  = isset( $ctx['hold_year'] ) ? (int) $ctx['hold_year'] : 0;
	$html  = '<span class="ril-hold" data-ril-hold>';
	$html .= '<label class="ril-hold-check"><input type="checkbox" name="softhold" value="1"' . checked( $on, true, false ) . '> <span>We don\'t have the exact date yet — hold the month</span></label>';
	$html .= '<span class="ril-hold-pick"' . ( $on ? '' : ' hidden' ) . '><select name="hold_month" aria-label="Month"><option value="">Month</option>';
	for ( $m = 1; $m <= 12; $m++ ) {
		$html .= '<option value="' . $m . '"' . selected( $month, $m, false ) . '>' . gmdate( 'F', mktime( 12, 0, 0, $m, 1, 2000 ) ) . '</option>';
	}
	$html .= '</select><select name="hold_year" aria-label="Year"><option value="">Year</option>';
	$y0 = (int) gmdate( 'Y' );
	for ( $y = $y0; $y <= $y0 + 4; $y++ ) {
		$html .= '<option value="' . $y . '"' . selected( $year, $y, false ) . '>' . $y . '</option>';
	}
	$html .= '</select></span></span>';
	return $html;
}

/** Texto com os campos do casal: viram inputs (edit) ou valores destacados (view). */
function ril_render_inline( $text, $mode, $values, $placeholder = 'Fill in', $ctx = array() ) {
	$parts = preg_split( '/(\[\[\s*[^\[\]]+?\s*\]\])/u', $text, -1, PREG_SPLIT_DELIM_CAPTURE );
	$html  = '';
	foreach ( $parts as $part ) {
		if ( preg_match( '/^\[\[\s*([^\[\]]+?)\s*\]\]$/u', $part, $m ) ) {
			$name = trim( $m[1] );
			$val  = isset( $values[ $name ] ) ? $values[ $name ] : '';
			if ( 'edit' === $mode ) {
				$type    = ril_field_type( $name );
				$is_hold = ! empty( $ctx['hold'] ) && $ctx['hold'] === $name;
				$is_evt  = ! empty( $ctx['event'] ) && in_array( $name, $ctx['event'], true );
				$soft    = ! empty( $ctx['softhold'] );
				$html .= sprintf(
					'<input type="%4$s" class="ril-blank ril-blank-%4$s" name="f[%1$s]" data-blank="%1$s" aria-label="%1$s" placeholder="%2$s" value="%3$s"%5$s%6$s>',
					esc_attr( $name ),
					esc_attr( $placeholder . ': ' . $name ),
					esc_attr( $val ),
					esc_attr( $type ),
					( $is_hold || $is_evt ) && $soft ? '' : ' required',
					$is_hold ? ' data-ril-hold-date' . ( $soft ? ' hidden' : '' ) : ( $is_evt ? ' data-ril-event' : '' )
				);
				if ( $is_hold ) {
					$html .= ril_hold_toggle_html( $ctx );
				}
			} elseif ( '' !== $val ) {
				$html .= '<span class="ril-filled">' . esc_html( $val ) . '</span>';
			} else {
				$html .= '<span class="ril-missing">[' . esc_html( $name ) . ']</span>';
			}
		} else {
			$html .= esc_html( $part );
		}
	}
	return $html;
}

/** O contrato em HTML. */
function ril_render_doc( $title, $body, $mode = 'view', $values = array(), $header_html = '', $placeholder = 'Fill in', $ctx = array() ) {
	if ( 'edit' === $mode && ! isset( $ctx['hold'] ) ) {
		$ctx['hold']  = ril_hold_field( $body );
		$ctx['event'] = ril_event_fields( $body );
	}
	$blocks = ril_blocks( $body );
	$out    = '<article class="ril-doc"><div class="ril-doc-head">' . $header_html . '<h1 class="ril-doc-title">' . esc_html( $title ) . '</h1></div>';
	$in_ul  = false;
	foreach ( $blocks as $i => $b ) {
		$inner = ril_render_inline( $b['text'], $mode, $values, $placeholder, $ctx );
		if ( 'li' === $b['type'] ) {
			if ( ! $in_ul ) {
				$out  .= '<ul>';
				$in_ul = true;
			}
			$out .= '<li>' . $inner . '</li>';
			continue;
		}
		if ( $in_ul ) {
			$out  .= '</ul>';
			$in_ul = false;
		}
		if ( 'h1' === $b['type'] ) {
			if ( 0 === $i && trim( $b['text'] ) === trim( $title ) ) {
				continue;
			}
			$out .= '<h2 class="ril-doc-h1">' . $inner . '</h2>';
		} elseif ( 'h2' === $b['type'] ) {
			$out .= '<h3 class="ril-doc-h2">' . $inner . '</h3>';
		} else {
			$out .= '<p>' . $inner . '</p>';
		}
	}
	if ( $in_ul ) {
		$out .= '</ul>';
	}
	return $out . '</article>';
}
