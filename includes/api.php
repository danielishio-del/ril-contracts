<?php
/**
 * RIL Contracts REST API
 *
 * Endpoints para gerenciar contratos via API
 * Base: /wp-json/ril/v1/
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Register REST routes
 */
add_action( 'rest_api_init', function () {
	// List & Create contracts
	register_rest_route( 'ril/v1', '/contracts', array(
		array(
			'methods'             => 'GET',
			'callback'            => 'ril_api_get_contracts',
			'permission_callback' => 'ril_api_check_permission',
			'args'                => array(
				'page'     => array(
					'type'              => 'integer',
					'minimum'           => 1,
					'default'           => 1,
					'sanitize_callback' => 'absint',
				),
				'per_page' => array(
					'type'              => 'integer',
					'minimum'           => 1,
					'maximum'           => 100,
					'default'           => 20,
					'sanitize_callback' => 'absint',
				),
				'status'   => array(
					'type'              => 'string',
					'enum'              => array( 'draft', 'sent', 'signed', 'completed', 'expired', 'canceled' ),
					'sanitize_callback' => 'sanitize_text_field',
				),
				'search'   => array(
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				),
			),
		),
		array(
			'methods'             => 'POST',
			'callback'            => 'ril_api_create_contract',
			'permission_callback' => 'ril_api_check_permission',
			'args'                => array(
				'title'           => array(
					'type'     => 'string',
					'required' => true,
				),
				'client_name'     => array(
					'type'     => 'string',
					'required' => true,
				),
				'client_email'    => array(
					'type'     => 'string',
					'required' => true,
					'format'   => 'email',
				),
				'wedding_date'    => array(
					'type'   => 'string',
					'format' => 'date',
				),
				'total_cents'     => array(
					'type'    => 'integer',
					'minimum' => 0,
				),
				'deposit_cents'   => array(
					'type'    => 'integer',
					'minimum' => 0,
				),
				'studio_fields'   => array(
					'type' => 'object',
				),
			),
		),
	) );

	// Get, Update, Delete single contract
	register_rest_route( 'ril/v1', '/contracts/(?P<id>\d+)', array(
		array(
			'methods'             => 'GET',
			'callback'            => 'ril_api_get_contract',
			'permission_callback' => 'ril_api_check_permission',
			'args'                => array(
				'id' => array(
					'type'              => 'integer',
					'required'          => true,
					'sanitize_callback' => 'absint',
				),
			),
		),
		array(
			'methods'             => 'PUT',
			'callback'            => 'ril_api_update_contract',
			'permission_callback' => 'ril_api_check_permission',
			'args'                => array(
				'id'              => array(
					'type'     => 'integer',
					'required' => true,
				),
				'title'           => array( 'type' => 'string' ),
				'client_name'     => array( 'type' => 'string' ),
				'client_email'    => array( 'type' => 'string' ),
				'wedding_date'    => array( 'type' => 'string' ),
				'total_cents'     => array( 'type' => 'integer' ),
				'deposit_cents'   => array( 'type' => 'integer' ),
				'studio_fields'   => array( 'type' => 'object' ),
				'status'          => array(
					'type' => 'string',
					'enum' => array( 'draft', 'sent', 'signed', 'completed', 'expired', 'canceled' ),
				),
				'payment_status'  => array(
					'type' => 'string',
					'enum' => array( 'unpaid', 'deposit_paid', 'paid_in_full' ),
				),
			),
		),
		array(
			'methods'             => 'DELETE',
			'callback'            => 'ril_api_delete_contract',
			'permission_callback' => 'ril_api_check_permission',
			'args'                => array(
				'id' => array(
					'type'     => 'integer',
					'required' => true,
				),
			),
		),
	) );

	// Send contract to client
	register_rest_route( 'ril/v1', '/contracts/(?P<id>\d+)/send', array(
		array(
			'methods'             => 'POST',
			'callback'            => 'ril_api_send_contract',
			'permission_callback' => 'ril_api_check_permission',
		),
	) );

	// Update payment status
	register_rest_route( 'ril/v1', '/contracts/(?P<id>\d+)/payment', array(
		array(
			'methods'             => 'POST',
			'callback'            => 'ril_api_update_payment',
			'permission_callback' => 'ril_api_check_permission',
			'args'                => array(
				'status' => array(
					'type'     => 'string',
					'required' => true,
					'enum'     => array( 'unpaid', 'deposit_paid', 'paid_in_full' ),
				),
			),
		),
	) );

	// Get template
	register_rest_route( 'ril/v1', '/template', array(
		array(
			'methods'             => 'GET',
			'callback'            => 'ril_api_get_template',
			'permission_callback' => 'ril_api_check_permission',
		),
	) );
} );

/**
 * Permission check — user must be logged in and admin
 */
function ril_api_check_permission() {
	if ( ! is_user_logged_in() ) {
		return new WP_Error( 'rest_not_logged_in', 'You must be logged in.', array( 'status' => 401 ) );
	}
	if ( ! current_user_can( 'manage_options' ) && ! current_user_can( 'edit_pages' ) ) {
		return new WP_Error( 'rest_forbidden', 'You do not have permission.', array( 'status' => 403 ) );
	}
	return true;
}

