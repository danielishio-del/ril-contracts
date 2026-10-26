<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function ril_table( $name ) {
	global $wpdb;
	return $wpdb->prefix . 'ril_' . $name;
}

function ril_install_tables() {
	global $wpdb;
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	$charset = $wpdb->get_charset_collate();

	$templates = ril_table( 'templates' );
	$contracts = ril_table( 'contracts' );
	$events    = ril_table( 'events' );

	dbDelta( "CREATE TABLE $templates (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  name varchar(191) NOT NULL,
  body longtext NOT NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id)
) $charset;" );

	dbDelta( "CREATE TABLE $contracts (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  token varchar(64) NOT NULL,
  template_id bigint(20) unsigned NULL,
  title varchar(191) NOT NULL,
  body longtext NOT NULL,
  studio_fields longtext NULL,
  client_fields longtext NULL,
  client_name varchar(191) NOT NULL,
  client_email varchar(191) NOT NULL DEFAULT '',
  wedding_date varchar(10) NOT NULL DEFAULT '',
  wedding_month varchar(7) NOT NULL DEFAULT '',
  soft_hold tinyint(1) NOT NULL DEFAULT 0,
  date_confirmed_at datetime NULL,
  total_cents bigint(20) NOT NULL DEFAULT 0,
  deposit_cents bigint(20) NOT NULL DEFAULT 0,
  status varchar(20) NOT NULL DEFAULT 'draft',
  link_days int(11) NOT NULL DEFAULT 14,
  expires_at datetime NULL,
  sent_hash varchar(64) NULL,
  final_hash varchar(64) NULL,
  client_signature longtext NULL,
  studio_signature longtext NULL,
  pdf longtext NULL,
  pdf_hash varchar(64) NULL,
  payment_status varchar(20) NOT NULL DEFAULT 'unpaid',
  created_at datetime NOT NULL,
  sent_at datetime NULL,
  signed_at datetime NULL,
  completed_at datetime NULL,
  canceled_at datetime NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY token (token),
  KEY status (status),
  KEY wedding_month (wedding_month)
) $charset;" );

	dbDelta( "CREATE TABLE $events (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  contract_id bigint(20) unsigned NOT NULL,
  type varchar(40) NOT NULL,
  at datetime NOT NULL,
  ip varchar(64) NULL,
  ua text NULL,
  detail longtext NULL,
  PRIMARY KEY  (id),
  KEY contract_id (contract_id)
) $charset;" );

	update_option( 'ril_contracts_db_version', RIL_CONTRACTS_DB_VERSION );

	$tpl   = require RIL_CONTRACTS_DIR . 'includes/default-template.php';
	$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $templates" );
	if ( $count > 0 ) {
		// Atualiza o modelo padrão antigo, só se ninguém editou o texto.
		// Trocas pontuais que valem mesmo se o modelo foi editado (valores e dados do estúdio que mudaram).
		$fixes = array(
			'Engagement Session ($570 value)'                      => 'Engagement Session ($560 value)',
			'78206 Varner Rd Suite D #1008, Palm Desert, CA 92211' => '68695 F St, Cathedral City, CA 92234, United States',
			'{{Coverage hours: 6 hours | 9 hours | 12 hours}}'     => '{{Coverage hours: 2 hours | 6 hours | 9 hours | 12 hours}}',
			'{{Gift: Engagement Session ($560 value) | Album Design, 20 pages (design only; printed album not included) | Engagement Session ($560 value) and Album Design, 20 pages (design only; printed album not included)}}' => '{{Gift: None | Engagement Session ($560 value) | Album Design, 20 pages (design only; printed album not included) | Engagement Session ($560 value) and Album Design, 20 pages (design only; printed album not included)}}',
		);
		foreach ( $wpdb->get_results( "SELECT id, body FROM $templates", ARRAY_A ) as $row ) {
			$plain = str_replace( "\r\n", "\n", $row['body'] );
			if ( in_array( hash( 'sha256', $plain ), explode( ',', RIL_DEFAULT_TEMPLATE_OLD_HASHES ), true ) ) {
				$wpdb->update( $templates, array( 'body' => $tpl['body'], 'updated_at' => ril_now() ), array( 'id' => $row['id'] ) );
				continue;
			}
			$fixed = strtr( $row['body'], $fixes );
			if ( $fixed !== $row['body'] ) {
				$wpdb->update( $templates, array( 'body' => $fixed, 'updated_at' => ril_now() ), array( 'id' => $row['id'] ) );
			}
		}
	}
	if ( 0 === $count ) {
		$now = ril_now();
		$wpdb->insert( $templates, array(
			'name'       => $tpl['name'],
			'body'       => $tpl['body'],
			'created_at' => $now,
			'updated_at' => $now,
		) );
	}
}

