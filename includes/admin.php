<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function ril_cap() {
	return apply_filters( 'ril_contracts_capability', 'edit_pages' );
}

add_action( 'admin_menu', function () {
	$cap = ril_cap();
	add_menu_page( 'Contratos', 'Contratos', $cap, 'ril-contracts', 'ril_page_main', 'dashicons-media-document', 26 );
	add_submenu_page( 'ril-contracts', 'Contratos', 'Todos os contratos', $cap, 'ril-contracts', 'ril_page_main' );
	add_submenu_page( 'ril-contracts', 'Novo contrato', 'Novo contrato', $cap, 'ril-contracts-new', 'ril_page_new' );
	add_submenu_page( 'ril-contracts', 'Agenda e soft holds', 'Agenda', $cap, 'ril-contracts-agenda', 'ril_page_agenda' );
	add_submenu_page( 'ril-contracts', 'Modelos', 'Modelos', $cap, 'ril-contracts-templates', 'ril_page_templates' );
	add_submenu_page( 'ril-contracts', 'Configurações', 'Configurações', $cap, 'ril-contracts-settings', 'ril_page_settings' );
} );

add_action( 'admin_enqueue_scripts', function ( $hook ) {
	if ( false === strpos( $hook, 'ril-contracts' ) ) {
		return;
	}
	wp_enqueue_style( 'ril-contracts', RIL_CONTRACTS_URL . 'assets/ril.css', array(), RIL_CONTRACTS_VERSION );
	if ( false !== strpos( $hook, 'ril-contracts-settings' ) ) {
		wp_enqueue_media();
	}
	wp_enqueue_script( 'ril-contracts', RIL_CONTRACTS_URL . 'assets/ril.js', array(), RIL_CONTRACTS_VERSION, true );
} );

function ril_guard( $nonce ) {
	if ( ! current_user_can( ril_cap() ) ) {
		wp_die( 'Sem permissão.', 403 );
	}
	check_admin_referer( $nonce );
}

function ril_post( $key, $default = '' ) {
	return isset( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) : $default;
}

function ril_post_text( $key ) {
	return isset( $_POST[ $key ] ) ? str_replace( "\r\n", "\n", wp_unslash( $_POST[ $key ] ) ) : '';
}

function ril_go( $args ) {
	wp_safe_redirect( ril_admin_url( $args ) );
	exit;
}

function ril_notice( $msg, $type = 'success' ) {
	echo '<div class="notice notice-' . esc_attr( $type ) . ' is-dismissible"><p>' . esc_html( $msg ) . '</p></div>';
}

/** Campo do pacote: lista de opções (se o modelo tiver) ou texto livre. */
function ril_package_input( $name, $options, $value = '' ) {
	$field = 'sf[' . esc_attr( $name ) . ']';
	if ( $options ) {
		if ( '' !== $value && ! in_array( $value, $options, true ) ) {
			$options[] = $value;
		}
		$html = '<select name="' . $field . '" required><option value="">Escolha…</option>';
		foreach ( $options as $o ) {
			$html .= '<option value="' . esc_attr( $o ) . '"' . selected( $value, $o, false ) . '>' . esc_html( $o ) . '</option>';
		}
		return $html . '</select>';
	}
	return '<input name="' . $field . '" required value="' . esc_attr( $value ) . '">';
}

/** Opções dos campos do pacote, vindas do modelo usado no contrato. */
function ril_contract_package_fields( $c ) {
	$tpl    = $c['template_id'] ? ril_get_template( (int) $c['template_id'] ) : null;
	$fields = $tpl ? ril_studio_fields( $tpl['body'] ) : array();
	foreach ( (array) $c['studio_fields'] as $k => $v ) {
		if ( '_' !== substr( $k, 0, 1 ) && ! isset( $fields[ $k ] ) ) {
			$fields[ $k ] = array();
		}
	}
	return $fields;
}

/** Valores automáticos do modelo ({{total}}, {{deposit}}...) para um contrato. */
function ril_auto_values( $name, $date, $total, $deposit, $created_at ) {
	$s = ril_settings();
	return array(
		'studio_name'  => $s['studio_name'],
		'client_names' => $name,
		'wedding_date' => ril_long_date( $date ),
		'total'        => ril_money( $total ),
		'deposit'      => ril_money( $deposit ),
		'balance'      => ril_money( max( 0, $total - $deposit ) ),
		'today'        => gmdate( 'F j, Y', $created_at ? strtotime( $created_at . ' UTC' ) : time() ),
	);
}

function ril_wedding_cell( $r ) {
	if ( ! empty( $r['soft_hold'] ) && $r['wedding_month'] ) {
		return '<span class="ril-badge ril-hold-badge">Soft hold · ' . esc_html( ril_month_pt( $r['wedding_month'] ) ) . '</span>';
	}
	return $r['wedding_date'] ? esc_html( ril_long_date( $r['wedding_date'] ) ) : '<span class="ril-muted">—</span>';
}

function ril_badge( $status ) {
	$labels = ril_status_labels() + ril_payment_labels();
	return '<span class="ril-badge ril-' . esc_attr( $status ) . '">' . esc_html( isset( $labels[ $status ] ) ? $labels[ $status ] : $status ) . '</span>';
}

/* =========================================================
 * Lista e detalhe
 * ======================================================= */

function ril_page_main() {
	$view = isset( $_GET['view'] ) ? sanitize_key( $_GET['view'] ) : '';
	if ( 'contract' === $view && ! empty( $_GET['id'] ) ) {
		ril_page_contract( (int) $_GET['id'] );
		return;
	}
	$rows    = ril_list_contracts();
	$waiting = 0;
	foreach ( $rows as $r ) {
		if ( 'signed' === ril_status( $r ) ) {
			$waiting++;
		}
	}
	echo '<div class="wrap ril-wrap"><h1 class="wp-heading-inline">Contratos</h1> <a class="page-title-action" href="' . esc_url( admin_url( 'admin.php?page=ril-contracts-new' ) ) . '">Novo contrato</a><hr class="wp-header-end">';
	if ( isset( $_GET['deleted'] ) ) {
		ril_notice( 'Contrato apagado.' );
	}
	if ( $waiting ) {
		ril_notice( 1 === $waiting ? '1 contrato esperando a sua contrassinatura.' : $waiting . ' contratos esperando a sua contrassinatura.', 'warning' );
	}
	if ( ! $rows ) {
		echo '<div class="ril-card ril-center" style="max-width:640px;margin-top:20px"><h2>Nenhum contrato ainda</h2><p>Crie o primeiro a partir do modelo. O casal recebe um link para ler e assinar pelo celular.</p><a class="ril-btn" href="' . esc_url( admin_url( 'admin.php?page=ril-contracts-new' ) ) . '">Criar contrato</a></div></div>';
		return;
	}
	echo '<table class="widefat striped ril-table" style="margin-top:16px"><thead><tr><th>Casal</th><th>Casamento</th><th>Total</th><th>Status</th><th>Pagamento</th><th>Criado</th></tr></thead><tbody>';
	foreach ( $rows as $r ) {
		$st  = ril_status( $r );
		$url = ril_admin_url( array( 'view' => 'contract', 'id' => $r['id'] ) );
		echo '<tr><td><a class="row-title" href="' . esc_url( $url ) . '">' . esc_html( $r['client_name'] ) . '</a><div class="ril-muted">' . esc_html( $r['title'] ) . '</div></td>';
		echo '<td>' . ril_wedding_cell( $r ) . '</td>'; // phpcs:ignore
		echo '<td>' . esc_html( $r['total_cents'] ? ril_money( $r['total_cents'] ) : '—' ) . '</td>';
		echo '<td>' . ril_badge( $st ) . '</td>'; // phpcs:ignore
		echo '<td>' . ( in_array( $st, array( 'signed', 'completed' ), true ) && $r['deposit_cents'] > 0 ? ril_badge( $r['payment_status'] ) : '<span class="ril-muted">—</span>' ) . '</td>'; // phpcs:ignore
		echo '<td>' . esc_html( ril_local( $r['created_at'] ) ) . '</td></tr>';
	}
	echo '</tbody></table></div>';
}

