<?php
/**
 * Jornada do casal: e-mails automáticos depois do contrato.
 *
 * signed   = casal assinou (boas-vindas + sinal)
 * final    = estúdio contrassinou (PDF final)
 * receipt  = pagamento marcado como recebido (recibo)
 * balance  = 30 dias antes do casamento (cobrança do saldo)
 * reminder = 7 dias antes, se o saldo ainda estiver em aberto
 *
 * Textos em inglês, na voz da Grasi, editáveis em Configurações.
 * Linha que começa com "??" só aparece se todos os campos dela tiverem valor.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const RIL_BALANCE_DAYS  = 30;
const RIL_REMINDER_DAYS = 7;

function ril_journey_defaults() {
	$sign = "Love,\nGrasi and the Rocker in Love family";
	return array(
		'signed'   => array(
			'label'   => 'Boas-vindas depois que o casal assina',
			'when'    => 'Sai na hora em que o casal assina. Leva o valor do sinal e as formas de pagamento.',
			'on'      => 1,
			'subject' => 'Welcome to the family, {first_names}!',
			'body'    => "Hi {first_names}!\n\n"
				. "You did it! Your contract is signed and your date is officially ours. Welcome to the family!\n\n"
				. "?? To lock everything in, here is your reservation deposit: **{deposit_due}**\n\n"
				. "{payment}\n\n"
				. "What happens next:\n"
				. "We'll countersign and send you the final copy of your contract.\n"
				. "As soon as your deposit lands, you'll get a receipt from me.\n"
				. "A few weeks before the wedding, we'll plan your day together.\n\n"
				. "This is not a photo shoot. It's your day, and we're here to help you live every second of it.\n\n"
				. $sign,
		),
		'final'    => array(
			'label'   => 'Contrato final (com o PDF)',
			'when'    => 'Sai quando vocês contrassinam. O PDF vai anexado. Sempre ligado.',
			'on'      => 1,
			'subject' => "It's official, {first_names}!",
			'body'    => "Hi {first_names}!\n\n"
				. "It's official: your contract is signed by both of us. Your final copy is attached, and you can always find it here:\n\n"
				. "{button}\n\n"
				. "We can't wait to celebrate with you!\n\n"
				. $sign,
		),
		'receipt'  => array(
			'label'   => 'Recibo de pagamento',
			'when'    => 'Sai quando vocês clicam em "Sinal recebido" ou "Pago total" no contrato.',
			'on'      => 1,
			'subject' => 'Got it! Thank you, {first_names}',
			'body'    => "Hi {first_names}!\n\n"
				. "Your payment just landed and I did a little happy dance here. Thank you!\n\n"
				. "Received: {amount_paid} on {payment_date}\n"
				. "Paid so far: {total_paid} of {total}\n"
				. "?? Still to go: {balance_due}, due by your wedding day\n"
				. "?? {all_paid}\n\n"
				. "Keep this email as your receipt. Any question, just reply here. It comes straight to me.\n\n"
				. $sign,
		),
		'balance'  => array(
			'label'   => 'Cobrança do saldo (30 dias antes)',
			'when'    => 'Sai sozinho 30 dias antes do casamento, se ainda houver saldo.',
			'on'      => 1,
			'subject' => '{days_left} to go!',
			'body'    => "Hi {first_names}!\n\n"
				. "Can you believe it? Your wedding is {days_left} away and we are already counting the days with you.\n\n"
				. "There is just one little thing left on our list: your balance of **{balance_due}**.\n\n"
				. "{payment}\n\n"
				. "Once this is done, you can forget about everything and just live your day. Real moments, real people, no posing marathon. That's the whole point.\n\n"
				. "See you soon,\nGrasi and the Rocker in Love family",
		),
		'reminder' => array(
			'label'   => 'Lembrete do saldo (7 dias antes)',
			'when'    => 'Sai sozinho 7 dias antes do casamento, só se o saldo ainda não estiver marcado como pago.',
			'on'      => 1,
			'subject' => 'Almost there, {first_names}! A friendly nudge',
			'body'    => "Hi {first_names}!\n\n"
				. "{days_left} to go! I'm getting emotional already.\n\n"
				. "Just a friendly nudge that your balance of **{balance_due}** is still open. You can pay here in two minutes:\n\n"
				. "{payment}\n\n"
				. "If you already sent it, ignore me and reply so I can mark it. Can't wait to see you two!\n\n"
				. "Grasi and the Rocker in Love family",
		),
	);
}

/** Configuração de um e-mail (texto salvo ou o padrão). */
function ril_journey( $key ) {
	$defaults = ril_journey_defaults();
	$saved    = get_option( 'ril_journey_emails', array() );
	$row      = isset( $saved[ $key ] ) && is_array( $saved[ $key ] ) ? $saved[ $key ] : array();
	$out      = $defaults[ $key ];
	foreach ( array( 'on', 'subject', 'body' ) as $f ) {
		if ( isset( $row[ $f ] ) && ( 'on' === $f || '' !== trim( (string) $row[ $f ] ) ) ) {
			$out[ $f ] = $row[ $f ];
		}
	}
	if ( 'final' === $key ) {
		$out['on'] = 1;
	}
	return $out;
}