/** Data/hora UTC no formato do banco. */
function ril_now() {
	return gmdate( 'Y-m-d H:i:s' );
}

/* ---------- Configurações ---------- */

function ril_settings() {
	$defaults = array(
		'studio_name'          => 'Rocker in Love',
		'signer_name'          => '',
		'signer_title'         => '',
		'email'                => get_option( 'admin_email' ),
		'payment_instructions' => '',
		'paypal_link'          => '',
		'paypal_fee_percent'   => 3,
		'deposit_percent'      => 40,
		'link_days'            => 14,
		'logo_id'              => 0,
		'from_name'            => 'Rocker in Love',
		'update_repo'          => RIL_CONTRACTS_DEFAULT_REPO,
		'update_token'         => '',
		'from_email'           => '',
		'email_subject'        => 'Welcome to the Rocker in Love family!',
		'email_body'           => ril_default_email_body(),
		'email_button'         => 'Review & sign your contract',
	);
	$saved = get_option( 'ril_contracts_settings', array() );
	return array_merge( $defaults, is_array( $saved ) ? $saved : array() );
}

function ril_default_email_body() {
	return "Hi {first_names},\n\n"
		. "Welcome to the Rocker in Love family! We are so excited that you chose us to be part of your wedding day, and we can't wait to start planning this with you.\n\n"
		. "Your contract is ready. It only takes a few minutes: fill in your event details, sign right on your phone, and once the retainer is in, your date is officially reserved.\n\n"
		. "{button}\n\n"
		. "If anything doesn't look right or you have any questions, just reply to this email. We're here for you.\n\n"
		. "With love,\nThe Rocker in Love team";
}

/** Logo: a escolhida nas Configurações, ou a logo do tema do site. */
function ril_logo_id() {
	$s  = ril_settings();
	$id = (int) $s['logo_id'];
	if ( ! $id ) {
		$id = (int) get_theme_mod( 'custom_logo' );
	}
	return $id && wp_attachment_is_image( $id ) ? $id : 0;
}

function ril_logo_url() {
	$id = ril_logo_id();
	if ( ! $id ) {
		return '';
	}
	$img = wp_get_attachment_image_src( $id, 'medium' );
	return $img ? $img[0] : (string) wp_get_attachment_url( $id );
}

/** Caminho do arquivo da logo em PNG/JPG (o PDF não aceita SVG). */
function ril_logo_file() {
	$id = ril_logo_id();
	if ( ! $id ) {
		return '';
	}
	$candidates = array();
	$medium     = image_get_intermediate_size( $id, 'medium' );
	$full       = get_attached_file( $id );
	if ( $medium && ! empty( $medium['path'] ) ) {
		$candidates[] = trailingslashit( wp_upload_dir()['basedir'] ) . $medium['path'];
	}
	if ( $full ) {
		$candidates[] = $full;
	}
	foreach ( $candidates as $file ) {
		if ( file_exists( $file ) && preg_match( '/\.(png|jpe?g)$/i', $file ) ) {
			return $file;
		}
	}
	return '';
}

/** "Ana Souza & John Miller" → "Ana & John". */
function ril_first_names( $client_name ) {
	$parts = preg_split( '/\s*(?:&|\band\b|\be\b|\+)\s*/iu', trim( $client_name ) );
	$names = array();
	foreach ( $parts as $p ) {
		$p = trim( $p );
		if ( '' !== $p ) {
			$names[] = preg_split( '/\s+/', $p )[0];
		}
	}
	return $names ? implode( ' & ', $names ) : $client_name;
}

/* ---------- Modelos ---------- */

function ril_get_templates() {
	global $wpdb;
	return $wpdb->get_results( 'SELECT * FROM ' . ril_table( 'templates' ) . ' ORDER BY updated_at DESC', ARRAY_A );
}

function ril_get_template( $id ) {
	global $wpdb;
	return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . ril_table( 'templates' ) . ' WHERE id = %d', $id ), ARRAY_A );
}

/* ---------- Contratos ---------- */