function ril_page_contract( $id ) {
	$c = ril_get_contract( $id );
	if ( ! $c ) {
		echo '<div class="wrap"><h1>Contrato não encontrado</h1></div>';
		return;
	}
	$s       = ril_settings();
	$st      = ril_status( $c );
	$link    = ril_client_url( $c['token'] );
	$editing = in_array( $st, array( 'draft', 'sent', 'expired' ), true ) && ! empty( $_GET['edit'] );
	$first   = preg_split( '/[ &]+/', $c['client_name'] )[0];
	$msg     = "Hi {$first}! Here is your contract with {$s['studio_name']} to review and sign: {$link}";
	$wa      = 'https://wa.me/?text=' . rawurlencode( $msg );
	$mailto  = 'mailto:' . rawurlencode( $c['client_email'] ) . '?subject=' . rawurlencode( 'Your contract with ' . $s['studio_name'] ) . '&body=' . rawurlencode( "Hi {$c['client_name']},\n\nHere is your contract to review and sign:\n{$link}\n\n" . ( $s['signer_name'] ? $s['signer_name'] : $s['studio_name'] ) );

	echo '<div class="wrap ril-wrap">';
	echo '<p><a href="' . esc_url( ril_admin_url() ) . '">← Contratos</a></p>';
	echo '<div class="ril-head"><div><h1>' . esc_html( $c['client_name'] ) . '</h1><p class="ril-muted">' . esc_html( $c['title'] ) . ' · ' . ril_wedding_cell( $c ) . '</p></div>' . ril_badge( $st ) . '</div>'; // phpcs:ignore

	$notices = array(
		'saved'   => 'Alterações salvas.',
		'updated' => 'Contrato atualizado. O casal já vê o texto novo no mesmo link.',
		'sent'    => 'Contrato enviado. Copie o link e mande para o casal (se o e-mail automático não estiver ligado).',
		'done'    => 'Contrato concluído. O PDF final está pronto e foi enviado por e-mail.',
		'paid'    => 'Pagamento atualizado.',
		'created' => 'Rascunho criado. Revise o texto e clique em "Enviar para assinatura".',
		'confirmed' => 'Data do casamento confirmada.',
	);
	foreach ( $notices as $k => $m ) {
		if ( isset( $_GET[ $k ] ) ) {
			ril_notice( $m );
		}
	}
	if ( isset( $_GET['mailfail'] ) ) {
		ril_notice( 'O e-mail não foi enviado pelo site. Mande o link manualmente (WhatsApp ou e-mail).', 'warning' );
	}
	if ( isset( $_GET['err'] ) ) {
		ril_notice( sanitize_text_field( wp_unslash( $_GET['err'] ) ), 'error' );
	}

	echo '<div class="ril-layout"><div class="ril-main">';
	if ( $editing ) {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="ril-card">';
		wp_nonce_field( 'ril_update_' . $c['id'] );
		echo '<input type="hidden" name="action" value="ril_update"><input type="hidden" name="id" value="' . esc_attr( $c['id'] ) . '">';
		echo '<div class="ril-grid">';
		echo '<label class="ril-field"><span>Título</span><input name="title" value="' . esc_attr( $c['title'] ) . '"></label>';
		echo '<label class="ril-field"><span>Nomes do casal</span><input name="client_name" value="' . esc_attr( $c['client_name'] ) . '"></label>';
		echo '<label class="ril-field"><span>E-mail do casal</span><input type="email" name="client_email" value="' . esc_attr( $c['client_email'] ) . '"></label>';
		echo '<label class="ril-field"><span>Link vale por (dias)</span><input type="number" min="1" max="365" name="link_days" value="' . esc_attr( $c['link_days'] ) . '"></label>';
		echo '<label class="ril-field"><span>Total (US$)</span><input name="total" inputmode="decimal" value="' . esc_attr( number_format( $c['total_cents'] / 100, 2, '.', ',' ) ) . '"></label>';
		echo '<label class="ril-field"><span>Sinal (US$)</span><input name="deposit" inputmode="decimal" value="' . esc_attr( number_format( $c['deposit_cents'] / 100, 2, '.', ',' ) ) . '"></label>';
		foreach ( ril_contract_package_fields( $c ) as $f => $opts ) {
			$val = isset( $c['studio_fields'][ $f ] ) ? (string) $c['studio_fields'][ $f ] : '';
			echo '<label class="ril-field"><span>' . esc_html( $f ) . '</span>' . ril_package_input( $f, $opts, $val ) . '</label>'; // phpcs:ignore
		}
		echo '</div>';
		echo '<label class="ril-field"><span>Texto deste contrato</span><textarea name="body" class="ril-code">' . esc_textarea( $c['body'] ) . '</textarea><small>Valores, nomes, horas e fotógrafos mudados acima são atualizados no texto sozinhos. Mudanças no texto valem só para este casal.</small></label>';
		if ( 'draft' !== $st ) {
			echo '<div class="ril-alert" style="background:#f4ead8;color:#8a5a12">Este contrato já foi enviado. O link do casal continua o mesmo e passa a mostrar o texto novo. A validade do link é renovada.</div>';
			if ( $c['client_email'] ) {
				echo '<label class="ril-check"><input type="checkbox" name="notify" value="1" checked><span>Avisar o casal por e-mail que o contrato foi atualizado</span></label>';
			}
		}
		echo '<button class="ril-btn">Salvar</button> <a class="ril-btn ril-btn-secondary" href="' . esc_url( ril_admin_url( array( 'view' => 'contract', 'id' => $c['id'] ) ) ) . '">Cancelar</a></form>';
	} else {
		echo ril_render_doc( $c['title'], $c['body'], 'view', $c['client_fields'], '<div class="ril-eyebrow">' . esc_html( $s['studio_name'] . ' · ' . $c['client_name'] ) . '</div>' ); // phpcs:ignore
	}

	if ( $c['client_signature'] || $c['studio_signature'] ) {
		echo '<div class="ril-card" style="margin-top:16px"><div class="ril-grid">';
		foreach ( array( 'Casal' => $c['client_signature'], 'Estúdio' => $c['studio_signature'] ) as $label => $sig ) {
			echo '<div><div class="ril-eyebrow">' . esc_html( $label ) . '</div>';
			if ( $sig ) {
				echo '<img class="ril-sig-img" alt="" src="' . esc_attr( $sig['image'] ) . '"><div><b>' . esc_html( $sig['name'] ) . '</b></div><div class="ril-muted ril-small">' . esc_html( ril_local( $sig['at'] ) . ' · IP ' . $sig['ip'] ) . '</div>';
			} else {
				echo '<div class="ril-muted">Aguardando</div>';
			}
			echo '</div>';
		}
		echo '</div></div>';
	}
	echo '</div><aside class="ril-side">';

	$post = esc_url( admin_url( 'admin-post.php' ) );

	if ( 'draft' === $st ) {
		$unfilled = ril_has_unfilled( $c['body'] );
		$blanks   = ril_client_field_names( $c['body'] );
		echo '<div class="ril-card"><h3>Revisar e enviar</h3>';
		if ( $unfilled ) {
			echo '<div class="ril-alert ril-error">Há campos {{ }} sem preencher. Clique em Editar e complete o texto.</div>';
		}
		echo '<p class="ril-small ril-muted">Depois de enviado, o texto fica travado. O casal vai preencher: ' . esc_html( $blanks ? implode( ', ', $blanks ) : 'nada' ) . '.</p>';
		echo '<form method="post" action="' . $post . '">'; // phpcs:ignore
		wp_nonce_field( 'ril_send_' . $c['id'] );
		echo '<input type="hidden" name="action" value="ril_send"><input type="hidden" name="id" value="' . esc_attr( $c['id'] ) . '">';
		if ( $c['client_email'] ) {
			echo '<label class="ril-check"><input type="checkbox" name="email" value="1" checked><span>Mandar o e-mail de boas-vindas com o link para ' . esc_html( $c['client_email'] ) . '</span></label>';
		}
		echo '<button class="ril-btn ril-block"' . ( $unfilled ? ' disabled' : '' ) . '>Enviar para assinatura</button></form>';
		echo '<div class="ril-actions" style="margin-top:12px"><a class="ril-btn ril-btn-secondary ril-btn-small" href="' . esc_url( ril_admin_url( array( 'view' => 'contract', 'id' => $c['id'], 'edit' => 1 ) ) ) . '">Editar</a>';
		ril_small_form( 'ril_delete', $c['id'], 'Apagar rascunho', 'ril-btn-danger', 'Apagar este rascunho?' );
		echo '</div></div>';
	}

	if ( in_array( $st, array( 'signed', 'completed' ), true ) && ( ! empty( $c['soft_hold'] ) || $c['date_confirmed_at'] ) ) {
		echo '<div class="ril-card"><h3>Data do casamento</h3>';
		if ( ! empty( $c['soft_hold'] ) ) {
			echo '<p><span class="ril-badge ril-hold-badge">Soft hold · ' . esc_html( ril_month_pt( $c['wedding_month'] ) ) . '</span></p>';
			echo '<p class="ril-small ril-muted">O casal ainda não tem o dia. Quando decidir, coloque a data aqui.</p>';
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			wp_nonce_field( 'ril_confirm_date_' . $c['id'] );
			echo '<input type="hidden" name="action" value="ril_confirm_date"><input type="hidden" name="id" value="' . esc_attr( $c['id'] ) . '">';
			$min = $c['wedding_month'] ? $c['wedding_month'] . '-01' : '';
			echo '<label class="ril-field"><span>Data confirmada</span><input type="date" name="date" required value="' . esc_attr( $min ) . '"></label>';
			if ( $c['client_email'] ) {
				echo '<label class="ril-check" style="margin:0 0 12px"><input type="checkbox" name="email" value="1" checked><span>Avisar o casal por e-mail</span></label>';
			}
			echo '<button class="ril-btn ril-block">Confirmar data</button></form>';
		} else {
			echo '<p>' . esc_html( ril_long_date( $c['wedding_date'] ) ) . '</p><p class="ril-small ril-muted" style="margin:0">Confirmada em ' . esc_html( ril_local( $c['date_confirmed_at'] ) ) . ' (era soft hold).</p>';
		}
		echo '</div>';
	}

	if ( in_array( $st, array( 'sent', 'signed', 'completed', 'expired' ), true ) ) {
		echo '<div class="ril-card"><h3>Link do casal</h3><div class="ril-linkbox"><input readonly value="' . esc_attr( $link ) . '" id="ril-link"><button type="button" class="ril-btn ril-btn-small" data-copy="#ril-link">Copiar</button></div>';
		if ( 'sent' === $st ) {
			echo '<div class="ril-actions" style="margin-top:10px"><a class="ril-btn ril-btn-secondary ril-btn-small" target="_blank" rel="noopener" href="' . esc_url( $wa ) . '">WhatsApp</a>';
			if ( $c['client_email'] ) {
				echo '<a class="ril-btn ril-btn-secondary ril-btn-small" href="' . esc_attr( $mailto ) . '">E-mail</a>';
			}
			echo '<a class="ril-btn ril-btn-secondary ril-btn-small" target="_blank" rel="noopener" href="' . esc_url( $link ) . '">Ver como o casal</a>';
			echo '<a class="ril-btn ril-btn-secondary ril-btn-small" href="' . esc_url( ril_admin_url( array( 'view' => 'contract', 'id' => $c['id'], 'edit' => 1 ) ) ) . '">Editar contrato</a></div>';
			if ( $c['expires_at'] ) {
				echo '<p class="ril-small ril-muted" style="margin:10px 0 0">Vale até ' . esc_html( ril_local( $c['expires_at'] ) ) . '.</p>';
			}
		}
		if ( 'expired' === $st ) {
			echo '<p class="ril-small ril-muted" style="margin:10px 0 0">O link expirou. Para reenviar, clique em Editar contrato e salve: a validade é renovada.</p>';
			echo '<a class="ril-btn ril-btn-secondary ril-btn-small" style="margin-top:8px" href="' . esc_url( ril_admin_url( array( 'view' => 'contract', 'id' => $c['id'], 'edit' => 1 ) ) ) . '">Editar contrato</a>';
		}
		echo '</div>';
	}

	if ( 'signed' === $st ) {
		echo '<div class="ril-card"><h3>Contrassinar</h3><p class="ril-small ril-muted">O casal já assinou. Assine para concluir e gerar o PDF final.</p>';
		echo '<form method="post" action="' . $post . '" data-ril-require-sig>'; // phpcs:ignore
		wp_nonce_field( 'ril_countersign_' . $c['id'] );
		echo '<input type="hidden" name="action" value="ril_countersign"><input type="hidden" name="id" value="' . esc_attr( $c['id'] ) . '">';
		echo '<label class="ril-field"><span>Quem assina pelo estúdio</span><input name="signer_name" required value="' . esc_attr( $s['signer_name'] ) . '"></label>';
		echo '<label class="ril-field"><span>Cargo</span><input name="signer_title" value="' . esc_attr( $s['signer_title'] ) . '" placeholder="Owner / Lead Photographer"></label>';
		echo '<div class="ril-field"><span>Assinatura</span>' . ril_signature_pad_html( 'Assine aqui', 'Limpar' ) . '</div>'; // phpcs:ignore
		echo '<button class="ril-btn ril-block">Contrassinar e concluir</button></form></div>';
	}

	if ( in_array( $st, array( 'signed', 'completed' ), true ) ) {
		echo '<div class="ril-card"><h3>PDF</h3><a class="ril-btn ril-btn-secondary ril-block" target="_blank" href="' . esc_url( ril_client_url( $c['token'], true ) ) . '">' . ( 'completed' === $st ? 'Baixar contrato final' : 'Baixar (falta sua assinatura)' ) . '</a>';
		if ( $c['pdf_hash'] ) {
			echo '<p class="ril-small ril-muted" style="word-break:break-all;margin:10px 0 0">SHA-256 do PDF: <span id="ril-pdf-hash">' . esc_html( $c['pdf_hash'] ) . '</span></p>';
		}
		echo '</div>';
	}

	if ( $c['total_cents'] > 0 || $c['deposit_cents'] > 0 ) {
		echo '<div class="ril-card"><h3>Pagamento</h3><dl class="ril-kv"><dt>Total</dt><dd>' . esc_html( ril_money( $c['total_cents'] ) ) . '</dd><dt>Sinal</dt><dd>' . esc_html( ril_money( $c['deposit_cents'] ) ) . '</dd><dt>Saldo</dt><dd>' . esc_html( ril_money( max( 0, $c['total_cents'] - $c['deposit_cents'] ) ) ) . '</dd><dt>Status</dt><dd>' . ril_badge( $c['payment_status'] ) . '</dd></dl>'; // phpcs:ignore
		echo '<div class="ril-actions" style="margin-top:12px">';
		foreach ( array( 'deposit_paid' => 'Sinal recebido', 'paid_in_full' => 'Pago total', 'unpaid' => 'Voltar a pendente' ) as $p => $label ) {
			if ( $p !== $c['payment_status'] ) {
				ril_small_form( 'ril_payment', $c['id'], $label, 'ril-btn-secondary', '', array( 'status' => $p ) );
			}
		}
		echo '</div></div>';
	}

	$events = ril_get_events( $c['id'] );
	$labels = ril_event_labels();
	echo '<div class="ril-card"><h3>Histórico</h3><ul class="ril-timeline">';
	foreach ( $events as $e ) {
		echo '<li><div>' . esc_html( isset( $labels[ $e['type'] ] ) ? $labels[ $e['type'] ] : $e['type'] ) . '</div><div class="ril-muted ril-small">' . esc_html( ril_local( $e['at'] ) . ( $e['ip'] && 'unknown' !== $e['ip'] ? ' · IP ' . $e['ip'] : '' ) ) . '</div></li>';
	}
	echo '</ul></div>';

	if ( in_array( $st, array( 'sent', 'signed', 'expired' ), true ) ) {
		ril_small_form( 'ril_cancel', $c['id'], 'Cancelar contrato', 'ril-btn-danger', 'Cancelar este contrato? O link do casal deixa de funcionar.' );
	}
	if ( 'canceled' === $st ) {
		ril_small_form( 'ril_delete', $c['id'], 'Apagar', 'ril-btn-danger', 'Apagar de vez?' );
	}
	echo '</aside></div></div>';
}

