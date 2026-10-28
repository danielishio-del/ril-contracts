<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'tFPDF' ) ) {
	require_once RIL_CONTRACTS_DIR . 'lib/tfpdf/tfpdf.php';
}
if ( ! class_exists( 'TTFontFile' ) ) {
	require_once RIL_CONTRACTS_DIR . 'lib/tfpdf/font/unifont/ttfonts.php';
}

class RIL_PDF extends tFPDF {
	public $footer_left  = '';
	public $footer_right = '';

	public function __construct( $orientation, $unit, $size ) {
		parent::__construct( $orientation, $unit, $size );
		// Fontes TrueType em UTF-8, para nomes com ł, ğ, ő, grego, cirílico, vietnamita...
		// Liberation tem as mesmas medidas de Helvetica e Times, então o PDF fica com a mesma cara.
		$this->fontpath = RIL_CONTRACTS_DIR . 'lib/tfpdf/font/';
		$this->AddFont( 'Sans', '', 'LiberationSans-Regular.ttf', true );
		$this->AddFont( 'Sans', 'B', 'LiberationSans-Bold.ttf', true );
		$this->AddFont( 'Serif', 'B', 'LiberationSerif-Bold.ttf', true );
	}

	public function Footer() {
		$this->SetY( -36 );
		$this->SetFont( 'Sans', '', 8 );
		$this->SetTextColor( 110, 110, 104 );
		$this->Cell( 0, 10, ril_pdf_txt( 'Page ' . $this->PageNo() . ' of {nb}  ·  ' . $this->footer_left ), 0, 0, 'L' );
		$this->SetX( 64 );
		$this->Cell( 0, 10, ril_pdf_txt( $this->footer_right ), 0, 0, 'R' );
	}
}

/** Texto para o PDF: UTF-8 válido, sem tabulação nem espaço especial. */
function ril_pdf_txt( $s ) {
	return str_replace( array( "\t", "\xC2\xA0" ), array( '  ', ' ' ), wp_check_invalid_utf8( (string) $s, true ) );
}

