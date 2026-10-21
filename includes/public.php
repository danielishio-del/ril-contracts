<?php
/**
 * Página do casal: /contract/{token}/ e /contract/{token}/pdf/
 * Renderiza uma página própria (sem o tema), para não depender do Elementor nem de cache.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function ril_register_rewrite() {
	add_rewrite_rule( '^contract/([A-Za-z0-9_-]{20,})/?$', 'index.php?ril_contract=$matches[1]', 'top' );
	add_rewrite_rule( '^contract/([A-Za-z0-9_-]{20,})/pdf/?$', 'index.php?ril_contract=$matches[1]&ril_pdf=1', 'top' );
}
add_action( 'init', 'ril_register_rewrite' );

// Se a regra sumir (ex.: outro plugin limpou), recria uma vez.
add_action( 'init', function () {
	$rules = get_option( 'rewrite_rules' );
	if ( get_option( 'permalink_structure' ) && is_array( $rules ) && ! isset( $rules['^contract/([A-Za-z0-9_-]{20,})/?$'] ) ) {
		flush_rewrite_rules( false );
	}
}, 99 );

add_filter( 'query_vars', function ( $vars ) {
	$vars[] = 'ril_contract';
	$vars[] = 'ril_pdf';
	return $vars;
} );

add_action( 'template_redirect', 'ril_public_router', 0 );

function ril_public_router() {
	$token = get_query_var( 'ril_contract' );
	if ( ! $token ) {
		return;
	}
	if ( ! defined( 'DONOTCACHEPAGE' ) ) {
		define( 'DONOTCACHEPAGE', true );
	}
	nocache_headers();
	header( 'X-Robots-Tag: noindex, nofollow' );
	header( 'Referrer-Policy: no-referrer' );
	header( 'X-Frame-Options: DENY' );

	$c = ril_get_contract_by_token( sanitize_text_field( $token ) );
	if ( ! $c || 'draft' === $c['status'] ) {
		status_header( 404 );
		ril_public_page( 'Not found', '<div class="ril-card"><p>This link is not valid. Please check with us.</p></div>' );
	}

	if ( get_query_var( 'ril_pdf' ) ) {
		if ( ! in_array( $c['status'], array( 'signed', 'completed' ), true ) ) {
			status_header( 404 );
			exit;
		}
		ril_send_pdf( $c );
	}

	$state = array();
	if ( 'POST' === $_SERVER['REQUEST_METHOD'] && isset( $_POST['ril_sign'] ) ) {
		$state = ril_handle_sign( $c );
		if ( ! empty( $state['ok'] ) ) {
			wp_safe_redirect( add_query_arg( 'signed', time(), ril_client_url( $c['token'] ) ), 303 );
			exit;
		}
	}

	$status = ril_status( $c );
	if ( 'sent' === $status && 'GET' === $_SERVER['REQUEST_METHOD'] ) {
		ril_log_view( $c );
	}
	ril_render_client( $c, $status, $state );
}

function ril_log_view( $c ) {
	global $wpdb;
	$meta   = ril_request_meta();
	$recent = $wpdb->get_var( $wpdb->prepare(
		'SELECT id FROM ' . ril_table( 'events' ) . " WHERE contract_id = %d AND type = 'viewed' AND ip = %s AND at > %s LIMIT 1",
		$c['id'],
		$meta['ip'],
		gmdate( 'Y-m-d H:i:s', time() - 600 )
	) );
	if ( ! $recent ) {
		ril_add_event( $c['id'], 'viewed' );
	}
}

function ril_handle_sign( $c ) {
	if ( 'sent' !== ril_status( $c ) ) {
		return array( 'error' => 'This contract can no longer be signed.' );
	}
	$seen_hash = isset( $_POST['body_hash'] ) ? sanitize_text_field( wp_unslash( $_POST['body_hash'] ) ) : '';
	$posted = isset( $_POST['f'] ) && is_array( $_POST['f'] ) ? wp_unslash( $_POST['f'] ) : array();
	$values = array();
	foreach ( ril_client_field_names( $c['body'] ) as $name ) {
		$v               = isset( $posted[ $name ] ) ? sanitize_text_field( $posted[ $name ] ) : '';
		$values[ $name ] = mb_substr( $v, 0, 500 );
	}
	$name    = isset( $_POST['signer_name'] ) ? mb_substr( sanitize_text_field( wp_unslash( $_POST['signer_name'] ) ), 0, 200 ) : '';
	$email   = isset( $_POST['signer_email'] ) ? sanitize_email( wp_unslash( $_POST['signer_email'] ) ) : '';
	$image   = isset( $_POST['signature'] ) ? ril_clean_signature_image( wp_unslash( $_POST['signature'] ) ) : '';
	$consent = ! empty( $_POST['consent'] );
	$hold    = ril_hold_field( $c['body'] );
	$event   = ril_event_fields( $c['body'] );
	$soft    = $hold && ! empty( $_POST['softhold'] );
	$hmonth  = isset( $_POST['hold_month'] ) ? (int) $_POST['hold_month'] : 0;
	$hyear   = isset( $_POST['hold_year'] ) ? (int) $_POST['hold_year'] : 0;
	$keep    = array( 'values' => $values, 'name' => $name, 'email' => $email, 'softhold' => $soft, 'hold_month' => $hmonth, 'hold_year' => $hyear );

	if ( ! hash_equals( hash( 'sha256', $c['body'] ), $seen_hash ) ) {
		return $keep + array( 'error' => 'We just updated this contract. Please review the new version above before signing.', 'changed' => true );
	}

	$month_key = '';
	if ( $soft ) {
		if ( $hmonth < 1 || $hmonth > 12 || $hyear < (int) gmdate( 'Y' ) || $hyear > (int) gmdate( 'Y' ) + 10 ) {
			return $keep + array( 'error' => 'Please choose the month and year you would like us to hold.' );
		}
		$month_key        = sprintf( '%04d-%02d', $hyear, $hmonth );
		$values[ $hold ]  = ril_month_label( $month_key ) . ' (exact date to be confirmed)';
		foreach ( $event as $f ) {
			if ( '' === $values[ $f ] ) {
				$values[ $f ] = 'TBD';
			}
		}
	}

	foreach ( $values as $v ) {
		if ( '' === $v ) {
			return $keep + array( 'error' => 'Please fill in every blank in the contract.' );
		}
	}
	if ( mb_strlen( $name ) < 2 ) {
		return $keep + array( 'error' => 'Please type your full name.' );
	}
	if ( ! $consent ) {
		return $keep + array( 'error' => 'Please check the box to agree to sign electronically.' );
	}
	if ( ! $image ) {
		return $keep + array( 'error' => 'Please draw your signature.' );
	}

	global $wpdb;
	$meta      = ril_request_meta();
	$formatted = array();
	$wedding   = $c['wedding_date'];
	foreach ( $values as $k => $v ) {
		$formatted[ $k ] = ril_format_client_value( $k, $v );
		if ( 'date' === ril_field_type( $k ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $v ) && preg_match( '/wedding|event/i', $k ) ) {
			$wedding = $v;
		}
	}
	$values     = $formatted;
	if ( $soft ) {
		$wedding = '';
	}
	$final_hash = hash( 'sha256', ril_fill_client( $c['body'], $values ) );
	$sig        = array(
		'name'    => $name,
		'email'   => $email ? $email : $c['client_email'],
		'image'   => $image,
		'ip'      => $meta['ip'],
		'ua'      => $meta['ua'],
		'at'      => ril_now(),
		'consent' => ril_consent_text(),
	);
	// Só assina se ainda estiver "sent" (evita assinatura dupla).
	$updated = $wpdb->query( $wpdb->prepare(
		'UPDATE ' . ril_table( 'contracts' ) . " SET client_fields = %s, client_signature = %s, final_hash = %s, wedding_date = %s, soft_hold = %d, wedding_month = %s, status = 'signed', signed_at = %s WHERE id = %d AND status = 'sent'",
		wp_json_encode( $values ),
		wp_json_encode( $sig ),
		$final_hash,
		$wedding,
		$soft ? 1 : 0,
		$soft ? $month_key : ( $wedding ? substr( $wedding, 0, 7 ) : '' ),
		ril_now(),
		$c['id']
	) );
	if ( ! $updated ) {
		return array( 'error' => 'This contract can no longer be signed.' );
	}
	ril_add_event( $c['id'], 'signed', array( 'name' => $name, 'email' => $sig['email'], 'hash' => $final_hash, 'consent' => true, 'soft_hold' => $soft ? $month_key : '' ) );
	if ( ril_mail_signed_to_studio( $c ) ) {
		ril_add_event( $c['id'], 'email_sent', array( 'to' => ril_settings()['email'] ) );
	}
	ril_journey_on_signed( $c['id'] );
	return array( 'ok' => true );
}

function ril_consent_text() {
	return 'I agree to sign this contract electronically. I understand that my electronic signature is legally binding, just like a handwritten signature, and that I can download a copy of the signed contract.';
}

function ril_paypal_href( $link, $cents ) {
	if ( ! $link ) {
		return '';
	}
	if ( preg_match( '#paypal\.me/[^/]+/?$#i', $link ) && $cents > 0 ) {
		return untrailingslashit( $link ) . '/' . number_format( $cents / 100, 2, '.', '' ) . 'USD';
	}
	return $link;
}

function ril_render_client( $c, $status, $state ) {
	$s      = ril_settings();
	$header = '<div class="ril-eyebrow">' . esc_html( $s['studio_name'] ) . '</div>'
		. '<div class="ril-muted ril-small" style="margin-top:4px">Prepared for ' . esc_html( $c['client_name'] )
		. ( $c['wedding_date'] ? ' · Wedding date: ' . esc_html( ril_long_date( $c['wedding_date'] ) ) : ( ! empty( $c['soft_hold'] ) && $c['wedding_month'] ? ' · Holding ' . esc_html( ril_month_label( $c['wedding_month'] ) ) : '' ) ) . '</div>';

	ob_start();
	$logo = ril_logo_url();
	echo '<div class="ril-hero">' . ( $logo ? '<img class="ril-logo" src="' . esc_url( $logo ) . '" alt="' . esc_attr( $s['studio_name'] ) . '">' : '<div class="ril-eyebrow">' . esc_html( $s['studio_name'] ) . '</div>' ) . '<h1>' . esc_html( $c['title'] ) . '</h1>';
	if ( 'sent' === $status ) {
		echo '<p class="ril-muted">Please read the contract below, fill in the blanks, and sign at the end.</p>';
	}
	echo '</div>';

	if ( 'canceled' === $status ) {
		echo '<div class="ril-card"><p>This contract was canceled. Please contact us if you have questions.</p></div>';
	} elseif ( 'expired' === $status ) {
		echo '<div class="ril-card"><p>This link has expired. Please contact us for a new one.</p></div>';
	} elseif ( 'sent' === $status ) {
		$values = isset( $state['values'] ) ? $state['values'] : array();
		if ( ! empty( $state['changed'] ) ) {
			echo '<div class="ril-alert ril-error" role="alert">We just updated this contract. Please review the new version below before signing.</div>';
		}
		echo '<form method="post" class="ril-stack" id="ril-sign-form" novalidate>';
		$ctx = array(
			'softhold'   => ! empty( $state['softhold'] ),
			'hold_month' => isset( $state['hold_month'] ) ? $state['hold_month'] : 0,
			'hold_year'  => isset( $state['hold_year'] ) ? $state['hold_year'] : 0,
			'hold'       => ril_hold_field( $c['body'] ),
			'event'      => ril_event_fields( $c['body'] ),
		);
		echo ril_render_doc( $c['title'], $c['body'], 'edit', $values, $header, 'Fill in', $ctx ); // phpcs:ignore
		echo '<section class="ril-card" id="sign"><h2>Sign</h2>';
		if ( ! empty( $state['error'] ) ) {
			echo '<div class="ril-alert ril-error" role="alert">' . esc_html( $state['error'] ) . '</div>';
		}
		echo '<div class="ril-grid">';
		echo '<label class="ril-field"><span>Your full legal name</span><input name="signer_name" autocomplete="name" required value="' . esc_attr( isset( $state['name'] ) ? $state['name'] : '' ) . '"></label>';
		echo '<label class="ril-field"><span>Your email</span><input type="email" name="signer_email" autocomplete="email" value="' . esc_attr( isset( $state['email'] ) ? $state['email'] : $c['client_email'] ) . '"></label>';
		echo '</div>';
		echo '<div class="ril-field"><span>Draw your signature</span>' . ril_signature_pad_html( 'Draw your signature', 'Clear' ) . '</div>';
		echo '<label class="ril-check"><input type="checkbox" name="consent" value="1"><span>' . esc_html( ril_consent_text() ) . '</span></label>';
		echo '<input type="hidden" name="ril_sign" value="1"><input type="hidden" name="body_hash" value="' . esc_attr( hash( 'sha256', $c['body'] ) ) . '">';
		echo '<button class="ril-btn ril-block" type="submit">Sign contract</button>';
		echo '</section></form>';
	} else {
		echo '<div class="ril-stack">';
		echo '<div class="ril-card ril-center"><h2>' . ( 'completed' === $status ? 'This contract is signed by both parties.' : 'Signed. Thank you!' ) . '</h2>';
		if ( 'signed' === $status ) {
			echo '<p class="ril-muted">We received your signature. You will get the fully signed copy once we countersign.</p>';
		}
		echo '<a class="ril-btn" href="' . esc_url( ril_client_url( $c['token'], true ) ) . '">Download PDF</a></div>';

		if ( $c['deposit_cents'] > 0 ) {
			echo '<div class="ril-pay"><div class="ril-eyebrow">Retainer payment</div>';
			if ( 'unpaid' === $c['payment_status'] ) {
				echo '<div class="ril-amount">' . esc_html( ril_money( $c['deposit_cents'] ) ) . '</div>';
				echo '<p class="ril-paynote">Due now to reserve your date · Total ' . esc_html( ril_money( $c['total_cents'] ) ) . '</p>';
				if ( $s['payment_instructions'] ) {
					echo '<div class="ril-payinfo">' . nl2br( esc_html( $s['payment_instructions'] ) ) . '</div>';
				}
				if ( $s['paypal_link'] ) {
					$fee = (int) round( $c['deposit_cents'] * (float) $s['paypal_fee_percent'] / 100 );
					echo '<a class="ril-btn ril-btn-light" target="_blank" rel="noopener" href="' . esc_url( ril_paypal_href( $s['paypal_link'], $c['deposit_cents'] + $fee ) ) . '">Pay with PayPal · ' . esc_html( ril_money( $c['deposit_cents'] + $fee ) ) . '</a>';
					if ( $fee > 0 ) {
						echo '<p class="ril-paynote" style="margin-top:10px">Includes the ' . esc_html( rtrim( rtrim( number_format( (float) $s['paypal_fee_percent'], 2 ), '0' ), '.' ) ) . '% PayPal fee. No fee with Zelle, cash or check.</p>';
					}
				}
			} elseif ( 'deposit_paid' === $c['payment_status'] && ril_amount_due( $c ) > 0 ) {
				$due = ril_amount_due( $c );
				echo '<div class="ril-paynote" style="margin-bottom:10px">Retainer received. Thank you!</div>';
				echo '<div class="ril-eyebrow">Balance</div><div class="ril-amount">' . esc_html( ril_money( $due ) ) . '</div>';
				echo '<p class="ril-paynote">Due by your wedding day' . ( $c['wedding_date'] ? ', ' . esc_html( ril_long_date( $c['wedding_date'] ) ) : '' ) . '</p>';
				if ( $s['payment_instructions'] ) {
					echo '<div class="ril-payinfo">' . nl2br( esc_html( $s['payment_instructions'] ) ) . '</div>';
				}
				if ( $s['paypal_link'] ) {
					$fee = (int) round( $due * (float) $s['paypal_fee_percent'] / 100 );
					echo '<a class="ril-btn ril-btn-light" target="_blank" rel="noopener" href="' . esc_url( ril_paypal_href( $s['paypal_link'], $due + $fee ) ) . '">Pay with PayPal · ' . esc_html( ril_money( $due + $fee ) ) . '</a>';
					if ( $fee > 0 ) {
						echo '<p class="ril-paynote" style="margin-top:10px">Includes the ' . esc_html( rtrim( rtrim( number_format( (float) $s['paypal_fee_percent'], 2 ), '0' ), '.' ) ) . '% PayPal fee. No fee with Zelle, cash or check.</p>';
					}
				}
			} else {
				echo '<div class="ril-amount" style="font-size:24px">All paid. Thank you!</div>';
			}
			echo '</div>';
		}

		echo ril_render_doc( $c['title'], $c['body'], 'view', $c['client_fields'], $header ); // phpcs:ignore
		echo '<div class="ril-card"><div class="ril-grid">';
		foreach ( array( array( $c['client_name'], $c['client_signature'] ), array( $s['studio_name'], $c['studio_signature'] ) ) as $pair ) {
			echo '<div><div class="ril-eyebrow">' . esc_html( $pair[0] ) . '</div>';
			if ( $pair[1] ) {
				echo '<img class="ril-sig-img" alt="" src="' . esc_attr( $pair[1]['image'] ) . '">';
				echo '<div><b>' . esc_html( $pair[1]['name'] ) . '</b></div><div class="ril-muted ril-small">Signed on ' . esc_html( ril_utc_label( $pair[1]['at'] ) ) . '</div>';
			} else {
				echo '<div class="ril-muted">Awaiting signature</div>';
			}
			echo '</div>';
		}
		echo '</div></div></div>';
	}
	ril_public_page( $c['title'], ob_get_clean() );
}

function ril_signature_pad_html( $hint, $clear ) {
	return '<div class="ril-sig" data-ril-sig><canvas aria-label="' . esc_attr( $hint ) . '"></canvas>'
		. '<span class="ril-sig-hint">' . esc_html( $hint ) . '</span>'
		. '<button type="button" class="ril-btn ril-btn-secondary ril-btn-small ril-sig-clear">' . esc_html( $clear ) . '</button>'
		. '<input type="hidden" name="signature" value=""></div>';
}

function ril_public_page( $title, $content ) {
	$s = ril_settings();
	?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?php echo esc_html( $title . ' · ' . $s['studio_name'] ); ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500&family=Playfair+Display:wght@400;500&display=swap">
<link rel="stylesheet" href="<?php echo esc_url( RIL_CONTRACTS_URL . 'assets/ril.css?v=' . RIL_CONTRACTS_VERSION ); ?>">
</head>
<body class="ril-public">
<main class="ril-narrow"><?php echo $content; // phpcs:ignore ?></main>
<script src="<?php echo esc_url( RIL_CONTRACTS_URL . 'assets/ril.js?v=' . RIL_CONTRACTS_VERSION ); ?>"></script>
</body>
</html>
	<?php
	exit;
}