/* ---------- Valores ---------- */

/** Quanto ainda falta pagar no total, para um status de pagamento. */
function ril_amount_due( $c, $status = null ) {
	$status = $status ? $status : $c['payment_status'];
	list( $prepaid ) = ril_prepaid( $c );
	if ( 'paid_in_full' === $status ) {
		return 0;
	}
	if ( 'deposit_paid' === $status ) {
		return ril_balance_cents( $c['total_cents'], $c['deposit_cents'], $prepaid );
	}
	return max( 0, (int) $c['total_cents'] - (int) $prepaid );
}

function ril_days_label( $days ) {
	return 1 === (int) $days ? '1 day' : (int) $days . ' days';
}

/** Dias até o casamento, no fuso do site (null sem data). */
function ril_days_to_wedding( $c ) {
	if ( empty( $c['wedding_date'] ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $c['wedding_date'] ) ) {
		return null;
	}
	$today = new DateTime( wp_date( 'Y-m-d' ) );
	$wed   = new DateTime( $c['wedding_date'] );
	return (int) $today->diff( $wed )->format( '%r%a' );
}

/** Bloco de pagamento (valor, Zelle, PayPal com taxa, link da página). */
function ril_payment_block_html( $c, $amount ) {
	if ( $amount <= 0 ) {
		return '';
	}
	$s    = ril_settings();
	$html = '<div style="background:#f7f6f2;border-radius:16px;padding:20px;margin:6px 0 18px;text-align:center">';
	$html .= '<div style="font-size:26px;font-weight:600;margin-bottom:6px">' . esc_html( ril_money( $amount ) ) . '</div>';
	if ( $s['payment_instructions'] ) {
		$html .= '<div style="text-align:left;font-size:14px;margin:12px 0;background:#fff;border-radius:12px;padding:12px 14px">' . nl2br( esc_html( $s['payment_instructions'] ) ) . '</div>';
	}
	if ( $s['paypal_link'] ) {
		$fee   = (int) round( $amount * (float) $s['paypal_fee_percent'] / 100 );
		$html .= '<p style="margin:14px 0 6px">' . ril_email_button( ril_paypal_href( $s['paypal_link'], $amount + $fee ), 'Pay with PayPal · ' . ril_money( $amount + $fee ) ) . '</p>';
		if ( $fee > 0 ) {
			$html .= '<div style="font-size:12px;color:#6d6d68">Includes the ' . esc_html( rtrim( rtrim( number_format( (float) $s['paypal_fee_percent'], 2 ), '0' ), '.' ) ) . '% PayPal fee. No fee with Zelle, cash or check.</div>';
		}
	}
	$html .= '<div style="font-size:12px;margin-top:12px"><a href="' . esc_url( ril_client_url( $c['token'] ) ) . '" style="color:#262626">See all payment details on your contract page</a></div>';
	return $html . '</div>';
}

/**
 * Monta assunto e HTML de um e-mail da jornada.
 * $extra: valores calculados na hora (amount_paid, payment_date, ...).
 */