function ril_small_form( $action, $id, $label, $class, $confirm = '', $extra = array() ) {
	echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline"' . ( $confirm ? ' data-confirm="' . esc_attr( $confirm ) . '"' : '' ) . '>';
	wp_nonce_field( $action . '_' . $id );
	echo '<input type="hidden" name="action" value="' . esc_attr( $action ) . '"><input type="hidden" name="id" value="' . esc_attr( $id ) . '">';
	foreach ( $extra as $k => $v ) {
		echo '<input type="hidden" name="' . esc_attr( $k ) . '" value="' . esc_attr( $v ) . '">';
	}
	echo '<button class="ril-btn ril-btn-small ' . esc_attr( $class ) . '">' . esc_html( $label ) . '</button></form>';
}

/* =========================================================
 * Novo contrato
 * ======================================================= */

function ril_page_new() {
	$templates = ril_get_templates();
	$tid       = isset( $_GET['template'] ) ? (int) $_GET['template'] : ( $templates ? (int) $templates[0]['id'] : 0 );
	$tpl       = $tid ? ril_get_template( $tid ) : null;
	$s         = ril_settings();

	echo '<div class="wrap ril-wrap"><h1>Novo contrato</h1>';
	if ( ! $tpl ) {
		echo '<p>Nenhum modelo encontrado. <a href="' . esc_url( admin_url( 'admin.php?page=ril-contracts-templates&edit=new' ) ) . '">Criar modelo</a></p></div>';
		return;
	}
	$fields = ril_studio_fields( $tpl['body'] );
	echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="ril-card" style="max-width:820px;margin-top:16px">';
	wp_nonce_field( 'ril_create' );
	echo '<input type="hidden" name="action" value="ril_create">';
	if ( count( $templates ) > 1 ) {
		echo '<label class="ril-field"><span>Modelo</span><select name="template_id" data-reload-template>';
		foreach ( $templates as $t ) {
			echo '<option value="' . esc_attr( $t['id'] ) . '"' . selected( (int) $t['id'], $tid, false ) . '>' . esc_html( $t['name'] ) . '</option>';
		}
		echo '</select></label>';
	} else {
		echo '<input type="hidden" name="template_id" value="' . esc_attr( $tid ) . '">';
	}
	echo '<h3>Casal</h3><div class="ril-grid">';
	echo '<label class="ril-field"><span>Nomes do casal</span><input name="client_name" required placeholder="Ana Silva & John Smith"></label>';
	echo '<label class="ril-field"><span>E-mail do casal</span><input type="email" name="client_email" placeholder="ana@email.com"></label>';
	if ( preg_match( '/\{\{\s*wedding_date\s*\}\}/', $tpl['body'] ) ) {
		echo '<label class="ril-field"><span>Data do casamento</span><input type="date" name="wedding_date"></label>';
	}
	echo '</div><h3>Valores (US$)</h3><div class="ril-grid">';
	echo '<label class="ril-field"><span>Total</span><input name="total" inputmode="decimal" placeholder="5,400.00"></label>';
	echo '<label class="ril-field"><span>Sinal</span><input name="deposit" inputmode="decimal" placeholder="2,160.00"></label>';
	echo '</div>';
	$client_fields = ril_client_field_names( $tpl['body'] );
	if ( $client_fields ) {
		echo '<p class="ril-small ril-muted" style="margin-top:-4px">O casal preenche ao assinar: ' . esc_html( implode( ', ', $client_fields ) ) . '.</p>';
	}
	if ( $fields ) {
		echo '<h3>Pacote</h3><div class="ril-grid">';
		foreach ( $fields as $f => $opts ) {
			echo '<label class="ril-field"><span>' . esc_html( $f ) . '</span>' . ril_package_input( $f, $opts ) . '</label>'; // phpcs:ignore
		}
		echo '</div>';
	}
	echo '<h3>Opções</h3><div class="ril-grid">';
	echo '<label class="ril-field"><span>Título</span><input name="title" value="' . esc_attr( preg_replace( '/^.*— /u', '', $tpl['name'] ) ) . '"></label>';
	echo '<label class="ril-field"><span>Link vale por (dias)</span><input type="number" name="link_days" min="1" max="365" value="' . esc_attr( $s['link_days'] ) . '"></label>';
	echo '</div><button class="ril-btn">Criar rascunho</button> <span class="ril-muted ril-small">Você revisa o texto antes de enviar.</span></form></div>';
}

