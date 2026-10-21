<?php
/**
 * Atualizações pelo GitHub.
 *
 * O plugin olha a última "release" do repositório configurado. Se a versão for maior que a instalada,
 * o WordPress mostra "Atualização disponível" na tela de Plugins (e instala sozinho, se a atualização
 * automática estiver ligada). Funciona com repositório privado usando uma chave de leitura (token).
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RIL_Updater {
	const CACHE = 'ril_contracts_release';

	public static function init() {
		add_filter( 'pre_set_site_transient_update_plugins', array( __CLASS__, 'inject' ) );
		add_filter( 'site_transient_update_plugins', array( __CLASS__, 'inject' ) );
		add_filter( 'plugins_api', array( __CLASS__, 'info' ), 20, 3 );
		add_filter( 'upgrader_pre_download', array( __CLASS__, 'download' ), 10, 4 );
		add_action( 'upgrader_process_complete', array( __CLASS__, 'clear' ), 10, 0 );
	}

	public static function basename() {
		return plugin_basename( RIL_CONTRACTS_FILE );
	}

	public static function repo() {
		$s    = ril_settings();
		$repo = trim( (string) $s['update_repo'] );
		$repo = preg_replace( '#^https?://github\.com/#i', '', $repo );
		$repo = trim( preg_replace( '#\.git$#', '', $repo ), '/' );
		return preg_match( '#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#', $repo ) ? $repo : '';
	}

	public static function api_base() {
		return defined( 'RIL_CONTRACTS_UPDATE_API' ) ? untrailingslashit( RIL_CONTRACTS_UPDATE_API ) : 'https://api.github.com';
	}

	private static function headers( $accept = 'application/vnd.github+json' ) {
		$s       = ril_settings();
		$headers = array(
			'Accept'     => $accept,
			'User-Agent' => 'ril-contracts/' . RIL_CONTRACTS_VERSION,
		);
		if ( ! empty( $s['update_token'] ) ) {
			$headers['Authorization'] = 'Bearer ' . $s['update_token'];
		}
		return $headers;
	}

	/** Última release (com cache de 6 horas). Devolve array ou WP_Error. */
	public static function release( $force = false ) {
		$repo = self::repo();
		if ( ! $repo ) {
			return new WP_Error( 'ril_no_repo', 'Repositório de atualizações não configurado.' );
		}
		$cached = get_site_transient( self::CACHE );
		if ( ! $force && is_array( $cached ) && isset( $cached['repo'] ) && $cached['repo'] === $repo ) {
			return isset( $cached['error'] ) ? new WP_Error( 'ril_cached', $cached['error'] ) : $cached;
		}
		$result = self::fetch( $repo );
		if ( is_wp_error( $result ) ) {
			set_site_transient( self::CACHE, array( 'repo' => $repo, 'error' => $result->get_error_message() ), HOUR_IN_SECONDS );
		} else {
			set_site_transient( self::CACHE, $result, 6 * HOUR_IN_SECONDS );
		}
		return $result;
	}

	private static function fetch( $repo ) {
		$res = wp_remote_get( self::api_base() . '/repos/' . $repo . '/releases/latest', array(
			'headers' => self::headers(),
			'timeout' => 15,
		) );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$code = wp_remote_retrieve_response_code( $res );
		if ( 200 !== $code ) {
			$msg = 404 === $code ? 'Repositório ou release não encontrado (confira o nome e a chave de acesso).' : ( 401 === $code ? 'Chave de acesso inválida.' : 'GitHub respondeu ' . $code . '.' );
			return new WP_Error( 'ril_http', $msg );
		}
		$data  = json_decode( wp_remote_retrieve_body( $res ), true );
		$asset = null;
		foreach ( (array) ( isset( $data['assets'] ) ? $data['assets'] : array() ) as $a ) {
			if ( isset( $a['name'] ) && preg_match( '/^ril-contracts.*\.zip$/i', $a['name'] ) ) {
				$asset = $a;
				break;
			}
		}
		if ( ! $asset ) {
			return new WP_Error( 'ril_no_asset', 'A release não tem o arquivo ril-contracts.zip.' );
		}
		$release = array(
			'repo'      => $repo,
			'version'   => ltrim( (string) $data['tag_name'], 'vV' ),
			'notes'     => isset( $data['body'] ) ? (string) $data['body'] : '',
			'published' => isset( $data['published_at'] ) ? (string) $data['published_at'] : '',
			'asset_api' => self::api_base() . '/repos/' . $repo . '/releases/assets/' . (int) $asset['id'],
			'html_url'  => isset( $data['html_url'] ) ? (string) $data['html_url'] : '',
		);
		return $release;
	}

	/** Versão do arquivo no disco (vale mesmo logo depois de uma atualização, no mesmo carregamento). */
	public static function installed_version() {
		if ( ! function_exists( 'get_file_data' ) ) {
			return RIL_CONTRACTS_VERSION;
		}
		$data = get_file_data( RIL_CONTRACTS_FILE, array( 'Version' => 'Version' ) );
		return ! empty( $data['Version'] ) ? $data['Version'] : RIL_CONTRACTS_VERSION;
	}

	public static function clear() {
		delete_site_transient( self::CACHE );
	}

	/** Coloca o plugin na lista de atualizações do WordPress. */
	public static function inject( $transient ) {
		if ( ! is_object( $transient ) ) {
			$transient = new stdClass();
		}
		$r = self::release();
		if ( is_wp_error( $r ) ) {
			// Sem como checar: ao menos não mostra atualização para uma versão que já está instalada.
			if ( isset( $transient->response[ self::basename() ] ) && version_compare( $transient->response[ self::basename() ]->new_version, self::installed_version(), '<=' ) ) {
				unset( $transient->response[ self::basename() ] );
			}
			return $transient;
		}
		$item = (object) array(
			'id'          => self::basename(),
			'slug'        => dirname( self::basename() ),
			'plugin'      => self::basename(),
			'new_version' => $r['version'],
			'url'         => $r['html_url'],
			'package'     => $r['asset_api'],
			'tested'      => get_bloginfo( 'version' ),
		);
		$installed = self::installed_version();
		if ( version_compare( $r['version'], $installed, '>' ) ) {
			$transient->response[ self::basename() ] = $item;
			unset( $transient->no_update[ self::basename() ] );
		} else {
			unset( $transient->response[ self::basename() ] );
			$transient->no_update[ self::basename() ] = $item;
		}
		return $transient;
	}

	/** Janela "Ver detalhes". */
	public static function info( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || empty( $args->slug ) || dirname( self::basename() ) !== $args->slug ) {
			return $result;
		}
		$r = self::release();
		if ( is_wp_error( $r ) ) {
			return $result;
		}
		return (object) array(
			'name'          => 'Rocker in Love Contracts',
			'slug'          => $args->slug,
			'version'       => $r['version'],
			'author'        => 'Rocker in Love',
			'last_updated'  => $r['published'],
			'download_link' => $r['asset_api'],
			'sections'      => array(
				'changelog' => $r['notes'] ? wpautop( esc_html( $r['notes'] ) ) : '<p>Nova versão.</p>',
			),
		);
	}

	/**
	 * Baixa o zip da release. O GitHub responde com um redirecionamento para um link temporário;
	 * a chave de acesso só vai na primeira chamada (o link temporário não aceita a chave).
	 */
	public static function download( $reply, $package, $upgrader, $hook_extra = array() ) {
		$api = self::api_base() . '/repos/';
		if ( ! is_string( $package ) || 0 !== strpos( $package, $api ) || false === strpos( $package, '/releases/assets/' ) ) {
			return $reply;
		}
		$res = wp_remote_get( $package, array(
			'headers'     => self::headers( 'application/octet-stream' ),
			'redirection' => 0,
			'timeout'     => 30,
		) );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$code = wp_remote_retrieve_response_code( $res );
		if ( in_array( $code, array( 301, 302, 303, 307, 308 ), true ) ) {
			$location = wp_remote_retrieve_header( $res, 'location' );
			if ( ! $location ) {
				return new WP_Error( 'ril_download', 'GitHub não informou o endereço do arquivo.' );
			}
			return download_url( $location, 300 );
		}
		if ( 200 === $code ) {
			$tmp = wp_tempnam( 'ril-contracts.zip' );
			file_put_contents( $tmp, wp_remote_retrieve_body( $res ) );
			return $tmp;
		}
		return new WP_Error( 'ril_download', 'Não foi possível baixar a atualização (GitHub respondeu ' . $code . ').' );
	}
}

RIL_Updater::init();