function ril_hydrate( $row ) {
	if ( ! $row ) {
		return null;
	}
	foreach ( array( 'studio_fields', 'client_fields', 'client_signature', 'studio_signature' ) as $k ) {
		$row[ $k ] = $row[ $k ] ? json_decode( $row[ $k ], true ) : null;
	}
	if ( ! is_array( $row['client_fields'] ) ) {
		$row['client_fields'] = array();
	}
	if ( ! is_array( $row['studio_fields'] ) ) {
		$row['studio_fields'] = array();
	}
	$row['id']            = (int) $row['id'];
	$row['total_cents']   = (int) $row['total_cents'];
	$row['deposit_cents'] = (int) $row['deposit_cents'];
	$row['soft_hold']     = ! empty( $row['soft_hold'] ) ? 1 : 0;
	return $row;
}

function ril_get_contract( $id ) {
	global $wpdb;
	return ril_hydrate( $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . ril_table( 'contracts' ) . ' WHERE id = %d', $id ), ARRAY_A ) );
}

function ril_get_contract_by_token( $token ) {
	global $wpdb;
	if ( ! is_string( $token ) || strlen( $token ) < 20 ) {
		return null;
	}
	return ril_hydrate( $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . ril_table( 'contracts' ) . ' WHERE token = %s', $token ), ARRAY_A ) );
}

function ril_list_contracts() {
	global $wpdb;
	$rows = $wpdb->get_results(
		'SELECT id, token, title, client_name, client_email, wedding_date, wedding_month, soft_hold, total_cents, deposit_cents, status, expires_at, payment_status, created_at
		 FROM ' . ril_table( 'contracts' ) . ' ORDER BY created_at DESC, id DESC LIMIT 500',
		ARRAY_A
	);
	return $rows ? $rows : array();
}

function ril_update_contract( $id, $data ) {
	global $wpdb;
	foreach ( array( 'studio_fields', 'client_fields', 'client_signature', 'studio_signature' ) as $k ) {
		if ( array_key_exists( $k, $data ) && is_array( $data[ $k ] ) ) {
			$data[ $k ] = wp_json_encode( $data[ $k ] );
		}
	}
	return $wpdb->update( ril_table( 'contracts' ), $data, array( 'id' => $id ) );
}

/** Status real, levando em conta a validade do link. */
function ril_status( $c ) {
	if ( 'sent' === $c['status'] && ! empty( $c['expires_at'] ) && strtotime( $c['expires_at'] . ' UTC' ) < time() ) {
		return 'expired';
	}
	return $c['status'];
}

/* ---------- Agenda / soft hold ---------- */

/** Contratos assinados (ou concluídos) com casamento confirmado neste dia. */
function ril_booked_on( $ymd ) {
	global $wpdb;
	return array_map( 'ril_hydrate', (array) $wpdb->get_results( $wpdb->prepare(
		'SELECT * FROM ' . ril_table( 'contracts' ) . " WHERE wedding_date = %s AND status IN ('signed','completed') ORDER BY id",
		$ymd
	), ARRAY_A ) );
}

/** Soft holds ativos (um mês, ou todos). */
function ril_soft_holds( $ym = '' ) {
	global $wpdb;
	$sql = 'SELECT * FROM ' . ril_table( 'contracts' ) . " WHERE soft_hold = 1 AND status IN ('signed','completed')";
	if ( $ym ) {
		$sql = $wpdb->prepare( $sql . ' AND wedding_month = %s', $ym );
	}
	return array_map( 'ril_hydrate', (array) $wpdb->get_results( $sql . ' ORDER BY wedding_month, id', ARRAY_A ) );
}

/** Outros casamentos confirmados no mesmo mês (para dar contexto). */
function ril_booked_in_month( $ym ) {
	global $wpdb;
	return array_map( 'ril_hydrate', (array) $wpdb->get_results( $wpdb->prepare(
		'SELECT * FROM ' . ril_table( 'contracts' ) . " WHERE soft_hold = 0 AND wedding_date LIKE %s AND status IN ('signed','completed') ORDER BY wedding_date",
		$wpdb->esc_like( $ym ) . '-%'
	), ARRAY_A ) );
}

/* ---------- Histórico ---------- */

function ril_add_event( $contract_id, $type, $detail = array() ) {
	global $wpdb;
	$meta = ril_request_meta();
	$wpdb->insert( ril_table( 'events' ), array(
		'contract_id' => $contract_id,
		'type'        => $type,
		'at'          => ril_now(),
		'ip'          => $meta['ip'],
		'ua'          => $meta['ua'],
		'detail'      => wp_json_encode( $detail ),
	) );
}

function ril_get_events( $contract_id ) {
	global $wpdb;
	$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . ril_table( 'events' ) . ' WHERE contract_id = %d ORDER BY at ASC, id ASC', $contract_id ), ARRAY_A );
	return $rows ? $rows : array();
}