/* =========================================================
 * Modelos
 * ======================================================= */

function ril_page_templates() {
	$edit = isset( $_GET['edit'] ) ? sanitize_text_field( wp_unslash( $_GET['edit'] ) ) : '';
	echo '<div class="wrap ril-wrap">';
	if ( '' === $edit ) {
		echo '<h1 class="wp-heading-inline">Modelos de contrato</h1> <a class="page-title-action" href="' . esc_url( admin_url( 'admin.php?page=ril-contracts-templates&edit=new' ) ) . '">Novo modelo</a><hr class="wp-header-end">';
		if ( isset( $_GET['deleted'] ) ) {
			ril_notice( 'Modelo apagado.' );
		}
		echo '<table class="widefat striped" style="margin-top:16px"><thead><tr><th>Nome</th><th>Campos de vocês</th><th>Campos do casal</th><th>Editado</th></tr></thead><tbody>';
		foreach ( ril_get_templates() as $t ) {
			echo '<tr><td><a class="row-title" href="' . esc_url( admin_url( 'admin.php?page=ril-contracts-templates&edit=' . $t['id'] ) ) . '">' . esc_html( $t['name'] ) . '</a></td><td>' . esc_html( implode( ', ', ril_studio_field_names( $t['body'] ) ) ) . '</td><td>' . esc_html( implode( ', ', ril_client_field_names( $t['body'] ) ) ) . '</td><td>' . esc_html( ril_local( $t['updated_at'] ) ) . '</td></tr>';
		}
		echo '</tbody></table></div>';
		return;
	}
	$tpl = 'new' === $edit ? null : ril_get_template( (int) $edit );
	echo '<p><a href="' . esc_url( admin_url( 'admin.php?page=ril-contracts-templates' ) ) . '">← Modelos</a></p><h1>' . esc_html( $tpl ? $tpl['name'] : 'Novo modelo' ) . '</h1>';
	if ( isset( $_GET['saved'] ) ) {
		ril_notice( 'Modelo salvo.' );
	}
	echo '<div class="ril-layout"><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="ril-card ril-main">';
	wp_nonce_field( 'ril_template' );
	echo '<input type="hidden" name="action" value="ril_template"><input type="hidden" name="id" value="' . esc_attr( $tpl ? $tpl['id'] : '' ) . '">';
	echo '<label class="ril-field"><span>Nome do modelo</span><input name="name" required value="' . esc_attr( $tpl ? $tpl['name'] : '' ) . '"></label>';
	echo '<label class="ril-field"><span>Texto</span><textarea name="body" class="ril-code" required>' . esc_textarea( $tpl ? $tpl['body'] : "# Title\n\nText with {{Studio field}} and [[Client field]]." ) . '</textarea></label>';
	echo '<button class="ril-btn">Salvar modelo</button></form>';
	echo '<aside class="ril-side"><div class="ril-card ril-small"><h3>Como escrever</h3>';
	echo '<p><code># Título</code> e <code>## Seção</code> viram títulos. Linha em branco separa parágrafos. <code>- item</code> vira lista.</p>';
	echo '<p><code>{{Local}}</code> = campo que <b>vocês</b> preenchem ao criar o contrato.</p>';
	echo '<p><code>[[Endereço]]</code> = campo que <b>o casal</b> preenche antes de assinar.</p>';
	echo '<p>Preenchidos sozinhos: <code>{{client_names}}</code> <code>{{wedding_date}}</code> <code>{{total}}</code> <code>{{deposit}}</code> <code>{{balance}}</code> <code>{{studio_name}}</code> <code>{{today}}</code></p></div>';
	if ( $tpl ) {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" data-confirm="Apagar este modelo? Contratos já criados não mudam.">';
		wp_nonce_field( 'ril_template_delete' );
		echo '<input type="hidden" name="action" value="ril_template_delete"><input type="hidden" name="id" value="' . esc_attr( $tpl['id'] ) . '"><button class="ril-btn ril-btn-small ril-btn-danger">Apagar modelo</button></form>';
	}
	echo '</aside></div></div>';
}

