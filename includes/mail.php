<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function ril_email_layout( $inner ) {
	$s    = ril_settings();
	$logo = ril_logo_url();
	$top  = $logo
		? '<div style="text-align:center;margin-bottom:24px"><img src="' . esc_url( $logo ) . '" alt="' . esc_attr( $s['studio_name'] ) . '" style="max-width:200px;max-height:80px;height:auto"></div>'
		: '<div style="font-size:11px;letter-spacing:.14em;text-transform:uppercase;color:#6d6d68;margin-bottom:16px">' . esc_html( $s['studio_name'] ) . '</div>';
	return '<div style="background:#f7f6f2;padding:32px 16px;font-family:Helvetica,Arial,sans-serif;color:#262626;font-size:15px;line-height:1.6">'
		. '<div style="max-width:540px;margin:0 auto;background:#fff;border-radius:20px;padding:36px 32px">'
		. $top . $inner . '</div></div>';
}

function ril_email_button( $href, $label ) {
	return '<a href="' . esc_url( $href ) . '" style="display:inline-block;background:#262626;color:#f5f3ed;text-decoration:none;padding:12px 22px;border-radius:999px;font-size:14px">' . esc_html( $label ) . '</a>';
}

/** Endereço de envio padrão: o e-mail do estúdio, se for do mesmo domínio do site; senão, contact@domínio-do-site. */
function ril_default_from_email() {
	$s      = ril_settings();
	$domain = strtolower( preg_replace( '/^www\./i', '', (string) wp_parse_url( home_url(), PHP_URL_HOST ) ) );
	if ( $s['email'] && $domain && substr( strtolower( $s['email'] ), -strlen( '@' . $domain ) ) === '@' . $domain ) {
		return $s['email'];
	}
	return ( $domain && false !== strpos( $domain, '.' ) ) ? 'contact@' . $domain : get_option( 'admin_email' );
}

/** Envia pelo wp_mail do site. Devolve true/false; nunca derruba a ação principal. */
function ril_mail( $to, $subject, $html, $attachments = array() ) {
	$s       = ril_settings();
	$headers = array( 'Content-Type: text/html; charset=UTF-8' );
	$name    = str_replace( array( '"', "\r", "\n" ), '', $s['from_name'] ? $s['from_name'] : $s['studio_name'] );
	$from    = is_email( $s['from_email'] ) ? $s['from_email'] : ril_default_from_email();
	$headers[] = 'From: "' . $name . '" <' . $from . '>';
	if ( $s['email'] ) {
		$headers[] = 'Reply-To: "' . $name . '" <' . $s['email'] . '>';
	}
	try {
		return (bool) wp_mail( $to, $subject, $html, $headers, $attachments );
	} catch ( Throwable $e ) {
		return false;
	}
}

/** Troca {first_names}, {client_names}, {studio_name} no texto. */
function ril_email_vars( $text, $c ) {
	$s = ril_settings();
	return strtr( $text, array(
		'{first_names}'  => ril_first_names( $c['client_name'] ),
		'{client_names}' => $c['client_name'],
		'{studio_name}'  => $s['studio_name'],
	) );
}

/** Texto simples do e-mail → HTML (parágrafos), com o botão onde estiver {button}. */
function ril_email_body_html( $text, $c, $button_html ) {
	$html = '';
	$text = ril_email_vars( str_replace( "\r\n", "\n", $text ), $c );
	if ( false === strpos( $text, '{button}' ) ) {
		$text .= "\n\n{button}";
	}
	foreach ( preg_split( '/\n\s*\n/', trim( $text ) ) as $para ) {
		if ( '{button}' === trim( $para ) ) {
			$html .= '<p style="margin:28px 0;text-align:center">' . $button_html . '</p>';
		} else {
			$html .= '<p style="margin:0 0 14px">' . nl2br( esc_html( $para ) ) . '</p>';
		}
	}
	return $html;
}

function ril_mail_link_to_client( $c ) {
	$s       = ril_settings();
	$button  = ril_email_button( ril_client_url( $c['token'] ), $s['email_button'] ? $s['email_button'] : 'Review & sign your contract' );
	$html    = ril_email_layout( ril_email_body_html( $s['email_body'], $c, $button ) );
	$subject = ril_email_vars( $s['email_subject'] ? $s['email_subject'] : 'Your contract with ' . $s['studio_name'], $c );
	return ril_mail( $c['client_email'], $subject, $html );
}

