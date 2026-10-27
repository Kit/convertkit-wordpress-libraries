<?php

namespace Tests;

use lucatume\WPBrowser\TestCase\WPTestCase;

/**
 * Tests for the ConvertKit_App_Server class.
 *
 * @since   2.9.0
 */
class AppServerTest extends WPTestCase
{
	/**
	 * The testing implementation.
	 *
	 * @var \IntegrationTester
	 */
	protected $tester;

	/**
	 * Holds the ConvertKit App Server class.
	 *
	 * @since   2.9.0
	 *
	 * @var     \ConvertKit_App_Server
	 */
	private $app_server;

	/**
	 * Holds the last request sent to the App Server.
	 *
	 * @since   2.9.0
	 *
	 * @var     array
	 */
	private $request = array();

	/**
	 * Holds the response code and body to return from the App Server.
	 *
	 * @since   2.9.0
	 *
	 * @var     array
	 */
	private $response = array(
		'code' => 200,
		'body' => array(),
	);

	/**
	 * Performs actions before each test.
	 *
	 * @since   2.9.0
	 */
	public function setUp(): void
	{
		parent::setUp();

		// Include class from /src to test.
		require_once 'src/class-convertkit-app-server.php';

		// Initialize the class we want to test.
		$this->app_server = new \ConvertKit_App_Server('client-id', 'https://app-server.test/');

		// Record requests and return a mocked response.
		add_filter(
			'pre_http_request',
			function ($response, $args, $url) {
				$this->request = array(
					'url'  => $url,
					'args' => $args,
					'body' => json_decode($args['body'], true),
				);

				return array(
					'headers'  => array(),
					'body'     => wp_json_encode($this->response['body']),
					'response' => array(
						'code'    => $this->response['code'],
						'message' => '',
					),
					'cookies'  => array(),
				);
			},
			10,
			3
		);
	}

	/**
	 * Performs actions after each test.
	 *
	 * @since   2.9.0
	 */
	public function tearDown(): void
	{
		remove_all_filters('pre_http_request');
		unset($this->app_server);

		parent::tearDown();
	}

	/**
	 * Test that the URLs are built from the App Server URL.
	 *
	 * @since   2.9.0
	 */
	public function testURLs()
	{
		$this->assertEquals('https://app-server.test/kit/api/callback', $this->app_server->get_redirect_uri());
		$this->assertEquals('https://app-server.test/kit/complete', $this->app_server->get_complete_url());
		$this->assertEquals(
			'https://app-server.test/connect/start?authorize_url=https%3A%2F%2Fapp.kit.com%2Foauth%2Fauthorize%3Fclient_id%3Dclient-id%26state%3Dabc',
			$this->app_server->get_connect_url('https://app.kit.com/oauth/authorize?client_id=client-id&state=abc')
		);
	}

	/**
	 * Test that the App Server's host is added to the allowed redirect hosts once.
	 *
	 * @since   2.9.0
	 */
	public function testAllowedRedirectHosts()
	{
		$this->assertEquals(
			array( 'example.com', 'app-server.test' ),
			$this->app_server->allowed_redirect_hosts(array( 'example.com', 'app-server.test' ))
		);
		$this->assertEquals(
			array( 'example.com', 'app-server.test' ),
			$this->app_server->allowed_redirect_hosts(array( 'example.com' ))
		);
	}

	/**
	 * Test that register_site() sends the site URL, client ID and access token,
	 * and returns the site secret.
	 *
	 * @since   2.9.0
	 */
	public function testRegisterSite()
	{
		$this->response['body'] = array( 'site_secret' => 'secret' );

		$result = $this->app_server->register_site('access-token');

		$this->assertEquals(array( 'site_secret' => 'secret' ), $result);
		$this->assertEquals('https://app-server.test/api/sites', $this->request['url']);
		$this->assertEquals('POST', $this->request['args']['method']);
		$this->assertArrayNotHasKey('Authorization', $this->request['args']['headers']);
		$this->assertEquals(
			array(
				'site_url'     => get_site_url(),
				'client_id'    => 'client-id',
				'access_token' => 'access-token',
			),
			$this->request['body']
		);
	}

	/**
	 * Test that unregister_site() sends a DELETE request, and returns the remaining sites.
	 *
	 * @since   2.9.0
	 */
	public function testUnregisterSite()
	{
		$this->response['body'] = array( 'remaining_sites' => 1 );

		$result = $this->app_server->unregister_site('access-token');

		$this->assertEquals(array( 'remaining_sites' => 1 ), $result);
		$this->assertEquals('https://app-server.test/api/sites', $this->request['url']);
		$this->assertEquals('DELETE', $this->request['args']['method']);
		$this->assertEquals('access-token', $this->request['body']['access_token']);
	}

	/**
	 * Test that send_automation_event() authenticates with the site secret and sends the event.
	 *
	 * @since   2.9.0
	 */
	public function testSendAutomationEvent()
	{
		$this->response = array(
			'code' => 201,
			'body' => array( 'id' => 1 ),
		);

		$result = $this->app_server->send_automation_event('secret', 'abandoned_checkout', '123', array( 'total' => '$10.00' ));

		$this->assertEquals(array( 'id' => 1 ), $result);
		$this->assertEquals('https://app-server.test/api/automation-events', $this->request['url']);
		$this->assertEquals('POST', $this->request['args']['method']);
		$this->assertEquals('Bearer secret', $this->request['args']['headers']['Authorization']);
		$this->assertEquals(
			array(
				'site_url'      => get_site_url(),
				'client_id'     => 'client-id',
				'type'          => 'abandoned_checkout',
				'subscriber_id' => 123,
				'context'       => array( 'total' => '$10.00' ),
			),
			$this->request['body']
		);
	}

	/**
	 * Test that send_automation_event() sends a null context when none is given.
	 *
	 * @since   2.9.0
	 */
	public function testSendAutomationEventWithoutContext()
	{
		$this->response['code'] = 201;

		$this->app_server->send_automation_event('secret', 'product_purchased', 123);

		$this->assertNull($this->request['body']['context']);
	}

	/**
	 * Test that a WP_Error is returned when the App Server returns an unexpected response code.
	 *
	 * @since   2.9.0
	 */
	public function testUnexpectedResponseCode()
	{
		$this->response['code'] = 401;

		$result = $this->app_server->send_automation_event('invalid-secret', 'abandoned_checkout', 123);

		$this->assertInstanceOf(\WP_Error::class, $result);
		$this->assertEquals('convertkit_app_server_error', $result->get_error_code());
		$this->assertEquals('Kit App Server returned HTTP 401', $result->get_error_message());
	}
}