/* =========================================================
 * Configurações
 * ======================================================= */

function ril_page_settings() {
	$s = ril_settings();
	echo '<div class="wrap ril-wrap"><h1>Configurações dos contratos</h1>';
	if ( isset( $_GET['saved'] ) ) {
		ril_notice( 'Configurações salvas.' );
	}
	echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="ril-card" style="max-width:820px;margin-top:16px">';
	wp_nonce_field( 'ril_settings' );
	echo '<input type="hidden" name="action" value="ril_settings"><h3>Estúdio</h3><div class="ril-grid">';
	echo '<label class="ril-field"><span>Nome do estúdio</span><input name="studio_name" required value="' . esc_attr( $s['studio_name'] ) . '"></label>';
	echo '<label class="ril-field"><span>E-mail do estúdio</span><input type="email" name="email" value="' . esc_attr( $s['email'] ) . '"><small>Recebe aviso quando o casal assina e a cópia final.</small></label>';
	echo '<label class="ril-field"><span>Quem contrassina</span><input name="signer_name" value="' . esc_attr( $s['signer_name'] ) . '" placeholder="Daniel …"></label>';
	echo '<label class="ril-field"><span>Cargo</span><input name="signer_title" value="' . esc_attr( $s['signer_title'] ) . '" placeholder="Owner / Lead Photographer"></label>';
	echo '<label class="ril-field"><span>Link vale por (dias), padrão</span><input type="number" min="1" max="365" name="link_days" value="' . esc_attr( $s['link_days'] ) . '"></label>';
	echo '</div><h3>Logo</h3>';
	$logo_url = ril_logo_url();
	echo '<div class="ril-logo-field" data-ril-logo>';
	echo '<div class="ril-logo-preview">' . ( $logo_url ? '<img src="' . esc_url( $logo_url ) . '" alt="">' : '<span class="ril-muted">Nenhuma logo</span>' ) . '</div>';
	echo '<input type="hidden" name="logo_id" value="' . esc_attr( (int) $s['logo_id'] ) . '">';
	echo '<button type="button" class="ril-btn ril-btn-secondary ril-btn-small" data-ril-logo-pick>Escolher logo</button> ';
	echo '<button type="button" class="ril-btn ril-btn-secondary ril-btn-small" data-ril-logo-clear>Remover</button>';
	echo '<p class="ril-small ril-muted" style="margin:8px 0 0">Aparece na página do casal, nos e-mails e no PDF. Use PNG ou JPG (o PDF não aceita SVG). Sem logo escolhida, usa a logo do tema do site.</p></div>';

	echo '<h3>Remetente dos e-mails</h3><div class="ril-grid">';
	echo '<label class="ril-field"><span>Nome que aparece para o casal</span><input name="from_name" value="' . esc_attr( $s['from_name'] ) . '"></label>';
	echo '<label class="ril-field"><span>E-mail de envio</span><input type="email" name="from_email" value="' . esc_attr( $s['from_email'] ) . '" placeholder="' . esc_attr( ril_default_from_email() ) . '"><small>Use um e-mail do domínio do site (ex.: contact@rockerinlove.com) para não cair no spam.</small></label>';
	echo '</div>';
	echo '<h3>E-mail de boas-vindas (com o link do contrato)</h3>';
	echo '<label class="ril-field"><span>Assunto</span><input name="email_subject" value="' . esc_attr( $s['email_subject'] ) . '"></label>';
	echo '<label class="ril-field"><span>Texto</span><textarea name="email_body" rows="12">' . esc_textarea( $s['email_body'] ) . '</textarea>';
	echo '<small>Use {first_names} para o primeiro nome do casal (ex.: Ana &amp; John), {client_names} para os nomes completos e {button} onde o botão do contrato deve aparecer. Deixe em branco para voltar ao texto padrão.</small></label>';
	echo '<label class="ril-field"><span>Texto do botão</span><input name="email_button" value="' . esc_attr( $s['email_button'] ) . '"></label>';
	echo '<h3>Pagamento</h3>';
	echo '<label class="ril-field"><span>Instruções de pagamento (em inglês, o casal vê depois de assinar)</span><textarea name="payment_instructions" rows="4" placeholder="Zelle: payments@rockerinlove.com (Rocker in Love)&#10;Please include your names in the memo.">' . esc_textarea( $s['payment_instructions'] ) . '</textarea></label>';
	echo '<div class="ril-grid"><label class="ril-field"><span>Link do PayPal (opcional)</span><input name="paypal_link" value="' . esc_attr( $s['paypal_link'] ) . '" placeholder="https://paypal.me/rockerinlove"><small>Se for paypal.me, o valor entra no link sozinho.</small></label>';
	echo '<label class="ril-field"><span>Taxa do PayPal (%)</span><input name="paypal_fee_percent" inputmode="decimal" value="' . esc_attr( $s['paypal_fee_percent'] ) . '"><small>Somada ao sinal quando o casal paga pelo PayPal.</small></label></div>';
	echo '<h3>Atualizações automáticas (GitHub)</h3><div class="ril-grid">';
	echo '<label class="ril-field"><span>Repositório</span><input name="update_repo" value="' . esc_attr( $s['update_repo'] ) . '" placeholder="seu-usuario/ril-contracts"></label>';
	echo '<label class="ril-field"><span>Chave de acesso (só se o repositório for privado)</span><input type="password" name="update_token" autocomplete="off" value="" placeholder="' . esc_attr( $s['update_token'] ? 'Chave salva. Deixe em branco para manter.' : 'github_pat_…' ) . '"></label>';
	echo '</div>';
	if ( $s['update_token'] ) {
		echo '<label class="ril-check" style="margin:0 0 14px"><input type="checkbox" name="update_token_clear" value="1"><span>Apagar a chave salva</span></label>';
	}
	echo '<button class="ril-btn">Salvar</button></form>';

	echo '<div class="ril-card ril-small" style="max-width:820px;margin-top:16px"><h3>Status das atualizações</h3>';
	if ( ! RIL_Updater::repo() ) {
		echo '<p>Preencha o repositório acima para o plugin buscar versões novas sozinho.</p>';
	} else {
		$r = RIL_Updater::release( isset( $_GET['check'] ) );
		echo '<p>Versão instalada: <b>' . esc_html( RIL_CONTRACTS_VERSION ) . '</b>. ';
		if ( is_wp_error( $r ) ) {
			echo '<span style="color:#9b3b32">Não consegui verificar: ' . esc_html( $r->get_error_message() ) . '</span>';
		} elseif ( version_compare( $r['version'], RIL_Updater::installed_version(), '>' ) ) {
			echo 'Versão nova disponível: <b>' . esc_html( $r['version'] ) . '</b>. <a href="' . esc_url( admin_url( 'plugins.php' ) ) . '">Atualizar na tela de Plugins</a>.';
		} else {
			echo 'Está na versão mais recente.';
		}
		echo '</p><p><a class="ril-btn ril-btn-secondary ril-btn-small" href="' . esc_url( admin_url( 'admin.php?page=ril-contracts-settings&check=1' ) ) . '">Verificar agora</a></p>';
		echo '<p class="ril-muted">Dica: na tela de Plugins, clique em "Ativar atualizações automáticas" neste plugin para ele se atualizar sozinho.</p>';
	}
	echo '</div>';
	echo '<div class="ril-card ril-small" style="max-width:820px;margin-top:16px"><h3>E-mail</h3><p>Os e-mails saem pelo próprio WordPress, com o nome e o e-mail de remetente acima. Se o site não costuma enviar e-mails (ou eles caem no spam), instale um plugin de SMTP, por exemplo WP Mail SMTP, ligado ao e-mail contact@rockerinlove.com. Se esse plugin tiver a opção "Force From Name", deixe o nome como Rocker in Love. Sem e-mail, tudo funciona copiando o link.</p></div></div>';
}

/* =========================================================
 * Ações (admin-post.php)
 * ======================================================= */