function ril_mail_signed_to_studio( $c ) {
	$s = ril_settings();
	if ( ! $s['email'] ) {
		return false;
	}
	$html = ril_email_layout(
		'<p><b>' . esc_html( $c['client_name'] ) . '</b> assinou o contrato <b>' . esc_html( $c['title'] ) . '</b>.</p>'
		. '<p>Falta a sua contrassinatura.</p>'
		. '<p style="margin:24px 0">' . ril_email_button( ril_admin_url( array( 'view' => 'contract', 'id' => $c['id'] ) ), 'Abrir e contrassinar' ) . '</p>'
	);
	return ril_mail( $s['email'], $c['client_name'] . ' assinou: ' . $c['title'], $html );
}

function ril_mail_final( $c, $pdf_bytes ) {
	$s  = ril_settings();
	$to = array_filter( array( $c['client_email'], $s['email'] ) );
	if ( ! $to ) {
		return false;
	}
	$dir  = trailingslashit( get_temp_dir() ) . 'ril-' . wp_generate_password( 8, false );
	wp_mkdir_p( $dir );
	$file = $dir . '/' . ril_pdf_filename( $c );
	file_put_contents( $file, $pdf_bytes );
	$mail = ril_journey_render( 'final', $c );
	$ok   = ril_mail( array_values( $to ), $mail['subject'], $mail['html'], array( $file ) );
	wp_delete_file( $file );
	@rmdir( $dir );
	return $ok;
}

function ril_mail_date_confirmed( $c ) {
	$s = ril_settings();
	if ( ! $c['client_email'] ) {
		return false;
	}
	$html = ril_email_layout(
		'<p>Hi ' . esc_html( ril_first_names( $c['client_name'] ) ) . ',</p>'
		. '<p>Your wedding date is confirmed: <b>' . esc_html( ril_long_date( $c['wedding_date'] ) ) . '</b>. It\'s officially on our calendar and we can\'t wait!</p>'
		. '<p>If anything changes, just reply to this email.</p>'
		. '<p>With love,<br>The ' . esc_html( $s['studio_name'] ) . ' team</p>'
	);
	return ril_mail( $c['client_email'], 'Your wedding date is confirmed!', $html );
}

function ril_mail_contract_updated( $c ) {
	$s    = ril_settings();
	$html = ril_email_layout(
		'<p>Hi ' . esc_html( ril_first_names( $c['client_name'] ) ) . ',</p>'
		. '<p>We made a small update to your contract. Please take a quick look at the new version before signing. It\'s at the same link as before.</p>'
		. '<p style="margin:28px 0;text-align:center">' . ril_email_button( ril_client_url( $c['token'] ), 'Review your updated contract' ) . '</p>'
		. '<p>Any questions, just reply to this email.</p>'
		. '<p>With love,<br>The ' . esc_html( $s['studio_name'] ) . ' team</p>'
	);
	return ril_mail( $c['client_email'], 'Your contract was updated', $html );
}

/** Aviso ao estúdio: o link do casal está para vencer (ou venceu) sem assinatura. */
function ril_mail_link_warning_to_studio( $c ) {
	$s = ril_settings();
	if ( ! $s['email'] ) {
		return false;
	}
	$expired = strtotime( $c['expires_at'] . ' UTC' ) <= time();
	$views   = array_filter( ril_get_events( $c['id'] ), function ( $e ) {
		return 'viewed' === $e['type'];
	} );
	if ( $views ) {
		$last = end( $views );
		$seen = 1 === count( $views ) ? 'O casal abriu o link 1 vez, em ' . ril_local( $last['at'] ) . '.' : 'O casal abriu o link ' . count( $views ) . ' vezes, a última em ' . ril_local( $last['at'] ) . '.';
	} else {
		$seen = 'O casal ainda não abriu o link.';
	}
	$reminded = ril_last_event_at( $c['id'], 'mail_sign_reminder' );
	$html     = ril_email_layout(
		'<p><b>' . esc_html( $c['client_name'] ) . '</b> ainda não assinou o contrato <b>' . esc_html( $c['title'] ) . '</b>.</p>'
		. '<p>' . ( $expired ? 'O link venceu em ' : 'O link vence em ' ) . '<b>' . esc_html( ril_local( $c['expires_at'] ) ) . '</b>.</p>'
		. '<p>' . esc_html( $seen ) . '</p>'
		. ( $reminded && strtotime( $reminded . ' UTC' ) >= ril_link_period_start( $c ) ? '<p>O lembrete para assinar foi enviado ao casal em ' . esc_html( ril_local( $reminded ) ) . '.</p>' : '' )
		. '<p>Para dar mais prazo, abra o contrato e clique em "Renovar link".</p>'
		. '<p style="margin:24px 0">' . ril_email_button( ril_admin_url( array( 'view' => 'contract', 'id' => $c['id'] ) ), 'Abrir o contrato' ) . '</p>'
	);
	return ril_mail( $s['email'], ( $expired ? 'Link venceu sem assinatura: ' : 'Link vence em breve: ' ) . $c['client_name'], $html );
}
