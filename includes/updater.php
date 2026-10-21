<?php
/**
 * Atualizações pelo GitHub.
 *
 * O plugin olha o código mais recente do repositório configurado (branch main). Se a versão for maior que a instalada,
 * o WordPress mostra "Atualização disponível" na tela de Plugins (e instala sozinho, se a atualização
 * automática estiver ligada). Funciona com repositório privado usando uma chave de leitura (token).
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RIL_Updater {
	const CACHE  = 'ril_contracts_release';
	const BRANCH = 'main';

	public static function init() {
		add_filter( 'pre_set_site_transient_update_plugins', array( __CLASS__, 'inject' ) );
		add_filter( 'site_transient_update_plugins', array( __CLASS__, 'inject' ) );
		add_filter( 'plugins_api', array( __CLASS__, 'info' ), 20, 3 );
		add_filter( 'upgrader_pre_download', array( __CLASS__, 'download' ), 10, 4 );
		add_filter( 'upgrader_source_selection', array( __CLASS__, 'fix_folder' ), 10, 4 );
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

	/** Versão mais recente no GitHub (com cache de 6 horas). Devolve array ou WP_Error. */
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
		$api = self::api_base() . '/repos/' . $repo;
		$res = wp_remote_get( $api . '/commits/' . self::BRANCH, array(
			'headers' => self::headers(),
			'timeout' => 15,
		) );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$code = wp_remote_retrieve_response_code( $res );
		if ( 200 !== $code ) {
			$msg = in_array( $code, array( 404, 422 ), true ) ? 'Repositório não encontrado (confira o nome e a chave de acesso).' : ( 401 === $code ? 'Chave de acesso inválida.' : 'GitHub respondeu ' . $code . '.' );
			return new WP_Error( 'ril_http', $msg );
		}
		$commit = json_decode( wp_remote_retrieve_body( $res ), true );
		if ( empty( $commit['sha'] ) ) {
			return new WP_Error( 'ril_http', 'Resposta inesperada do GitHub.' );
		}
		$sha = (string) $commit['sha'];

		// Lê a versão no cabeçalho do arquivo principal do plugin, naquele commit.
		$res = wp_remote_get( $api . '/contents/ril-contracts.php?ref=' . rawurlencode( $sha ), array(
			'headers' => self::headers( 'application/vnd.github.raw' ),
			'timeout' => 15,
		) );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		if ( 200 !== wp_remote_retrieve_response_code( $res ) || ! preg_match( '/^[ \t\/*#@]*Version:\s*([0-9][0-9A-Za-z.\-]*)/mi', wp_remote_retrieve_body( $res ), $m ) ) {
			return new WP_Error( 'ril_no_version', 'Não encontrei a versão do plugin no repositório.' );
		}
		return array(
			'repo'      => $repo,
			'version'   => $m[1],
			'notes'     => isset( $commit['commit']['message'] ) ? (string) $commit['commit']['message'] : '',
			'published' => isset( $commit['commit']['committer']['date'] ) ? (string) $commit['commit']['committer']['date'] : '',
			'asset_api' => $api . '/zipball/' . $sha,
			'html_url'  => 'https://github.com/' . $repo,
		);
	}

	/**
	 * O zip do GitHub vem dentro de uma pasta "usuario-ril-contracts-abc123".
	 * Renomeia para "ril-contracts" para o WordPress substituir o plugin certo.
	 */
	public static function fix_folder( $source, $remote_source, $upgrader, $hook_extra = array() ) {
		global $wp_filesystem;
		if ( empty( $hook_extra['plugin'] ) || self::basename() !== $hook_extra['plugin'] || ! $wp_filesystem ) {
			return $source;
		}
		$want = trailingslashit( $remote_source ) . dirname( self::basename() ) . '/';
		if ( untrailingslashit( $source ) === untrailingslashit( $want ) ) {
			return $source;
		}
		if ( ! $wp_filesystem->exists( trailingslashit( $source ) . 'ril-contracts.php' ) ) {
			return new WP_Error( 'ril_bad_zip', 'O arquivo baixado não parece ser o plugin de contratos.' );
		}
		if ( ! $wp_filesystem->move( $source, $want, true ) ) {
			return new WP_Error( 'ril_bad_zip', 'Não consegui preparar a pasta da atualização.' );
		}
		return $want;
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
		$is_asset = is_string( $package ) && false !== strpos( $package, '/releases/assets/' );
		$is_zip   = is_string( $package ) && false !== strpos( $package, '/zipball/' );
		if ( ! is_string( $package ) || 0 !== strpos( $package, $api ) || ( ! $is_asset && ! $is_zip ) ) {
			return $reply;
		}
		$res = wp_remote_get( $package, array(
			'headers'     => self::headers( $is_asset ? 'application/octet-stream' : 'application/vnd.github+json' ),
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
