<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function ril_money( $cents ) {
	return '$' . number_format( ( (int) $cents ) / 100, 2, '.', ',' );
}

/** "1,500.00", "1500", "$1.500,00" → centavos. */
/** Saldo até o casamento: total menos sinal menos o que já foi pago antes. */
function ril_balance_cents( $total, $deposit, $prepaid = 0 ) {
	return max( 0, (int) $total - (int) $deposit - (int) $prepaid );
}

/** "40%" (porcentagem do sinal sobre o total). */
function ril_percent_label( $part, $total ) {
	if ( $total <= 0 ) {
		return '';
	}
	return rtrim( rtrim( number_format( $part * 100 / $total, 1, '.', '' ), '0' ), '.' ) . '%';
}

/** Pagamento antecipado guardado no contrato: [centavos, descrição]. */
function ril_prepaid( $c ) {
	$f = isset( $c['studio_fields'] ) ? (array) $c['studio_fields'] : array();
	return array( isset( $f['_prepaid_cents'] ) ? (int) $f['_prepaid_cents'] : 0, isset( $f['_prepaid_note'] ) ? (string) $f['_prepaid_note'] : '' );
}

/** Sinal padrão a partir do total, pela porcentagem das Configurações. */
function ril_default_deposit( $total ) {
	$s = ril_settings();
	return (int) round( $total * (float) $s['deposit_percent'] / 100 );
}

function ril_parse_money( $input ) {
	$clean = preg_replace( '/[^\d.,]/', '', (string) $input );
	if ( '' === $clean ) {
		return 0;
	}
	$last_comma = strrpos( $clean, ',' );
	$last_dot   = strrpos( $clean, '.' );
	if ( false !== $last_comma && ( false === $last_dot || $last_comma > $last_dot ) && strlen( $clean ) - $last_comma - 1 <= 2 ) {
		$clean = str_replace( '.', '', $clean );
		$clean = str_replace( ',', '.', $clean );
	} else {
		$clean = str_replace( ',', '', $clean );
	}
	return (int) round( ( (float) $clean ) * 100 );
}

/** "2027-06-12" → "June 12, 2027" (sempre em inglês, como o contrato). */
function ril_long_date( $ymd ) {
	if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $ymd ) ) {
		return (string) $ymd;
	}
	return gmdate( 'F j, Y', strtotime( $ymd . ' 12:00:00 UTC' ) );
}

/** Data UTC do banco → horário do site, para o painel. */
function ril_local( $utc ) {
	if ( ! $utc ) {
		return '';
	}
	return wp_date( 'd/m/Y H:i', strtotime( $utc . ' UTC' ) );
}

function ril_utc_label( $utc ) {
	return $utc ? gmdate( 'Y-m-d H:i:s', strtotime( $utc . ' UTC' ) ) . ' UTC' : '';
}

function ril_request_meta() {
	$ip = '';
	if ( ! empty( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ) {
		$ip = $_SERVER['HTTP_CF_CONNECTING_IP'];
	} elseif ( ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
		$ip = trim( explode( ',', $_SERVER['HTTP_X_FORWARDED_FOR'] )[0] );
	} elseif ( ! empty( $_SERVER['REMOTE_ADDR'] ) ) {
		$ip = $_SERVER['REMOTE_ADDR'];
	}
	$ip = substr( preg_replace( '/[^0-9a-fA-F:.]/', '', (string) $ip ), 0, 64 );
	$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ), 0, 400 ) : '';
	return array( 'ip' => $ip ? $ip : 'unknown', 'ua' => $ua );
}

function ril_new_token() {
	return rtrim( strtr( base64_encode( random_bytes( 24 ) ), '+/', '-_' ), '=' );
}

/** Link público do casal. */
function ril_client_url( $token, $pdf = false ) {
	if ( get_option( 'permalink_structure' ) ) {
		return home_url( '/contract/' . $token . '/' . ( $pdf ? 'pdf/' : '' ) );
	}
	$args = array( 'ril_contract' => $token );
	if ( $pdf ) {
		$args['ril_pdf'] = '1';
	}
	return add_query_arg( $args, home_url( '/' ) );
}