/** Monta o PDF do contrato e devolve os bytes. */
function ril_build_pdf( $c ) {
	$s      = ril_settings();
	$events = ril_get_events( $c['id'] );

	$pdf = new RIL_PDF( 'P', 'pt', 'Letter' );
	$pdf->SetMargins( 64, 64, 64 );
	$pdf->SetAutoPageBreak( true, 64 );
	$pdf->AliasNbPages();
	$pdf->SetTitle( ril_pdf_txt( $c['title'] ), true );
	$pdf->SetAuthor( ril_pdf_txt( $s['studio_name'] ), true );
	$pdf->SetSubject( ril_pdf_txt( 'Prepared for ' . $c['client_name'] ), true );
	$pdf->SetCreator( ril_pdf_txt( $s['studio_name'] ), true );
	$pdf->footer_left  = 'Contract #' . $c['id'];
	$pdf->footer_right = preg_replace( '#^https?://#', '', home_url() );
	$pdf->AddPage();

	$ink   = array( 38, 38, 38 );
	$muted = array( 110, 110, 104 );
	$w     = $pdf->GetPageWidth() - 128;

	$txt = function ( $text, $font = 'Sans', $style = '', $size = 10.5, $color = null, $lh = null, $after = 0 ) use ( $pdf, $ink ) {
		$c = $color ? $color : $ink;
		$pdf->SetTextColor( $c[0], $c[1], $c[2] );
		$pdf->SetFont( $font, $style, $size );
		$pdf->MultiCell( 0, $lh ? $lh : $size * 1.45, ril_pdf_txt( $text ), 0, 'L' );
		if ( $after ) {
			$pdf->Ln( $after );
		}
	};
	$rule = function ( $gap = 10 ) use ( $pdf, $w ) {
		$pdf->Ln( $gap );
		$pdf->SetDrawColor( 222, 221, 215 );
		$pdf->SetLineWidth( 0.6 );
		$pdf->Line( 64, $pdf->GetY(), 64 + $w, $pdf->GetY() );
		$pdf->Ln( $gap );
	};

	// Cabeçalho
	$logo = ril_logo_file();
	$drew = false;
	if ( $logo ) {
		$size = @getimagesize( $logo );
		if ( $size && $size[0] > 0 && $size[1] > 0 ) {
			$lw = min( 150, $size[0] * 54 / $size[1] );
			$lh = $lw * $size[1] / $size[0];
			try {
				$pdf->Image( $logo, 64, $pdf->GetY(), $lw, $lh );
				$pdf->SetY( $pdf->GetY() + $lh + 14 );
				$drew = true;
			} catch ( Exception $e ) {
				$drew = false;
			}
		}
	}
	if ( ! $drew ) {
		$txt( strtoupper( $s['studio_name'] ), 'Sans', 'B', 8.5, $muted, 12, 4 );
	}
	$txt( $c['title'], 'Serif', 'B', 20, null, 24, 4 );
	$txt( 'Prepared for: ' . $c['client_name'], 'Sans', '', 10, $muted, 14 );
	if ( $c['wedding_date'] ) {
		$txt( 'Wedding date: ' . ril_long_date( $c['wedding_date'] ), 'Sans', '', 10, $muted, 14 );
	}
	$rule( 10 );

	// Corpo
	$body = ril_fill_client( $c['body'], $c['client_fields'] );
	foreach ( ril_blocks( $body ) as $i => $b ) {
		if ( 'h1' === $b['type'] ) {
			if ( 0 === $i && trim( $b['text'] ) === trim( $c['title'] ) ) {
				continue;
			}
			$pdf->Ln( 4 );
			$txt( $b['text'], 'Serif', 'B', 15, null, 19, 4 );
		} elseif ( 'h2' === $b['type'] ) {
			if ( $pdf->GetY() > $pdf->GetPageHeight() - 130 ) {
				$pdf->AddPage();
			}
			$pdf->Ln( 6 );
			$txt( $b['text'], 'Serif', 'B', 12.5, null, 16, 3 );
		} elseif ( 'li' === $b['type'] ) {
			$y = $pdf->GetY();
			$pdf->SetFont( 'Sans', '', 10.5 );
			$pdf->SetTextColor( $ink[0], $ink[1], $ink[2] );
			$pdf->Text( 68, $y + 11, '•' );
			$pdf->SetX( 80 );
			$pdf->MultiCell( 0, 15, ril_pdf_txt( $b['text'] ), 0, 'L' );
			$pdf->Ln( 1 );
		} else {
			$txt( $b['text'], 'Sans', '', 10.5, null, 15, 7 );
		}
	}

	// Assinaturas
	if ( $pdf->GetY() > $pdf->GetPageHeight() - 300 ) {
		$pdf->AddPage();
	}
	$rule( 12 );
	$sig_block = function ( $label, $sig, $fallback ) use ( $pdf, $txt, $muted ) {
		if ( $pdf->GetY() > $pdf->GetPageHeight() - 160 ) {
			$pdf->AddPage();
		}
		$txt( strtoupper( $label ), 'Sans', 'B', 8.5, $muted, 12, 4 );
		$y = $pdf->GetY();
		if ( $sig && ! empty( $sig['image'] ) ) {
			$tmp = wp_tempnam( 'ril-sig' );
			file_put_contents( $tmp, base64_decode( substr( $sig['image'], 22 ) ) );
			$size  = @getimagesize( $tmp );
			$scale = $size ? min( 200 / $size[0], 60 / $size[1] ) : 1;
			try {
				$pdf->Image( $tmp, 64, $y, $size ? $size[0] * $scale : 200, $size ? $size[1] * $scale : 60, 'PNG' );
			} catch ( Exception $e ) {
				// Se a imagem falhar, o nome e a trilha de auditoria continuam valendo.
			}
			wp_delete_file( $tmp );
			$pdf->SetY( $y + ( $size ? $size[1] * $scale : 60 ) + 4 );
		} else {
			$pdf->SetY( $y + 44 );
		}
		$pdf->SetDrawColor( 38, 38, 38 );
		$pdf->Line( 64, $pdf->GetY(), 304, $pdf->GetY() );
		$pdf->Ln( 5 );
		$txt( $sig ? $sig['name'] : $fallback, 'Sans', 'B', 10.5, null, 14 );
		if ( $sig && ! empty( $sig['title'] ) ) {
			$txt( $sig['title'], 'Sans', '', 9.5, $muted, 13 );
		}
		$txt( $sig ? 'Signed: ' . ril_utc_label( $sig['at'] ) : 'Not signed yet', 'Sans', '', 9, $muted, 13, 16 );
	};
	$sig_block( 'Client', $c['client_signature'], $c['client_name'] );
	$sig_block( 'Photographer', $c['studio_signature'], $s['signer_name'] ? $s['signer_name'] : $s['studio_name'] );

	// Certificado
	$pdf->AddPage();
	$txt( 'Signature certificate', 'Serif', 'B', 17, null, 21, 8 );
	$kv = function ( $k, $v ) use ( $txt, $muted ) {
		$txt( strtoupper( $k ), 'Sans', 'B', 8, $muted, 11 );
		$txt( '' !== (string) $v ? $v : '—', 'Sans', '', 9.5, null, 13, 6 );
	};
	$kv( 'Contract', '#' . $c['id'] . ' · ' . $c['title'] );
	$kv( 'SHA-256 of the text as sent', $c['sent_hash'] );
	$kv( 'SHA-256 of the text as signed', $c['final_hash'] );
	foreach ( array( 'Client' => $c['client_signature'], 'Photographer' => $c['studio_signature'] ) as $label => $sig ) {
		if ( $sig ) {
			$kv( $label, $sig['name'] . ( ! empty( $sig['email'] ) ? ' <' . $sig['email'] . '>' : '' ) . ' · ' . ril_utc_label( $sig['at'] ) . ' · IP ' . $sig['ip'] . "\n" . $sig['ua'] );
		}
	}
	$rule( 8 );
	$txt( 'AUDIT TRAIL', 'Sans', 'B', 8, $muted, 11, 4 );
	$labels = array(
		'created'       => 'Created',
		'edited'        => 'Text edited',
		'sent'          => 'Sent to client',
		'link_renewed'  => 'Signing link renewed',
		'email_sent'    => 'Email sent',
		'viewed'        => 'Viewed by client',
		'signed'        => 'Signed by client',
		'countersigned' => 'Countersigned by photographer',
		'completed'     => 'Completed',
		'canceled'      => 'Canceled',
		'date_confirmed' => 'Wedding date confirmed',
	);
	foreach ( $events as $e ) {
		if ( ! isset( $labels[ $e['type'] ] ) ) {
			continue;
		}
		$txt( ril_utc_label( $e['at'] ) . '  ·  ' . $labels[ $e['type'] ] . ( $e['ip'] ? '  ·  IP ' . $e['ip'] : '' ), 'Sans', '', 9, null, 12 );
		if ( $e['ua'] ) {
			$pdf->SetX( 76 );
			$txt( $e['ua'], 'Sans', '', 7.5, $muted, 10, 3 );
		}
	}
	$rule( 8 );
	$txt( 'Times are in UTC. IP addresses and devices were recorded when each action happened. The hashes identify the exact text each party saw; any change to the text would produce a different hash.', 'Sans', '', 8.5, $muted, 12 );

	return $pdf->Output( 'S' );
}