add_action( 'admin_post_ril_settings', function () {
	ril_guard( 'ril_settings' );
	update_option( 'ril_contracts_settings', array(
		'studio_name'          => ril_post( 'studio_name', 'Rocker in Love' ),
		'email'                => sanitize_email( ril_post( 'email' ) ),
		'signer_name'          => ril_post( 'signer_name' ),
		'signer_title'         => ril_post( 'signer_title' ),
		'link_days'            => max( 1, min( 365, (int) ril_post( 'link_days', 14 ) ) ),
		'payment_instructions' => sanitize_textarea_field( ril_post_text( 'payment_instructions' ) ),
		'paypal_link'          => esc_url_raw( ril_post( 'paypal_link' ) ),
		'paypal_fee_percent'   => max( 0, min( 20, (float) str_replace( ',', '.', ril_post( 'paypal_fee_percent', '0' ) ) ) ),
		'logo_id'              => (int) ril_post( 'logo_id', 0 ),
		'from_name'            => ril_post( 'from_name' ) ? ril_post( 'from_name' ) : 'Rocker in Love',
		'update_repo'          => ril_post( 'update_repo' ),
		'update_token'         => ! empty( $_POST['update_token_clear'] ) ? '' : ( ril_post( 'update_token' ) ? ril_post( 'update_token' ) : ril_settings()['update_token'] ),
		'from_email'           => sanitize_email( ril_post( 'from_email' ) ),
		'email_subject'        => ril_post( 'email_subject' ) ? ril_post( 'email_subject' ) : 'Welcome to the Rocker in Love family!',
		'email_body'           => trim( ril_post_text( 'email_body' ) ) ? sanitize_textarea_field( ril_post_text( 'email_body' ) ) : ril_default_email_body(),
		'email_button'         => ril_post( 'email_button' ) ? ril_post( 'email_button' ) : 'Review & sign your contract',
	) );
	RIL_Updater::clear();
	delete_site_transient( 'update_plugins' );
	wp_safe_redirect( admin_url( 'admin.php?page=ril-contracts-settings&saved=1' ) );
	exit;
} );

add_action( 'admin_post_ril_template', function () {
	ril_guard( 'ril_template' );
	global $wpdb;
	$id   = (int) ril_post( 'id' );
	$name = ril_post( 'name', 'Sem título' );
	$body = ril_post_text( 'body' );
	if ( '' === trim( $body ) ) {
		wp_die( 'O texto do modelo está vazio.' );
	}
	if ( $id ) {
		$wpdb->update( ril_table( 'templates' ), array( 'name' => $name, 'body' => $body, 'updated_at' => ril_now() ), array( 'id' => $id ) );
	} else {
		$wpdb->insert( ril_table( 'templates' ), array( 'name' => $name, 'body' => $body, 'created_at' => ril_now(), 'updated_at' => ril_now() ) );
		$id = $wpdb->insert_id;
	}
	wp_safe_redirect( admin_url( 'admin.php?page=ril-contracts-templates&edit=' . $id . '&saved=1' ) );
	exit;
} );

add_action( 'admin_post_ril_template_delete', function () {
	ril_guard( 'ril_template_delete' );
	global $wpdb;
	$wpdb->delete( ril_table( 'templates' ), array( 'id' => (int) ril_post( 'id' ) ) );
	wp_safe_redirect( admin_url( 'admin.php?page=ril-contracts-templates&deleted=1' ) );
	exit;
} );

add_action( 'admin_post_ril_create', function () {
	ril_guard( 'ril_create' );
	global $wpdb;
	$tpl = ril_get_template( (int) ril_post( 'template_id' ) );
	if ( ! $tpl ) {
		wp_die( 'Modelo não encontrado.' );
	}
	$s       = ril_settings();
	$client  = ril_post( 'client_name' );
	$total   = ril_parse_money( ril_post( 'total' ) );
	$deposit = ril_parse_money( ril_post( 'deposit' ) );
	$date    = preg_match( '/^\d{4}-\d{2}-\d{2}$/', ril_post( 'wedding_date' ) ) ? ril_post( 'wedding_date' ) : '';
	if ( '' === $client ) {
		wp_die( 'Informe o nome do casal.' );
	}
	$custom = array();
	$posted = isset( $_POST['sf'] ) && is_array( $_POST['sf'] ) ? wp_unslash( $_POST['sf'] ) : array();
	foreach ( ril_studio_field_names( $tpl['body'] ) as $f ) {
		$custom[ $f ] = isset( $posted[ $f ] ) ? sanitize_text_field( $posted[ $f ] ) : '';
	}
	$auto = array(
		'studio_name'  => $s['studio_name'],
		'client_names' => $client,
		'wedding_date' => ril_long_date( $date ),
		'total'        => ril_money( $total ),
		'deposit'      => ril_money( $deposit ),
		'balance'      => ril_money( max( 0, $total - $deposit ) ),
		'today'        => gmdate( 'F j, Y' ),
	);
	$wpdb->insert( ril_table( 'contracts' ), array(
		'token'         => ril_new_token(),
		'template_id'   => $tpl['id'],
		'title'         => ril_post( 'title', $tpl['name'] ),
		'body'          => ril_fill_studio( $tpl['body'], array_merge( $auto, array_filter( $custom, 'strlen' ) ) ),
		'studio_fields' => wp_json_encode( $custom ),
		'client_fields' => wp_json_encode( array() ),
		'client_name'   => $client,
		'client_email'  => sanitize_email( ril_post( 'client_email' ) ),
		'wedding_date'  => $date,
		'total_cents'   => $total,
		'deposit_cents' => $deposit,
		'status'        => 'draft',
		'link_days'     => max( 1, min( 365, (int) ril_post( 'link_days', $s['link_days'] ) ) ),
		'created_at'    => ril_now(),
	) );
	$id = (int) $wpdb->insert_id;
	if ( ! $id ) {
		wp_die( 'Não foi possível salvar o contrato: ' . esc_html( $wpdb->last_error ) );
	}
	ril_add_event( $id, 'created', array( 'template' => $tpl['name'] ) );
	ril_go( array( 'view' => 'contract', 'id' => $id, 'created' => 1 ) );
} );

function ril_action_contract( $action ) {
	$id = (int) ril_post( 'id' );
	ril_guard( $action . '_' . $id );
	$c = ril_get_contract( $id );
	if ( ! $c ) {
		wp_die( 'Contrato não encontrado.' );
	}
	return $c;
}

