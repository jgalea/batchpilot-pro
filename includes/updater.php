<?php
defined( 'ABSPATH' ) || exit;

const BATCHPILOT_PRO_REPO = 'jgalea/batchpilot-pro';

function batchpilot_pro_latest_release() {
	$release = get_site_transient( 'batchpilot_pro_release' );
	if ( false !== $release ) {
		return $release ? $release : null;
	}

	$release  = array();
	$response = wp_remote_get(
		'https://api.github.com/repos/' . BATCHPILOT_PRO_REPO . '/releases/latest',
		array(
			'timeout' => 10,
			'headers' => array( 'Accept' => 'application/vnd.github+json' ),
		)
	);

	if ( 200 === wp_remote_retrieve_response_code( $response ) ) {
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		foreach ( (array) ( $data['assets'] ?? array() ) as $asset ) {
			if ( 'batchpilot-pro.zip' === ( $asset['name'] ?? '' ) ) {
				$release = array(
					'version' => ltrim( (string) $data['tag_name'], 'v' ),
					'package' => $asset['browser_download_url'],
					'url'     => $data['html_url'],
					'notes'   => (string) ( $data['body'] ?? '' ),
				);
				break;
			}
		}
	}

	set_site_transient( 'batchpilot_pro_release', $release, $release ? 12 * HOUR_IN_SECONDS : HOUR_IN_SECONDS );

	return $release ? $release : null;
}

// Hooked through the plugin's Update URI header, so WordPress.org never answers for this slug.
function batchpilot_pro_check_update( $update, $plugin_data, $plugin_file ) {
	if ( BATCHPILOT_PRO_BASENAME !== $plugin_file ) {
		return $update;
	}

	$release = batchpilot_pro_latest_release();
	if ( ! $release ) {
		return $update;
	}

	return array(
		'slug'    => 'batchpilot-pro',
		'version' => $release['version'],
		'url'     => $release['url'],
		'package' => $release['package'],
	);
}
add_filter( 'update_plugins_github.com', 'batchpilot_pro_check_update', 10, 3 );

function batchpilot_pro_plugin_info( $result, $action, $args ) {
	if ( 'plugin_information' !== $action || 'batchpilot-pro' !== ( $args->slug ?? '' ) ) {
		return $result;
	}

	$release = batchpilot_pro_latest_release();
	if ( ! $release ) {
		return $result;
	}

	return (object) array(
		'name'          => 'BatchPilot Pro',
		'slug'          => 'batchpilot-pro',
		'version'       => $release['version'],
		'author'        => '<a href="https://jeangalea.com/">Jean Galea</a>',
		'homepage'      => 'https://github.com/' . BATCHPILOT_PRO_REPO,
		'download_link' => $release['package'],
		'requires'      => '6.3',
		'requires_php'  => '7.4',
		'sections'      => array( 'changelog' => wp_kses_post( wpautop( $release['notes'] ) ) ),
	);
}
add_filter( 'plugins_api', 'batchpilot_pro_plugin_info', 10, 3 );

function batchpilot_pro_clear_release_cache( $upgrader, $options ) {
	if ( 'update' === ( $options['action'] ?? '' ) && 'plugin' === ( $options['type'] ?? '' ) ) {
		delete_site_transient( 'batchpilot_pro_release' );
	}
}
add_action( 'upgrader_process_complete', 'batchpilot_pro_clear_release_cache', 10, 2 );