/**
 * GET /wp-json/ril/v1/contracts
 * List contracts with filters & pagination
 */
function ril_api_get_contracts( $request ) {
	global $wpdb;

	$page     = (int) $request->get_param( 'page' ) ?: 1;
	$per_page = (int) $request->get_param( 'per_page' ) ?: 20;
	$status   = $request->get_param( 'status' );
	$search   = $request->get_param( 'search' );

	$offset = ( $page - 1 ) * $per_page;
	$table  = ril_table( 'contracts' );

	$where = array( '1=1' );
	if ( $status ) {
		$where[] = $wpdb->prepare( "status = %s", $status );
	}
	if ( $search ) {
		$where[] = $wpdb->prepare( "client_name LIKE %s OR client_email LIKE %s", "%$search%", "%$search%" );
	}

	$where_sql = implode( ' AND ', $where );
	$total     = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table WHERE $where_sql" );
	$rows      = $wpdb->get_results( "SELECT * FROM $table WHERE $where_sql ORDER BY created_at DESC LIMIT $offset, $per_page" );

	return array(
		'data'       => array_map( 'ril_format_contract', $rows ),
		'pagination' => array(
			'total'       => $total,
			'page'        => $page,
			'per_page'    => $per_page,
			'total_pages' => ceil( $total / $per_page ),
		),
	);
}

/**
 * GET /wp-json/ril/v1/contracts/{id}
 * Get single contract
 */
function ril_api_get_contract( $request ) {
	$id = (int) $request->get_param( 'id' );
	$c  = ril_get_contract( $id );

	if ( ! $c ) {
		return new WP_Error( 'not_found', 'Contract not found', array( 'status' => 404 ) );
	}

	return ril_format_contract( $c );
}

/**
 * POST /wp-json/ril/v1/contracts
 * Create new contract
 */
function ril_api_create_contract( $request ) {
	global $wpdb;

	$title         = sanitize_text_field( $request->get_param( 'title' ) );
	$client_name   = sanitize_text_field( $request->get_param( 'client_name' ) );
	$client_email  = sanitize_email( $request->get_param( 'client_email' ) );
	$wedding_date  = sanitize_text_field( $request->get_param( 'wedding_date' ) );
	$total_cents   = (int) $request->get_param( 'total_cents' ) ?: 0;
	$deposit_cents = (int) $request->get_param( 'deposit_cents' ) ?: 0;
	$studio_fields = (array) $request->get_param( 'studio_fields' );

	if ( ! $title || ! $client_name || ! $client_email ) {
		return new WP_Error( 'missing_fields', 'Missing required fields', array( 'status' => 400 ) );
	}

	$tpl  = require RIL_CONTRACTS_DIR . 'includes/default-template.php';
	$body = ril_fill_studio( $tpl['body'], array_merge(
		ril_auto_values( $client_name, $wedding_date, $total_cents, $deposit_cents, ril_now(), 0, '' ),
		array_filter( array_map( 'strval', $studio_fields ), 'strlen' )
	) );

	$data = array(
		'token'           => bin2hex( random_bytes( 32 ) ),
		'title'           => $title,
		'client_name'     => $client_name,
		'client_email'    => $client_email,
		'wedding_date'    => $wedding_date ?: '',
		'total_cents'     => $total_cents,
		'deposit_cents'   => $deposit_cents,
		'body'            => $body,
		'studio_fields'   => wp_json_encode( $studio_fields ),
		'status'          => 'draft',
		'payment_status'  => 'unpaid',
		'created_at'      => ril_now(),
	);

	$wpdb->insert( ril_table( 'contracts' ), $data );
	$id = $wpdb->insert_id;

	return ril_format_contract( ril_get_contract( $id ) );
}

/**
 * PUT /wp-json/ril/v1/contracts/{id}
 * Update contract
 */
function ril_api_update_contract( $request ) {
	$id = (int) $request->get_param( 'id' );
	$c  = ril_get_contract( $id );

	if ( ! $c ) {
		return new WP_Error( 'not_found', 'Contract not found', array( 'status' => 404 ) );
	}

	$data = array();

	if ( $request->has_param( 'title' ) ) {
		$data['title'] = sanitize_text_field( $request->get_param( 'title' ) );
	}
	if ( $request->has_param( 'client_name' ) ) {
		$data['client_name'] = sanitize_text_field( $request->get_param( 'client_name' ) );
	}
	if ( $request->has_param( 'client_email' ) ) {
		$data['client_email'] = sanitize_email( $request->get_param( 'client_email' ) );
	}
	if ( $request->has_param( 'wedding_date' ) ) {
		$data['wedding_date'] = sanitize_text_field( $request->get_param( 'wedding_date' ) );
	}
	if ( $request->has_param( 'total_cents' ) ) {
		$data['total_cents'] = (int) $request->get_param( 'total_cents' );
	}
	if ( $request->has_param( 'deposit_cents' ) ) {
		$data['deposit_cents'] = (int) $request->get_param( 'deposit_cents' );
	}
	if ( $request->has_param( 'status' ) ) {
		$data['status'] = sanitize_text_field( $request->get_param( 'status' ) );
	}
	if ( $request->has_param( 'payment_status' ) ) {
		$data['payment_status'] = sanitize_text_field( $request->get_param( 'payment_status' ) );
	}

	if ( ! empty( $data ) ) {
		ril_update_contract( $id, $data );
		ril_add_event( $id, 'api_updated', array( 'fields' => array_keys( $data ) ) );
	}

	return ril_format_contract( ril_get_contract( $id ) );
}

