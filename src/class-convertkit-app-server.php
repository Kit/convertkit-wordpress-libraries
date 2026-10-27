<?php
/**
 * Kit App Server class.
 *
 * @package ConvertKit
 * @author ConvertKit
 */

/**
 * Communicates with the Kit App Server, which connects Kit WordPress Plugins
 * to their Kit App Store app, and serves the app's Kit plugins.
 *
 * @since   2.9.0
 */
class ConvertKit_App_Server {

	/**
	 * Holds the Plugin's Kit OAuth client ID.
	 *
	 * @since   2.9.0
	 *
	 * @var     string
	 */
	private $client_id = '';

	/**
	 * Holds the App Server URL.
	 *
	 * @since   2.9.0
	 *
	 * @var     string
	 */
	private $url = '';

	/**
	 * Constructor.
	 *
	 * @since   2.9.0
	 *
	 * @param   string $client_id  Plugin's Kit OAuth client ID.
	 * @param   string $url        App Server URL.
	 */
	public function __construct( $client_id, $url ) {

		$this->client_id = $client_id;
		$this->url       = untrailingslashit( $url );

	}

	/**
	 * Returns the OAuth redirect URI to use for Kit OAuth.
	 *
	 * @since   2.9.0
	 *
	 * @return  string
	 */
	public function get_redirect_uri() {

		return $this->url . '/kit/api/callback';

	}

	/**
	 * Returns the URL to start connecting to Kit, which installs the Kit app
	 * if needed and then continues the given Kit OAuth URL.
	 *
	 * @since   2.9.0
	 *
	 * @param   string $authorize_url  Kit OAuth URL.
	 * @return  string
	 */
	public function get_connect_url( $authorize_url ) {

		return add_query_arg(
			array(
				'authorize_url' => rawurlencode( $authorize_url ),
			),
			$this->url . '/connect/start'
		);

	}

	/**
	 * Returns the URL to redirect to once connected, which completes the Kit App Store
	 * install and then redirects to the Plugin's settings.
	 *
	 * @since   2.9.0
	 *
	 * @return  string
	 */
	public function get_complete_url() {

		return $this->url . '/kit/complete';

	}

	/**
	 * Permits wp_safe_redirect() to redirect to the App Server.
	 *
	 * @since   2.9.0
	 *
	 * @param   array $hosts  Allowed redirect hosts.
	 * @return  array
	 */
	public function allowed_redirect_hosts( $hosts ) {

		$hosts[] = wp_parse_url( $this->url, PHP_URL_HOST );
		return array_unique( $hosts );

	}

	/**
	 * Registers this site with the App Server.
	 *
	 * @since   2.9.0
	 *
	 * @param   string $access_token   Kit access token.
	 * @return  WP_Error|array          Response, including the site_secret
	 */
	public function register_site( $access_token ) {

		return $this->request( 'POST', '/api/sites', array( 'access_token' => $access_token ), 200 );

	}

	/**
	 * Unregisters this site from the App Server.
	 *
	 * @since   2.9.0
	 *
	 * @param   string $access_token   Kit access token.
	 * @return  WP_Error|array          Response, including remaining_sites
	 */
	public function unregister_site( $access_token ) {

		return $this->request( 'DELETE', '/api/sites', array( 'access_token' => $access_token ), 200 );

	}

	/**
	 * Sends an automation event to the App Server, which Kit polls for the app's event nodes.
	 *
	 * @since   2.9.0
	 *
	 * @param   string $site_secret    Site secret returned by register_site().
	 * @param   string $type           Event type (e.g. abandoned_checkout).
	 * @param   int    $subscriber_id  Kit Subscriber ID.
	 * @param   array  $context        Event data, available in Kit emails as Liquid.
	 * @return  WP_Error|array
	 */
	public function send_automation_event( $site_secret, $type, $subscriber_id, $context = array() ) {

		return $this->request(
			'POST',
			'/api/automation-events',
			array(
				'type'          => $type,
				'subscriber_id' => absint( $subscriber_id ),
				'context'       => ! empty( $context ) ? $context : null,
			),
			201,
			$site_secret
		);

	}

	/**
	 * Sends a request to the App Server.
	 *
	 * @since   2.9.0
	 *
	 * @param   string      $method         HTTP method.
	 * @param   string      $path           Path.
	 * @param   array       $body           Body, merged with this site's URL and client ID.
	 * @param   int         $expected_code  Expected HTTP response code.
	 * @param   bool|string $site_secret    Site secret, sent as a Bearer token.
	 * @return  WP_Error|array
	 */
	private function request( $method, $path, $body, $expected_code, $site_secret = false ) {

		$headers = array(
			'Accept'       => 'application/json',
			'Content-Type' => 'application/json',
		);
		if ( $site_secret ) {
			$headers['Authorization'] = 'Bearer ' . $site_secret;
		}

		$response = wp_remote_request(
			$this->url . $path,
			array(
				'method'  => $method,
				'timeout' => 15,
				'headers' => $headers,
				'body'    => wp_json_encode(
					array_merge(
						array(
							'site_url'  => get_site_url(),
							'client_id' => $this->client_id,
						),
						$body
					)
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( wp_remote_retrieve_response_code( $response ) !== $expected_code ) {
			return new WP_Error(
				'convertkit_app_server_error',
				sprintf(
					'Kit App Server returned HTTP %s',
					wp_remote_retrieve_response_code( $response )
				)
			);
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		return is_array( $data ) ? $data : array();

	}

}