function ril_journey_render( $key, $c, $extra = array() ) {
	$cfg      = ril_journey( $key );
	$s        = ril_settings();
	$due      = isset( $extra['due'] ) ? (int) $extra['due'] : ril_amount_due( $c );
	$days     = ril_days_to_wedding( $c );
	$dep_due  = ( 'unpaid' === $c['payment_status'] && $c['deposit_cents'] > 0 ) ? (int) $c['deposit_cents'] : 0;
	$pay_for  = isset( $extra['pay'] ) ? (int) $extra['pay'] : 0;
	$vars     = array(
		'{first_names}'  => ril_first_names( $c['client_name'] ),
		'{client_names}' => $c['client_name'],
		'{studio_name}'  => $s['studio_name'],
		'{total}'        => ril_money( $c['total_cents'] ),
		'{deposit}'      => ril_money( $c['deposit_cents'] ),
		'{deposit_due}'  => $dep_due ? ril_money( $dep_due ) : '',
		'{balance_due}'  => $due > 0 ? ril_money( $due ) : '',
		'{all_paid}'     => $due > 0 ? '' : "You're all paid up. Now the only thing left is to enjoy!",
		'{amount_paid}'  => isset( $extra['amount_paid'] ) ? ril_money( $extra['amount_paid'] ) : '',
		'{total_paid}'   => ril_money( max( 0, (int) $c['total_cents'] - $due ) ),
		'{payment_date}' => wp_date( 'F j, Y' ),
		'{wedding_date}' => $c['wedding_date'] ? ril_long_date( $c['wedding_date'] ) : '',
		'{days_left}'    => null === $days ? '' : ril_days_label( max( 0, $days ) ),
	);
	$blocks = array(
		'{payment}' => ril_payment_block_html( $c, $pay_for ),
		'{button}'  => '<p style="margin:24px 0;text-align:center">' . ril_email_button( ril_client_url( $c['token'] ), 'View your contract' ) . '</p>',
	);

	// Linhas "??": só ficam se todos os campos tiverem valor.
	$lines = array();
	foreach ( preg_split( '/\r?\n/', (string) $cfg['body'] ) as $line ) {
		if ( preg_match( '/^\?\?\s?(.*)$/', $line, $m ) ) {
			preg_match_all( '/\{[a-z_]+\}/', $m[1], $ph );
			$keep = true;
			foreach ( $ph[0] as $p ) {
				if ( isset( $vars[ $p ] ) && '' === $vars[ $p ] ) {
					$keep = false;
				}
			}
			if ( ! $keep ) {
				continue;
			}
			$line = $m[1];
		}
		$lines[] = $line;
	}
	$html = '';
	foreach ( preg_split( '/\n\s*\n/', trim( implode( "\n", $lines ) ) ) as $para ) {
		$t = trim( $para );
		if ( isset( $blocks[ $t ] ) ) {
			$html .= $blocks[ $t ];
			continue;
		}
		$p = nl2br( esc_html( strtr( $para, $vars ) ) );
		$p = preg_replace( '/\*\*(.+?)\*\*/', '<b>$1</b>', $p );
		$html .= '<p style="margin:0 0 14px">' . $p . '</p>';
	}
	return array(
		'subject' => trim( strtr( (string) $cfg['subject'], $vars ) ),
		'html'    => ril_email_layout( $html ),
	);
}

function ril_has_event( $contract_id, $type ) {
	global $wpdb;
	return (bool) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . ril_table( 'events' ) . ' WHERE contract_id = %d AND type = %s LIMIT 1', $contract_id, $type ) );
}

function ril_last_event_at( $contract_id, $type ) {
	global $wpdb;
	return $wpdb->get_var( $wpdb->prepare( 'SELECT at FROM ' . ril_table( 'events' ) . ' WHERE contract_id = %d AND type = %s ORDER BY at DESC, id DESC LIMIT 1', $contract_id, $type ) );
}

/** Envia um e-mail da jornada ao casal e registra no histórico. Devolve true/false. */
function ril_journey_send( $key, $c, $extra = array(), $attachments = array(), $cc_studio = false ) {
	$cfg = ril_journey( $key );
	$to  = $c['client_email'];
	if ( empty( $cfg['on'] ) || ! is_email( $to ) ) {
		return false;
	}
	$mail = ril_journey_render( $key, $c, $extra );
	$s    = ril_settings();
	$list = array( $to );
	if ( $cc_studio && $s['email'] && $s['email'] !== $to ) {
		$list[] = $s['email'];
	}
	$ok = ril_mail( $list, $mail['subject'], $mail['html'], $attachments );
	ril_add_event( $c['id'], $ok ? 'mail_' . $key : 'email_failed', array( 'to' => $to, 'about' => $key ) );
	return $ok;
}

/* ---------- Gatilhos ---------- */