function ril_admin_url( $args = array() ) {
	return add_query_arg( array_merge( array( 'page' => 'ril-contracts' ), $args ), admin_url( 'admin.php' ) );
}

function ril_month_pt( $ym ) {
	$m = array( '', 'janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho', 'julho', 'agosto', 'setembro', 'outubro', 'novembro', 'dezembro' );
	if ( ! preg_match( '/^(\d{4})-(\d{2})$/', (string) $ym, $x ) ) {
		return (string) $ym;
	}
	return $m[ (int) $x[2] ] . ' ' . $x[1];
}

/** Telefone do casal (campo Phone do contrato), só dígitos, com +1 se tiver 10 dígitos. */
function ril_client_phone_digits( $c ) {
	foreach ( (array) $c['client_fields'] as $k => $v ) {
		if ( 'tel' === ril_field_type( $k ) ) {
			$d = preg_replace( '/\D/', '', (string) $v );
			if ( 10 === strlen( $d ) ) {
				$d = '1' . $d;
			}
			return strlen( $d ) >= 10 ? $d : '';
		}
	}
	return '';
}

function ril_client_phone( $c ) {
	foreach ( (array) $c['client_fields'] as $k => $v ) {
		if ( 'tel' === ril_field_type( $k ) ) {
			return (string) $v;
		}
	}
	return '';
}

function ril_status_labels() {
	return array(
		'draft'     => 'Rascunho',
		'sent'      => 'Enviado',
		'signed'    => 'Casal assinou',
		'completed' => 'Concluído',
		'canceled'  => 'Cancelado',
		'expired'   => 'Expirado',
	);
}

function ril_payment_labels() {
	return array(
		'unpaid'       => 'Sinal pendente',
		'deposit_paid' => 'Sinal pago',
		'paid_in_full' => 'Pago',
	);
}

function ril_event_labels() {
	return array(
		'created'       => 'Contrato criado',
		'edited'        => 'Contrato editado',
		'sent'          => 'Enviado ao casal',
		'email_sent'    => 'E-mail enviado',
		'email_failed'  => 'E-mail não foi enviado',
		'viewed'        => 'Casal abriu o link',
		'signed'        => 'Casal assinou',
		'countersigned' => 'Estúdio contrassinou',
		'completed'     => 'Contrato concluído',
		'canceled'      => 'Cancelado',
		'deposit_paid'  => 'Sinal marcado como pago',
		'paid_in_full'  => 'Pagamento total marcado',
		'unpaid'        => 'Pagamento voltou a pendente',
		'date_confirmed' => 'Data do casamento confirmada',
	);
}

/** Valida e limpa a imagem da assinatura (PNG em data URL). */
function ril_clean_signature_image( $raw ) {
	$raw = (string) $raw;
	if ( 0 !== strpos( $raw, 'data:image/png;base64,' ) || strlen( $raw ) < 200 || strlen( $raw ) > 700000 ) {
		return '';
	}
	$bin = base64_decode( substr( $raw, 22 ), true );
	if ( false === $bin || "\x89PNG" !== substr( $bin, 0, 4 ) ) {
		return '';
	}
	return 'data:image/png;base64,' . base64_encode( $bin );
}

function ril_pdf_filename( $c ) {
	$name = trim( preg_replace( '/[^A-Za-z0-9 &\-]+/', '', $c['title'] . ' - ' . $c['client_name'] ) );
	return ( $name ? $name : 'contract' ) . '.pdf';
}

/** Envia o PDF (o guardado, se o contrato estiver concluído). */
function ril_send_pdf( $c ) {
	if ( ! empty( $c['pdf'] ) ) {
		$bytes = base64_decode( $c['pdf'] );
	} else {
		$bytes = ril_build_pdf( $c );
	}
	nocache_headers();
	header( 'Content-Type: application/pdf' );
	header( 'Content-Disposition: inline; filename="' . ril_pdf_filename( $c ) . '"' );
	header( 'Content-Length: ' . strlen( $bytes ) );
	header( 'X-Robots-Tag: noindex, nofollow' );
	echo $bytes; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	exit;
}