add_action( 'admin_post_ril_update', function () {
	$c = ril_action_contract( 'ril_update' );
	if ( ! in_array( $c['status'], array( 'draft', 'sent' ), true ) ) {
		ril_go( array( 'view' => 'contract', 'id' => $c['id'], 'err' => 'Depois que o casal assina, o contrato não pode ser editado. Cancele e mande um novo, ou faça um aditivo.' ) );
	}
	$s         = ril_settings();
	$body      = ril_post_text( 'body' );
	$name      = ril_post( 'client_name', $c['client_name'] );
	$total     = '' !== ril_post( 'total' ) ? ril_parse_money( ril_post( 'total' ) ) : $c['total_cents'];
	$deposit   = '' !== ril_post( 'deposit' ) ? ril_parse_money( ril_post( 'deposit' ) ) : $c['deposit_cents'];
	$fields    = (array) $c['studio_fields'];
	$posted    = isset( $_POST['sf'] ) && is_array( $_POST['sf'] ) ? wp_unslash( $_POST['sf'] ) : array();
	$swap      = array();
	if ( $total !== $c['total_cents'] || $deposit !== $c['deposit_cents'] ) {
		$swap[ ril_money( $c['total_cents'] ) ]                                 = ril_money( $total );
		$swap[ ril_money( $c['deposit_cents'] ) ]                               = ril_money( $deposit );
		$swap[ ril_money( max( 0, $c['total_cents'] - $c['deposit_cents'] ) ) ] = ril_money( max( 0, $total - $deposit ) );
	}
	foreach ( $posted as $k => $v ) {
		$k = sanitize_text_field( $k );
		$v = sanitize_text_field( $v );
		if ( isset( $fields[ $k ] ) && '' !== (string) $fields[ $k ] && $v !== $fields[ $k ] && '' !== $v ) {
			$swap[ (string) $fields[ $k ] ] = $v;
		}
		if ( '' !== $v ) {
			$fields[ $k ] = $v;
		}
	}
	if ( $name && $name !== $c['client_name'] ) {
		$swap[ $c['client_name'] ] = $name;
	}
	foreach ( $swap as $from => $to ) {
		if ( $from === $to ) {
			unset( $swap[ $from ] );
		}
	}
	$tpl     = $c['template_id'] ? ril_get_template( (int) $c['template_id'] ) : null;
	$old_all = array_merge( ril_auto_values( $c['client_name'], $c['wedding_date'], $c['total_cents'], $c['deposit_cents'], $c['created_at'] ), array_filter( array_map( 'strval', (array) $c['studio_fields'] ), 'strlen' ) );
	if ( $swap && $tpl && $body === $c['body'] && ril_fill_studio( $tpl['body'], $old_all ) === $c['body'] ) {
		// Texto não foi mexido à mão: remonta a partir do modelo com os valores novos.
		$new_all = array_merge( ril_auto_values( $name, $c['wedding_date'], $total, $deposit, $c['created_at'] ), array_filter( array_map( 'strval', $fields ), 'strlen' ) );
		$body    = ril_fill_studio( $tpl['body'], $new_all );
	} elseif ( $swap ) {
		// Texto foi editado à mão: troca os valores antigos pelos novos, se não houver ambiguidade.
		$targets = array();
		foreach ( $swap as $from => $to ) {
			$targets[ $from ][] = $to;
		}
		$amounts = array( ril_money( $c['total_cents'] ), ril_money( $c['deposit_cents'] ), ril_money( max( 0, $c['total_cents'] - $c['deposit_cents'] ) ) );
		if ( count( $amounts ) !== count( array_unique( $amounts ) ) && ( $total !== $c['total_cents'] || $deposit !== $c['deposit_cents'] ) ) {
			ril_go( array( 'view' => 'contract', 'id' => $c['id'], 'edit' => 1, 'err' => 'O sinal e o saldo tinham o mesmo valor e o texto foi editado à mão, então não dá para trocar os valores sozinho. Atualize os valores direto no texto.' ) );
		}
		$body = strtr( $body, $swap );
	}
	$data = array(
		'title'         => ril_post( 'title', $c['title'] ),
		'client_name'   => $name,
		'client_email'  => sanitize_email( ril_post( 'client_email' ) ),
		'link_days'     => max( 1, min( 365, (int) ril_post( 'link_days', 14 ) ) ),
		'total_cents'   => $total,
		'deposit_cents' => $deposit,
		'studio_fields' => $fields,
		'body'          => $body,
	);
	$args = array( 'view' => 'contract', 'id' => $c['id'], 'saved' => 1 );
	if ( 'sent' === $c['status'] ) {
		if ( ril_has_unfilled( $body ) ) {
			ril_go( array( 'view' => 'contract', 'id' => $c['id'], 'edit' => 1, 'err' => 'Ainda há campos {{ }} sem preencher no texto.' ) );
		}
		$data['sent_hash']  = hash( 'sha256', $body );
		$data['expires_at'] = gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS * $data['link_days'] );
		$args               = array( 'view' => 'contract', 'id' => $c['id'], 'updated' => 1 );
	}
	ril_update_contract( $c['id'], $data );
	ril_add_event( $c['id'], 'edited', array( 'after_sent' => 'sent' === $c['status'], 'hash' => hash( 'sha256', $body ) ) );
	if ( 'sent' === $c['status'] && ! empty( $_POST['notify'] ) ) {
		$fresh = ril_get_contract( $c['id'] );
		if ( $fresh['client_email'] && ril_mail_contract_updated( $fresh ) ) {
			ril_add_event( $c['id'], 'email_sent', array( 'to' => $fresh['client_email'], 'about' => 'updated' ) );
		} else {
			$args['mailfail'] = 1;
		}
	}
	ril_go( $args );
} );

add_action( 'admin_post_ril_send', function () {
	$c = ril_action_contract( 'ril_send' );
	if ( 'draft' !== $c['status'] ) {
		ril_go( array( 'view' => 'contract', 'id' => $c['id'] ) );
	}
	if ( ril_has_unfilled( $c['body'] ) ) {
		ril_go( array( 'view' => 'contract', 'id' => $c['id'], 'err' => 'Ainda há campos {{ }} sem preencher.' ) );
	}
	$hash = hash( 'sha256', $c['body'] );
	ril_update_contract( $c['id'], array(
		'status'     => 'sent',
		'sent_at'    => ril_now(),
		'sent_hash'  => $hash,
		'expires_at' => gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS * max( 1, (int) $c['link_days'] ) ),
	) );
	ril_add_event( $c['id'], 'sent', array( 'hash' => $hash ) );
	$args = array( 'view' => 'contract', 'id' => $c['id'], 'sent' => 1 );
	if ( ! empty( $_POST['email'] ) && $c['client_email'] ) {
		if ( ril_mail_link_to_client( $c ) ) {
			ril_add_event( $c['id'], 'email_sent', array( 'to' => $c['client_email'] ) );
		} else {
			ril_add_event( $c['id'], 'email_failed', array( 'to' => $c['client_email'] ) );
			$args['mailfail'] = 1;
		}
	}
	ril_go( $args );
} );

add_action( 'admin_post_ril_countersign', function () {
	global $wpdb;
	$c = ril_action_contract( 'ril_countersign' );
	if ( 'signed' !== ril_status( $c ) ) {
		ril_go( array( 'view' => 'contract', 'id' => $c['id'], 'err' => 'O casal ainda não assinou este contrato.' ) );
	}
	$name  = ril_post( 'signer_name' );
	$image = isset( $_POST['signature'] ) ? ril_clean_signature_image( wp_unslash( $_POST['signature'] ) ) : '';
	if ( '' === $name || '' === $image ) {
		ril_go( array( 'view' => 'contract', 'id' => $c['id'], 'err' => 'Digite o nome e desenhe a assinatura.' ) );
	}
	$meta = ril_request_meta();
	$sig  = array(
		'name'    => $name,
		'title'   => ril_post( 'signer_title' ),
		'image'   => $image,
		'ip'      => $meta['ip'],
		'ua'      => $meta['ua'],
		'at'      => ril_now(),
		'consent' => 'studio',
	);
	$ok = $wpdb->query( $wpdb->prepare(
		'UPDATE ' . ril_table( 'contracts' ) . " SET studio_signature = %s, status = 'completed', completed_at = %s WHERE id = %d AND status = 'signed'",
		wp_json_encode( $sig ),
		ril_now(),
		$c['id']
	) );
	if ( ! $ok ) {
		ril_go( array( 'view' => 'contract', 'id' => $c['id'], 'err' => 'O contrato mudou enquanto você assinava. Recarregue a página.' ) );
	}
	ril_add_event( $c['id'], 'countersigned', array( 'name' => $name ) );
	ril_add_event( $c['id'], 'completed' );

	$fresh = ril_get_contract( $c['id'] );
	$bytes = ril_build_pdf( $fresh );
	ril_update_contract( $c['id'], array( 'pdf' => base64_encode( $bytes ), 'pdf_hash' => hash( 'sha256', $bytes ) ) );
	$args = array( 'view' => 'contract', 'id' => $c['id'], 'done' => 1 );
	if ( ril_mail_final( $fresh, $bytes ) ) {
		ril_add_event( $c['id'], 'email_sent', array( 'to' => 'client + studio' ) );
	} else {
		$args['mailfail'] = 1;
	}
	ril_go( $args );
} );

add_action( 'admin_post_ril_confirm_date', function () {
	$c    = ril_action_contract( 'ril_confirm_date' );
	$date = ril_post( 'date' );
	if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
		ril_go( array( 'view' => 'contract', 'id' => $c['id'], 'err' => 'Escolha uma data válida.' ) );
	}
	ril_update_contract( $c['id'], array(
		'wedding_date'      => $date,
		'wedding_month'     => substr( $date, 0, 7 ),
		'soft_hold'         => 0,
		'date_confirmed_at' => ril_now(),
	) );
	ril_add_event( $c['id'], 'date_confirmed', array( 'date' => $date, 'held_month' => $c['wedding_month'] ) );
	$args = array( 'view' => 'contract', 'id' => $c['id'], 'confirmed' => 1 );
	if ( ! empty( $_POST['email'] ) ) {
		if ( ril_mail_date_confirmed( ril_get_contract( $c['id'] ) ) ) {
			ril_add_event( $c['id'], 'email_sent', array( 'to' => $c['client_email'], 'about' => 'date_confirmed' ) );
		} else {
			$args['mailfail'] = 1;
		}
	}
	ril_go( $args );
} );

