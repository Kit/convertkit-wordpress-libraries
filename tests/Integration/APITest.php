<?php

namespace Tests;

use lucatume\WPBrowser\TestCase\WPTestCase;

// Load the shared tests trait.
require_once __DIR__ . '/TestsTrait.php';

/**
 * Tests for the ConvertKit_API class.
 *
 * @since   2.0.0
 */
class APITest extends WPTestCase
{
	use \TestsTrait;

	/**
	 * The testing implementation.
	 *
	 * @var \IntegrationTester
	 */
	protected $tester;

	/**
	 * Holds the ConvertKit API class.
	 *
	 * @since   1.0.0
	 *
	 * @var     ConvertKit_API
	 */
	private $api;

	/**
	 * Holds the expected WP_Error code.
	 *
	 * @since   1.0.0
	 *
	 * @var     string
	 */
	private $errorCode = 'convertkit_api_error';

	/**
	 * Custom Field IDs to delete on teardown of a test.
	 *
	 * @since   2.0.0
	 *
	 * @var     array<int, int>
	 */
	protected $custom_field_ids = [];

	/**
	 * Subscriber IDs to unsubscribe on teardown of a test.
	 *
	 * @since   2.0.0
	 *
	 * @var     array<int, int>
	 */
	protected $subscriber_ids = [];

	/**
	 * Broadcast IDs to delete on teardown of a test.
	 *
	 * @since   2.0.0
	 *
	 * @var     array<int, int>
	 */
	protected $broadcast_ids = [];

	/**
	 * Webhook IDs to delete on teardown of a test.
	 *
	 * @since   2.0.0
	 *
	 * @var     array<int, int>
	 */
	protected $webhook_ids = [];

	/**
	 * Performs actions before each test.
	 *
	 * @since   1.0.0
	 */
	public function setUp(): void
	{
		parent::setUp();

		// Include class from /src to test.
		require_once 'src/class-convertkit-api-traits.php';
		require_once 'src/class-convertkit-api-v4.php';
		require_once 'src/class-convertkit-log.php';

		// Initialize the classes we want to test.
		$rawApi = new \ConvertKit_API_V4(
			client_id: $_ENV['CONVERTKIT_OAUTH_CLIENT_ID'],
			redirect_uri: $_ENV['CONVERTKIT_OAUTH_REDIRECT_URI'],
			access_token: $_ENV['CONVERTKIT_OAUTH_ACCESS_TOKEN'],
			refresh_token: $_ENV['CONVERTKIT_OAUTH_REFRESH_TOKEN']
		);

		$rawApiNoData = new \ConvertKit_API_V4(
			client_id: $_ENV['CONVERTKIT_OAUTH_CLIENT_ID'],
			redirect_uri: $_ENV['CONVERTKIT_OAUTH_REDIRECT_URI'],
			access_token: $_ENV['CONVERTKIT_OAUTH_ACCESS_TOKEN_NO_DATA'],
			refresh_token: $_ENV['CONVERTKIT_OAUTH_REFRESH_TOKEN_NO_DATA']
		);

		// For tests from TestsTrait, use the ObjectResponseProxy to convert array responses to stdClass.
		if ($this->currentTestIsFromTestsTrait()) {
			require_once __DIR__ . '/../Support/Helper/WPUnit/ObjectResponseProxy.php';
			$this->api         = new \Helper\WPUnit\ObjectResponseProxy($rawApi);
			$this->api_no_data = new \Helper\WPUnit\ObjectResponseProxy($rawApiNoData);
		} else {
			$this->api         = $rawApi;
			$this->api_no_data = $rawApiNoData;
		}

		// Wait a second to avoid hitting a 429 rate limit.
		sleep(1);
	}

	/**
	 * Returns true when the currently-executing test method was defined on
	 * the shared TestsTrait rather than on this class.
	 *
	 * PHP reflection preserves the source file of trait-provided methods
	 * even after the trait has been flattened into the composing class, so
	 * we can distinguish the two just by looking at the declaring file.
	 *
	 * @since   2.6.0
	 *
	 * @return  bool
	 */
	private function currentTestIsFromTestsTrait(): bool
	{
		try {
			// PHPUnit 10+ uses name(); older uses getName(false). Prefer the new API.
			$testName = method_exists($this, 'name')
				? $this->name()
				: $this->getName(false);
			$method   = new \ReflectionMethod($this, $testName);
			$file     = $method->getFileName();
			return $file !== false && basename($file) === 'TestsTrait.php';
		} catch (\Throwable $e) {
			return false;
		}
	}

	/**
	 * Performs actions after each test.
	 *
	 * @since   1.0.0
	 */
	public function tearDown(): void
	{
		// Delete any Custom Fields.
		foreach ($this->custom_field_ids as $id) {
			$this->api->delete_custom_field($id);
		}

		// Unsubscribe any Subscribers.
		foreach ($this->subscriber_ids as $id) {
			$this->api->unsubscribe($id);
		}

		// Delete any Webhooks.
		foreach ($this->webhook_ids as $id) {
			$this->api->delete_webhook($id);
		}

		// Delete any Broadcasts.
		foreach ($this->broadcast_ids as $id) {
			$this->api->delete_broadcast($id);
		}

		parent::tearDown();
	}

	/**
	 * Assert that the given callable produces an API-level error.
	 *
	 * In WP Libs an API-level error surfaces as a WP_Error return value
	 * (never as a thrown exception). We accept both return and throw so
	 * that any input-validation code that throws still counts.
	 *
	 * @since   2.0.5
	 *
	 * @param   callable $fn Callable that should fail.
	 * @return  void
	 */
	protected function assertApiError(callable $fn): void
	{
		try {
			$result = $fn();
		} catch (\Throwable $e) {
			$this->assertTrue(true, 'Callable threw an exception as expected.');
			return;
		}
		$this->assertInstanceOf(\WP_Error::class, $result);
	}

	/**
	 * Assert that the last API response had the given HTTP status code.
	 *
	 * Backed by ConvertKit_API_V4::get_last_response_code(), which mirrors
	 * the PHP SDK's getResponseInterface()->getStatusCode().
	 *
	 * @since   2.0.5
	 *
	 * @param   int $expected Expected HTTP status code.
	 * @return  void
	 */
	protected function assertLastResponseStatusCode(int $expected): void
	{
		$this->assertEquals($expected, $this->api->get_last_response_code());
	}

	/**
	 * Test that a log directory and file are created in the expected location, with .htaccess
	 * and index.html protection, and that the name and email addresses are masked.
	 *
	 * @since   1.4.2
	 */
	public function testLog()
	{
		// Define location for log file.
		define( 'CONVERTKIT_PLUGIN_PATH', $_ENV['WORDPRESS_ROOT_DIR'] . '/wp-content/uploads' );

		// Create a log.txt file.
		$this->tester->writeToFile(CONVERTKIT_PLUGIN_PATH . '/log.txt', 'historical log file');

		// Initialize API with logging enabled.
		$api = new \ConvertKit_API_V4(
			client_id: $_ENV['CONVERTKIT_OAUTH_CLIENT_ID'],
			redirect_uri: $_ENV['CONVERTKIT_OAUTH_REDIRECT_URI'],
			access_token: $_ENV['CONVERTKIT_OAUTH_ACCESS_TOKEN'],
			refresh_token: $_ENV['CONVERTKIT_OAUTH_REFRESH_TOKEN'],
			debug: true
		);

		// Perform actions that will write sensitive data to the log file.
		$api->form_subscribe(
			form_id: $_ENV['CONVERTKIT_API_FORM_ID'],
			email: $_ENV['CONVERTKIT_API_SUBSCRIBER_EMAIL'],
			first_name: 'First Name',
			custom_fields: array(
				'last_name' => 'Last',
			)
		);
		$api->profile($_ENV['CONVERTKIT_API_SIGNED_SUBSCRIBER_ID']);

		// Confirm the historical log.txt file has been deleted.
		$this->assertFileDoesNotExist(CONVERTKIT_PLUGIN_PATH . '/log.txt');

		// Confirm the .htaccess and index.html files exist.
		$this->assertDirectoryExists(CONVERTKIT_PLUGIN_PATH . '/log');
		$this->assertFileExists(CONVERTKIT_PLUGIN_PATH . '/log/.htaccess');
		$this->assertFileExists(CONVERTKIT_PLUGIN_PATH . '/log/index.html');
		$this->assertFileExists(CONVERTKIT_PLUGIN_PATH . '/log/log.txt');

		// Confirm the contents of the log file have masked the email address, name and signed subscriber ID.
		$this->tester->openFile(CONVERTKIT_PLUGIN_PATH . '/log/log.txt');
		$this->tester->seeInThisFile('API: POST subscribers: {"email_address":"o****@n********.c**","first_name":"******Name","state":"active","fields":{"last_name":"Last"}}');
		$this->tester->seeInThisFile('API: GET profile/*****************************************');
		$this->tester->dontSeeInThisFile($_ENV['CONVERTKIT_API_SUBSCRIBER_EMAIL']);
		$this->tester->dontSeeInThisFile('First Name');
		$this->tester->dontSeeInThisFile($_ENV['CONVERTKIT_API_SIGNED_SUBSCRIBER_ID']);

		// Cleanup test.
		$this->tester->cleanDir(CONVERTKIT_PLUGIN_PATH . '/log');
		$this->tester->deleteDir(CONVERTKIT_PLUGIN_PATH . '/log');
	}