/**
 * DELETE /wp-json/ril/v1/contracts/{id}
 * Delete contract
 */
function ril_api_delete_contract( $request ) {
	global $wpdb;

	$id = (int) $request->get_param( 'id' );
	$c  = ril_get_contract( $id );

	if ( ! $c ) {
		return new WP_Error( 'not_found', 'Contract not found', array( 'status' => 404 ) );
	}

	if ( 'draft' !== $c['status'] ) {
		return new WP_Error( 'invalid_status', 'Only draft contracts can be deleted', array( 'status' => 400 ) );
	}

	$wpdb->delete( ril_table( 'contracts' ), array( 'id' => $id ) );

	return array( 'deleted' => true );
}

/**
 * POST /wp-json/ril/v1/contracts/{id}/send
 * Send contract to client
 */
function ril_api_send_contract( $request ) {
	$id = (int) $request->get_param( 'id' );
	$c  = ril_get_contract( $id );

	if ( ! $c ) {
		return new WP_Error( 'not_found', 'Contract not found', array( 'status' => 404 ) );
	}

	if ( 'draft' !== $c['status'] ) {
		return new WP_Error( 'invalid_status', 'Only draft contracts can be sent', array( 'status' => 400 ) );
	}

	if ( ! $c['client_email'] ) {
		return new WP_Error( 'no_email', 'Client email required', array( 'status' => 400 ) );
	}

	$hash = hash( 'sha256', $c['body'] );
	ril_update_contract( $id, array(
		'status'     => 'sent',
		'sent_at'    => ril_now(),
		'sent_hash'  => $hash,
		'expires_at' => gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS * 14 ),
	) );

	ril_add_event( $id, 'sent', array( 'hash' => $hash ) );

	if ( ril_mail_link_to_client( ril_get_contract( $id ) ) ) {
		ril_add_event( $id, 'email_sent', array( 'to' => $c['client_email'] ) );
		return array( 'sent' => true, 'email' => 'sent' );
	} else {
		return array( 'sent' => true, 'email' => 'failed' );
	}
}

/**
 * POST /wp-json/ril/v1/contracts/{id}/payment
 * Update payment status
 */
function ril_api_update_payment( $request ) {
	$id     = (int) $request->get_param( 'id' );
	$status = sanitize_text_field( $request->get_param( 'status' ) );

	$c = ril_get_contract( $id );
	if ( ! $c ) {
		return new WP_Error( 'not_found', 'Contract not found', array( 'status' => 404 ) );
	}

	ril_update_contract( $id, array( 'payment_status' => $status ) );
	ril_add_event( $id, 'payment_updated', array( 'status' => $status ) );

	if ( 'deposit_paid' === $status || 'paid_in_full' === $status ) {
		ril_journey_on_payment( ril_get_contract( $id ) );
	}

	return ril_format_contract( ril_get_contract( $id ) );
}

/**
 * GET /wp-json/ril/v1/template
 * Get default template
 */
function ril_api_get_template( $request ) {
	$tpl = require RIL_CONTRACTS_DIR . 'includes/default-template.php';

	return array(
		'name' => $tpl['name'],
		'body' => $tpl['body'],
	);
}

/**
 * Format contract for API response
 */
function ril_format_contract( $c ) {
	if ( ! $c ) {
		return null;
	}

	list( $pp, $pn ) = ril_prepaid( $c );

	return array(
		'id'              => (int) $c['id'],
		'token'           => $c['token'],
		'title'           => $c['title'],
		'client_name'     => $c['client_name'],
		'client_email'    => $c['client_email'],
		'wedding_date'    => $c['wedding_date'],
		'wedding_month'   => $c['wedding_month'],
		'total_cents'     => (int) $c['total_cents'],
		'deposit_cents'   => (int) $c['deposit_cents'],
		'prepaid_cents'   => (int) $pp,
		'prepaid_note'    => $pn,
		'balance_cents'   => (int) ril_balance_cents( $c['total_cents'], $c['deposit_cents'], $pp ),
		'status'          => $c['status'],
		'payment_status'  => $c['payment_status'],
		'soft_hold'       => (bool) $c['soft_hold'],
		'created_at'      => $c['created_at'],
		'sent_at'         => $c['sent_at'],
		'signed_at'       => $c['signed_at'],
		'completed_at'    => $c['completed_at'],
		'expires_at'      => $c['expires_at'],
		'link'            => ril_client_url( $c['token'] ),
	);
}