add_action( 'admin_post_ril_payment', function () {
	$c      = ril_action_contract( 'ril_payment' );
	$status = ril_post( 'status' );
	if ( ! in_array( $status, array( 'unpaid', 'deposit_paid', 'paid_in_full' ), true ) ) {
		wp_die( 'Status inválido.' );
	}
	ril_update_contract( $c['id'], array( 'payment_status' => $status ) );
	ril_add_event( $c['id'], $status );
	ril_go( array( 'view' => 'contract', 'id' => $c['id'], 'paid' => 1 ) );
} );

add_action( 'admin_post_ril_cancel', function () {
	$c = ril_action_contract( 'ril_cancel' );
	if ( 'completed' !== $c['status'] ) {
		ril_update_contract( $c['id'], array( 'status' => 'canceled', 'canceled_at' => ril_now() ) );
		ril_add_event( $c['id'], 'canceled' );
	}
	ril_go( array( 'view' => 'contract', 'id' => $c['id'] ) );
} );

add_action( 'admin_post_ril_delete', function () {
	global $wpdb;
	$c = ril_action_contract( 'ril_delete' );
	if ( in_array( $c['status'], array( 'draft', 'canceled' ), true ) ) {
		$wpdb->delete( ril_table( 'events' ), array( 'contract_id' => $c['id'] ) );
		$wpdb->delete( ril_table( 'contracts' ), array( 'id' => $c['id'] ) );
	}
	ril_go( array( 'deleted' => 1 ) );
} );

/* =========================================================
 * Agenda e soft holds
 * ======================================================= */

function ril_hold_message( $c, $ymd ) {
	$s    = ril_settings();
	$when = $ymd ? gmdate( 'l, F j, Y', strtotime( $ymd . ' 12:00:00 UTC' ) ) : ril_month_label( $c['wedding_month'] );
	return 'Hi ' . ril_first_names( $c['client_name'] ) . '! '
		. 'We just received an inquiry for ' . $when . ', which is in ' . ril_month_label( $c['wedding_month'] ) . ', the month we are holding for you. '
		. 'Do you already have your exact wedding date? We would love to lock it in for you first. '
		. 'Just let us know! - ' . $s['studio_name'];
}

function ril_hold_card( $c, $ymd = '' ) {
	$msg   = ril_hold_message( $c, $ymd );
	$phone = ril_client_phone_digits( $c );
	$wa    = 'https://wa.me/' . $phone . '?text=' . rawurlencode( $msg );
	$mail  = 'mailto:' . rawurlencode( $c['client_email'] ) . '?subject=' . rawurlencode( 'Your wedding date' ) . '&body=' . rawurlencode( $msg );
	$out   = '<div class="ril-card ril-hold-card"><div class="ril-head"><div><b>' . esc_html( $c['client_name'] ) . '</b>';
	$out  .= '<div class="ril-muted ril-small">' . esc_html( trim( ril_client_phone( $c ) . ( $c['client_email'] ? ' · ' . $c['client_email'] : '' ), ' ·' ) ) . '</div></div>';
	$out  .= '<span class="ril-badge ril-hold-badge">Soft hold · ' . esc_html( ril_month_pt( $c['wedding_month'] ) ) . '</span></div>';
	$out  .= '<div class="ril-actions" style="margin-top:12px">';
	if ( $phone ) {
		$out .= '<a class="ril-btn ril-btn-small" target="_blank" rel="noopener" href="' . esc_url( $wa ) . '">WhatsApp</a>';
	}
	if ( $c['client_email'] ) {
		$out .= '<a class="ril-btn ril-btn-secondary ril-btn-small" href="' . esc_attr( $mail ) . '">E-mail</a>';
	}
	$out .= '<a class="ril-btn ril-btn-secondary ril-btn-small" href="' . esc_url( ril_admin_url( array( 'view' => 'contract', 'id' => $c['id'] ) ) ) . '">Abrir contrato / confirmar data</a></div>';
	if ( $ymd ) {
		$out .= '<details style="margin-top:10px"><summary class="ril-small ril-muted">Ver a mensagem</summary><p class="ril-small" style="margin:8px 0 0">' . esc_html( $msg ) . '</p></details>';
	}
	return $out . '</div>';
}

function ril_page_agenda() {
	$check = isset( $_GET['check'] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $_GET['check'] ) ? $_GET['check'] : '';
	echo '<div class="wrap ril-wrap"><h1>Agenda e soft holds</h1>';
	echo '<form method="get" class="ril-card" style="max-width:820px;margin-top:16px"><input type="hidden" name="page" value="ril-contracts-agenda">';
	echo '<h3 style="margin-top:0">Chegou um pedido de orçamento? Verifique a data</h3><div class="ril-actions">';
	echo '<input type="date" name="check" required value="' . esc_attr( $check ) . '" style="max-width:220px;min-height:42px;border:1px solid #deddd7;border-radius:12px;padding:8px 12px">';
	echo '<button class="ril-btn">Verificar</button></div></form>';

	if ( $check ) {
		$ym     = substr( $check, 0, 7 );
		$booked = ril_booked_on( $check );
		$holds  = ril_soft_holds( $ym );
		$others = array_filter( ril_booked_in_month( $ym ), function ( $c ) use ( $check ) {
			return $c['wedding_date'] !== $check;
		} );
		echo '<div style="max-width:820px;margin-top:16px" class="ril-stack">';
		echo '<h2>' . esc_html( ril_long_date( $check ) ) . '</h2>';
		if ( $booked ) {
			foreach ( $booked as $c ) {
				echo '<div class="ril-card"><span class="ril-badge ril-canceled">Data ocupada</span> <b>' . esc_html( $c['client_name'] ) . '</b> já tem contrato para este dia. <a href="' . esc_url( ril_admin_url( array( 'view' => 'contract', 'id' => $c['id'] ) ) ) . '">Abrir</a></div>';
			}
		} else {
			echo '<div class="ril-card"><span class="ril-badge ril-completed">Livre</span> Nenhum casamento confirmado neste dia.</div>';
		}
		if ( $holds ) {
			echo '<h3>' . ( 1 === count( $holds ) ? '1 casal segurando' : count( $holds ) . ' casais segurando' ) . ' ' . esc_html( ril_month_pt( $ym ) ) . ': falem com eles antes de fechar</h3>';
			foreach ( $holds as $c ) {
				echo ril_hold_card( $c, $check ); // phpcs:ignore
			}
		} else {
			echo '<p class="ril-muted">Ninguém está segurando ' . esc_html( ril_month_pt( $ym ) ) . '.</p>';
		}
		if ( $others ) {
			echo '<p class="ril-small ril-muted">Outros casamentos confirmados em ' . esc_html( ril_month_pt( $ym ) ) . ': ';
			echo esc_html( implode( ', ', array_map( function ( $c ) {
				return gmdate( 'j/n', strtotime( $c['wedding_date'] . ' 12:00 UTC' ) ) . ' ' . $c['client_name'];
			}, $others ) ) ) . '</p>';
		}
		echo '</div>';
	}

	$all = ril_soft_holds();
	echo '<div style="max-width:820px;margin-top:28px"><h2>Todos os soft holds</h2>';
	if ( ! $all ) {
		echo '<p class="ril-muted">Nenhum soft hold no momento. Quando um casal assinar escolhendo só o mês, ele aparece aqui.</p>';
	}
	$by_month = array();
	foreach ( $all as $c ) {
		$by_month[ $c['wedding_month'] ][] = $c;
	}
	foreach ( $by_month as $ym => $list ) {
		echo '<h3>' . esc_html( ucfirst( ril_month_pt( $ym ) ) ) . ' <span class="ril-muted" style="font-weight:400">(' . count( $list ) . ')</span></h3><div class="ril-stack">';
		foreach ( $list as $c ) {
			echo ril_hold_card( $c ); // phpcs:ignore
		}
		echo '</div>';
	}
	echo '</div></div>';
}