	/**
	 * Test that a 401 unauthorized error gracefully returns a WP_Error.
	 *
	 * @since   1.3.2
	 */
	public function test401Unauthorized()
	{
		$api    = new \ConvertKit_API_V4(
			client_id: $_ENV['CONVERTKIT_OAUTH_CLIENT_ID'],
			redirect_uri: $_ENV['CONVERTKIT_OAUTH_REDIRECT_URI'],
			access_token: 'not-a-real-access-token',
			refresh_token: 'not-a-real-refresh-token'
		);
		$result = $api->get_account();
		$this->assertInstanceOf(\WP_Error::class, $result);
		$this->assertEquals($result->get_error_code(), $this->errorCode);
		$this->assertEquals($result->get_error_message(), 'The access token is invalid');
		$this->assertEquals($result->get_error_data($result->get_error_code()), 401);
	}

	/**
	 * Test that a 429 internal server error gracefully returns a WP_Error.
	 *
	 * @since   1.0.0
	 */
	public function test429RateLimitHit()
	{
		// Force WordPress HTTP classes and functions to return a 429 error.
		$this->mockResponses(
			httpCode: 429,
			httpMessage: 'Rate limit hit'
		);
		$result = $this->api->get_account(); // The API function we use doesn't matter, as mockResponse forces a 429 error.
		$this->assertInstanceOf(\WP_Error::class, $result);
		$this->assertEquals($result->get_error_code(), $this->errorCode);
		$this->assertEquals($result->get_error_message(), 'ConvertKit API Error: Rate limit hit.');
		$this->assertEquals($result->get_error_data($result->get_error_code()), 429);
	}

	/**
	 * Test that a 500 internal server error gracefully returns a WP_Error.
	 *
	 * @since   1.0.0
	 */
	public function test500InternalServerError()
	{
		// Force WordPress HTTP classes and functions to return a 500 error.
		$this->mockResponses(
			httpCode: 500,
			httpMessage: 'Internal server error.'
		);
		$result = $this->api->get_account(); // The API function we use doesn't matter, as mockResponse forces a 500 error.
		$this->assertInstanceOf(\WP_Error::class, $result);
		$this->assertEquals($result->get_error_code(), $this->errorCode);
		$this->assertEquals($result->get_error_message(), 'ConvertKit API Error: Internal server error.');
		$this->assertEquals($result->get_error_data($result->get_error_code()), 500);
	}

	/**
	 * Test that a 502 bad gateway gracefully returns a WP_Error.
	 *
	 * @since   1.0.0
	 */
	public function test502BadGateway()
	{
		// Force WordPress HTTP classes and functions to return a 502 error.
		$this->mockResponses(
			httpCode: 502,
			httpMessage: 'Bad gateway.'
		);
		$result = $this->api->get_account(); // The API function we use doesn't matter, as mockResponse forces a 502 error.
		$this->assertInstanceOf(\WP_Error::class, $result);
		$this->assertEquals($result->get_error_code(), $this->errorCode);
		$this->assertEquals($result->get_error_message(), 'ConvertKit API Error: Bad gateway.');
		$this->assertEquals($result->get_error_data($result->get_error_code()), 502);
	}

	/**
	 * Test that the User Agent string is in the expected format when
	 * a context is provided.
	 *
	 * @since   1.2.0
	 */
	public function testUserAgentWithContext()
	{
		// When an API call is made, inspect the user-agent argument.
		add_filter(
			'http_request_args',
			function($args, $url) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
				$this->assertStringContainsString(';context/TestContext', $args['user-agent']);
				return $args;
			},
			10,
			2
		);