/** Casal assinou. */
function ril_journey_on_signed( $contract_id ) {
	$c = ril_get_contract( $contract_id );
	if ( $c && ! ril_has_event( $c['id'], 'mail_signed' ) ) {
		$pay = ( 'unpaid' === $c['payment_status'] ) ? (int) $c['deposit_cents'] : 0;
		ril_journey_send( 'signed', $c, array( 'pay' => $pay ) );
	}
}

/** Pagamento marcado. $before = status anterior. */
function ril_journey_on_payment( $contract_id, $before ) {
	$c = ril_get_contract( $contract_id );
	if ( ! $c || ! in_array( $c['payment_status'], array( 'deposit_paid', 'paid_in_full' ), true ) ) {
		return null;
	}
	$was  = ril_amount_due( $c, $before );
	$now  = ril_amount_due( $c );
	$paid = $was - $now;
	if ( $paid <= 0 ) {
		return null;
	}
	return ril_journey_send( 'receipt', $c, array( 'amount_paid' => $paid, 'due' => $now ) );
}

/** Rotina diária: cobrança do saldo e lembrete. */
function ril_journey_daily() {
	global $wpdb;
	$rows = $wpdb->get_results(
		'SELECT id FROM ' . ril_table( 'contracts' ) . " WHERE status IN ('signed','completed') AND payment_status <> 'paid_in_full' AND wedding_date <> '' AND client_email <> ''",
		ARRAY_A
	);
	$sent = 0;
	foreach ( (array) $rows as $r ) {
		$c = ril_get_contract( (int) $r['id'] );
		if ( ! $c || ! empty( $c['soft_hold'] ) ) {
			continue;
		}
		$days = ril_days_to_wedding( $c );
		$due  = ril_amount_due( $c );
		if ( null === $days || $days < 0 || $due <= 0 ) {
			continue;
		}
		if ( $days <= RIL_BALANCE_DAYS && ! ril_has_event( $c['id'], 'mail_balance' ) ) {
			$sent += ril_journey_send( 'balance', $c, array( 'pay' => $due ) ) ? 1 : 0;
			continue;
		}
		$last = ril_last_event_at( $c['id'], 'mail_balance' );
		if ( $days <= RIL_REMINDER_DAYS && $last && ! ril_has_event( $c['id'], 'mail_reminder' ) && strtotime( $last . ' UTC' ) <= time() - 2 * DAY_IN_SECONDS ) {
			$sent += ril_journey_send( 'reminder', $c, array( 'pay' => $due ) ) ? 1 : 0;
		}
	}
	update_option( 'ril_journey_last_run', array( 'at' => ril_now(), 'sent' => $sent ), false );
	return $sent;
}

add_action( 'ril_journey_daily', 'ril_journey_daily' );

add_action( 'init', function () {
	if ( ! wp_next_scheduled( 'ril_journey_daily' ) ) {
		// Todo dia às 9h no fuso do site.
		$next = strtotime( wp_date( 'Y-m-d' ) . ' 09:00:00 ' . wp_timezone_string() );
		if ( $next < time() ) {
			$next += DAY_IN_SECONDS;
		}
		wp_schedule_event( $next, 'daily', 'ril_journey_daily' );
	}
} );

/** Próximo e-mail automático deste contrato (para mostrar no painel). */
function ril_journey_next( $c ) {
	if ( ! in_array( ril_status( $c ), array( 'signed', 'completed' ), true ) || 'paid_in_full' === $c['payment_status'] || ril_amount_due( $c ) <= 0 ) {
		return '';
	}
	if ( ! empty( $c['soft_hold'] ) || empty( $c['wedding_date'] ) ) {
		return 'Cobrança do saldo: sai quando a data do casamento for confirmada.';
	}
	$wed = strtotime( $c['wedding_date'] . ' 12:00:00' );
	if ( ! ril_has_event( $c['id'], 'mail_balance' ) ) {
		return ril_journey( 'balance' )['on'] ? 'Cobrança do saldo: sai em ' . wp_date( 'd/m/Y', max( time(), $wed - RIL_BALANCE_DAYS * DAY_IN_SECONDS ) ) . '.' : 'Cobrança do saldo automática está desligada.';
	}
	if ( ! ril_has_event( $c['id'], 'mail_reminder' ) && ril_journey( 'reminder' )['on'] ) {
		return 'Lembrete do saldo: sai em ' . wp_date( 'd/m/Y', max( time(), $wed - RIL_REMINDER_DAYS * DAY_IN_SECONDS ) ) . ', se ainda não estiver pago.';
	}
	return '';
}
