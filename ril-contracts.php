<?php
/**
 * Plugin Name:       Rocker in Love Contracts
 * Description:       Contratos com assinatura eletrônica: crie a partir de um modelo, mande o link para o casal assinar no celular, contrassine e gere o PDF final com certificado de assinatura.
 * Version:           1.4.4
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Rocker in Love
 * Text Domain:       ril-contracts
 * License:           GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'RIL_CONTRACTS_VERSION', '1.4.4' );
if ( ! defined( 'RIL_CONTRACTS_DEFAULT_REPO' ) ) {
	define( 'RIL_CONTRACTS_DEFAULT_REPO', 'danielishio-del/ril-contracts' );
}
define( 'RIL_CONTRACTS_DB_VERSION', '6' );
define( 'RIL_CONTRACTS_FILE', __FILE__ );
define( 'RIL_CONTRACTS_DIR', plugin_dir_path( __FILE__ ) );
define( 'RIL_CONTRACTS_URL', plugin_dir_url( __FILE__ ) );

require_once RIL_CONTRACTS_DIR . 'includes/db.php';
require_once RIL_CONTRACTS_DIR . 'includes/template.php';
require_once RIL_CONTRACTS_DIR . 'includes/helpers.php';
require_once RIL_CONTRACTS_DIR . 'includes/pdf.php';
require_once RIL_CONTRACTS_DIR . 'includes/mail.php';
require_once RIL_CONTRACTS_DIR . 'includes/public.php';
require_once RIL_CONTRACTS_DIR . 'includes/updater.php';
if ( is_admin() ) {
	require_once RIL_CONTRACTS_DIR . 'includes/admin.php';
}

register_activation_hook( __FILE__, 'ril_activate' );
register_deactivation_hook( __FILE__, 'ril_deactivate' );

function ril_activate() {
	ril_install_tables();
	ril_register_rewrite();
	flush_rewrite_rules();
}

function ril_deactivate() {
	flush_rewrite_rules();
}

add_action( 'plugins_loaded', function () {
	if ( get_option( 'ril_contracts_db_version' ) !== RIL_CONTRACTS_DB_VERSION ) {
		ril_install_tables();
	}
} );