		// Perform a request.
		$api    = new \ConvertKit_API_V4(
			client_id: $_ENV['CONVERTKIT_OAUTH_CLIENT_ID'],
			redirect_uri: $_ENV['CONVERTKIT_OAUTH_REDIRECT_URI'],
			access_token: $_ENV['CONVERTKIT_OAUTH_ACCESS_TOKEN'],
			refresh_token: $_ENV['CONVERTKIT_OAUTH_REFRESH_TOKEN'],
			debug: false,
			context: 'TestContext'
		);
		$result = $api->get_account();
	}

	/**
	 * Test that the User Agent string is in the expected format when
	 * no context is provided.
	 *
	 * @since   1.2.0
	 */
	public function testUserAgentWithoutContext()
	{
		// When an API call is made, inspect the user-agent argument.
		add_filter(
			'http_request_args',
			function($args, $url) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
				$this->assertStringNotContainsString(';context/TestContext', $args['user-agent']);
				return $args;
			},
			10,
			2
		);

		// Perform a request.
		$result = $this->api->get_account();
	}

	/**
	 * Test that get_oauth_url() returns the correct URL to begin the OAuth process.
	 *
	 * @since   2.0.0
	 *
	 * @return  void
	 */
	public function testGetOAuthURL()
	{
		// Confirm the OAuth URL returned is correct.
		$this->assertEquals(
			$this->api->get_oauth_url(),
			'https://app.kit.com/oauth/authorize?' . http_build_query(
				[
					'client_id'             => $_ENV['CONVERTKIT_OAUTH_CLIENT_ID'],
					'response_type'         => 'code',
					'redirect_uri'          => $_ENV['CONVERTKIT_OAUTH_REDIRECT_URI'],
					'code_challenge'        => $this->api->generate_code_challenge( $this->api->get_code_verifier() ),
					'code_challenge_method' => 'S256',
				]
			)
		);
	}

	/**
	 * Test that get_oauth_url() returns the correct URL to begin the OAuth process
	 * when a state parameter is supplied.
	 *
	 * @since   2.0.0
	 *
	 * @return  void
	 */
	public function testGetOAuthURLWithState()
	{
		// Confirm the OAuth URL returned is correct.
		$this->assertEquals(
			$this->api->get_oauth_url( 'https://example.com' ),
			'https://app.kit.com/oauth/authorize?' . http_build_query(
				[
					'client_id'             => $_ENV['CONVERTKIT_OAUTH_CLIENT_ID'],
					'response_type'         => 'code',
					'redirect_uri'          => $_ENV['CONVERTKIT_OAUTH_REDIRECT_URI'],
					'code_challenge'        => $this->api->generate_code_challenge( $this->api->get_code_verifier() ),
					'code_challenge_method' => 'S256',
					'state'                 => $this->api->base64_urlencode(
						wp_json_encode(
							array(
								'return_to' => 'https://example.com',
								'client_id' => $_ENV['CONVERTKIT_OAUTH_CLIENT_ID'],
							)
						)
					),
				]
			)
		);
	}

	/**
	 * Test that get_oauth_url() returns the correct URL to begin the OAuth process
	 * when a tenant_name parameter is supplied.
	 *
	 * @since   2.0.5
	 *
	 * @return  void
	 */
	public function testGetOAuthURLWithTenantName()
	{
		// Confirm the OAuth URL returned is correct.
		$this->assertEquals(
			$this->api->get_oauth_url( false, 'https://example.com' ),
			'https://app.kit.com/oauth/authorize?' . http_build_query(
				[
					'client_id'             => $_ENV['CONVERTKIT_OAUTH_CLIENT_ID'],
					'response_type'         => 'code',
					'redirect_uri'          => $_ENV['CONVERTKIT_OAUTH_REDIRECT_URI'],
					'code_challenge'        => $this->api->generate_code_challenge( $this->api->get_code_verifier() ),
					'code_challenge_method' => 'S256',
					'tenant_name'           => 'https://example.com',
				]
			)
		);
	}

	/**
	 * Test that get_access_token() returns the expected data.
	 *
	 * @since   2.0.0
	 *
	 * @return void
	 */
	public function testGetAccessToken()
	{
		// Define response parameters.
		$params = array(
			'access_token'  => 'example-access-token',
			'refresh_token' => 'example-refresh-token',
			'token_type'    => 'Bearer',
			'created_at'    => strtotime('now'),
			'expires_in'    => strtotime('+3 days'),
			'scope'         => 'public',
		);

		// Mock the API response.
		$this->mockResponses(
			httpCode: 200,
			httpMessage: 'OK',
			body: wp_json_encode( $params )
		);

		// Send request.
		$result = $this->api->get_access_token( 'auth-code' );

		// Inspect response.
		$this->assertNotInstanceOf(\WP_Error::class, $result);
		$this->assertIsArray($result);
		$this->assertArrayHasKey('access_token', $result);
		$this->assertArrayHasKey('refresh_token', $result);
		$this->assertArrayHasKey('token_type', $result);
		$this->assertArrayHasKey('created_at', $result);
		$this->assertArrayHasKey('expires_in', $result);
		$this->assertArrayHasKey('scope', $result);
		$this->assertEquals($result['access_token'], $params['access_token']);
		$this->assertEquals($result['refresh_token'], $params['refresh_token']);
		$this->assertEquals($result['created_at'], $params['created_at']);
		$this->assertEquals($result['expires_in'], $params['expires_in']);
	}

	/**
	 * Test that supplying an invalid auth code when fetching an access token returns a WP_Error.
	 *
	 * @since   2.0.0
	 *
	 * @return void
	 */
	public function testGetAccessTokenWithInvalidAuthCode()
	{
		$result = $this->api->get_access_token( 'not-a-real-auth-code' );
		$this->assertInstanceOf(\WP_Error::class, $result);
		$this->assertEquals($result->get_error_code(), 'convertkit_api_error');
	}

	/**
	 * Test that refresh_token() returns the expected data.
	 *
	 * @since   2.0.0
	 *
	 * @return void
	 */
	public function testRefreshToken()
	{
		// Add mock handler for this API request, as this results in a new
		// access and refresh token being provided, which would result in
		// other tests breaking due to changed tokens.
		$this->mockResponses(
			httpCode: 200,
			httpMessage: 'OK',
			body: wp_json_encode(
				array(
					'access_token'  => $_ENV['CONVERTKIT_OAUTH_ACCESS_TOKEN'],
					'refresh_token' => $_ENV['CONVERTKIT_OAUTH_REFRESH_TOKEN'],
					'token_type'    => 'bearer',
					'created_at'    => strtotime( 'now' ),
					'expires_in'    => 10000,
					'scope'         => 'public',
				)
			)
		);

		// Send request.
		$result = $this->api->refresh_token();

		// Inspect response.
		$this->assertNotInstanceOf(\WP_Error::class, $result);
		$this->assertIsArray($result);
		$this->assertArrayHasKey('access_token', $result);
		$this->assertArrayHasKey('refresh_token', $result);
		$this->assertArrayHasKey('token_type', $result);
		$this->assertArrayHasKey('created_at', $result);
		$this->assertArrayHasKey('expires_in', $result);
		$this->assertArrayHasKey('scope', $result);
		$this->assertEquals($result['access_token'], $_ENV['CONVERTKIT_OAUTH_ACCESS_TOKEN']);
		$this->assertEquals($result['refresh_token'], $_ENV['CONVERTKIT_OAUTH_REFRESH_TOKEN']);
	}

	/**
	 * Test that supplying an invalid refresh token when refreshing an access token returns a WP_Error.
	 *
	 * @since   2.0.0
	 *
	 * @return void
	 */
	public function testRefreshTokenWithInvalidToken()
	{
		// Setup API.
		$api = new \ConvertKit_API_V4(
			client_id: $_ENV['CONVERTKIT_OAUTH_CLIENT_ID'],
			redirect_uri: $_ENV['CONVERTKIT_OAUTH_REDIRECT_URI'],
			access_token: $_ENV['CONVERTKIT_OAUTH_ACCESS_TOKEN'],
			refresh_token: 'not-a-real-refresh-token'
		);

		$result = $api->refresh_token();
		$this->assertInstanceOf(\WP_Error::class, $result);
		$this->assertEquals($result->get_error_code(), 'convertkit_api_error');
	}

	/**
	 * Test that the access token and refresh token are revoked when revoke_tokens() is called.
	 *
	 * @since   2.1.4
	 */
	public function testRevokeTokens()
	{
		// Initialize the API without an access token or refresh token.
		$api = new \ConvertKit_API_V4(
			$_ENV['CONVERTKIT_OAUTH_CLIENT_ID'],
			$_ENV['CONVERTKIT_OAUTH_REDIRECT_URI']
		);

		// Generate an access token by API key and secret.
		$result = $api->get_access_token_by_api_key_and_secret(
			$_ENV['CONVERTKIT_API_KEY'],
			$_ENV['CONVERTKIT_API_SECRET'],
			wp_generate_password( 10, false ) // Random tenant name to produce a token for this request only.
		);

		// Initialize the API with the access token and refresh token.
		$api = new \ConvertKit_API_V4(
			$_ENV['CONVERTKIT_OAUTH_CLIENT_ID'],
			$_ENV['CONVERTKIT_OAUTH_REDIRECT_URI'],
			$result['oauth']['access_token'],
			$result['oauth']['refresh_token']
		);

		// Confirm the token works when making an authenticated request.
		$this->assertNotInstanceOf( 'WP_Error', $api->get_account() );

		// Revoke the access and refresh tokens.
		$api->revoke_tokens();

		// Initialize the API with the (now revoked) access token and refresh token.
		// revoke_tokens() will have removed the access token and refresh token from the API class, so we need to provide them again
		// to test they're revoked.
		$api = new \ConvertKit_API_V4(
			$_ENV['CONVERTKIT_OAUTH_CLIENT_ID'],
			$_ENV['CONVERTKIT_OAUTH_REDIRECT_URI'],
			$result['oauth']['access_token'],
			$result['oauth']['refresh_token']
		);

		// Confirm attempting to use the revoked access token no longer works.
		$this->assertInstanceOf( 'WP_Error', $api->get_account() );

		// Confirm attempting to use the revoked refresh token no longer works.
		$this->assertInstanceOf( 'WP_Error', $api->refresh_token() );
	}

	/**
	 * Test that supplying no API credentials to the API class returns a WP_Error.
	 *
	 * @since   2.0.2
	 */
	public function testNoAPICredentials()
	{
		$api    = new \ConvertKit_API_V4(
			client_id: $_ENV['CONVERTKIT_OAUTH_CLIENT_ID'],
			redirect_uri: $_ENV['CONVERTKIT_OAUTH_REDIRECT_URI']
		);
		$result = $api->get_account();
		$this->assertInstanceOf(\WP_Error::class, $result);
		$this->assertEquals($result->get_error_code(), $this->errorCode);
		$this->assertEquals($result->get_error_message(), 'Authentication Failed');
	}

	/**
	 * Test that supplying invalid API credentials to the API class returns a WP_Error.
	 *
	 * @since   1.0.0
	 */
	public function testInvalidAPICredentials()
	{
		$api    = new \ConvertKit_API_V4(
			client_id: $_ENV['CONVERTKIT_OAUTH_CLIENT_ID'],
			redirect_uri: $_ENV['CONVERTKIT_OAUTH_REDIRECT_URI'],
			access_token: 'fakeAccessToken',
			refresh_token: 'fakeRefreshToken'
		);
		$result = $api->get_account();
		$this->assertInstanceOf(\WP_Error::class, $result);
		$this->assertEquals($result->get_error_code(), $this->errorCode);
		$this->assertEquals($result->get_error_message(), 'The access token is invalid');
	}

	/**
	 * Test that fetching an Access Token using a valid API Key and Secret returns the expected data.
	 *
	 * @since   2.0.0
	 */
	public function testGetAccessTokenByAPIKeyAndSecret()
	{
		$api    = new \ConvertKit_API_V4(
			client_id: $_ENV['CONVERTKIT_OAUTH_CLIENT_ID'],
			redirect_uri: $_ENV['CONVERTKIT_OAUTH_REDIRECT_URI']
		);
		$result = $api->get_access_token_by_api_key_and_secret(
			api_key: $_ENV['CONVERTKIT_API_KEY'],
			api_secret: $_ENV['CONVERTKIT_API_SECRET']
		);
		$this->assertNotInstanceOf(\WP_Error::class, $result);
		$this->assertIsArray($result);
		$this->assertArrayHasKey('oauth', $result);
		$this->assertArrayHasKey('access_token', $result['oauth']);
		$this->assertArrayHasKey('refresh_token', $result['oauth']);
		$this->assertArrayHasKey('expires_at', $result['oauth']);
	}

	/**
	 * Test that fetching an Access Token using an invalid API Key and Secret returns a WP_Error.
	 *
	 * @since   2.0.0
	 */
	public function testGetAccessTokenByInvalidAPIKeyAndSecret()
	{
		$api    = new \ConvertKit_API_V4(
			client_id: $_ENV['CONVERTKIT_OAUTH_CLIENT_ID'],
			redirect_uri: $_ENV['CONVERTKIT_OAUTH_REDIRECT_URI']
		);
		$result = $api->get_access_token_by_api_key_and_secret(
			api_key: 'invalid-api-key',
			api_secret: 'invalid-api-secret'
		);
		$this->assertInstanceOf(\WP_Error::class, $result);
		$this->assertEquals($result->get_error_code(), $this->errorCode);
		$this->assertEquals('Authorization Failed: API Secret not valid', $result->get_error_message());
	}

	/**
	 * Test that fetching an Access Token using an invalid client ID returns a WP_Error.
	 *
	 * @since   2.0.7
	 */
	public function testGetAccessTokenByAPIKeyAndSecretWithInvalidClientID()
	{
		$api    = new \ConvertKit_API_V4(
			client_id: 'invalidClientID',
			redirect_uri: $_ENV['CONVERTKIT_OAUTH_REDIRECT_URI']
		);
		$result = $api->get_access_token_by_api_key_and_secret(
			api_key: $_ENV['CONVERTKIT_API_KEY'],
			api_secret: $_ENV['CONVERTKIT_API_SECRET']
		);
		$this->assertInstanceOf(\WP_Error::class, $result);
		$this->assertEquals($result->get_error_code(), $this->errorCode);
	}

	/**
	 * Test that fetching an Access Token using a blank client ID returns a WP_Error.
	 *
	 * @since   2.0.7
	 */
	public function testGetAccessTokenByAPIKeyAndSecretWithBlankClientID()
	{
		$api    = new \ConvertKit_API_V4(
			client_id: '',
			redirect_uri: $_ENV['CONVERTKIT_OAUTH_REDIRECT_URI']
		);
		$result = $api->get_access_token_by_api_key_and_secret(
			api_key: $_ENV['CONVERTKIT_API_KEY'],
			api_secret: $_ENV['CONVERTKIT_API_SECRET']
		);
		$this->assertInstanceOf(\WP_Error::class, $result);
		$this->assertEquals($result->get_error_code(), $this->errorCode);
	}

	/**
	 * Test that fetching an Access Token using a tenant_name parameter returns the expected data.
	 *
	 * @since   2.0.7
	 */
	public function testGetAccessTokenByAPIKeyAndSecretWithTenantName()
	{
		$api    = new \ConvertKit_API_V4(
			client_id: $_ENV['CONVERTKIT_OAUTH_CLIENT_ID'],
			redirect_uri: $_ENV['CONVERTKIT_OAUTH_REDIRECT_URI']
		);
		$result = $api->get_access_token_by_api_key_and_secret(
			api_key: $_ENV['CONVERTKIT_API_KEY'],
			api_secret: $_ENV['CONVERTKIT_API_SECRET'],
			tenant_name:'https://example.com'
		);
		$this->assertNotInstanceOf(\WP_Error::class, $result);
		$this->assertIsArray($result);
		$this->assertArrayHasKey('oauth', $result);
		$this->assertArrayHasKey('access_token', $result['oauth']);
		$this->assertArrayHasKey('refresh_token', $result['oauth']);
		$this->assertArrayHasKey('expires_at', $result['oauth']);
	}
	/**
	 * Test that get_legacy_forms() returns the expected data.
	 *
	 * @since   2.0.0
	 *
	 * @return void
	 */
	public function testGetLegacyForms()
	{
		$result = $this->api->get_legacy_forms();

		// Assert forms and pagination exist.
		$this->assertDataExists($result, 'legacy_landing_pages');
		$this->assertPaginationExists($result);

		// Iterate through each form, confirming no landing pages were included.
		foreach ($result['legacy_landing_pages'] as $form) {
			// Assert shape of object is valid.
			$this->assertArrayHasKey('id', $form);
			$this->assertArrayHasKey('name', $form);
			$this->assertArrayHasKey('created_at', $form);
			$this->assertArrayHasKey('type', $form);
			$this->assertArrayHasKey('url', $form);

			// Assert form is not a landing page i.e it is an embed.
			$this->assertEquals($form['type'], 'embed');
		}
	}

	/**
	 * Test that get_legacy_forms() returns the expected data
	 * when the total count is included.
	 *
	 * @since   2.0.0
	 *
	 * @return void
	 */
	public function testGetLegacyFormsWithTotalCount()
	{
		$result = $this->api->get_legacy_forms(
			include_total_count: true
		);

		// Assert forms and pagination exist.
		$this->assertDataExists($result, 'legacy_landing_pages');
		$this->assertPaginationExists($result);

		// Assert total count is included.
		$this->assertArrayHasKey('total_count', $result['pagination']);
		$this->assertGreaterThan(0, $result['pagination']['total_count']);
	}
	/**
	 * Test that get_legacy_landing_pages() returns the expected data.
	 *
	 * @since   2.0.0
	 *
	 * @return void
	 */
	public function testGetLegacyLandingPages()
	{
		$result = $this->api->get_legacy_landing_pages();

		// Assert landing pages and pagination exist.
		$this->assertDataExists($result, 'legacy_landing_pages');
		$this->assertPaginationExists($result);

		// Iterate through each landing page, confirming no forms were included.
		foreach ($result['legacy_landing_pages'] as $form) {
			// Assert shape of object is valid.
			$this->assertArrayHasKey('id', $form);
			$this->assertArrayHasKey('name', $form);
			$this->assertArrayHasKey('created_at', $form);
			$this->assertArrayHasKey('type', $form);
			$this->assertArrayHasKey('url', $form);

			// Assert landing page is not a form i.e it is hosted.
			$this->assertEquals($form['type'], 'hosted');
		}
	}

	/**
	 * Test that get_landing_pages() returns the expected data
	 * when the total count is included.
	 *
	 * @since   2.0.0
	 *
	 * @return void
	 */
	public function testGetLegacyLandingPagesWithTotalCount()
	{
		$result = $this->api->get_legacy_landing_pages(
			include_total_count: true
		);

		// Assert forms and pagination exist.
		$this->assertDataExists($result, 'legacy_landing_pages');
		$this->assertPaginationExists($result);

		// Assert total count is included.
		$this->assertArrayHasKey('total_count', $result['pagination']);
		$this->assertGreaterThan(0, $result['pagination']['total_count']);
	}
	/**
	 * Test that add_subscriber_to_form() returns a WP_Error when a legacy
	 * form ID is specified.
	 *
	 * @since   2.0.0
	 *
	 * @return void
	 */
	public function testAddSubscriberToFormWithLegacyFormID()
	{
		$result = $this->api->add_subscriber_to_form(
			form_id: $_ENV['CONVERTKIT_API_LEGACY_FORM_ID'],
			subscriber_id: $_ENV['CONVERTKIT_API_SUBSCRIBER_ID']
		);
		$this->assertInstanceOf(\WP_Error::class, $result);
		$this->assertEquals($result->get_error_code(), $this->errorCode);
	}


	/**
	 * Test that add_subscriber_to_legacy_form() returns the expected data.
	 *
	 * @since   2.0.0
	 *
	 * @return void
	 */
	public function testAddSubscriberToLegacyForm()
	{
		// Create subscriber.
		$subscriber = $this->api->create_subscriber($this->generateEmailAddress());

		$this->assertNotInstanceOf(\WP_Error::class, $subscriber);
		$this->assertIsArray($subscriber);

		// Set subscriber_id to ensure subscriber is unsubscribed after test.
		$this->subscriber_ids[] = $subscriber['subscriber']['id'];

		// Add subscriber to legacy form.
		$result = $this->api->add_subscriber_to_legacy_form(
			form_id: (int) $_ENV['CONVERTKIT_API_LEGACY_FORM_ID'],
			subscriber_id: $subscriber['subscriber']['id']
		);
		$this->assertNotInstanceOf(\WP_Error::class, $result);
		$this->assertIsArray($result);
		$this->assertArrayHasKey('subscriber', $result);
		$this->assertArrayHasKey('id', $result['subscriber']);
		$this->assertEquals($result['subscriber']['id'], $subscriber['subscriber']['id']);
	}

	/**
	 * Test that add_subscriber_to_legacy_form() returns a WP_Error when an invalid
	 * form ID is specified.
	 *
	 * @since   2.0.0
	 *
	 * @return void
	 */
	public function testAddSubscriberToLegacyFormWithInvalidFormID()
	{
		$result = $this->api->add_subscriber_to_legacy_form(
			form_id: 12345,
			subscriber_id: $_ENV['CONVERTKIT_API_SUBSCRIBER_ID']
		);
		$this->assertInstanceOf(\WP_Error::class, $result);
		$this->assertEquals($result->get_error_code(), $this->errorCode);
	}

	/**
	 * Test that add_subscriber_to_legacy_form() returns a WP_Error when a non-legacy
	 * form ID is specified.
	 *
	 * @since   2.0.0
	 *
	 * @return void
	 */
	public function testAddSubscriberToLegacyFormWithNonLegacyFormID()
	{
		$result = $this->api->add_subscriber_to_legacy_form(
			form_id: (int) $_ENV['CONVERTKIT_API_FORM_ID'],
			subscriber_id: $_ENV['CONVERTKIT_API_SUBSCRIBER_ID']
		);
		$this->assertInstanceOf(\WP_Error::class, $result);
		$this->assertEquals($result->get_error_code(), $this->errorCode);
	}

	/**
	 * Test that add_subscriber_to_legacy_form() returns a WP_Error when an invalid
	 * email address is specified.
	 *
	 * @since   2.0.0
	 *
	 * @return void
	 */
	public function testAddSubscriberToLegacyFormWithInvalidSubscriberID()
	{
		$result = $this->api->add_subscriber_to_legacy_form(
			form_id: (int) $_ENV['CONVERTKIT_API_LEGACY_FORM_ID'],
			subscriber_id: 12345
		);
		$this->assertInstanceOf(\WP_Error::class, $result);
		$this->assertEquals($result->get_error_code(), $this->errorCode);
	}
	/**
	 * Test that create_tag() returns the expected data.
	 *
	 * @since   1.0.0
	 *
	 * @return void
	 */
	public function testCreateTag()
	{
		$tagName = 'Tag Test ' . wp_rand();

		// Add mock handler for this API request, as the API doesn't provide
		// a method to delete tags to cleanup the test.
		$this->mockResponses(
			201,
			'Created',
			wp_json_encode(
				array(
					'tag' => array(
						'id'         => 12345,
						'name'       => $tagName,
						'created_at' => date('Y-m-d') . 'T' . date('H:i:s') . 'Z',
					),
				)
			)
		);

		// Send request.
		$result = $this->api->create_tag($tagName);

		// Assert response contains correct data.
		$this->assertArrayHasKey('id', $result['tag']);
		$this->assertArrayHasKey('name', $result['tag']);
		$this->assertArrayHasKey('created_at', $result['tag']);
		$this->assertEquals($result['tag']['name'], $tagName);
	}
	/**
	 * Test that create_tags() returns the expected data.
	 *
	 * @since   2.0.0
	 *
	 * @return void
	 */
	public function testCreateTags()
	{
		$tagNames = [
			'Tag Test ' . wp_rand(),
			'Tag Test ' . wp_rand(),
		];

		// Add mock handler for this API request, as the API doesn't provide
		// a method to delete tags to cleanup the test.
		$this->mockResponses(
			201,
			'Created',
			wp_json_encode(
				array(
					'tags'     => array(
						array(
							'id'         => 12345,
							'name'       => $tagNames[0],
							'created_at' => date('Y-m-d') . 'T' . date('H:i:s') . 'Z',
						),
						array(
							'id'         => 23456,
							'name'       => $tagNames[1],
							'created_at' => date('Y-m-d') . 'T' . date('H:i:s') . 'Z',
						),
					),
					'failures' => array(),
				)
			)
		);

		$result = $this->api->create_tags($tagNames);

		// Assert no failures.
		$this->assertCount(0, $result['failures']);
	}
	/**
	 * Test that create_broadcast(), update_broadcast() and delete_broadcast() works
	 * when specifying valid published_at and send_at values.
	 *
	 * We do all tests in a single function, so we don't end up with unnecessary Broadcasts remaining
	 * on the ConvertKit account when running tests, which might impact
	 * other tests that expect (or do not expect) specific Broadcasts.
	 *
	 * @since   2.0.0
	 *
	 * @return void
	 */
	public function testCreateUpdateAndDeleteDraftBroadcast()
	{
		// Create a broadcast first.
		$result = $this->api->create_broadcast(
			subject: 'Test Subject',
			content: 'Test Content',
			description: 'Test Broadcast from WordPress Libraries'
		);
		$this->assertNotInstanceOf(\WP_Error::class, $result);
		$this->assertIsArray($result);

		// Store Broadcast ID.
		$broadcastID = $result['broadcast']['id'];

		// Confirm the Broadcast saved.
		$this->assertArrayHasKey('broadcast', $result);
		$this->assertArrayHasKey('id', $result['broadcast']);
		$this->assertEquals('Test Subject', $result['broadcast']['subject']);
		$this->assertEquals('Test Content', $result['broadcast']['content']);
		$this->assertEquals('Test Broadcast from WordPress Libraries', $result['broadcast']['description']);
		$this->assertEquals(null, $result['broadcast']['published_at']);
		$this->assertEquals(null, $result['broadcast']['send_at']);

		// Update the existing broadcast.
		$result = $this->api->update_broadcast(
			id: $broadcastID,
			subject: 'New Test Subject',
			content: 'New Test Content',
			description: 'New Test Broadcast from WordPress Libraries'
		);
		$this->assertNotInstanceOf(\WP_Error::class, $result);
		$this->assertIsArray($result);

		// Confirm the changes saved.
		$this->assertArrayHasKey('broadcast', $result);
		$this->assertArrayHasKey('id', $result['broadcast']);
		$this->assertEquals('New Test Subject', $result['broadcast']['subject']);
		$this->assertEquals('New Test Content', $result['broadcast']['content']);
		$this->assertEquals('New Test Broadcast from WordPress Libraries', $result['broadcast']['description']);
		$this->assertEquals(null, $result['broadcast']['published_at']);
		$this->assertEquals(null, $result['broadcast']['send_at']);

		// Delete Broadcast.
		$result = $this->api->delete_broadcast($broadcastID);
		$this->assertNotInstanceOf(\WP_Error::class, $result);
	}
	/**
	 * Test that the `form_subscribe()` function returns the expected data.
	 *
	 * @since   2.0.0
	 *
	 * @return void
	 */
	public function testFormSubscribe()
	{
		// Make request.
		$emailAddress = $this->generateEmailAddress();
		$result       = $this->api->form_subscribe(
			form_id: $_ENV['CONVERTKIT_API_FORM_ID'],
			email: $emailAddress,
			first_name: 'First',
			custom_fields: [
				'last_name' => 'Last',
			]
		);

		// Test array was returned.
		$this->assertNotInstanceOf(\WP_Error::class, $result);
		$this->assertIsArray($result);

		// Assert subscriber created.
		$this->assertArrayHasKey('subscriber', $result);
		$this->assertArrayHasKey('email_address', $result['subscriber']);
		$this->assertEquals($emailAddress, $result['subscriber']['email_address']);
	}

	/**
	 * Test that the `form_subscribe()` function returns a WP_Error
	 * when an invalid Form ID is specified.
	 *
	 * @since   2.0.0
	 *
	 * @return void
	 */
	public function testFormSubscribeWithInvalidFormID()
	{
		$result = $this->api->form_subscribe(
			form_id: 12345,
			email: $this->generateEmailAddress()
		);
		$this->assertInstanceOf(\WP_Error::class, $result);
		$this->assertEquals($result->get_error_code(), $this->errorCode);
	}

	/**
	 * Test that the `form_subscribe()` function returns a WP_Error
	 * when a legacy Form ID is specified.
	 *
	 * @since   2.0.0
	 *
	 * @return void
	 */
	public function testFormSubscribeWithLegacyFormID()
	{
		$result = $this->api->form_subscribe(
			form_id: $_ENV['CONVERTKIT_API_LEGACY_FORM_ID'],
			email: $this->generateEmailAddress()
		);
		$this->assertInstanceOf(\WP_Error::class, $result);
		$this->assertEquals($result->get_error_code(), $this->errorCode);
	}

	/**
	 * Test that the `form_subscribe()` function returns a WP_Error
	 * when an invalid email address is specified.
	 *
	 * @since   2.0.0
	 *
	 * @return void
	 */
	public function testFormSubscribeWithInvalidEmailAddress()
	{
		$result = $this->api->form_subscribe(
			form_id: $_ENV['CONVERTKIT_API_FORM_ID'],
			email: 'not-a-valid-email'
		);
		$this->assertInstanceOf(\WP_Error::class, $result);
		$this->assertEquals($result->get_error_code(), $this->errorCode);
	}

	/**
	 * Test that the `legacy_form_subscribe()` function returns the expected data.
	 *
	 * @since   2.0.0
	 *
	 * @return void
	 */
	public function testLegacyFormSubscribe()
	{
		// Make request.
		$emailAddress = $this->generateEmailAddress();
		$result       = $this->api->legacy_form_subscribe(
			form_id: $_ENV['CONVERTKIT_API_LEGACY_FORM_ID'],
			email: $emailAddress,
			first_name: 'First',
			custom_fields: [
				'last_name' => 'Last',
			]
		);

		// Test array was returned.
		$this->assertNotInstanceOf(\WP_Error::class, $result);
		$this->assertIsArray($result);

		// Assert subscriber created.
		$this->assertArrayHasKey('subscriber', $result);
		$this->assertArrayHasKey('email_address', $result['subscriber']);
		$this->assertEquals($emailAddress, $result['subscriber']['email_address']);
	}

	/**
	 * Test that the `legacy_form_subscribe()` function returns a WP_Error
	 * when an invalid Form ID is specified.
	 *
	 * @since   2.0.0
	 *
	 * @return void
	 */
	public function testLegacyFormSubscribeWithInvalidFormID()
	{
		$result = $this->api->legacy_form_subscribe(
			form_id: 12345,
			email: $this->generateEmailAddress()
		);
		$this->assertInstanceOf(\WP_Error::class, $result);
		$this->assertEquals($result->get_error_code(), $this->errorCode);
	}

	/**
	 * Test that the `legacy_form_subscribe()` function returns a WP_Error
	 * when a non-legacy Form ID is specified.
	 *
	 * @since   2.0.0
	 *
	 * @return void
	 */
	public function testLegacyFormSubscribeWithNonLegacyFormID()
	{
		$result = $this->api->legacy_form_subscribe(
			form_id: $_ENV['CONVERTKIT_API_FORM_ID'],
			email: $this->generateEmailAddress()
		);
		$this->assertInstanceOf(\WP_Error::class, $result);
		$this->assertEquals($result->get_error_code(), $this->errorCode);
	}

	/**
	 * Test that the `legacy_form_subscribe()` function returns a WP_Error
	 * when an invalid email address is specified.
	 *
	 * @since   2.0.0
	 *
	 * @return void
	 */
	public function testLegacyFormSubscribeWithInvalidEmailAddress()
	{
		$result = $this->api->legacy_form_subscribe(
			form_id: $_ENV['CONVERTKIT_API_LEGACY_FORM_ID'],
			email: 'not-a-valid-email'
		);
		$this->assertInstanceOf(\WP_Error::class, $result);
		$this->assertEquals($result->get_error_code(), $this->errorCode);
	}

	/**
	 * Test that the `tag_subscribe()` function returns the expected data.
	 *
	 * @since   2.0.0
	 *
	 * @return void
	 */
	public function testTagSubscribe()
	{
		// Make request.
		$emailAddress = $this->generateEmailAddress();
		$result       = $this->api->tag_subscribe(
			tag_id: $_ENV['CONVERTKIT_API_TAG_ID'],
			email: $emailAddress,
			first_name: 'First',
			custom_fields: [
				'last_name' => 'Last',
			]
		);

		// Test array was returned.
		$this->assertNotInstanceOf(\WP_Error::class, $result);
		$this->assertIsArray($result);

		// Assert subscriber created.
		$this->assertArrayHasKey('subscriber', $result);
		$this->assertArrayHasKey('email_address', $result['subscriber']);
		$this->assertEquals($emailAddress, $result['subscriber']['email_address']);
	}

	/**
	 * Test that the `tag_subscribe()` function returns a WP_Error
	 * when an invalid Tag ID is specified.
	 *
	 * @since   2.0.0
	 *
	 * @return void
	 */
	public function testTagSubscribeWithInvalidTagID()
	{
		$result = $this->api->tag_subscribe(
			tag_id: 12345,
			email: $this->generateEmailAddress()
		);
		$this->assertInstanceOf(\WP_Error::class, $result);
		$this->assertEquals($result->get_error_code(), $this->errorCode);
	}

	/**
	 * Test that the `form_subscribe()` function returns a WP_Error
	 * when an invalid email address is specified.
	 *
	 * @since   2.0.0
	 *
	 * @return void
	 */
	public function testTagSubscribeWithInvalidEmailAddress()
	{
		$result = $this->api->tag_subscribe(
			tag_id: $_ENV['CONVERTKIT_API_TAG_ID'],
			email: 'not-a-valid-email'
		);
		$this->assertInstanceOf(\WP_Error::class, $result);
		$this->assertEquals($result->get_error_code(), $this->errorCode);
	}

	/**
	 * Test that the `sequence_subscribe()` function returns the expected data.
	 *
	 * @since   2.0.0
	 *
	 * @return void
	 */
	public function testSequenceSubscribe()
	{
		// Make request.
		$emailAddress = $this->generateEmailAddress();
		$result       = $this->api->sequence_subscribe(
			sequence_id: $_ENV['CONVERTKIT_API_SEQUENCE_ID'],
			email: $emailAddress,
			first_name: 'First',
			custom_fields: [
				'last_name' => 'Last',
			]
		);

		// Test array was returned.
		$this->assertNotInstanceOf(\WP_Error::class, $result);
		$this->assertIsArray($result);

		// Assert subscriber created.
		$this->assertArrayHasKey('subscriber', $result);
		$this->assertArrayHasKey('email_address', $result['subscriber']);
		$this->assertEquals($emailAddress, $result['subscriber']['email_address']);
	}

	/**
	 * Test that the `sequence_subscribe()` function returns a WP_Error
	 * when an invalid Tag ID is specified.
	 *
	 * @since   2.0.0
	 *
	 * @return void
	 */
	public function testSequenceSubscribeWithInvalidTagID()
	{
		$result = $this->api->sequence_subscribe(
			sequence_id: 12345,
			email: $this->generateEmailAddress()
		);
		$this->assertInstanceOf(\WP_Error::class, $result);
		$this->assertEquals($result->get_error_code(), $this->errorCode);
	}

	/**
	 * Test that the `sequence_subscribe()` function returns a WP_Error
	 * when an invalid email address is specified.
	 *
	 * @since   2.0.0
	 *
	 * @return void
	 */
	public function testSequenceSubscribeWithInvalidEmailAddress()
	{
		$result = $this->api->sequence_subscribe(
			sequence_id: $_ENV['CONVERTKIT_API_TAG_ID'],
			email: 'not-a-valid-email'
		);
		$this->assertInstanceOf(\WP_Error::class, $result);
		$this->assertEquals($result->get_error_code(), $this->errorCode);
	}

	/**
	 * Test that the `get_posts()` function returns expected data.
	 *
	 * @since   1.0.0
	 */
	public function testGetPosts()
	{
		$result = $this->api->get_posts();

		// Test array was returned.
		$this->assertNotInstanceOf(\WP_Error::class, $result);
		$this->assertIsArray($result);

		// Test expected response keys exist.
		$this->assertArrayHasKey('total_posts', $result);
		$this->assertArrayHasKey('page', $result);
		$this->assertArrayHasKey('total_pages', $result);
		$this->assertArrayHasKey('posts', $result);

		// Test first post within posts array.
		$this->assertArrayHasKey('id', reset($result['posts']));
		$this->assertArrayHasKey('title', reset($result['posts']));
		$this->assertArrayHasKey('url', reset($result['posts']));
		$this->assertArrayHasKey('published_at', reset($result['posts']));
		$this->assertArrayHasKey('is_paid', reset($result['posts']));
	}

	/**
	 * Test that get_posts() returns the expected data
	 * when the post content is included.
	 *
	 * @since   2.5.0
	 *
	 * @return void
	 */
	public function testGetPostsWithIncludeContent()
	{
		$this->markTestSkipped('Kit WordPress Libraries uses the wordpress/posts endpoint, which does not support the include_content parameter.');
	}

	/**
	 * Test that get_posts() returns the expected data
	 * when the total count is included.
	 *
	 * @since   2.5.0
	 *
	 * @return void
	 */
	public function testGetPostsWithTotalCount()
	{
		$this->markTestSkipped('Kit WordPress Libraries uses the wordpress/posts endpoint, which does not support the total_count parameter.');
	}

	/**
	 * Test that get_posts() returns the expected data
	 * when pagination parameters and per_page limits are specified.
	 *
	 * @since   2.5.0
	 *
	 * @return void
	 */
	public function testGetPostsPagination()
	{
		$this->markTestSkipped('Kit WordPress Libraries uses the wordpress/posts endpoint, which does not support the pagination parameter.');
	}

	/**
	 * Test that the `get_posts()` function returns a blank array when no data
	 * exists on the ConvertKit account.
	 *
	 * @since   1.0.0
	 */
	public function testGetPostsNoData()
	{
		$result = $this->api_no_data->get_posts();
		$this->assertNotInstanceOf(\WP_Error::class, $result);
		$this->assertIsArray($result);
		$this->assertCount(0, $result);
	}

	/**
	 * Test that the `get_posts()` function returns expected data
	 * when valid parameters are included.
	 *
	 * @since   1.0.0
	 */
	public function testGetPostsWithValidParameters()
	{
		$result = $this->api->get_posts(1, 2);

		// Test array was returned.
		$this->assertNotInstanceOf(\WP_Error::class, $result);
		$this->assertIsArray($result);

		// Test expected response keys exist.
		$this->assertArrayHasKey('total_posts', $result);
		$this->assertArrayHasKey('page', $result);
		$this->assertArrayHasKey('total_pages', $result);
		$this->assertArrayHasKey('posts', $result);

		// Test expected number of posts returned.
		$this->assertCount(2, $result['posts']);

		// Test first post within posts array.
		$this->assertArrayHasKey('id', reset($result['posts']));
		$this->assertArrayHasKey('title', reset($result['posts']));
		$this->assertArrayHasKey('url', reset($result['posts']));
		$this->assertArrayHasKey('published_at', reset($result['posts']));
		$this->assertArrayHasKey('is_paid', reset($result['posts']));
	}

	/**
	 * Test that the `get_posts()` function returns an error
	 * when the page parameter is less than 1.
	 *
	 * @since   1.0.0
	 */
	public function testGetPostsWithInvalidPageParameter()
	{
		$result = $this->api->get_posts(0);
		$this->assertInstanceOf(\WP_Error::class, $result);
		$this->assertEquals($result->get_error_code(), $this->errorCode);
		$this->assertEquals('get_posts(): the page parameter must be equal to or greater than 1.', $result->get_error_message());
	}

	/**
	 * Test that the `get_posts()` function returns an error
	 * when the per_page parameter is less than 1.
	 *
	 * @since   1.0.0
	 */
	public function testGetPostsWithNegativePerPageParameter()
	{
		$result = $this->api->get_posts(1, 0);
		$this->assertInstanceOf(\WP_Error::class, $result);
		$this->assertEquals($result->get_error_code(), $this->errorCode);
		$this->assertEquals('get_posts(): the per_page parameter must be equal to or greater than 1.', $result->get_error_message());
	}

	/**
	 * Test that the `get_posts()` function returns an error
	 * when the per_page parameter is greater than 50.
	 *
	 * @since   1.0.0
	 */
	public function testGetPostsWithOutOfBoundsPerPageParameter()
	{
		$result = $this->api->get_posts(1, 100);
		$this->assertInstanceOf(\WP_Error::class, $result);
		$this->assertEquals($result->get_error_code(), $this->errorCode);
		$this->assertEquals('get_posts(): the per_page parameter must be equal to or less than 50.', $result->get_error_message());
	}

	/**
	 * Test that the `get_all_posts()` function returns expected data.
	 *
	 * @since   1.0.0
	 */
	public function testGetAllPosts()
	{
		$result = $this->api->get_all_posts();
		$this->assertNotInstanceOf(\WP_Error::class, $result);
		$this->assertIsArray($result);
		$this->assertArrayHasKey('id', reset($result));
		$this->assertArrayHasKey('title', reset($result));
		$this->assertArrayHasKey('url', reset($result));
		$this->assertArrayHasKey('published_at', reset($result));
		$this->assertArrayHasKey('is_paid', reset($result));
	}

	/**
	 * Test that the `get_all_posts()` function returns a blank array when no data
	 * exists on the ConvertKit account.
	 *
	 * @since   1.0.0
	 */
	public function testGetAllPostsNoData()
	{
		$result = $this->api_no_data->get_all_posts();
		$this->assertNotInstanceOf(\WP_Error::class, $result);
		$this->assertIsArray($result);
		$this->assertCount(0, $result);
	}

	/**
	 * Test that the `get_all_posts()` function returns expected data
	 * when valid parameters are included.
	 *
	 * @since   1.0.0
	 */
	public function testGetAllPostsWithValidParameters()
	{
		$result = $this->api->get_all_posts(2); // Number of posts to fetch in each request within the function.
		$this->assertNotInstanceOf(\WP_Error::class, $result);
		$this->assertIsArray($result);
		$this->assertCount(5, $result);
		$this->assertArrayHasKey('id', reset($result));
		$this->assertArrayHasKey('title', reset($result));
		$this->assertArrayHasKey('url', reset($result));
		$this->assertArrayHasKey('published_at', reset($result));
		$this->assertArrayHasKey('is_paid', reset($result));
	}

	/**
	 * Test that the `get_all_posts()` function returns an error
	 * when the page parameter is less than 1.
	 *
	 * @since   1.0.0
	 */
	public function testGetAllPostsWithInvalidPostsPerRequestParameter()
	{
		// Test with a number less than 1.
		$result = $this->api->get_all_posts(0);
		$this->assertInstanceOf(\WP_Error::class, $result);
		$this->assertEquals($result->get_error_code(), $this->errorCode);
		$this->assertEquals('get_all_posts(): the posts_per_request parameter must be equal to or greater than 1.', $result->get_error_message());

		// Test with a number greater than 50.
		$result = $this->api->get_all_posts(51);
		$this->assertInstanceOf(\WP_Error::class, $result);
		$this->assertEquals($result->get_error_code(), $this->errorCode);
		$this->assertEquals('get_all_posts(): the posts_per_request parameter must be equal to or less than 50.', $result->get_error_message());
	}

	/**
	 * Test that the `get_post()` function returns expected data.
	 *
	 * @since   1.3.8
	 */
	public function testGetPost()
	{
		$result = $this->api->get_post($_ENV['CONVERTKIT_API_POST_ID']);
		$this->assertNotInstanceOf(\WP_Error::class, $result);
		$this->assertIsArray($result);
		$this->assertArrayHasKey('id', $result);
		$this->assertArrayHasKey('title', $result);
		$this->assertArrayHasKey('description', $result);
		$this->assertArrayHasKey('published_at', $result);
		$this->assertArrayHasKey('is_paid', $result);
		$this->assertArrayHasKey('thumbnail_alt', $result);
		$this->assertArrayHasKey('thumbnail_url', $result);
		$this->assertArrayHasKey('url', $result);
		$this->assertArrayHasKey('product_id', $result);
		$this->assertArrayHasKey('content', $result);
	}

	/**
	 * Test that the `get_products()` function returns expected data.
	 *
	 * @since   1.1.0
	 */
	public function testGetProducts()
	{
		$result = $this->api->get_products();
		$this->assertNotInstanceOf(\WP_Error::class, $result);
		$this->assertIsArray($result);
		$this->assertArrayHasKey('id', reset($result));
		$this->assertArrayHasKey('name', reset($result));
		$this->assertArrayHasKey('url', reset($result));
		$this->assertArrayHasKey('published', reset($result));
	}

	/**
	 * Test that the `get_products()` function returns a blank array when no data
	 * exists on the ConvertKit account.
	 *
	 * @since   1.1.0
	 */
	public function testGetProductsNoData()
	{
		$result = $this->api_no_data->get_products();
		$this->assertNotInstanceOf(\WP_Error::class, $result);
		$this->assertIsArray($result);
		$this->assertCount(0, $result);
	}

	/**
	 * Test that the `subscriber_authentication_send_code()` function returns the expected
	 * response when a valid email subscriber is specified.
	 *
	 * @since   1.3.0
	 */
	public function testSubscriberAuthenticationSendCodeWithSubscribedEmail()
	{
		$result = $this->api->subscriber_authentication_send_code(
			email: $_ENV['CONVERTKIT_API_SUBSCRIBER_EMAIL'],
			redirect_url: $_ENV['WORDPRESS_URL']
		);
		$this->assertNotInstanceOf(\WP_Error::class, $result);
	}

	/**
	 * Test that the `subscriber_authentication_send_code()` function returns the expected
	 * response when an email address is specified that is not a subscriber in ConvertKit.
	 *
	 * @since   1.3.0
	 */
	public function testSubscriberAuthenticationSendCodeWithNotSubscribedEmail()
	{
		$result = $this->api->subscriber_authentication_send_code(
			email: 'email-not-subscribed@kit.com',
			redirect_url: $_ENV['WORDPRESS_URL']
		);
		$this->assertInstanceOf(\WP_Error::class, $result);
		$this->assertEquals($result->get_error_code(), $this->errorCode);
		$this->assertEquals($result->get_error_message(), 'invalid: Email address is invalid');
	}

	/**
	 * Test that the `subscriber_authentication_send_code()` function returns the expected
	 * response when no email address is specified.
	 *
	 * @since   1.3.0
	 */
	public function testSubscriberAuthenticationSendCodeWithNoEmail()
	{
		$result = $this->api->subscriber_authentication_send_code(
			email: '',
			redirect_url: $_ENV['WORDPRESS_URL']
		);
		$this->assertInstanceOf(\WP_Error::class, $result);
		$this->assertEquals($result->get_error_code(), $this->errorCode);
		$this->assertEquals($result->get_error_message(), 'subscriber_authentication_send_code(): the email parameter is empty.');
	}

	/**
	 * Test that the `subscriber_authentication_send_code()` function returns the expected
	 * response when an invalid email address is specified.
	 *
	 * @since   1.3.0
	 */
	public function testSubscriberAuthenticationSendCodeWithInvalidEmail()
	{
		$result = $this->api->subscriber_authentication_send_code(
			email: 'not-an-email-address',
			redirect_url: $_ENV['WORDPRESS_URL']
		);
		$this->assertInstanceOf(\WP_Error::class, $result);
		$this->assertEquals($result->get_error_code(), $this->errorCode);
		$this->assertEquals($result->get_error_message(), 'invalid: Email address is invalid');
	}

	/**
	 * Test that the `subscriber_authentication_send_code()` function returns the expected
	 * response when an invalid redirect URL is specified.
	 *
	 * @since   1.3.0
	 */
	public function testSubscriberAuthenticationSendCodeWithInvalidRedirectURL()
	{
		$result = $this->api->subscriber_authentication_send_code(
			email: $_ENV['CONVERTKIT_API_SUBSCRIBER_EMAIL'],
			redirect_url: 'not-a-valid-url'
		);
		$this->assertInstanceOf(\WP_Error::class, $result);
		$this->assertEquals($result->get_error_code(), $this->errorCode);
		$this->assertEquals($result->get_error_message(), 'subscriber_authentication_send_code(): the redirect_url parameter is not a valid URL.');
	}

	/**
	 * Test that the `subscriber_authentication_verify()` function returns the expected
	 * response when a valid token is specified, but the subscriber code is invalid.
	 *
	 * @since   1.3.0
	 */
	public function testSubscriberAuthenticationVerifyWithValidTokenAndInvalidSubscriberCode()
	{
		$result = $this->api->subscriber_authentication_verify(
			token: $_ENV['CONVERTKIT_API_SUBSCRIBER_TOKEN'],
			subscriber_code: 'subscriberCode'
		);
		$this->assertInstanceOf(\WP_Error::class, $result);
		$this->assertEquals($result->get_error_code(), $this->errorCode);
		$this->assertEquals($result->get_error_message(), 'The entered code is invalid. Please try again, or click the link sent in the email.');
	}

	/**
	 * Test that the `subscriber_authentication_verify()` function returns the expected
	 * response when no token is specified.
	 *
	 * @since   1.3.0
	 */
	public function testSubscriberAuthenticationVerifyWithNoToken()
	{
		$result = $this->api->subscriber_authentication_verify(
			token: '',
			subscriber_code: 'subscriberCode'
		);
		$this->assertInstanceOf(\WP_Error::class, $result);
		$this->assertEquals($result->get_error_code(), $this->errorCode);
		$this->assertEquals($result->get_error_message(), 'subscriber_authentication_verify(): the token parameter is empty.');
	}

	/**
	 * Test that the `subscriber_authentication_verify()` function returns the expected
	 * response when no subscriber code is specified.
	 *
	 * @since   1.3.0
	 */
	public function testSubscriberAuthenticationVerifyWithNoSubscriberCode()
	{
		$result = $this->api->subscriber_authentication_verify(
			token: 'token',
			subscriber_code: ''
		);
		$this->assertInstanceOf(\WP_Error::class, $result);
		$this->assertEquals($result->get_error_code(), $this->errorCode);
		$this->assertEquals($result->get_error_message(), 'subscriber_authentication_verify(): the subscriber_code parameter is empty.');
	}

	/**
	 * Test that the `subscriber_authentication_verify()` function returns the expected
	 * response when an invalid token and subscriber code is specified.
	 *
	 * @since   1.3.0
	 */
	public function testSubscriberAuthenticationVerifyWithInvalidTokenAndSubscriberCode()
	{
		$result = $this->api->subscriber_authentication_verify(
			token: 'invalidToken',
			subscriber_code: 'invalidSubscriberCode'
		);
		$this->assertInstanceOf(\WP_Error::class, $result);
		$this->assertEquals($result->get_error_code(), $this->errorCode);
		$this->assertEquals($result->get_error_message(), 'The entered code is invalid. Please try again, or click the link sent in the email.');
	}

	/**
	 * Test that the `profile()` function returns the expected
	 * response when a valid signed subscriber ID is specified,
	 * and that the subscriber belongs to the expected product ID.
	 *
	 * @since   1.3.0
	 */
	public function testProfilesWithValidSignedSubscriberID()
	{
		$result = $this->api->profile($_ENV['CONVERTKIT_API_SIGNED_SUBSCRIBER_ID']);
		$this->assertNotInstanceOf(\WP_Error::class, $result);
		$this->assertIsArray($result);
		$this->assertArrayHasKey('id', $result);
		$this->assertArrayHasKey('products', $result);
		$this->assertEquals($_ENV['CONVERTKIT_API_PRODUCT_ID'], $result['products'][0]);
	}

	/**
	 * Test that the `profile()` function returns the expected
	 * response when an invalid signed subscriber ID is specified.
	 *
	 * @since   1.3.0
	 */
	public function testProfilesWithInvalidSignedSubscriberID()
	{
		$result = $this->api->profile('fakeSignedID');
		$this->assertInstanceOf(\WP_Error::class, $result);
		$this->assertEquals($result->get_error_code(), $this->errorCode);
	}

	/**
	 * Test that the `profile()` function returns the expected
	 * response when no signed subscriber ID is specified.
	 *
	 * @since   1.3.0
	 */
	public function testProfilesWithNoSignedSubscriberID()
	{
		$result = $this->api->profile('');
		$this->assertInstanceOf(\WP_Error::class, $result);
		$this->assertEquals($result->get_error_code(), $this->errorCode);
	}
	/**
	 * Test that the `recommendations_script()` function returns expected data
	 * for a ConvertKit account that has the Creator Network enabled.
	 *
	 * @since   1.3.7
	 */
	public function testRecommendationsScript()
	{
		$result = $this->api->recommendations_script();
		$this->assertNotInstanceOf(\WP_Error::class, $result);
		$this->assertIsArray($result);
		$this->assertArrayHasKey('enabled', $result);
		$this->assertArrayHasKey('embed_js', $result);
		$this->assertTrue($result['enabled']);
		$this->assertEquals($result['embed_js'], $_ENV['CONVERTKIT_API_RECOMMENDATIONS_JS']);
	}

	/**
	 * Test that the `recommendations_script()` function returns expected data
	 * for a ConvertKit account that has the Creator Network disabled.
	 *
	 * @since   1.3.7
	 */
	public function testRecommendationsScriptWhenCreatorNetworkDisabled()
	{
		$result = $this->api_no_data->recommendations_script();
		$this->assertNotInstanceOf(\WP_Error::class, $result);
		$this->assertIsArray($result);
		$this->assertArrayHasKey('enabled', $result);
		$this->assertArrayHasKey('embed_js', $result);
		$this->assertFalse($result['enabled']);
		$this->assertNull($result['embed_js']);
	}

	/**
	 * Test that the `get_form_html()` function returns expected data
	 * when a valid legacy form ID is specified.
	 *
	 * @since   1.2.2
	 */
	public function testGetLegacyFormHTML()
	{
		$result = $this->api->get_form_html(
			id: $_ENV['CONVERTKIT_API_LEGACY_FORM_ID'],
			api_key: $_ENV['CONVERTKIT_API_KEY']
		);
		$this->assertNotInstanceOf(\WP_Error::class, $result);
		$this->assertStringContainsString('<form id="ck_subscribe_form" class="ck_subscribe_form" action="https://api.kit.com/landing_pages/' . $_ENV['CONVERTKIT_API_LEGACY_FORM_ID'] . '/subscribe" data-remote="true">', $result);

		// Assert that the API class' manually added UTF-8 Content-Type has been removed prior to output.
		$this->assertStringNotContainsString('<meta http-equiv="Content-Type" content="text/html; charset=utf-8">', $result);

		// Assert that character encoding works, and that special characters are not malformed.
		$this->assertStringContainsString('Vantar þinn ungling sjálfstraust í stærðfræði?', $result);
	}

	/**
	 * Test that the `get_form_html()` function returns a WP_Error
	 * when an invalid legacy form ID is specified.
	 *
	 * @since   1.2.2
	 */
	public function testGetLegacyFormHTMLWithInvalidFormID()
	{
		$result = $this->api->get_form_html(
			id: '11111',
			api_key: $_ENV['CONVERTKIT_API_KEY']
		);
		$this->assertInstanceOf(\WP_Error::class, $result);
	}

	/**
	 * Test that the `get_landing_page_html()` function returns expected data
	 * when a valid landing page URL is specified.
	 *
	 * @since   1.2.2
	 */
	public function testGetLandingPageHTML()
	{
		$result = $this->api->get_landing_page_html($_ENV['CONVERTKIT_API_LANDING_PAGE_URL']);
		$this->assertNotInstanceOf(\WP_Error::class, $result);
		$this->assertStringContainsString('<form method="POST" action="https://app.kit.com/forms/' . $_ENV['CONVERTKIT_API_LANDING_PAGE_ID'] . '/subscriptions" data-sv-form="' . $_ENV['CONVERTKIT_API_LANDING_PAGE_ID'] . '" data-uid="99f1db6843" class="formkit-form"', $result);

		// Check that rocket-loader.min.js has been removed, as including it breaks landing page redirects.
		$this->assertStringNotContainsString('rocket-loader.min.js', $result);

		// Check that all Cloudflare / rocket-loader.min.js script types have their prepended random string removed
		// e.g. type="d4d618933d20ff16d2d8ebb4-text/javascript" --> type="text/javascript".
		$this->assertStringNotContainsString('-text/javascript"', $result);

		// Check that the <html> tag wasn't replaced, as this isn't a legacy landing page.
		// It should be preserved as e.g. <html lang="en">.
		$this->assertStringContainsString('<html lang="en">', $result);
		$this->assertStringNotContainsString('<html>', $result);
	}

	/**
	 * Test that the `get_landing_page_html()` function returns expected data
	 * when a valid landing page URL is specified whicih contains special characters.
	 *
	 * @since   1.3.3
	 */
	public function testGetLandingPageWithCharacterEncodingHTML()
	{
		$result = $this->api->get_landing_page_html($_ENV['CONVERTKIT_API_LANDING_PAGE_CHARACTER_ENCODING_URL']);
		$this->assertNotInstanceOf(\WP_Error::class, $result);
		$this->assertStringContainsString('<form method="POST" action="https://app.kit.com/forms/' . $_ENV['CONVERTKIT_API_LANDING_PAGE_CHARACTER_ENCODING_ID'] . '/subscriptions" data-sv-form="' . $_ENV['CONVERTKIT_API_LANDING_PAGE_CHARACTER_ENCODING_ID'] . '" data-uid="cc5eb21744" class="formkit-form"', $result);

		// Assert that character encoding works, and that special characters are not malformed.
		$this->assertStringContainsString('Vantar þinn ungling sjálfstraust í stærðfræði?', $result);

		// Check that rocket-loader.min.js has been removed, as including it breaks landing page redirects.
		$this->assertStringNotContainsString('rocket-loader.min.js', $result);

		// Check that all Cloudflare / rocket-loader.min.js script types have their prepended random string removed
		// e.g. type="d4d618933d20ff16d2d8ebb4-text/javascript" --> type="text/javascript".
		$this->assertStringNotContainsString('-text/javascript"', $result);
	}

	/**
	 * Test that the `get_landing_page_html()` function returns expected data
	 * when a valid legacy landing page URL is specified.
	 *
	 * @since   1.2.2
	 */
	public function testGetLegacyLandingPageHTML()
	{
		$result = $this->api->get_landing_page_html($_ENV['CONVERTKIT_API_LEGACY_LANDING_PAGE_URL']);
		$this->assertNotInstanceOf(\WP_Error::class, $result);

		$this->assertStringContainsString('<form id="ck_subscribe_form" class="ck_subscribe_form" action="https://app.kit.com/landing_pages/' . $_ENV['CONVERTKIT_API_LEGACY_LANDING_PAGE_ID'] . '/subscribe" data-remote="true">', $result);

		// Check that rocket-loader.min.js has been removed, as including it breaks landing page redirects.
		$this->assertStringNotContainsString('rocket-loader.min.js', $result);

		// Check that all Cloudflare / rocket-loader.min.js script types have their prepended random string removed
		// e.g. type="d4d618933d20ff16d2d8ebb4-text/javascript" --> type="text/javascript".
		$this->assertStringNotContainsString('-text/javascript"', $result);

		// Check that the <html> tag was added, as this isn't included in legacy landing pages.
		$this->assertStringContainsString('<html>', $result);
	}

	/**
	 * Test that the `get_landing_page_html()` function returns a WP_Error
	 * when an invalid landing page URL is specified.
	 *
	 * @since   1.2.2
	 */
	public function testGetLandingPageHTMLWithInvalidLandingPageURL()
	{
		$result = $this->api->get_landing_page_html('http://fake-url');
		$this->assertInstanceOf(\WP_Error::class, $result);
	}

	/**
	 * Forces WordPress' wp_remote_*() functions to return a specific HTTP response code
	 * and message by short circuiting using the `pre_http_request` filter.
	 *
	 * This emulates server responses that the API class has to handle from ConvertKit's API,
	 * which we cannot easily recreate e.g. 500 or 502 errors.
	 *
	 * @since   1.0.0
	 *
	 * @param   int         $httpCode       HTTP Code.
	 * @param   string      $httpMessage    HTTP Message.
	 * @param   null|string $body           Response body.
	 */
	private function mockResponses( $httpCode, $httpMessage, $body = null )
	{
		add_filter(
			'pre_http_request',
			function( $response ) use ( $httpCode, $httpMessage, $body ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
				return array(
					'headers'       => array(),
					'body'          => $body,
					'response'      => array(
						'code'    => $httpCode,
						'message' => $httpMessage,
					),
					'cookies'       => array(),
					'http_response' => null,
				);
			}
		);
	}

	/**
	 * Mocks an API response as if the Access Token expired.
	 *
	 * @since   2.0.2
	 *
	 * @param   mixed  $response       HTTP Response.
	 * @param   array  $parsed_args    Request arguments.
	 * @param   string $url            Request URL.
	 * @return  mixed
	 */
	public function mockAccessTokenExpiredResponse( $response, $parsed_args, $url )
	{
		// Only mock requests made to the /account endpoint.
		if ( strpos( $url, 'https://api.kit.com/v4/account' ) === false ) {
			return $response;
		}

		// Remove this filter, so we don't end up in a loop when retrying the request.
		remove_filter( 'pre_http_request', array( $this, 'mockAccessTokenExpiredResponse' ) );

		// Return a 401 unauthorized response with the errors body as if the API
		// returned "The access token expired".
		return array(
			'headers'       => array(),
			'body'          => wp_json_encode(
				array(
					'errors' => array(
						'The access token expired',
					),
				)
			),
			'response'      => array(
				'code'    => 401,
				'message' => 'The access token expired',
			),
			'cookies'       => array(),
			'http_response' => null,
		);
	}

	/**
	 * Mocks an API response as if a refresh token was used to fetch new tokens.
	 *
	 * @since   2.0.2
	 *
	 * @param   mixed  $response       HTTP Response.
	 * @param   array  $parsed_args    Request arguments.
	 * @param   string $url            Request URL.
	 * @return  mixed
	 */
	public function mockRefreshTokenResponse( $response, $parsed_args, $url )
	{
		// Only mock requests made to the /token endpoint.
		if ( strpos( $url, 'https://api.kit.com/oauth/token' ) === false ) {
			return $response;
		}

		// Remove this filter, so we don't end up in a loop when retrying the request.
		remove_filter( 'pre_http_request', array( $this, 'mockRefreshTokenResponse' ) );

		// Return a mock access and refresh token for this API request, as calling
		// refresh_token results in a new access and refresh token being provided,
		// which would result in other tests breaking due to changed tokens.
		return array(
			'headers'       => array(),
			'body'          => wp_json_encode(
				array(
					'access_token'  => 'new-' . $_ENV['CONVERTKIT_OAUTH_ACCESS_TOKEN'],
					'refresh_token' => 'new-' . $_ENV['CONVERTKIT_OAUTH_REFRESH_TOKEN'],
					'token_type'    => 'bearer',
					'created_at'    => strtotime( 'now' ),
					'expires_in'    => 10000,
					'scope'         => 'public',
				)
			),
			'response'      => array(
				'code'    => 200,
				'message' => 'OK',
			),
			'cookies'       => array(),
			'http_response' => null,
		);
	}
}
