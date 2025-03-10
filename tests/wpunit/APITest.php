<?php
/**
 * Tests for the ConvertKit_API class.
 *
 * @since   1.0.0
 */
class APITest extends \Codeception\TestCase\WPTestCase
{
	/**
	 * The testing implementation.
	 *
	 * @var \WpunitTester.
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
		$this->api = new ConvertKit_API_V4(
			$_ENV['CONVERTKIT_OAUTH_CLIENT_ID'],
			$_ENV['CONVERTKIT_OAUTH_REDIRECT_URI'],
			$_ENV['CONVERTKIT_OAUTH_ACCESS_TOKEN'],
			$_ENV['CONVERTKIT_OAUTH_REFRESH_TOKEN']
		);

		$this->api_no_data = new ConvertKit_API_V4(
			$_ENV['CONVERTKIT_OAUTH_CLIENT_ID'],
			$_ENV['CONVERTKIT_OAUTH_REDIRECT_URI'],
			$_ENV['CONVERTKIT_OAUTH_ACCESS_TOKEN_NO_DATA'],
			$_ENV['CONVERTKIT_OAUTH_REFRESH_TOKEN_NO_DATA']
		);

		// Wait a second to avoid hitting a 429 rate limit.
		sleep(1);
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
	 * Test that a log directory and file are created in the expected location, with .htaccess
	 * and index.html protection, and that the name and email addresses are masked.
	 *
	 * @since   1.4.2
	 */
	public function testLog()
	{
		// Define location for log file.
		define( 'CONVERTKIT_PLUGIN_PATH', $_ENV['WP_ROOT_FOLDER'] . '/wp-content/uploads' );

		// Create a log.txt file.
		$this->tester->writeToFile(CONVERTKIT_PLUGIN_PATH . '/log.txt', 'historical log file');

		// Initialize API with logging enabled.
		$api = new ConvertKit_API_V4(
			$_ENV['CONVERTKIT_OAUTH_CLIENT_ID'],
			$_ENV['CONVERTKIT_OAUTH_REDIRECT_URI'],
			$_ENV['CONVERTKIT_OAUTH_ACCESS_TOKEN'],
			$_ENV['CONVERTKIT_OAUTH_REFRESH_TOKEN'],
			true
		);

		// Perform actions that will write sensitive data to the log file.
		$api->form_subscribe(
			$_ENV['CONVERTKIT_API_FORM_ID'],
			$_ENV['CONVERTKIT_API_SUBSCRIBER_EMAIL'],
			'First Name',
			array(
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
		$api    = new ConvertKit_API_V4(
			$_ENV['CONVERTKIT_OAUTH_CLIENT_ID'],
			$_ENV['CONVERTKIT_OAUTH_REDIRECT_URI'],
			'not-a-real-access-token',
			'not-a-real-refresh-token'
		);
		$result = $api->get_account();
		$this->assertInstanceOf(WP_Error::class, $result);
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
			429,
			'Rate limit hit'
		);
		$result = $this->api->get_account(); // The API function we use doesn't matter, as mockResponse forces a 429 error.
		$this->assertInstanceOf(WP_Error::class, $result);
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
		$this->mockResponses( 500, 'Internal server error.' );
		$result = $this->api->get_account(); // The API function we use doesn't matter, as mockResponse forces a 500 error.
		$this->assertInstanceOf(WP_Error::class, $result);
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
		$this->mockResponses( 502, 'Bad gateway.' );
		$result = $this->api->get_account(); // The API function we use doesn't matter, as mockResponse forces a 502 error.
		$this->assertInstanceOf(WP_Error::class, $result);
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
		$api    = new ConvertKit_API_V4(
			$_ENV['CONVERTKIT_OAUTH_CLIENT_ID'],
			$_ENV['CONVERTKIT_OAUTH_REDIRECT_URI'],
			$_ENV['CONVERTKIT_OAUTH_ACCESS_TOKEN'],
			$_ENV['CONVERTKIT_OAUTH_REFRESH_TOKEN'],
			false,
			'TestContext'
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
		$this->mockResponses( 200, 'OK', wp_json_encode( $params ) );

		// Send request.
		$result = $this->api->get_access_token( 'auth-code' );

		// Inspect response.
		$this->assertNotInstanceOf(WP_Error::class, $result);
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
		$this->assertInstanceOf(WP_Error::class, $result);
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
			200,
			'OK',
			wp_json_encode(
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
		$this->assertNotInstanceOf(WP_Error::class, $result);
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
		$api = new ConvertKit_API_V4(
			$_ENV['CONVERTKIT_OAUTH_CLIENT_ID'],
			$_ENV['CONVERTKIT_OAUTH_REDIRECT_URI'],
			$_ENV['CONVERTKIT_OAUTH_ACCESS_TOKEN'],
			'not-a-real-refresh-token'
		);

		$result = $api->refresh_token();
		$this->assertInstanceOf(WP_Error::class, $result);
		$this->assertEquals($result->get_error_code(), 'convertkit_api_error');
	}

	/**
	 * Test that making a call with an expired access token results in refresh_token()
	 * not being automatically called, when the WordPress site isn't a production site.
	 *
	 * @since   2.0.2
	 *
	 * @return void
	 */
	public function testRefreshTokenWhenAccessTokenExpiredErrorOnNonProductionSite()
	{
		// If the refresh token action in the libraries is triggered when calling get_account(), the test failed.
		add_action(
			'convertkit_api_refresh_token',
			function() {
				$this->fail('`convertkit_api_refresh_token` was triggered when calling `get_account` with an expired access token on a non-production site.');
			}
		);

		// Filter requests to mock the token expiry and refreshing the token.
		add_filter( 'pre_http_request', array( $this, 'mockAccessTokenExpiredResponse' ), 10, 3 );
		add_filter( 'pre_http_request', array( $this, 'mockRefreshTokenResponse' ), 10, 3 );

		// Run request, which will trigger the above filters as if the token expired and refreshes automatically.
		$result = $this->api->get_account();
	}

	/**
	 * Test that supplying no API credentials to the API class returns a WP_Error.
	 *
	 * @since   2.0.2
	 */
	public function testNoAPICredentials()
	{
		$api    = new ConvertKit_API_V4( $_ENV['CONVERTKIT_OAUTH_CLIENT_ID'], $_ENV['CONVERTKIT_OAUTH_REDIRECT_URI'] );
		$result = $api->get_account();
		$this->assertInstanceOf(WP_Error::class, $result);
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
		$api    = new ConvertKit_API_V4( $_ENV['CONVERTKIT_OAUTH_CLIENT_ID'], $_ENV['CONVERTKIT_OAUTH_REDIRECT_URI'], 'fakeAccessToken', 'fakeRefreshToken' );
		$result = $api->get_account();
		$this->assertInstanceOf(WP_Error::class, $result);
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
		$api    = new ConvertKit_API_V4( $_ENV['CONVERTKIT_OAUTH_CLIENT_ID'], $_ENV['CONVERTKIT_OAUTH_REDIRECT_URI'] );
		$result = $api->get_access_token_by_api_key_and_secret(
			$_ENV['CONVERTKIT_API_KEY'],
			$_ENV['CONVERTKIT_API_SECRET']
		);
		$this->assertNotInstanceOf(WP_Error::class, $result);
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
		$api    = new ConvertKit_API_V4( $_ENV['CONVERTKIT_OAUTH_CLIENT_ID'], $_ENV['CONVERTKIT_OAUTH_REDIRECT_URI'] );
		$result = $api->get_access_token_by_api_key_and_secret(
			'invalid-api-key',
			'invalid-api-secret'
		);
		$this->assertInstanceOf(WP_Error::class, $result);
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
		$api    = new ConvertKit_API_V4( 'invalidClientID', $_ENV['CONVERTKIT_OAUTH_REDIRECT_URI'] );
		$result = $api->get_access_token_by_api_key_and_secret(
			$_ENV['CONVERTKIT_API_KEY'],
			$_ENV['CONVERTKIT_API_SECRET']
		);
		$this->assertInstanceOf(WP_Error::class, $result);
		$this->assertEquals($result->get_error_code(), $this->errorCode);
	}

	/**
	 * Test that supplying valid API credentials to the API class returns the expected account information.
	 *
	 * @since   1.0.0
	 */
	public function testGetAccount()
	{
		$result = $this->api->get_account();
		$this->assertNotInstanceOf(WP_Error::class, $result);
		$this->assertIsArray($result);

		$this->assertArrayHasKey('user', $result);
		$this->assertArrayHasKey('account', $result);

		$this->assertArrayHasKey('name', $result['account']);
		$this->assertArrayHasKey('plan_type', $result['account']);
		$this->assertArrayHasKey('primary_email_address', $result['account']);
		$this->assertEquals('wordpress@convertkit.com', $result['account']['primary_email_address']);
	}

	/**
	 * Test that get_account_colors() returns the expected data.
	 *
	 * @since   2.0.0
	 *
	 * @return void
	 */
	public function testGetAccountColors()
	{
		$result = $this->api->get_account_colors();
		$this->assertNotInstanceOf(WP_Error::class, $result);
		$this->assertIsArray($result);

		$this->assertArrayHasKey('colors', $result);
		$this->assertIsArray($result['colors']);
	}

	/**
	 * Test that update_account_colors() updates the account's colors.
	 *
	 * @since   2.0.0
	 *
	 * @return void
	 */
	public function testUpdateAccountColors()
	{
		$result = $this->api->update_account_colors(
			[
				'#111111',
			]
		);
		$this->assertNotInstanceOf(WP_Error::class, $result);
		$this->assertIsArray($result);

		$this->assertArrayHasKey('colors', $result);
		$this->assertIsArray($result['colors']);
		$this->assertEquals($result['colors'][0], '#111111');
	}

	/**
	 * Test that get_creator_profile() returns the expected data.
	 *
	 * @since   2.0.0
	 *
	 * @return void
	 */
	public function testGetCreatorProfile()
	{
		$result = $this->api->get_creator_profile();
		$this->assertNotInstanceOf(WP_Error::class, $result);
		$this->assertIsArray($result);

		$this->assertArrayHasKey('name', $result['profile']);
		$this->assertArrayHasKey('byline', $result['profile']);
		$this->assertArrayHasKey('bio', $result['profile']);
		$this->assertArrayHasKey('image_url', $result['profile']);
		$this->assertArrayHasKey('profile_url', $result['profile']);
	}

	/**
	 * Test that get_email_stats() returns the expected data.
	 *
	 * @since   2.0.0
	 *
	 * @return void
	 */
	public function testGetEmailStats()
	{
		$result = $this->api->get_email_stats();
		$this->assertNotInstanceOf(WP_Error::class, $result);
		$this->assertIsArray($result);

		$this->assertArrayHasKey('sent', $result['stats']);
		$this->assertArrayHasKey('clicked', $result['stats']);
		$this->assertArrayHasKey('opened', $result['stats']);
		$this->assertArrayHasKey('email_stats_mode', $result['stats']);
		$this->assertArrayHasKey('open_tracking_enabled', $result['stats']);
		$this->assertArrayHasKey('click_tracking_enabled', $result['stats']);
		$this->assertArrayHasKey('starting', $result['stats']);
		$this->assertArrayHasKey('ending', $result['stats']);
	}

	/**
	 * Test that get_growth_stats() returns the expected data.
	 *
	 * @since   2.0.0
	 *
	 * @return void
	 */
	public function testGetGrowthStats()
	{
		$result = $this->api->get_growth_stats();
		$this->assertNotInstanceOf(WP_Error::class, $result);
		$this->assertIsArray($result);

		$this->assertArrayHasKey('cancellations', $result['stats']);
		$this->assertArrayHasKey('net_new_subscribers', $result['stats']);
		$this->assertArrayHasKey('new_subscribers', $result['stats']);
		$this->assertArrayHasKey('subscribers', $result['stats']);
		$this->assertArrayHasKey('starting', $result['stats']);
		$this->assertArrayHasKey('ending', $result['stats']);
	}

	/**
	 * Test that get_growth_stats() returns the expected data
	 * when a start date is specified.
	 *
	 * @since   2.0.0
	 *
	 * @return void
	 */
	public function testGetGrowthStatsWithStartDate()
	{
		// Define start and end dates.
		$starting = new DateTime('now');
		$starting->modify('-7 days');
		$ending = new DateTime('now');

		// Send request.
		$result = $this->api->get_growth_stats($starting);
		$this->assertNotInstanceOf(WP_Error::class, $result);
		$this->assertIsArray($result);

		// Confirm response object contains expected keys.
		$this->assertArrayHasKey('cancellations', $result['stats']);
		$this->assertArrayHasKey('net_new_subscribers', $result['stats']);
		$this->assertArrayHasKey('new_subscribers', $result['stats']);
		$this->assertArrayHasKey('subscribers', $result['stats']);
		$this->assertArrayHasKey('starting', $result['stats']);
		$this->assertArrayHasKey('ending', $result['stats']);

		// Assert start and end dates were honored.
		$timezone = ( new DateTime() )->setTimezone(new DateTimeZone('America/New_York'))->format('P'); // Gets timezone offset for New York (-04:00 during DST, -05:00 otherwise).
		$this->assertEquals($result['stats']['starting'], $starting->format('Y-m-d') . 'T00:00:00' . $timezone);
		$this->assertEquals($result['stats']['ending'], $ending->format('Y-m-d') . 'T23:59:59' . $timezone);
	}

	/**
	 * Test that get_growth_stats() returns the expected data
	 * when an end date is specified.
	 *
	 * @since   2.0.0
	 *
	 * @return void
	 */
	public function testGetGrowthStatsWithEndDate()
	{
		// Define start and end dates.
		$starting = new DateTime('now');
		$starting->modify('-90 days');
		$ending = new DateTime('now');
		$ending->modify('-7 days');

		// Send request.
		$result = $this->api->get_growth_stats(null, $ending);
		$this->assertNotInstanceOf(WP_Error::class, $result);
		$this->assertIsArray($result);

		// Confirm response object contains expected keys.
		$this->assertArrayHasKey('cancellations', $result['stats']);
		$this->assertArrayHasKey('net_new_subscribers', $result['stats']);
		$this->assertArrayHasKey('new_subscribers', $result['stats']);
		$this->assertArrayHasKey('subscribers', $result['stats']);
		$this->assertArrayHasKey('starting', $result['stats']);
		$this->assertArrayHasKey('ending', $result['stats']);

		// Assert start and end dates were honored.
		$timezone = ( new DateTime() )->setTimezone(new DateTimeZone('America/New_York'))->format('P'); // Gets timezone offset for New York (-04:00 during DST, -05:00 otherwise).
		$this->assertEquals($result['stats']['starting'], $starting->format('Y-m-d') . 'T00:00:00' . $timezone);
		$this->assertEquals($result['stats']['ending'], $ending->format('Y-m-d') . 'T23:59:59' . $timezone);
	}

	/**
	 * Test that get_forms() returns the expected data.
	 *
	 * @since   1.0.0
	 *
	 * @return void
	 */
	public function testGetForms()
	{
		$result = $this->api->get_forms();

		// Assert forms and pagination exist.
		$this->assertDataExists($result, 'forms');
		$this->assertPaginationExists($result);

		// Iterate through each form, confirming no landing pages were included.
		foreach ($result['forms'] as $form) {
			// Assert shape of object is valid.
			$this->assertArrayHasKey('id', $form);
			$this->assertArrayHasKey('name', $form);
			$this->assertArrayHasKey('created_at', $form);
			$this->assertArrayHasKey('type', $form);
			$this->assertArrayHasKey('format', $form);
			$this->assertArrayHasKey('embed_js', $form);
			$this->assertArrayHasKey('embed_url', $form);
			$this->assertArrayHasKey('archived', $form);

			// Assert form is not a landing page i.e embed.
			$this->assertEquals($form['type'], 'embed');

			// Assert form is not archived.
			$this->assertFalse($form['archived']);
		}
	}

	/**
	 * Test that get_forms() returns the expected data when
	 * the status is set to archived.
	 *
	 * @since   2.0.0
	 *
	 * @return void
	 */
	public function testGetFormsWithArchivedStatus()
	{
		$result = $this->api->get_forms('archived');

		// Assert forms and pagination exist.
		$this->assertDataExists($result, 'forms');
		$this->assertPaginationExists($result);

		// Iterate through each form, confirming no landing pages were included.
		foreach ($result['forms'] as $form) {
			// Assert shape of object is valid.
			$this->assertArrayHasKey('id', $form);
			$this->assertArrayHasKey('name', $form);
			$this->assertArrayHasKey('created_at', $form);
			$this->assertArrayHasKey('type', $form);
			$this->assertArrayHasKey('format', $form);
			$this->assertArrayHasKey('embed_js', $form);
			$this->assertArrayHasKey('embed_url', $form);
			$this->assertArrayHasKey('archived', $form);

			// Assert form is not a landing page i.e embed.
			$this->assertEquals($form['type'], 'embed');

			// Assert form is archived.
			$this->assertTrue($form['archived']);
		}
	}

	/**
	 * Test that get_forms() returns the expected data
	 * when the total count is included.
	 *
	 * @since   2.0.0
	 *
	 * @return void
	 */
	public function testGetFormsWithTotalCount()
	{
		$result = $this->api->get_forms('active', true);

		// Assert forms and pagination exist.
		$this->assertDataExists($result, 'forms');
		$this->assertPaginationExists($result);

		// Assert total count is included.
		$this->assertArrayHasKey('total_count', $result['pagination']);
		$this->assertGreaterThan(0, $result['pagination']['total_count']);
	}

	/**
	 * Test that get_forms() returns the expected data when pagination parameters
	 * and per_page limits are specified.
	 *
	 * @since   2.0.0
	 *
	 * @return void
	 */
	public function testGetFormsPagination()
	{
		// Return one form.
		$result = $this->api->get_forms('active', false, '', '', 1);

		// Assert forms and pagination exist.
		$this->assertDataExists($result, 'forms');
		$this->assertPaginationExists($result);

		// Assert a single form was returned.
		$this->assertCount(1, $result['forms']);

		// Assert has_previous_page and has_next_page are correct.
		$this->assertFalse($result['pagination']['has_previous_page']);
		$this->assertTrue($result['pagination']['has_next_page']);

		// Use pagination to fetch next page.
		$result = $this->api->get_forms('active', false, $result['pagination']['end_cursor'], '', 1);

		// Assert forms and pagination exist.
		$this->assertDataExists($result, 'forms');
		$this->assertPaginationExists($result);

		// Assert a single form was returned.
		$this->assertCount(1, $result['forms']);

		// Assert has_previous_page and has_next_page are correct.
		$this->assertTrue($result['pagination']['has_previous_page']);
		$this->assertTrue($result['pagination']['has_next_page']);

		// Use pagination to fetch previous page.
		$result = $this->api->get_forms('active', false, '', $result['pagination']['start_cursor'], 1);

		// Assert forms and pagination exist.
		$this->assertDataExists($result, 'forms');
		$this->assertPaginationExists($result);

		// Assert a single form was returned.
		$this->assertCount(1, $result['forms']);

		// Assert has_previous_page and has_next_page are correct.
		$this->assertFalse($result['pagination']['has_previous_page']);
		$this->assertTrue($result['pagination']['has_next_page']);
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
		$result = $this->api->get_legacy_forms(true);

		// Assert forms and pagination exist.
		$this->assertDataExists($result, 'legacy_landing_pages');
		$this->assertPaginationExists($result);

		// Assert total count is included.
		$this->assertArrayHasKey('total_count', $result['pagination']);
		$this->assertGreaterThan(0, $result['pagination']['total_count']);
	}

	/**
	 * Test that get_landing_pages() returns the expected data.
	 *
	 * @since   1.0.0
	 *
	 * @return void
	 */
	public function testGetLandingPages()
	{
		$result = $this->api->get_landing_pages();

		// Assert forms and pagination exist.
		$this->assertDataExists($result, 'forms');
		$this->assertPaginationExists($result);

		// Iterate through each landing page, confirming no forms were included.
		foreach ($result['forms'] as $form) {
			// Assert shape of object is valid.
			$this->assertArrayHasKey('id', $form);
			$this->assertArrayHasKey('name', $form);
			$this->assertArrayHasKey('created_at', $form);
			$this->assertArrayHasKey('type', $form);
			$this->assertArrayHasKey('format', $form);
			$this->assertArrayHasKey('embed_js', $form);
			$this->assertArrayHasKey('embed_url', $form);
			$this->assertArrayHasKey('archived', $form);

			// Assert form is a landing page i.e. hosted.
			$this->assertEquals($form['type'], 'hosted');

			// Assert form is not archived.
			$this->assertFalse($form['archived']);
		}
	}

	/**
	 * Test that get_landing_pages() returns the expected data when
	 * the status is set to archived.
	 *
	 * @since   2.0.0
	 *
	 * @return void
	 */
	public function testGetLandingPagesWithArchivedStatus()
	{
		$result = $this->api->get_forms('archived');

		// Assert forms and pagination exist.
		$this->assertDataExists($result, 'forms');
		$this->assertPaginationExists($result);

		// Assert no landing pages are returned, as the account doesn't have any archived landing pages.
		$this->assertCount(0, $result['forms']);
	}

	/**
	 * Test that get_landing_pages() returns the expected data
	 * when the total count is included.
	 *
	 * @since   2.0.0
	 *
	 * @return void
	 */
	public function testGetLandingPagesWithTotalCount()
	{
		$result = $this->api->get_landing_pages('active', true);

		// Assert forms and pagination exist.
		$this->assertDataExists($result, 'forms');
		$this->assertPaginationExists($result);

		// Assert total count is included.
		$this->assertArrayHasKey('total_count', $result['pagination']);
		$this->assertGreaterThan(0, $result['pagination']['total_count']);
	}

	/**
	 * Test that get_landing_pages() returns the expected data when pagination parameters
	 * and per_page limits are specified.
	 *
	 * @since   2.0.0
	 *
	 * @return void
	 */
	public function testGetLandingPagesPagination()
	{
		// Return one landing page.
		$result = $this->api->get_landing_pages('active', false, '', '', 1);

		// Assert landing pages and pagination exist.
		$this->assertDataExists($result, 'forms');
		$this->assertPaginationExists($result);

		// Assert a single landing page was returned.
		$this->assertCount(1, $result['forms']);

		// Assert has_previous_page and has_next_page are correct.
		$this->assertFalse($result['pagination']['has_previous_page']);
		$this->assertTrue($result['pagination']['has_next_page']);

		// Use pagination to fetch next page.
		$result = $this->api->get_landing_pages('active', false, $result['pagination']['end_cursor'], '', 1);

		// Assert landing pages and pagination exist.
		$this->assertDataExists($result, 'forms');
		$this->assertPaginationExists($result);

		// Assert a single landing page was returned.
		$this->assertCount(1, $result['forms']);

		// Assert has_previous_page and has_next_page are correct.
		$this->assertTrue($result['pagination']['has_previous_page']);
		$this->assertFalse($result['pagination']['has_next_page']);

		// Use pagination to fetch previous page.
		$result = $this->api->get_landing_pages('active', false, '', $result['pagination']['start_cursor'], 1);

		// Assert landing pages and pagination exist.
		$this->assertDataExists($result, 'forms');
		$this->assertPaginationExists($result);

		// Assert a single landing page was returned.
		$this->assertCount(1, $result['forms']);

		// Assert has_previous_page and has_next_page are correct.
		$this->assertFalse($result['pagination']['has_previous_page']);
		$this->assertTrue($result['pagination']['has_next_page']);
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
		$result = $this->api->get_legacy_landing_pages(true);

		// Assert forms and pagination exist.
		$this->assertDataExists($result, 'legacy_landing_pages');
		$this->assertPaginationExists($result);

		// Assert total count is included.
		$this->assertArrayHasKey('total_count', $result['pagination']);
		$this->assertGreaterThan(0, $result['pagination']['total_count']);
	}

	/**
	 * Test that get_form_subscriptions() returns the expected data
	 * when a valid Form ID is specified.
	 *
	 * @since   1.0.0
	 *
	 * @return void
	 */
	public function testGetFormSubscriptions()
	{
		$result = $this->api->get_form_subscriptions( (int) $_ENV['CONVERTKIT_API_FORM_ID']);

		// Assert subscribers and pagination exist.
		$this->assertDataExists($result, 'subscribers');
		$this->assertPaginationExists($result);
	}

	/**
	 * Test that get_form_subscriptions() returns the expected data
	 * when the total count is included.
	 *
	 * @since   2.0.0
	 *
	 * @return void
	 */
	public function testGetFormSubscriptionsWithTotalCount()
	{
		$result = $this->api->get_form_subscriptions(
			(int) $_ENV['CONVERTKIT_API_FORM_ID'], // Form ID.
			'active', // Subscriber state.
			null, // Created after.
			null, // Created before.
			null, // Added after.
			null, // Added before.
			true // Include total count.
		);

		// Assert subscribers and pagination exist.
		$this->assertDataExists($result, 'subscribers');
		$this->assertPaginationExists($result);

		// Assert total count is included.
		$this->assertArrayHasKey('total_count', $result['pagination']);
		$this->assertGreaterThan(0, $result['pagination']['total_count']);
	}

	/**
	 * Test that get_form_subscriptions() returns the expected data
	 * when a valid Form ID is specified and the subscription status
	 * is cancelled.
	 *
	 * @since   1.0.0
	 *
	 * @return void
	 */
	public function testGetFormSubscriptionsWithBouncedSubscriberState()
	{
		$result = $this->api->get_form_subscriptions(
			(int) $_ENV['CONVERTKIT_API_FORM_ID'], // Form ID.
			'bounced' // Subscriber state.
		);

		// Assert subscribers and pagination exist.
		$this->assertDataExists($result, 'subscribers');
		$this->assertPaginationExists($result);

		// Check the correct subscribers were returned.
		$this->assertEquals($result['subscribers'][0]['state'], 'bounced');
	}

	/**
	 * Test that get_form_subscriptions() returns the expected data
	 * when a valid Form ID is specified and the added_after parameter
	 * is used.
	 *
	 * @since   2.0.0
	 *
	 * @return void
	 */
	public function testGetFormSubscriptionsWithAddedAfterParam()
	{
		$date   = new \DateTime('2022-01-01');
		$result = $this->api->get_form_subscriptions(
			(int) $_ENV['CONVERTKIT_API_FORM_ID'], // Form ID.
			'active', // Subscriber state.
			null, // Created after.
			null, // Created before.
			$date // Added after.
		);

		// Assert subscribers and pagination exist.
		$this->assertDataExists($result, 'subscribers');
		$this->assertPaginationExists($result);

		// Check the correct subscribers were returned.
		$this->assertGreaterThanOrEqual(
			$date->format('Y-m-d'),
			date('Y-m-d', strtotime($result['subscribers'][0]['added_at']))
		);
	}

	/**
	 * Test that get_form_subscriptions() returns the expected data
	 * when a valid Form ID is specified and the added_before parameter
	 * is used.
	 *
	 * @since   2.0.0
	 *
	 * @return void
	 */
	public function testGetFormSubscriptionsWithAddedBeforeParam()
	{
		$date   = new \DateTime('2024-01-01');
		$result = $this->api->get_form_subscriptions(
			(int) $_ENV['CONVERTKIT_API_FORM_ID'], // Form ID.
			'active', // Subscriber state.
			null, // Created after.
			null, // Created before.
			null, // Added after.
			$date, // Added before.
		);

		// Assert subscribers and pagination exist.
		$this->assertDataExists($result, 'subscribers');
		$this->assertPaginationExists($result);

		// Check the correct subscribers were returned.
		$this->assertLessThanOrEqual(
			$date->format('Y-m-d'),
			date('Y-m-d', strtotime($result['subscribers'][0]['added_at']))
		);
	}

	/**
	 * Test that get_form_subscriptions() returns the expected data
	 * when a valid Form ID is specified and the created_after parameter
	 * is used.
	 *
	 * @since   2.0.0
	 *
	 * @return void
	 */
	public function testGetFormSubscriptionsWithCreatedAfterParam()
	{
		$date   = new \DateTime('2022-01-01');
		$result = $this->api->get_form_subscriptions(
			(int) $_ENV['CONVERTKIT_API_FORM_ID'], // Form ID.
			'active', // Subscriber state.
			$date // Created after.
		);

		// Assert subscribers and pagination exist.
		$this->assertDataExists($result, 'subscribers');
		$this->assertPaginationExists($result);

		// Check the correct subscribers were returned.
		$this->assertGreaterThanOrEqual(
			$date->format('Y-m-d'),
			date('Y-m-d', strtotime($result['subscribers'][0]['created_at']))
		);
	}

	/**
	 * Test that get_form_subscriptions() returns the expected data
	 * when a valid Form ID is specified and the created_before parameter
	 * is used.
	 *
	 * @since   2.0.0
	 *
	 * @return void
	 */
	public function testGetFormSubscriptionsWithCreatedBeforeParam()
	{
		$date   = new \DateTime('2024-01-01');
		$result = $this->api->get_form_subscriptions(
			(int) $_ENV['CONVERTKIT_API_FORM_ID'], // Form ID.
			'active', // Subscriber state.
			null, // Created after.
			$date // Created before.
		);

		// Assert subscribers and pagination exist.
		$this->assertDataExists($result, 'subscribers');
		$this->assertPaginationExists($result);

		// Check the correct subscribers were returned.
		$this->assertLessThanOrEqual(
			$date->format('Y-m-d'),
			date('Y-m-d', strtotime($result['subscribers'][0]['created_at']))
		);
	}

	/**
	 * Test that get_form_subscriptions() returns the expected data
	 * when a valid Form ID is specified and pagination parameters
	 * and per_page limits are specified.
	 *
	 * @since   1.0.0
	 *
	 * @return void
	 */
	public function testGetFormSubscriptionsPagination()
	{
		$result = $this->api->get_form_subscriptions(
			(int) $_ENV['CONVERTKIT_API_FORM_ID'], // Form ID.
			'active', // Subscriber state.
			null, // Created after.
			null, // Created before.
			null, // Added after.
			null, // Added before.
			false, // Include total count.
			'', // After cursor.
			'', // Before cursor.
			1, // Per page.
		);

		// Assert subscribers and pagination exist.
		$this->assertDataExists($result, 'subscribers');
		$this->assertPaginationExists($result);

		// Assert a single subscriber was returned.
		$this->assertCount(1, $result['subscribers']);

		// Assert has_previous_page and has_next_page are correct.
		$this->assertFalse($result['pagination']['has_previous_page']);
		$this->assertTrue($result['pagination']['has_next_page']);

		// Use pagination to fetch next page.
		$result = $this->api->get_form_subscriptions(
			(int) $_ENV['CONVERTKIT_API_FORM_ID'], // Form ID.
			'active', // Subscriber state.
			null, // Created after.
			null, // Created before.
			null, // Added after.
			null, // Added before.
			false, // Include total count.
			$result['pagination']['end_cursor'], // After cursor.
			'', // Before cursor.
			1, // Per page.
		);

		// Assert subscribers and pagination exist.
		$this->assertDataExists($result, 'subscribers');
		$this->assertPaginationExists($result);

		// Assert a single subscriber was returned.
		$this->assertCount(1, $result['subscribers']);

		// Assert has_previous_page and has_next_page are correct.
		$this->assertTrue($result['pagination']['has_previous_page']);
		$this->assertTrue($result['pagination']['has_next_page']);

		// Use pagination to fetch previous page.
		$result = $this->api->get_form_subscriptions(
			(int) $_ENV['CONVERTKIT_API_FORM_ID'], // Form ID.
			'active', // Subscriber state.
			null, // Created after.
			null, // Created before.
			null, // Added after.
			null, // Added before.
			false, // Include total count.
			'', // After cursor.
			$result['pagination']['start_cursor'], // Before cursor.
			1, // Per page.
		);

		// Assert subscribers and pagination exist.
		$this->assertDataExists($result, 'subscribers');
		$this->assertPaginationExists($result);
	}

	/**
	 * Test that get_form_subscriptions() throws a ClientException when an invalid
	 * Form ID is specified.
	 *
	 * @since   1.0.0
	 *
	 * @return void
	 */
	public function testGetFormSubscriptionsWithInvalidFormID()
	{
		$result = $this->api->get_form_subscriptions(12345);
		$this->assertInstanceOf(WP_Error::class, $result);
		$this->assertEquals($result->get_error_code(), $this->errorCode);
	}

	/**
	 * Test that get_form_subscriptions() throws a ClientException when an invalid
	 * subscriber state is specified.
	 *
	 * @since   2.0.0
	 *
	 * @return void
	 */
	public function testGetFormSubscriptionsWithInvalidSubscriberState()
	{
		$result = $this->api->get_form_subscriptions(
			(int) $_ENV['CONVERTKIT_API_FORM_ID'],
			'not-a-valid-state'
		);
		$this->assertInstanceOf(WP_Error::class, $result);
		$this->assertEquals($result->get_error_code(), $this->errorCode);
	}

	/**
	 * Test that get_form_subscriptions() throws a ClientException when invalid
	 * pagination parameters are specified.
	 *
	 * @since   2.0.0
	 *
	 * @return void
	 */
	public function testGetFormSubscriptionsWithInvalidPagination()
	{
		$result = $this->api->get_form_subscriptions(
			(int) $_ENV['CONVERTKIT_API_FORM_ID'], // Form ID.
			'active', // Subscriber state.
			null, // Created after.
			null, // Created before.
			null, // Added after.
			null, // Added before.
			false, // Include total count.
			'not-a-valid-cursor' // After cursor.
		);
		$this->assertInstanceOf(WP_Error::class, $result);
		$this->assertEquals($result->get_error_code(), $this->errorCode);
	}

	/**
	 * Test that add_subscriber_to_form_by_email() returns the expected data.
	 *
	 * @since   1.0.0
	 *
	 * @return void
	 */
	public function testAddSubscriberToFormByEmail()
	{
		// Create subscriber.
		$emailAddress = $this->generateEmailAddress();
		$result       = $this->api->create_subscriber($emailAddress);
		$this->assertNotInstanceOf(WP_Error::class, $result);
		$this->assertIsArray($result);

		// Set subscriber_id to ensure subscriber is unsubscribed after test.
		$this->subscriber_ids[] = $result['subscriber']['id'];

		// Add subscriber to form.
		$result = $this->api->add_subscriber_to_form_by_email(
			$_ENV['CONVERTKIT_API_FORM_ID'],
			$emailAddress
		);
		$this->assertNotInstanceOf(WP_Error::class, $result);
		$this->assertIsArray($result);
		$this->assertArrayHasKey('subscriber', $result);
		$this->assertArrayHasKey('id', $result['subscriber']);
		$this->assertEquals(
			$result['subscriber']['email_address'],
			$emailAddress
		);
	}

	/**
	 * Test that add_subscriber_to_form_by_email() returns the expected data
	 * when a referrer is specified.
	 *
	 * @since   2.1.0
	 *
	 * @return void
	 */
	public function testAddSubscriberToFormByEmailWithReferrer()
	{
		// Create subscriber.
		$emailAddress = $this->generateEmailAddress();
		$subscriber   = $this->api->create_subscriber($emailAddress);

		// Set subscriber_id to ensure subscriber is unsubscribed after test.
		$this->subscriber_ids[] = $subscriber['subscriber']['id'];

		// Add subscriber to form.
		$result = $this->api->add_subscriber_to_form_by_email(
			(int) $_ENV['CONVERTKIT_API_FORM_ID'],
			$emailAddress,
			'https://mywebsite.com/bfpromo/',
		);

		$this->assertNotInstanceOf(WP_Error::class, $result);
		$this->assertIsArray($result);
		$this->assertArrayHasKey('subscriber', $result);
		$this->assertArrayHasKey('id', $result['subscriber']);
		$this->assertEquals(
			$result['subscriber']['email_address'],
			$emailAddress
		);

		// Assert referrer data set for form subscriber.
		$this->assertEquals(
			$result['subscriber']['referrer'],
			'https://mywebsite.com/bfpromo/'
		);
	}

	/**
	 * Test that add_subscriber_to_form_by_email() returns the expected data
	 * when a referrer is specified that includes UTM parameters.
	 *
	 * @since   2.1.0
	 *
	 * @return void
	 */
	public function testAddSubscriberToFormByEmailWithReferrerUTMParams()
	{
		// Define referrer.
		$referrerUTMParams = [
			'utm_source'   => 'facebook',
			'utm_medium'   => 'cpc',
			'utm_campaign' => 'black_friday',
			'utm_term'     => 'car_owners',
			'utm_content'  => 'get_10_off',
		];
		$referrer          = 'https://mywebsite.com/bfpromo/?' . http_build_query($referrerUTMParams);

		// Create subscriber.
		$emailAddress = $this->generateEmailAddress();
		$subscriber   = $this->api->create_subscriber($emailAddress);

		// Set subscriber_id to ensure subscriber is unsubscribed after test.
		$this->subscriber_ids[] = $subscriber['subscriber']['id'];

		// Add subscriber to form.
		$result = $this->api->add_subscriber_to_form_by_email(
			(int) $_ENV['CONVERTKIT_API_FORM_ID'],
			$emailAddress,
			$referrer,
		);

		$this->assertNotInstanceOf(WP_Error::class, $result);
		$this->assertIsArray($result);
		$this->assertArrayHasKey('subscriber', $result);
		$this->assertArrayHasKey('id', $result['subscriber']);
		$this->assertEquals(
			$result['subscriber']['email_address'],
			$emailAddress
		);

		// Assert referrer data set for form subscriber.
		$this->assertEquals(
			$result['subscriber']['referrer'],
			$referrer
		);
		$this->assertEquals(
			$result['subscriber']['referrer_utm_parameters']['source'],
			$referrerUTMParams['utm_source']
		);
		$this->assertEquals(
			$result['subscriber']['referrer_utm_parameters']['medium'],
			$referrerUTMParams['utm_medium']
		);
		$this->assertEquals(
			$result['subscriber']['referrer_utm_parameters']['campaign'],
			$referrerUTMParams['utm_campaign']
		);
		$this->assertEquals(
			$result['subscriber']['referrer_utm_parameters']['term'],
			$referrerUTMParams['utm_term']
		);
		$this->assertEquals(
			$result['subscriber']['referrer_utm_parameters']['content'],
			$referrerUTMParams['utm_content']
		);
	}

	/**
	 * Test that add_subscriber_to_form_by_email() returns a WP_Error when an invalid
	 * form is specified.
	 *
	 * @since   1.0.0
	 *
	 * @return void
	 */
	public function testAddSubscriberToFormByEmailWithInvalidformID()
	{
		$result = $this->api->add_subscriber_to_form_by_email(
			12345,
			$_ENV['CONVERTKIT_API_SUBSCRIBER_EMAIL']
		);
		$this->assertInstanceOf(WP_Error::class, $result);
		$this->assertEquals($result->get_error_code(), $this->errorCode);
	}

	/**
	 * Test that add_subscriber_to_form_by_email() returns a WP_Error when an invalid
	 * email address is specified.
	 *
	 * @since   1.0.0
	 *
	 * @return void
	 */
	public function testAddSubscriberToFormByEmailWithInvalidEmailAddress()
	{
		$result = $this->api->add_subscriber_to_form_by_email(
			$_ENV['CONVERTKIT_API_FORM_ID'],
			'not-an-email-address'
		);
		$this->assertInstanceOf(WP_Error::class, $result);
		$this->assertEquals($result->get_error_code(), $this->errorCode);
	}

	/**
	 * Test that add_subscriber_to_form() returns the expected data.
	 *
	 * @since   2.0.0
	 *
	 * @return void
	 */
	public function testAddSubscriberToForm()
	{
		// Create subscriber.
		$subscriber = $this->api->create_subscriber(
			$this->generateEmailAddress()
		);

		$this->assertNotInstanceOf(WP_Error::class, $subscriber);
		$this->assertIsArray($subscriber);

		// Set subscriber_id to ensure subscriber is unsubscribed after test.
		$this->subscriber_ids[] = $subscriber['subscriber']['id'];

		// Add subscriber to form.
		$result = $this->api->add_subscriber_to_form(
			(int) $_ENV['CONVERTKIT_API_FORM_ID'],
			$subscriber['subscriber']['id']
		);
		$this->assertNotInstanceOf(WP_Error::class, $result);
		$this->assertIsArray($result);
		$this->assertArrayHasKey('subscriber', $result);
		$this->assertArrayHasKey('id', $result['subscriber']);
		$this->assertEquals($result['subscriber']['id'], $subscriber['subscriber']['id']);
	}

	/**
	 * Test that add_subscriber_to_form() returns the expected data
	 * when a referrer is specified.
	 *
	 * @since   2.1.0
	 *
	 * @return void
	 */
	public function testAddSubscriberToFormWithReferrer()
	{
		// Create subscriber.
		$emailAddress = $this->generateEmailAddress();
		$subscriber   = $this->api->create_subscriber($emailAddress);

		// Set subscriber_id to ensure subscriber is unsubscribed after test.
		$this->subscriber_ids[] = $subscriber['subscriber']['id'];

		// Add subscriber to form.
		$result = $this->api->add_subscriber_to_form(
			(int) $_ENV['CONVERTKIT_API_FORM_ID'],
			$subscriber['subscriber']['id'],
			'https://mywebsite.com/bfpromo/',
		);

		$this->assertNotInstanceOf(WP_Error::class, $result);
		$this->assertIsArray($result);
		$this->assertArrayHasKey('subscriber', $result);
		$this->assertArrayHasKey('id', $result['subscriber']);
		$this->assertEquals(
			$result['subscriber']['id'],
			$subscriber['subscriber']['id']
		);

		// Assert referrer data set for form subscriber.
		$this->assertEquals(
			$result['subscriber']['referrer'],
			'https://mywebsite.com/bfpromo/'
		);
	}

	/**
	 * Test that add_subscriber_to_form() returns the expected data
	 * when a referrer is specified that includes UTM parameters.
	 *
	 * @since   2.1.0
	 *
	 * @return void
	 */
	public function testAddSubscriberToFormWithReferrerUTMParams()
	{
		// Define referrer.
		$referrerUTMParams = [
			'utm_source'   => 'facebook',
			'utm_medium'   => 'cpc',
			'utm_campaign' => 'black_friday',
			'utm_term'     => 'car_owners',
			'utm_content'  => 'get_10_off',
		];
		$referrer          = 'https://mywebsite.com/bfpromo/?' . http_build_query($referrerUTMParams);

		// Create subscriber.
		$emailAddress = $this->generateEmailAddress();
		$subscriber   = $this->api->create_subscriber($emailAddress);

		// Set subscriber_id to ensure subscriber is unsubscribed after test.
		$this->subscriber_ids[] = $subscriber['subscriber']['id'];

		// Add subscriber to form.
		$result = $this->api->add_subscriber_to_form(
			(int) $_ENV['CONVERTKIT_API_FORM_ID'],
			$subscriber['subscriber']['id'],
			$referrer,
		);

		$this->assertNotInstanceOf(WP_Error::class, $result);
		$this->assertIsArray($result);
		$this->assertArrayHasKey('subscriber', $result);
		$this->assertArrayHasKey('id', $result['subscriber']);
		$this->assertEquals(
			$result['subscriber']['id'],
			$subscriber['subscriber']['id']
		);

		// Assert referrer data set for form subscriber.
		$this->assertEquals(
			$result['subscriber']['referrer'],
			$referrer
		);
		$this->assertEquals(
			$result['subscriber']['referrer_utm_parameters']['source'],
			$referrerUTMParams['utm_source']
		);
		$this->assertEquals(
			$result['subscriber']['referrer_utm_parameters']['medium'],
			$referrerUTMParams['utm_medium']
		);
		$this->assertEquals(
			$result['subscriber']['referrer_utm_parameters']['campaign'],
			$referrerUTMParams['utm_campaign']
		);
		$this->assertEquals(
			$result['subscriber']['referrer_utm_parameters']['term'],
			$referrerUTMParams['utm_term']
		);
		$this->assertEquals(
			$result['subscriber']['referrer_utm_parameters']['content'],
			$referrerUTMParams['utm_content']
		);
	}

	/**
	 * Test that add_subscriber_to_form() returns a WP_Error when an invalid
	 * form ID is specified.
	 *
	 * @since   2.0.0
	 *
	 * @return void
	 */
	public function testAddSubscriberToFormWithInvalidFormID()
	{
		$result = $this->api->add_subscriber_to_form(
			12345,
			$_ENV['CONVERTKIT_API_SUBSCRIBER_ID']
		);
		$this->assertInstanceOf(WP_Error::class, $result);
		$this->assertEquals($result->get_error_code(), $this->errorCode);
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
			$_ENV['CONVERTKIT_API_LEGACY_FORM_ID'],
			$_ENV['CONVERTKIT_API_SUBSCRIBER_ID']
		);
		$this->assertInstanceOf(WP_Error::class, $result);
		$this->assertEquals($result->get_error_code(), $this->errorCode);
	}

	/**
	 * Test that add_subscriber_to_form() returns a WP_Error when an invalid
	 * email address is specified.
	 *
	 * @since   2.0.0
	 *
	 * @return void
	 */
	public function testAddSubscriberToformWithInvalidSubscriberID()
	{
		$result = $this->api->add_subscriber_to_form(
			$_ENV['CONVERTKIT_API_FORM_ID'],
			12345
		);
		$this->assertInstanceOf(WP_Error::class, $result);
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
		$subscriber = $this->api->create_subscriber(
			$this->generateEmailAddress()
		);

		$this->assertNotInstanceOf(WP_Error::class, $subscriber);
		$this->assertIsArray($subscriber);

		// Set subscriber_id to ensure subscriber is unsubscribed after test.
		$this->subscriber_ids[] = $subscriber['subscriber']['id'];

		// Add subscriber to legacy form.
		$result = $this->api->add_subscriber_to_legacy_form(
			(int) $_ENV['CONVERTKIT_API_LEGACY_FORM_ID'],
			$subscriber['subscriber']['id']
		);
		$this->assertNotInstanceOf(WP_Error::class, $result);
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
			12345,
			$_ENV['CONVERTKIT_API_SUBSCRIBER_ID']
		);
		$this->assertInstanceOf(WP_Error::class, $result);
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
			$_ENV['CONVERTKIT_API_FORM_ID'],
			$_ENV['CONVERTKIT_API_SUBSCRIBER_ID']
		);
		$this->assertInstanceOf(WP_Error::class, $result);
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
			$_ENV['CONVERTKIT_API_LEGACY_FORM_ID'],
			12345
		);
		$this->assertInstanceOf(WP_Error::class, $result);
		$this->assertEquals($result->get_error_code(), $this->errorCode);
	}

	/**
	 * Test that get_sequences() returns the expected data.
	 *
	 * @since   1.0.0
	 *
	 * @return void
	 */
	public function testGetSequences()
	{
		$result = $this->api->get_sequences();

		// Assert sequences and pagination exist.
		$this->assertDataExists($result, 'sequences');
		$this->assertPaginationExists($result);

		// Check first sequence in resultset has expected data.
		$sequence = $result['sequences'][0];
		$this->assertArrayHasKey('id', $sequence);
		$this->assertArrayHasKey('name', $sequence);
		$this->assertArrayHasKey('hold', $sequence);
		$this->assertArrayHasKey('repeat', $sequence);
		$this->assertArrayHasKey('created_at', $sequence);
	}

	/**
	 * Test that get_sequences() returns the expected data
	 * when the total count is included.
	 *
	 * @since   2.0.0
	 *
	 * @return void
	 */
	public function testGetSequencesWithTotalCount()
	{
		$result = $this->api->get_sequences(true);

		// Assert sequences and pagination exist.
		$this->assertDataExists($result, 'sequences');
		$this->assertPaginationExists($result);

		// Assert total count is included.
		$this->assertArrayHasKey('total_count', $result['pagination']);
		$this->assertGreaterThan(0, $result['pagination']['total_count']);
	}

	/**
	 * Test that get_sequences() returns the expected data when
	 * pagination parameters and per_page limits are specified.
	 *
	 * @since   2.0.0
	 *
	 * @return void
	 */
	public function testGetSequencesPagination()
	{
		// Return one sequence.
		$result = $this->api->get_sequences(false, '', '', 1);

		// Assert sequences and pagination exist.
		$this->assertDataExists($result, 'sequences');
		$this->assertPaginationExists($result);

		// Assert a single sequence was returned.
		$this->assertCount(1, $result['sequences']);

		// Assert has_previous_page and has_next_page are correct.
		$this->assertFalse($result['pagination']['has_previous_page']);
		$this->assertTrue($result['pagination']['has_next_page']);

		// Use pagination to fetch next page.
		$result = $this->api->get_sequences(false, $result['pagination']['end_cursor'], '', 1);

		// Assert sequences and pagination exist.
		$this->assertDataExists($result, 'sequences');
		$this->assertPaginationExists($result);

		// Assert a single sequence was returned.
		$this->assertCount(1, $result['sequences']);

		// Assert has_previous_page and has_next_page are correct.
		$this->assertTrue($result['pagination']['has_previous_page']);
		$this->assertFalse($result['pagination']['has_next_page']);

		// Use pagination to fetch previous page.
		$result = $this->api->get_sequences(false, '', $result['pagination']['start_cursor'], 1);

		// Assert sequences and pagination exist.
		$this->assertDataExists($result, 'sequences');
		$this->assertPaginationExists($result);

		// Assert a single sequence was returned.
		$this->assertCount(1, $result['sequences']);

		// Assert has_previous_page and has_next_page are correct.
		$this->assertFalse($result['pagination']['has_previous_page']);
		$this->assertTrue($result['pagination']['has_next_page']);
	}

	/**
	 * Test that add_subscriber_to_sequence_by_email() returns the expected data.
	 *
	 * @since   1.0.0
	 *
	 * @return void
	 */
	public function testAddSubscriberToSequenceByEmail()
	{
		// Create subscriber.
		$emailAddress = $this->generateEmailAddress();
		$result       = $this->api->create_subscriber($emailAddress);
		$this->assertNotInstanceOf(WP_Error::class, $result);
		$this->assertIsArray($result);

		// Set subscriber_id to ensure subscriber is unsubscribed after test.
		$this->subscriber_ids[] = $result['subscriber']['id'];

		// Add subscriber to sequence.
		$result = $this->api->add_subscriber_to_sequence_by_email(
			$_ENV['CONVERTKIT_API_SEQUENCE_ID'],
			$emailAddress
		);
		$this->assertNotInstanceOf(WP_Error::class, $result);
		$this->assertIsArray($result);
		$this->assertArrayHasKey('subscriber', $result);
		$this->assertArrayHasKey('id', $result['subscriber']);
		$this->assertEquals(
			$result['subscriber']['email_address'],
			$emailAddress
		);
	}

	/**
	 * Test that add_subscriber_to_sequence_by_email() returns a WP_Error when an invalid
	 * sequence is specified.
	 *
	 * @since   1.0.0
	 *
	 * @return void
	 */
	public function testAddSubscriberToSequenceByEmailWithInvalidSequenceID()
	{
		$result = $this->api->add_subscriber_to_sequence_by_email(
			12345,
			$_ENV['CONVERTKIT_API_SUBSCRIBER_EMAIL']
		);
		$this->assertInstanceOf(WP_Error::class, $result);
		$this->assertEquals($result->get_error_code(), $this->errorCode);
	}

	/**
	 * Test that add_subscriber_to_sequence_by_email() returns a WP_Error when an invalid
	 * email address is specified.
	 *
	 * @since   1.0.0
	 *
	 * @return void
	 */
	public function testAddSubscriberToSequenceByEmailWithInvalidEmailAddress()
	{
		$result = $this->api->add_subscriber_to_sequence_by_email(
			$_ENV['CONVERTKIT_API_SEQUENCE_ID'],
			'not-an-email-address'
		);
		$this->assertInstanceOf(WP_Error::class, $result);
		$this->assertEquals($result->get_error_code(), $this->errorCode);
	}

	/**
	 * Test that add_subscriber_to_sequence() returns the expected data.
	 *
	 * @since   2.0.0
	 *
	 * @return void
	 */
	public function testAddSubscriberToSequence()
	{
		// Create subscriber.
		$subscriber = $this->api->create_subscriber(
			$this->generateEmailAddress()
		);

		$this->assertNotInstanceOf(WP_Error::class, $subscriber);
		$this->assertIsArray($subscriber);

		// Set subscriber_id to ensure subscriber is unsubscribed after test.
		$this->subscriber_ids[] = $subscriber['subscriber']['id'];

		// Add subscriber to sequence.
		$result = $this->api->add_subscriber_to_sequence(
			(int) $_ENV['CONVERTKIT_API_SEQUENCE_ID'],
			$subscriber['subscriber']['id']
		);
		$this->assertNotInstanceOf(WP_Error::class, $result);
		$this->assertIsArray($result);
		$this->assertArrayHasKey('subscriber', $result);
		$this->assertArrayHasKey('id', $result['subscriber']);
		$this->assertEquals($result['subscriber']['id'], $subscriber['subscriber']['id']);
	}

	/**
	 * Test that add_subscriber_to_sequence() returns a WP_Error when an invalid
	 * sequence ID is specified.
	 *
	 * @since   2.0.0
	 *
	 * @return void
	 */
	public function testAddSubscriberToSequenceWithInvalidSequenceID()
	{
		$result = $this->api->add_subscriber_to_sequence(
			12345,
			$_ENV['CONVERTKIT_API_SUBSCRIBER_ID']
		);
		$this->assertInstanceOf(WP_Error::class, $result);
		$this->assertEquals($result->get_error_code(), $this->errorCode);
	}

	/**
	 * Test that add_subscriber_to_sequence() returns a WP_Error when an invalid
	 * email address is specified.
	 *
	 * @since   2.0.0
	 *
	 * @return void
	 */
	public function testAddSubscriberToSequenceWithInvalidSubscriberID()
	{
		$result = $this->api->add_subscriber_to_sequence(
			$_ENV['CONVERTKIT_API_SUBSCRIBER_ID'],
			12345
		);
		$this->assertInstanceOf(WP_Error::class, $result);
		$this->assertEquals($result->get_error_code(), $this->errorCode);
	}

	/**
	 * Test that get_sequence_subscriptions() returns the expected data.
	 *
	 * @since   1.0.0
	 *
	 * @return void
	 */
	public function testGetSequenceSubscriptions()
	{
		$result = $this->api->get_sequence_subscriptions($_ENV['CONVERTKIT_API_SEQUENCE_ID']);

		// Assert subscribers and pagination exist.
		$this->assertDataExists($result, 'subscribers');
		$this->assertPaginationExists($result);
	}

	/**
	 * Test that get_sequence_subscriptions() returns the expected data
	 * when the total count is included.
	 *
	 * @since   2.0.0
	 *
	 * @return void
	 */
	public function testGetSequenceSubscriptionsWithTotalCount()
	{
		$result = $this->api->get_sequence_subscriptions(
			$_ENV['CONVERTKIT_API_SEQUENCE_ID'], // Sequence ID.
			'active', // Subscriber state.
			null, // Created after.
			null, // Created before.
			null, // Added after.
			null, // Added before.
			true // Include total count.
		);

		// Assert subscribers and pagination exist.
		$this->assertDataExists($result, 'subscribers');
		$this->assertPaginationExists($result);

		// Assert total count is included.
		$this->assertArrayHasKey('total_count', $result['pagination']);
		$this->assertGreaterThan(0, $result['pagination']['total_count']);
	}

	/**
	 * Test that get_sequence_subscriptions() returns the expected data
	 * when a valid Sequence ID is specified and the subscription status
	 * is cancelled.
	 *
	 * @since   1.0.0
	 *
	 * @return void
	 */
	public function testGetSequenceSubscriptionsWithBouncedSubscriberState()
	{
		$result = $this->api->get_sequence_subscriptions(
			$_ENV['CONVERTKIT_API_SEQUENCE_ID'], // Sequence ID.
			'bounced' // Subscriber state.
		);

		// Assert subscribers and pagination exist.
		$this->assertDataExists($result, 'subscribers');
		$this->assertPaginationExists($result);

		// Check the correct subscribers were returned.
		$this->assertEquals($result['subscribers'][0]['state'], 'bounced');
	}

	/**
	 * Test that get_sequence_subscriptions() returns the expected data
	 * when a valid Sequence ID is specified and the added_after parameter
	 * is used.
	 *
	 * @since   2.0.0
	 *
	 * @return void
	 */
	public function testGetSequenceSubscriptionsWithAddedAfterParam()
	{
		$date   = new \DateTime('2022-01-01');
		$result = $this->api->get_sequence_subscriptions(
			$_ENV['CONVERTKIT_API_SEQUENCE_ID'], // Sequence ID.
			'active', // Subscriber state.
			null, // Created after.
			null, // Created before.
			$date // Added after.
		);

		// Assert subscribers and pagination exist.
		$this->assertDataExists($result, 'subscribers');
		$this->assertPaginationExists($result);

		// Check the correct subscribers were returned.
		$this->assertGreaterThanOrEqual(
			$date->format('Y-m-d'),
			date('Y-m-d', strtotime($result['subscribers'][0]['added_at']))
		);
	}

	/**
	 * Test that get_sequence_subscriptions() returns the expected data
	 * when a valid Sequence ID is specified and the added_before parameter
	 * is used.
	 *
	 * @since   2.0.0
	 *
	 * @return void
	 */
	public function testGetSequenceSubscriptionsWithAddedBeforeParam()
	{
		$date   = new \DateTime('2024-01-01');
		$result = $this->api->get_sequence_subscriptions(
			$_ENV['CONVERTKIT_API_SEQUENCE_ID'], // Sequence ID.
			'active', // Subscriber state.
			null, // Created after.
			null, // Created before.
			null, // Added after.
			$date // Added before.
		);

		// Assert subscribers and pagination exist.
		$this->assertDataExists($result, 'subscribers');
		$this->assertPaginationExists($result);

		// Check the correct subscribers were returned.
		$this->assertLessThanOrEqual(
			$date->format('Y-m-d'),
			date('Y-m-d', strtotime($result['subscribers'][0]['added_at']))
		);
	}

	/**
	 * Test that get_sequence_subscriptions() returns the expected data
	 * when a valid Sequence ID is specified and the created_after parameter
	 * is used.
	 *
	 * @since   2.0.0
	 *
	 * @return void
	 */
	public function testGetSequenceSubscriptionsWithCreatedAfterParam()
	{
		$date   = new \DateTime('2022-01-01');
		$result = $this->api->get_sequence_subscriptions(
			$_ENV['CONVERTKIT_API_SEQUENCE_ID'], // Sequence ID.
			'active', // Subscriber state.
			$date // Created after.
		);

		// Assert subscribers and pagination exist.
		$this->assertDataExists($result, 'subscribers');
		$this->assertPaginationExists($result);

		// Check the correct subscribers were returned.
		$this->assertGreaterThanOrEqual(
			$date->format('Y-m-d'),
			date('Y-m-d', strtotime($result['subscribers'][0]['created_at']))
		);
	}

	/**
	 * Test that get_sequence_subscriptions() returns the expected data
	 * when a valid Sequence ID is specified and the created_before parameter
	 * is used.
	 *
	 * @since   2.0.0
	 *
	 * @return void
	 */
	public function testGetSequenceSubscriptionsWithCreatedBeforeParam()
	{
		$date   = new \DateTime('2024-01-01');
		$result = $this->api->get_sequence_subscriptions(
			$_ENV['CONVERTKIT_API_SEQUENCE_ID'], // Sequence ID.
			'active', // Subscriber state.
			null, // Created after.
			$date // Created before.
		);

		// Assert subscribers and pagination exist.
		$this->assertDataExists($result, 'subscribers');
		$this->assertPaginationExists($result);

		// Check the correct subscribers were returned.
		$this->assertLessThanOrEqual(
			$date->format('Y-m-d'),
			date('Y-m-d', strtotime($result['subscribers'][0]['created_at']))
		);
	}

	/**
	 * Test that get_sequence_subscriptions() returns the expected data
	 * when a valid Sequence ID is specified and pagination parameters
	 * and per_page limits are specified.
	 *
	 * @since   1.0.0
	 *
	 * @return void
	 */
	public function testGetSequenceSubscriptionsPagination()
	{
		$result = $this->api->get_sequence_subscriptions(
			$_ENV['CONVERTKIT_API_SEQUENCE_ID'], // Sequence ID.
			'active', // Subscriber state.
			null, // Created after.
			null, // Created before.
			null, // Added after.
			null, // Added before.
			false, // Include total count.
			'', // After cursor.
			'', // Before cursor.
			1 // Per page.
		);

		// Assert subscribers and pagination exist.
		$this->assertDataExists($result, 'subscribers');
		$this->assertPaginationExists($result);

		// Assert a single subscriber was returned.
		$this->assertCount(1, $result['subscribers']);

		// Assert has_previous_page and has_next_page are correct.
		$this->assertFalse($result['pagination']['has_previous_page']);
		$this->assertTrue($result['pagination']['has_next_page']);

		// Use pagination to fetch next page.
		$result = $this->api->get_sequence_subscriptions(
			$_ENV['CONVERTKIT_API_SEQUENCE_ID'], // Sequence ID.
			'active', // Subscriber state.
			null, // Created after.
			null, // Created before.
			null, // Added after.
			null, // Added before.
			false, // Include total count.
			$result['pagination']['end_cursor'], // After cursor.
			'', // Before cursor.
			1 // Per page.
		);

		// Assert subscribers and pagination exist.
		$this->assertDataExists($result, 'subscribers');
		$this->assertPaginationExists($result);

		// Assert a single subscriber was returned.
		$this->assertCount(1, $result['subscribers']);

		// Assert has_previous_page and has_next_page are correct.
		$this->assertTrue($result['pagination']['has_previous_page']);
		$this->assertFalse($result['pagination']['has_next_page']);

		// Use pagination to fetch previous page.
		$result = $this->api->get_sequence_subscriptions(
			$_ENV['CONVERTKIT_API_SEQUENCE_ID'], // Sequence ID.
			'active', // Subscriber state.
			null, // Created after.
			null, // Created before.
			null, // Added after.
			null, // Added before.
			false, // Include total count.
			'', // After cursor.
			$result['pagination']['start_cursor'], // Before cursor.
			1 // Per page.
		);

		// Assert subscribers and pagination exist.
		$this->assertDataExists($result, 'subscribers');
		$this->assertPaginationExists($result);
	}

	/**
	 * Test that get_sequence_subscriptions() returns a WP_Error when an invalid
	 * Sequence ID is specified.
	 *
	 * @since   1.0.0
	 *
	 * @return void
	 */
	public function testGetSequenceSubscriptionsWithInvalidSequenceID()
	{
		$result = $this->api->get_sequence_subscriptions(12345);
		$this->assertInstanceOf(WP_Error::class, $result);
		$this->assertEquals($result->get_error_code(), $this->errorCode);
	}

	/**
	 * Test that get_sequence_subscriptions() returns a WP_Error when an invalid
	 * subscriber state is specified.
	 *
	 * @since   2.0.0
	 *
	 * @return void
	 */
	public function testGetSequenceSubscriptionsWithInvalidSubscriberState()
	{
		$result = $this->api->get_sequence_subscriptions(
			(int) $_ENV['CONVERTKIT_API_SEQUENCE_ID'],
			'not-a-valid-state'
		);
		$this->assertInstanceOf(WP_Error::class, $result);
		$this->assertEquals($result->get_error_code(), $this->errorCode);
	}

	/**
	 * Test that get_sequence_subscriptions() returns a WP_Error when invalid
	 * pagination parameters are specified.
	 *
	 * @since   2.0.0
	 *
	 * @return void
	 */
	public function testGetSequenceSubscriptionsWithInvalidPagination()
	{
		$result = $this->api->get_sequence_subscriptions(
			(int) $_ENV['CONVERTKIT_API_SEQUENCE_ID'],
			'not-a-valid-cursor'
		);
		$this->assertInstanceOf(WP_Error::class, $result);
		$this->assertEquals($result->get_error_code(), $this->errorCode);
	}

	/**
	 * Test that get_tags() returns the expected data.
	 *
	 * @since   1.0.0
	 *
	 * @return void
	 */
	public function testGetTags()
	{
		$result = $this->api->get_tags();

		// Assert sequences and pagination exist.
		$this->assertDataExists($result, 'tags');
		$this->assertPaginationExists($result);

		// Check first tag in resultset has expected data.
		$tag = $result['tags'][0];
		$this->assertArrayHasKey('id', $tag);
		$this->assertArrayHasKey('name', $tag);
		$this->assertArrayHasKey('created_at', $tag);
	}

	/**
	 * Test that get_tags() returns the expected data
	 * when the total count is included.
	 *
	 * @since   2.0.0
	 *
	 * @return void
	 */
	public function testGetTagsWithTotalCount()
	{
		$result = $this->api->get_tags(true);

		// Assert tags and pagination exist.
		$this->assertDataExists($result, 'tags');
		$this->assertPaginationExists($result);

		// Assert total count is included.
		$this->assertArrayHasKey('total_count', $result['pagination']);
		$this->assertGreaterThan(0, $result['pagination']['total_count']);
	}

	/**
	 * Test that get_tags() returns the expected data
	 * when pagination parameters and per_page limits are specified.
	 *
	 * @since   2.0.0
	 *
	 * @return void
	 */
	public function testGetTagsPagination()
	{
		$result = $this->api->get_tags(false, '', '', 1);

		// Assert tags and pagination exist.
		$this->assertDataExists($result, 'tags');
		$this->assertPaginationExists($result);

		// Assert a single tag was returned.
		$this->assertCount(1, $result['tags']);

		// Assert has_previous_page and has_next_page are correct.
		$this->assertFalse($result['pagination']['has_previous_page']);
		$this->assertTrue($result['pagination']['has_next_page']);

		// Use pagination to fetch next page.
		$result = $this->api->get_tags(false, $result['pagination']['end_cursor'], '', 1);

		// Assert tags and pagination exist.
		$this->assertDataExists($result, 'tags');
		$this->assertPaginationExists($result);

		// Assert a single subscriber was returned.
		$this->assertCount(1, $result['tags']);

		// Assert has_previous_page and has_next_page are correct.
		$this->assertTrue($result['pagination']['has_previous_page']);
		$this->assertTrue($result['pagination']['has_next_page']);

		// Use pagination to fetch previous page.
		$result = $this->api->get_tags(false, '', $result['pagination']['start_cursor'], 1);

		// Assert tags and pagination exist.
		$this->assertDataExists($result, 'tags');
		$this->assertPaginationExists($result);
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
	 * Test that create_tag() returns a WP_Error when creating
	 * a blank tag.
	 *
	 * @since   1.0.0
	 *
	 * @return void
	 */
	public function testCreateTagBlank()
	{
		$result = $this->api->create_tag('');
		$this->assertInstanceOf(WP_Error::class, $result);
		$this->assertEquals($result->get_error_code(), $this->errorCode);
	}

	/**
	 * Test that create_tag() returns the expected data when creating
	 * a tag that already exists.
	 *
	 * @since   1.0.0
	 *
	 * @return void
	 */
	public function testCreateTagThatExists()
	{
		$result = $this->api->create_tag($_ENV['CONVERTKIT_API_TAG_NAME']);

		// Assert response contains correct data.
		$this->assertArrayHasKey('id', $result['tag']);
		$this->assertArrayHasKey('name', $result['tag']);
		$this->assertArrayHasKey('created_at', $result['tag']);
		$this->assertEquals($result['tag']['name'], $_ENV['CONVERTKIT_API_TAG_NAME']);
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
	 * Test that create_tags() returns failures when attempting
	 * to create blank tags.
	 *
	 * @since   2.0.0
	 *
	 * @return void
	 */
	public function testCreateTagsBlank()
	{
		$result = $this->api->create_tags(
			[
				'',
				'',
			]
		);
		$this->assertInstanceOf(WP_Error::class, $result);
		$this->assertEquals($result->get_error_code(), $this->errorCode);
	}

	/**
	 * Test that create_tags() returns the expected data when creating
	 * tags that already exist.
	 *
	 * @since   2.0.0
	 *
	 * @return void
	 */
	public function testCreateTagsThatExist()
	{
		$result = $this->api->create_tags(
			[
				$_ENV['CONVERTKIT_API_TAG_NAME'],
				$_ENV['CONVERTKIT_API_TAG_NAME_2'],
			]
		);

		// Assert existing tags are returned.
		$this->assertCount(2, $result['tags']);
		$this->assertEquals($result['tags'][1]['name'], $_ENV['CONVERTKIT_API_TAG_NAME']);
		$this->assertEquals($result['tags'][0]['name'], $_ENV['CONVERTKIT_API_TAG_NAME_2']);
	}

	/**
	 * Test that tag_subscriber_by_email() returns the expected data.
	 *
	 * @since   1.0.0
	 *
	 * @return void
	 */
	public function testTagSubscriberByEmail()
	{
		// Create subscriber.
		$emailAddress = $this->generateEmailAddress();
		$this->api->create_subscriber($emailAddress);

		// Tag subscriber by email.
		$subscriber = $this->api->tag_subscriber_by_email(
			(int) $_ENV['CONVERTKIT_API_TAG_ID'],
			$emailAddress,
		);
		$this->assertArrayHasKey('subscriber', $subscriber);
		$this->assertArrayHasKey('id', $subscriber['subscriber']);
		$this->assertArrayHasKey('tagged_at', $subscriber['subscriber']);

		// Confirm the subscriber is tagged.
		$result = $this->api->get_subscriber_tags($subscriber['subscriber']['id']);

		// Assert tags and pagination exist.
		$this->assertDataExists($result, 'tags');
		$this->assertPaginationExists($result);

		// Assert correct tag was assigned.
		$this->assertEquals($result['tags'][0]['id'], $_ENV['CONVERTKIT_API_TAG_ID']);
	}

	/**
	 * Test that tag_subscriber_by_email() returns a WP_Error when an invalid
	 * tag is specified.
	 *
	 * @since   2.0.0
	 *
	 * @return void
	 */
	public function testTagSubscriberByEmailWithInvalidTagID()
	{
		// Create subscriber.
		$emailAddress = $this->generateEmailAddress();
		$this->api->create_subscriber($emailAddress);

		$result = $this->api->tag_subscriber_by_email(
			12345,
			$emailAddress
		);
		$this->assertInstanceOf(WP_Error::class, $result);
		$this->assertEquals($result->get_error_code(), $this->errorCode);
	}

	/**
	 * Test that tag_subscriber_by_email() returns a WP_Error when an invalid
	 * email address is specified.
	 *
	 * @since   2.0.0
	 *
	 * @return void
	 */
	public function testTagSubscriberByEmailWithInvalidEmailAddress()
	{
		$result = $this->api->tag_subscriber_by_email(
			(int) $_ENV['CONVERTKIT_API_TAG_ID'],
			'not-an-email-address'
		);
		$this->assertInstanceOf(WP_Error::class, $result);
		$this->assertEquals($result->get_error_code(), $this->errorCode);
	}

	/**
	 * Test that tag_subscriber() returns the expected data.
	 *
	 * @since   2.0.0
	 *
	 * @return void
	 */
	public function testTagSubscriber()
	{
		// Create subscriber.
		$emailAddress = $this->generateEmailAddress();
		$subscriber   = $this->api->create_subscriber($emailAddress);

		// Tag subscriber by email.
		$result = $this->api->tag_subscriber(
			(int) $_ENV['CONVERTKIT_API_TAG_ID'],
			$subscriber['subscriber']['id'],
		);
		$this->assertArrayHasKey('subscriber', $result);
		$this->assertArrayHasKey('id', $result['subscriber']);
		$this->assertArrayHasKey('tagged_at', $result['subscriber']);

		// Confirm the subscriber is tagged.
		$result = $this->api->get_subscriber_tags($result['subscriber']['id']);

		// Assert tags and pagination exist.
		$this->assertDataExists($result, 'tags');
		$this->assertPaginationExists($result);

		// Assert correct tag was assigned.
		$this->assertEquals($result['tags'][0]['id'], $_ENV['CONVERTKIT_API_TAG_ID']);
	}

	/**
	 * Test that tag_subscriber() returns a WP_Error when an invalid
	 * sequence ID is specified.
	 *
	 * @since   2.0.0
	 *
	 * @return void
	 */
	public function testTagSubscriberWithInvalidTagID()
	{
		// Create subscriber.
		$emailAddress = $this->generateEmailAddress();
		$subscriber   = $this->api->create_subscriber($emailAddress);

		$result = $this->api->tag_subscriber(
			12345,
			$subscriber['subscriber']['id']
		);
		$this->assertInstanceOf(WP_Error::class, $result);
		$this->assertEquals($result->get_error_code(), $this->errorCode);
	}

	/**
	 * Test that tag_subscriber() returns a WP_Error when an invalid
	 * email address is specified.
	 *
	 * @since   2.0.0
	 *
	 * @return void
	 */
	public function testTagSubscriberWithInvalidSubscriberID()
	{
		$result = $this->api->tag_subscriber(
			$_ENV['CONVERTKIT_API_TAG_ID'],
			12345
		);
		$this->assertInstanceOf(WP_Error::class, $result);
		$this->assertEquals($result->get_error_code(), $this->errorCode);
	}

	/**
	 * Test that remove_tag_from_subscriber() works.
	 *
	 * @since   1.0.0
	 *
	 * @return void
	 */
	public function testRemoveTagFromSubscriber()
	{
		// Create subscriber.
		$emailAddress = $this->generateEmailAddress();
		$this->api->create_subscriber($emailAddress);

		// Tag subscriber by email.
		$subscriber = $this->api->tag_subscriber_by_email(
			(int) $_ENV['CONVERTKIT_API_TAG_ID'],
			$emailAddress,
		);

		// Remove tag from subscriber.
		$result = $this->api->remove_tag_from_subscriber(
			(int) $_ENV['CONVERTKIT_API_TAG_ID'],
			$subscriber['subscriber']['id']
		);

		// Confirm that the subscriber no longer has the tag.
		$result = $this->api->get_subscriber_tags($subscriber['subscriber']['id']);
		$this->assertIsArray($result['tags']);
		$this->assertCount(0, $result['tags']);
	}

	/**
	 * Test that remove_tag_from_subscriber() returns a WP_Error when an invalid
	 * tag ID is specified.
	 *
	 * @since   1.0.0
	 *
	 * @return void
	 */
	public function testRemoveTagFromSubscriberWithInvalidTagID()
	{
		// Create subscriber.
		$emailAddress = $this->generateEmailAddress();
		$this->api->create_subscriber($emailAddress);

		// Tag subscriber by email.
		$subscriber = $this->api->tag_subscriber_by_email(
			(int) $_ENV['CONVERTKIT_API_TAG_ID'],
			$emailAddress,
		);

		// Remove tag from subscriber.
		$result = $this->api->remove_tag_from_subscriber(
			12345,
			$subscriber['subscriber']['id']
		);
		$this->assertInstanceOf(WP_Error::class, $result);
		$this->assertEquals($result->get_error_code(), $this->errorCode);
	}

	/**
	 * Test that remove_tag_from_subscriber() returns a WP_Error when an invalid
	 * subscriber ID is specified.
	 *
	 * @since   1.0.0
	 *
	 * @return void
	 */
	public function testRemoveTagFromSubscriberWithInvalidSubscriberID()
	{
		$result = $this->api->remove_tag_from_subscriber(
			(int) $_ENV['CONVERTKIT_API_TAG_ID'],
			12345
		);
		$this->assertInstanceOf(WP_Error::class, $result);
		$this->assertEquals($result->get_error_code(), $this->errorCode);
	}

	/**
	 * Test that remove_tag_from_subscriber() works.
	 *
	 * @since   1.0.0
	 *
	 * @return void
	 */
	public function testRemoveTagFromSubscriberByEmail()
	{
		// Create subscriber.
		$emailAddress = $this->generateEmailAddress();
		$this->api->create_subscriber($emailAddress);

		// Tag subscriber by email.
		$subscriber = $this->api->tag_subscriber_by_email(
			(int) $_ENV['CONVERTKIT_API_TAG_ID'],
			$emailAddress,
		);

		// Remove tag from subscriber.
		$result = $this->api->remove_tag_from_subscriber(
			(int) $_ENV['CONVERTKIT_API_TAG_ID'],
			$subscriber['subscriber']['id']
		);

		// Confirm that the subscriber no longer has the tag.
		$result = $this->api->get_subscriber_tags($subscriber['subscriber']['id']);
		$this->assertIsArray($result['tags']);
		$this->assertCount(0, $result['tags']);
	}

	/**
	 * Test that remove_tag_from_subscriber() returns a WP_Error when an invalid
	 * tag ID is specified.
	 *
	 * @since   1.0.0
	 *
	 * @return void
	 */
	public function testRemoveTagFromSubscriberByEmailWithInvalidTagID()
	{
		$result = $this->api->remove_tag_from_subscriber_by_email(
			12345,
			$_ENV['CONVERTKIT_API_SUBSCRIBER_EMAIL']
		);
		$this->assertInstanceOf(WP_Error::class, $result);
		$this->assertEquals($result->get_error_code(), $this->errorCode);
	}

	/**
	 * Test that remove_tag_from_subscriber() returns a WP_Error when an invalid
	 * email address is specified.
	 *
	 * @since   2.0.0
	 *
	 * @return void
	 */
	public function testRemoveTagFromSubscriberByEmailWithInvalidEmailAddress()
	{
		$result = $this->api->remove_tag_from_subscriber_by_email(
			$_ENV['CONVERTKIT_API_TAG_ID'],
			'not-an-email-address'
		);
		$this->assertInstanceOf(WP_Error::class, $result);
		$this->assertEquals($result->get_error_code(), $this->errorCode);
	}

	/**
	 * Test that get_tag_subscriptions() returns the expected data
	 * when a valid Tag ID is specified.
	 *
	 * @since   1.0.0
	 *
	 * @return void
	 */
	public function testGetTagSubscriptions()
	{
		$result = $this->api->get_tag_subscriptions( (int) $_ENV['CONVERTKIT_API_TAG_ID']);

		// Assert subscribers and pagination exist.
		$this->assertDataExists($result, 'subscribers');
		$this->assertPaginationExists($result);
	}

	/**
	 * Test that get_tag_subscriptions() returns the expected data
	 * when the total count is included.
	 *
	 * @since   2.0.0
	 *
	 * @return void
	 */
	public function testGetTagSubscriptionsWithTotalCount()
	{
		$result = $this->api->get_tag_subscriptions(
			(int) $_ENV['CONVERTKIT_API_TAG_ID'], // Tag ID.
			'active', // Subscriber state.
			null, // Created after.
			null, // Created before.
			null, // Tagged after.
			null, // Tagged before.
			true // Include total count.
		);

		// Assert subscribers and pagination exist.
		$this->assertDataExists($result, 'subscribers');
		$this->assertPaginationExists($result);

		// Assert total count is included.
		$this->assertArrayHasKey('total_count', $result['pagination']);
		$this->assertGreaterThan(0, $result['pagination']['total_count']);
	}

	/**
	 * Test that get_tag_subscriptions() returns the expected data
	 * when a valid Tag ID is specified and the subscription status
	 * is bounced.
	 *
	 * @since   1.0.0
	 *
	 * @return void
	 */
	public function testGetTagSubscriptionsWithBouncedSubscriberState()
	{
		$result = $this->api->get_tag_subscriptions(
			(int) $_ENV['CONVERTKIT_API_TAG_ID'], // Tag ID.
			'bounced' // Subscriber state.
		);

		// Assert subscribers and pagination exist.
		$this->assertDataExists($result, 'subscribers');
		$this->assertPaginationExists($result);

		// Check the correct subscribers were returned.
		$this->assertEquals($result['subscribers'][0]['state'], 'bounced');
	}


	/**
	 * Test that get_tag_subscriptions() returns the expected data
	 * when a valid Tag ID is specified and the added_after parameter
	 * is used.
	 *
	 * @since   2.0.0
	 *
	 * @return void
	 */
	public function testGetTagSubscriptionsWithTaggedAfterParam()
	{
		$date   = new \DateTime('2022-01-01');
		$result = $this->api->get_tag_subscriptions(
			(int) $_ENV['CONVERTKIT_API_TAG_ID'], // Tag ID.
			'active', // Subscriber state.
			null, // Created after.
			null, // Created before.
			$date // Tagged after.
		);

		// Assert subscribers and pagination exist.
		$this->assertDataExists($result, 'subscribers');
		$this->assertPaginationExists($result);

		// Check the correct subscribers were returned.
		$this->assertGreaterThanOrEqual(
			$date->format('Y-m-d'),
			date('Y-m-d', strtotime($result['subscribers'][0]['tagged_at']))
		);
	}

	/**
	 * Test that get_tag_subscriptions() returns the expected data
	 * when a valid Tag ID is specified and the tagged_before parameter
	 * is used.
	 *
	 * @since   2.0.0
	 *
	 * @return void
	 */
	public function testGetTagSubscriptionsWithTaggedBeforeParam()
	{
		$date   = new \DateTime('2024-01-01');
		$result = $this->api->get_tag_subscriptions(
			(int) $_ENV['CONVERTKIT_API_TAG_ID'], // Tag ID.
			'active', // Subscriber state.
			null, // Created after.
			null, // Created before.
			null, // Tagged after.
			$date // Tagged before.
		);

		// Assert subscribers and pagination exist.
		$this->assertDataExists($result, 'subscribers');
		$this->assertPaginationExists($result);

		// Check the correct subscribers were returned.
		$this->assertLessThanOrEqual(
			$date->format('Y-m-d'),
			date('Y-m-d', strtotime($result['subscribers'][0]['tagged_at']))
		);
	}

	/**
	 * Test that get_tag_subscriptions() returns the expected data
	 * when a valid Tag ID is specified and the created_after parameter
	 * is used.
	 *
	 * @since   2.0.0
	 *
	 * @return void
	 */
	public function testGetTagSubscriptionsWithCreatedAfterParam()
	{
		$date   = new \DateTime('2022-01-01');
		$result = $this->api->get_tag_subscriptions(
			(int) $_ENV['CONVERTKIT_API_TAG_ID'], // Tag ID.
			'active', // Subscriber state.
			$date // Created after.
		);

		// Assert subscribers and pagination exist.
		$this->assertDataExists($result, 'subscribers');
		$this->assertPaginationExists($result);

		// Check the correct subscribers were returned.
		$this->assertGreaterThanOrEqual(
			$date->format('Y-m-d'),
			date('Y-m-d', strtotime($result['subscribers'][0]['created_at']))
		);
	}

	/**
	 * Test that get_tag_subscriptions() returns the expected data
	 * when a valid Tag ID is specified and the created_before parameter
	 * is used.
	 *
	 * @since   2.0.0
	 *
	 * @return void
	 */
	public function testGetTagSubscriptionsWithCreatedBeforeParam()
	{
		$date   = new \DateTime('2024-01-01');
		$result = $this->api->get_tag_subscriptions(
			(int) $_ENV['CONVERTKIT_API_TAG_ID'], // Tag ID.
			'active', // Subscriber state.
			null, // Created after.
			$date // Created before.
		);

		// Assert subscribers and pagination exist.
		$this->assertDataExists($result, 'subscribers');
		$this->assertPaginationExists($result);

		// Check the correct subscribers were returned.
		$this->assertLessThanOrEqual(
			$date->format('Y-m-d'),
			date('Y-m-d', strtotime($result['subscribers'][0]['created_at']))
		);
	}

	/**
	 * Test that get_tag_subscriptions() returns the expected data
	 * when a valid Tag ID is specified and pagination parameters
	 * and per_page limits are specified.
	 *
	 * @since   1.0.0
	 *
	 * @return void
	 */
	public function testGetTagSubscriptionsPagination()
	{
		$result = $this->api->get_tag_subscriptions(
			(int) $_ENV['CONVERTKIT_API_TAG_ID'], // Tag ID.
			'active', // Subscriber state.
			null, // Created after.
			null, // Created before.
			null, // Tagged after.
			null, // Tagged before.
			false, // Include total count.
			'', // After cursor.
			'', // Before cursor.
			1 // Per page.
		);

		// Assert subscribers and pagination exist.
		$this->assertDataExists($result, 'subscribers');
		$this->assertPaginationExists($result);

		// Assert a single subscriber was returned.
		$this->assertCount(1, $result['subscribers']);

		// Assert has_previous_page and has_next_page are correct.
		$this->assertFalse($result['pagination']['has_previous_page']);
		$this->assertTrue($result['pagination']['has_next_page']);

		// Use pagination to fetch next page.
		$result = $this->api->get_tag_subscriptions(
			(int) $_ENV['CONVERTKIT_API_TAG_ID'], // Tag ID.
			'active', // Subscriber state.
			null, // Created after.
			null, // Created before.
			null, // Tagged after.
			null, // Tagged before.
			false, // Include total count.
			$result['pagination']['end_cursor'], // After cursor.
			'', // Before cursor.
			1 // Per page.
		);

		// Assert subscribers and pagination exist.
		$this->assertDataExists($result, 'subscribers');
		$this->assertPaginationExists($result);

		// Assert a single subscriber was returned.
		$this->assertCount(1, $result['subscribers']);

		// Assert has_previous_page and has_next_page are correct.
		$this->assertTrue($result['pagination']['has_previous_page']);
		$this->assertTrue($result['pagination']['has_next_page']);

		// Use pagination to fetch previous page.
		$result = $this->api->get_tag_subscriptions(
			(int) $_ENV['CONVERTKIT_API_TAG_ID'], // Tag ID.
			'active', // Subscriber state.
			null, // Created after.
			null, // Created before.
			null, // Tagged after.
			null, // Tagged before.
			false, // Include total count.
			'', // After cursor.
			$result['pagination']['start_cursor'], // Before cursor.
			1 // Per page.
		);

		// Assert subscribers and pagination exist.
		$this->assertDataExists($result, 'subscribers');
		$this->assertPaginationExists($result);
	}

	/**
	 * Test that get_tag_subscriptions() returns a WP_Error when
	 * an invalid Tag ID is specified.
	 *
	 * @since   1.0.0
	 *
	 * @return void
	 */
	public function testGetTagSubscriptionsWithInvalidTagID()
	{
		$result = $this->api->get_tag_subscriptions(12345);
		$this->assertInstanceOf(WP_Error::class, $result);
		$this->assertEquals($result->get_error_code(), $this->errorCode);
	}

	/**
	 * Test that get_subscribers() returns the expected data.
	 *
	 * @since   2.0.0
	 *
	 * @return void
	 */
	public function testGetSubscribers()
	{
		$result = $this->api->get_subscribers();

		// Assert subscribers and pagination exist.
		$this->assertDataExists($result, 'subscribers');
		$this->assertPaginationExists($result);
	}

	/**
	 * Test that get_subscribers() returns the expected data
	 * when the total count is included.
	 *
	 * @since   2.0.0
	 *
	 * @return void
	 */
	public function testGetSubscribersWithTotalCount()
	{
		$result = $this->api->get_subscribers(
			'active', // Subscriber state.
			'', // Email address.
			null, // Created after.
			null, // Created before.
			null, // Updated after.
			null, // Updated before.
			'id', // Sort field.
			'desc', // Sort order.
			true, // Include total count.
			'', // After cursor.
			'', // Before cursor.
			100 // Per page.
		);

		// Assert subscribers and pagination exist.
		$this->assertDataExists($result, 'subscribers');
		$this->assertPaginationExists($result);

		// Assert total count is included.
		$this->assertArrayHasKey('total_count', $result['pagination']);
		$this->assertGreaterThan(0, $result['pagination']['total_count']);
	}

	/**
	 * Test that get_subscribers() returns the expected data when
	 * searching by an email address.
	 *
	 * @since   2.0.0
	 *
	 * @return void
	 */
	public function testGetSubscribersByEmailAddress()
	{
		$result = $this->api->get_subscribers(
			'active', // Subscriber state.
			$_ENV['CONVERTKIT_API_SUBSCRIBER_EMAIL'], // Email address.
			null, // Created after.
			null, // Created before.
			null, // Updated after.
			null, // Updated before.
			'id', // Sort field.
			'desc', // Sort order.
			false, // Include total count.
			'', // After cursor.
			'', // Before cursor.
			100 // Per page.
		);

		// Assert subscribers and pagination exist.
		$this->assertDataExists($result, 'subscribers');
		$this->assertPaginationExists($result);

		// Assert correct subscriber returned.
		$this->assertEquals(
			$result['subscribers'][0]['email_address'],
			$_ENV['CONVERTKIT_API_SUBSCRIBER_EMAIL']
		);
	}

	/**
	 * Test that get_subscribers() returns the expected data
	 * when the subscription status is bounced.
	 *
	 * @since   1.0.0
	 *
	 * @return void
	 */
	public function testGetSubscribersWithBouncedSubscriberState()
	{
		$result = $this->api->get_subscribers(
			'bounced' // Subscriber state.
		);

		// Assert subscribers and pagination exist.
		$this->assertDataExists($result, 'subscribers');
		$this->assertPaginationExists($result);

		// Check the correct subscribers were returned.
		$this->assertEquals($result['subscribers'][0]['state'], 'bounced');
	}

	/**
	 * Test that get_subscribers() returns the expected data
	 * when the created_after parameter is used.
	 *
	 * @since   2.0.0
	 *
	 * @return void
	 */
	public function testGetSubscribersWithCreatedAfterParam()
	{
		$date   = new \DateTime('2022-01-01');
		$result = $this->api->get_subscribers(
			'active', // Subscriber state.
			'', // Email address.
			$date // Created after.
		);

		// Assert subscribers and pagination exist.
		$this->assertDataExists($result, 'subscribers');
		$this->assertPaginationExists($result);

		// Check the correct subscribers were returned.
		$this->assertGreaterThanOrEqual(
			$date->format('Y-m-d'),
			date('Y-m-d', strtotime($result['subscribers'][0]['created_at']))
		);
	}

	/**
	 * Test that get_subscribers() returns the expected data
	 * when the created_before parameter is used.
	 *
	 * @since   2.0.0
	 *
	 * @return void
	 */
	public function testGetSubscribersWithCreatedBeforeParam()
	{
		$date   = new \DateTime('2024-01-01');
		$result = $this->api->get_subscribers(
			'active', // Subscriber state.
			'', // Email address.
			null, // Created after.
			$date // Created before.
		);

		// Assert subscribers and pagination exist.
		$this->assertDataExists($result, 'subscribers');
		$this->assertPaginationExists($result);

		// Check the correct subscribers were returned.
		$this->assertLessThanOrEqual(
			$date->format('Y-m-d'),
			date('Y-m-d', strtotime($result['subscribers'][0]['created_at']))
		);
	}

	/**
	 * Test that get_subscribers() returns the expected data
	 * when the updated_after parameter is used.
	 *
	 * @since   2.0.0
	 *
	 * @return void
	 */
	public function testGetSubscribersWithUpdatedAfterParam()
	{
		$date   = new \DateTime('2022-01-01');
		$result = $this->api->get_subscribers(
			'active', // Subscriber state.
			'', // Email address.
			null, // Created after.
			null, // Created before.
			$date // Updated after.
		);

		// Assert subscribers and pagination exist.
		$this->assertDataExists($result, 'subscribers');
		$this->assertPaginationExists($result);
	}

	/**
	 * Test that get_subscribers() returns the expected data
	 * when the updated_before parameter is used.
	 *
	 * @since   2.0.0
	 *
	 * @return void
	 */
	public function testGetSubscribersWithUpdatedBeforeParam()
	{
		$date   = new \DateTime('2024-01-01');
		$result = $this->api->get_subscribers(
			'active', // Subscriber state.
			'', // Email address.
			null, // Created after.
			null, // Created before.
			null, // Updated after.
			$date // Updated before.
		);

		// Assert subscribers and pagination exist.
		$this->assertDataExists($result, 'subscribers');
		$this->assertPaginationExists($result);
	}

	/**
	 * Test that get_subscribers() returns the expected data
	 * when the sort_field parameter is used.
	 *
	 * @since   2.0.0
	 *
	 * @return void
	 */
	public function testGetSubscribersWithSortFieldParam()
	{
		$result = $this->api->get_subscribers(
			'active', // Subscriber state.
			'', // Email address.
			null, // Created after.
			null, // Created before.
			null, // Updated after.
			null, // Updated before.
			'id' // Sort field.
		);

		// Assert subscribers and pagination exist.
		$this->assertDataExists($result, 'subscribers');
		$this->assertPaginationExists($result);

		// Assert sorting is honored by ID in descending (default) order.
		$this->assertLessThanOrEqual(
			$result['subscribers'][0]['id'],
			$result['subscribers'][1]['id']
		);
	}

	/**
	 * Test that get_subscribers() returns the expected data
	 * when the sort_order parameter is used.
	 *
	 * @since   2.0.0
	 *
	 * @return void
	 */
	public function testGetSubscribersWithSortOrderParam()
	{
		$result = $this->api->get_subscribers(
			'active', // Subscriber state.
			'', // Email address.
			null, // Created after.
			null, // Created before.
			null, // Updated after.
			null, // Updated before.
			'id', // Sort field.
			'asc' // Sort order.
		);

		// Assert subscribers and pagination exist.
		$this->assertDataExists($result, 'subscribers');
		$this->assertPaginationExists($result);

		// Assert sorting is honored by ID (default) in ascending order.
		$this->assertGreaterThanOrEqual(
			$result['subscribers'][0]['id'],
			$result['subscribers'][1]['id']
		);
	}

	/**
	 * Test that get_subscribers() returns the expected data
	 * when pagination parameters and per_page limits are specified.
	 *
	 * @since   2.0.0
	 *
	 * @return void
	 */
	public function testGetSubscribersPagination()
	{
		// Return one broadcast.
		$result = $this->api->get_subscribers(
			'active', // Subscriber state.
			'', // Email address.
			null, // Created after.
			null, // Created before.
			null, // Updated after.
			null, // Updated before.
			'id', // Sort field.
			'desc', // Sort order.
			false, // Include total count.
			'', // After cursor.
			'', // Before cursor.
			1 // Per page.
		);

		// Assert subscribers and pagination exist.
		$this->assertDataExists($result, 'subscribers');
		$this->assertPaginationExists($result);

		// Assert a single subscriber was returned.
		$this->assertCount(1, $result['subscribers']);

		// Assert has_previous_page and has_next_page are correct.
		$this->assertFalse($result['pagination']['has_previous_page']);
		$this->assertTrue($result['pagination']['has_next_page']);

		// Use pagination to fetch next page.
		$result = $this->api->get_subscribers(
			'active', // Subscriber state.
			'', // Email address.
			null, // Created after.
			null, // Created before.
			null, // Updated after.
			null, // Updated before.
			'id', // Sort field.
			'desc', // Sort order.
			false, // Include total count.
			$result['pagination']['end_cursor'], // After cursor.
			'', // Before cursor.
			1 // Per page.
		);

		// Assert subscribers and pagination exist.
		$this->assertDataExists($result, 'subscribers');
		$this->assertPaginationExists($result);

		// Assert a single broadcast was returned.
		$this->assertCount(1, $result['subscribers']);

		// Assert has_previous_page and has_next_page are correct.
		$this->assertTrue($result['pagination']['has_previous_page']);
		$this->assertTrue($result['pagination']['has_next_page']);

		// Use pagination to fetch previous page.
		$result = $this->api->get_subscribers(
			'active', // Subscriber state.
			'', // Email address.
			null, // Created after.
			null, // Created before.
			null, // Updated after.
			null, // Updated before.
			'id', // Sort field.
			'desc', // Sort order.
			false, // Include total count.
			'', // After cursor.
			$result['pagination']['start_cursor'], // Before cursor.
			1 // Per page.
		);

		// Assert subscribers and pagination exist.
		$this->assertDataExists($result, 'subscribers');
		$this->assertPaginationExists($result);
	}

	/**
	 * Test that get_subscribers() returns a WP_Error when an invalid
	 * email address is specified.
	 *
	 * @since   2.0.0
	 *
	 * @return void
	 */
	public function testGetSubscribersWithInvalidEmailAddress()
	{
		$result = $this->api->get_subscribers(
			'active', // Subscriber state.
			'not-an-email-address' // Email address.
		);
		$this->assertInstanceOf(WP_Error::class, $result);
	}

	/**
	 * Test that get_subscribers() returns a WP_Error when an invalid
	 * subscriber state is specified.
	 *
	 * @since   2.0.0
	 *
	 * @return void
	 */
	public function testGetSubscribersWithInvalidSubscriberState()
	{
		$result = $this->api->get_subscribers(
			'not-a-valid-state' // Subscriber state.
		);
		$this->assertInstanceOf(WP_Error::class, $result);
	}

	/**
	 * Test that get_subscribers() returns a WP_Error when an invalid
	 * sort field is specified.
	 *
	 * @since   2.0.0
	 *
	 * @return void
	 */
	public function testGetSubscribersWithInvalidSortFieldParam()
	{
		$result = $this->api->get_subscribers(
			'active', // Subscriber state.
			'', // Email address.
			null, // Created after.
			null, // Created before.
			null, // Updated after.
			null, // Updated before.
			'not-a-valid-sort-field' // Sort field.
		);
		$this->assertInstanceOf(WP_Error::class, $result);
	}

	/**
	 * Test that get_subscribers() returns a WP_Error when an invalid
	 * sort order is specified.
	 *
	 * @since   2.0.0
	 *
	 * @return void
	 */
	public function testGetSubscribersWithInvalidSortOrderParam()
	{
		$result = $this->api->get_subscribers(
			'active', // Subscriber state.
			'', // Email address.
			null, // Created after.
			null, // Created before.
			null, // Updated after.
			null, // Updated before.
			'id', // Sort field.
			'not-a-valid-sort-order' // Sort order.
		);
		$this->assertInstanceOf(WP_Error::class, $result);
	}

	/**
	 * Test that get_subscribers() returns a WP_Error when an invalid
	 * pagination parameters are specified.
	 *
	 * @since   2.0.0
	 *
	 * @return void
	 */
	public function testGetSubscribersWithInvalidPagination()
	{
		$result = $this->api->get_subscribers(
			'active', // Subscriber state.
			'', // Email address.
			null, // Created after.
			null, // Created before.
			null, // Updated after.
			null, // Updated before.
			'id', // Sort field.
			'desc', // Sort order.
			false, // Include total count.
			'not-a-valid-cursor' // After cursor.
		);
		$this->assertInstanceOf(WP_Error::class, $result);
	}

	/**
	 * Test that create_subscriber() returns the expected data.
	 *
	 * @since   2.0.0
	 *
	 * @return void
	 */
	public function testCreateSubscriber()
	{
		$emailAddress = $this->generateEmailAddress();
		$result       = $this->api->create_subscriber(
			$emailAddress
		);
		$this->assertNotInstanceOf(WP_Error::class, $result);
		$this->assertIsArray($result);

		// Set subscriber_id to ensure subscriber is unsubscribed after test.
		$this->subscriber_ids[] = $result['subscriber']['id'];

		// Assert subscriber exists with correct data.
		$this->assertEquals($result['subscriber']['email_address'], $emailAddress);
	}

	/**
	 * Test that create_subscriber() returns the expected data
	 * when a first name is included.
	 *
	 * @since   2.0.0
	 *
	 * @return void
	 */
	public function testCreateSubscriberWithFirstName()
	{
		$firstName    = 'FirstName';
		$emailAddress = $this->generateEmailAddress();
		$result       = $this->api->create_subscriber(
			$emailAddress,
			$firstName
		);
		$this->assertNotInstanceOf(WP_Error::class, $result);
		$this->assertIsArray($result);

		// Set subscriber_id to ensure subscriber is unsubscribed after test.
		$this->subscriber_ids[] = $result['subscriber']['id'];

		// Assert subscriber exists with correct data.
		$this->assertEquals($result['subscriber']['email_address'], $emailAddress);
		$this->assertEquals($result['subscriber']['first_name'], $firstName);
	}

	/**
	 * Test that create_subscriber() returns the expected data
	 * when a subscriber state is included.
	 *
	 * @since   2.0.0
	 *
	 * @return void
	 */
	public function testCreateSubscriberWithSubscriberState()
	{
		$subscriberState = 'cancelled';
		$emailAddress    = $this->generateEmailAddress();
		$result          = $this->api->create_subscriber(
			$emailAddress,
			'', // First name.
			$subscriberState
		);
		$this->assertNotInstanceOf(WP_Error::class, $result);
		$this->assertIsArray($result);

		// Set subscriber_id to ensure subscriber is unsubscribed after test.
		$this->subscriber_ids[] = $result['subscriber']['id'];

		// Assert subscriber exists with correct data.
		$this->assertEquals($result['subscriber']['email_address'], $emailAddress);
		$this->assertEquals($result['subscriber']['state'], $subscriberState);
	}

	/**
	 * Test that create_subscriber() returns the expected data
	 * when custom field data is included.
	 *
	 * @since   2.0.0
	 *
	 * @return void
	 */
	public function testCreateSubscriberWithCustomFields()
	{
		$lastName     = 'LastName';
		$emailAddress = $this->generateEmailAddress();
		$result       = $this->api->create_subscriber(
			$emailAddress,
			'', // First name.
			'', // Subscriber state.
			[
				'last_name' => $lastName,
			]
		);
		$this->assertNotInstanceOf(WP_Error::class, $result);
		$this->assertIsArray($result);

		// Set subscriber_id to ensure subscriber is unsubscribed after test.
		$this->subscriber_ids[] = $result['subscriber']['id'];

		// Assert subscriber exists with correct data.
		$this->assertEquals($result['subscriber']['email_address'], $emailAddress);
		$this->assertEquals($result['subscriber']['fields']['last_name'], $lastName);
	}

	/**
	 * Test that create_subscriber() returns a WP_Error when an invalid
	 * email address is specified.
	 *
	 * @since   2.0.0
	 *
	 * @return void
	 */
	public function testCreateSubscriberWithInvalidEmailAddress()
	{
		$result = $this->api->create_subscriber(
			'not-an-email-address'
		);
		$this->assertInstanceOf(WP_Error::class, $result);
		$this->assertEquals($result->get_error_code(), $this->errorCode);
	}

	/**
	 * Test that create_subscriber() returns a WP_Error when an invalid
	 * subscriber state is specified.
	 *
	 * @since   2.0.0
	 *
	 * @return void
	 */
	public function testCreateSubscriberWithInvalidSubscriberState()
	{
		$emailAddress = $this->generateEmailAddress();
		$result       = $this->api->create_subscriber(
			$emailAddress,
			'', // First name.
			'not-a-valid-state'
		);
		$this->assertInstanceOf(WP_Error::class, $result);
		$this->assertEquals($result->get_error_code(), $this->errorCode);
	}

	/**
	 * Test that create_subscriber() returns the expected data
	 * when an invalid custom field is included.
	 *
	 * @since   2.0.0
	 *
	 * @return void
	 */
	public function testCreateSubscriberWithInvalidCustomFields()
	{
		$emailAddress = $this->generateEmailAddress();
		$result       = $this->api->create_subscriber(
			$emailAddress,
			'', // First name.
			'', // Subscriber state.
			[
				'not_a_custom_field' => 'value',
			]
		);
		$this->assertNotInstanceOf(WP_Error::class, $result);
		$this->assertIsArray($result);

		// Set subscriber_id to ensure subscriber is unsubscribed after test.
		$this->subscriber_ids[] = $result['subscriber']['id'];

		// Assert subscriber exists with correct data.
		$this->assertEquals($result['subscriber']['email_address'], $emailAddress);
	}

	/**
	 * Test that create_subscribers() returns the expected data.
	 *
	 * @since   2.0.0
	 *
	 * @return void
	 */
	public function testCreateSubscribers()
	{
		$subscribers = [
			[
				'email_address' => str_replace('@kit.com', '-1@kit.com', $this->generateEmailAddress()),
			],
			[
				'email_address' => str_replace('@kit.com', '-2@kit.com', $this->generateEmailAddress()),
			],
		];
		$result      = $this->api->create_subscribers($subscribers);
		$this->assertNotInstanceOf(WP_Error::class, $result);
		$this->assertIsArray($result);

		// Set subscriber_id to ensure subscriber is unsubscribed after test.
		foreach ($result['subscribers'] as $i => $subscriber) {
			$this->subscriber_ids[] = $subscriber['id'];
		}

		// Assert no failures.
		$this->assertCount(0, $result['failures']);

		// Assert subscribers exists with correct data.
		foreach ($result['subscribers'] as $i => $subscriber) {
			$this->assertEquals($subscriber['email_address'], $subscribers[ $i ]['email_address']);
		}
	}

	/**
	 * Test that create_subscribers() returns a WP_Error when no data is specified.
	 *
	 * @since   2.0.0
	 *
	 * @return void
	 */
	public function testCreateSubscribersWithBlankData()
	{
		$result = $this->api->create_subscribers(
			[
				[],
			]
		);
		$this->assertInstanceOf(WP_Error::class, $result);
		$this->assertEquals($result->get_error_code(), $this->errorCode);
	}

	/**
	 * Test that create_subscribers() returns the expected data when invalid email addresses
	 * are specified.
	 *
	 * @since   2.0.0
	 *
	 * @return void
	 */
	public function testCreateSubscribersWithInvalidEmailAddresses()
	{
		$subscribers = [
			[
				'email_address' => 'not-an-email-address',
			],
			[
				'email_address' => 'not-an-email-address-again',
			],
		];
		$result      = $this->api->create_subscribers($subscribers);

		// Assert no subscribers were added.
		$this->assertCount(0, $result['subscribers']);
		$this->assertCount(2, $result['failures']);
	}

	/**
	 * Test that get_subscriber_id() returns the expected data.
	 *
	 * @since   1.0.0
	 *
	 * @return void
	 */
	public function testGetSubscriberID()
	{
		$subscriber_id = $this->api->get_subscriber_id($_ENV['CONVERTKIT_API_SUBSCRIBER_EMAIL']);
		$this->assertIsInt($subscriber_id);
		$this->assertEquals($subscriber_id, (int) $_ENV['CONVERTKIT_API_SUBSCRIBER_ID']);
	}

	/**
	 * Test that get_subscriber_id() returns a WP_Error when an invalid
	 * email address is specified.
	 *
	 * @since   1.0.0
	 *
	 * @return void
	 */
	public function testGetSubscriberIDWithInvalidEmailAddress()
	{
		$result = $this->api->get_subscriber_id('not-an-email-address');
		$this->assertInstanceOf(WP_Error::class, $result);
		$this->assertEquals($result->get_error_code(), $this->errorCode);
	}

	/**
	 * Test that get_subscriber_id() return false when no subscriber found
	 * matching the given email address.
	 *
	 * @since   1.0.0
	 *
	 * @return void
	 */
	public function testGetSubscriberIDWithNotSubscribedEmailAddress()
	{
		$result = $this->api->get_subscriber_id('not-a-subscriber@test.com');
		$this->assertFalse($result);
	}

	/**
	 * Test that get_subscriber() returns the expected data.
	 *
	 * @since   1.0.0
	 *
	 * @return void
	 */
	public function testGetSubscriber()
	{
		$result = $this->api->get_subscriber( (int) $_ENV['CONVERTKIT_API_SUBSCRIBER_ID']);

		// Assert subscriber exists with correct data.
		$this->assertEquals($result['subscriber']['id'], $_ENV['CONVERTKIT_API_SUBSCRIBER_ID']);
		$this->assertEquals($result['subscriber']['email_address'], $_ENV['CONVERTKIT_API_SUBSCRIBER_EMAIL']);
	}

	/**
	 * Test that get_subscriber() returns a WP_Error when an invalid
	 * subscriber ID is specified.
	 *
	 * @since   1.0.0
	 *
	 * @return void
	 */
	public function testGetSubscriberWithInvalidSubscriberID()
	{
		$result = $this->api->get_subscriber(12345);
		$this->assertInstanceOf(WP_Error::class, $result);
		$this->assertEquals($result->get_error_code(), $this->errorCode);
	}

	/**
	 * Test that update_subscriber() works when no changes are made.
	 *
	 * @since   1.0.0
	 *
	 * @return void
	 */
	public function testUpdateSubscriberWithNoChanges()
	{
		$result = $this->api->update_subscriber($_ENV['CONVERTKIT_API_SUBSCRIBER_ID']);

		// Assert subscriber exists with correct data.
		$this->assertEquals($result['subscriber']['id'], $_ENV['CONVERTKIT_API_SUBSCRIBER_ID']);
		$this->assertEquals($result['subscriber']['email_address'], $_ENV['CONVERTKIT_API_SUBSCRIBER_EMAIL']);
	}

	/**
	 * Test that update_subscriber() works when updating the subscriber's first name.
	 *
	 * @since   1.0.0
	 *
	 * @return void
	 */
	public function testUpdateSubscriberFirstName()
	{
		// Add a subscriber.
		$firstName    = 'FirstName';
		$emailAddress = $this->generateEmailAddress();
		$result       = $this->api->create_subscriber(
			$emailAddress
		);
		$this->assertNotInstanceOf(WP_Error::class, $result);
		$this->assertIsArray($result);

		// Set subscriber_id to ensure subscriber is unsubscribed after test.
		$this->subscriber_ids[] = $result['subscriber']['id'];

		// Assert subscriber created with no first name.
		$this->assertNull($result['subscriber']['first_name']);

		// Get subscriber ID.
		$subscriberID = $result['subscriber']['id'];

		// Update subscriber's first name.
		$result = $this->api->update_subscriber(
			$subscriberID,
			$firstName
		);

		// Assert changes were made.
		$this->assertEquals($result['subscriber']['id'], $subscriberID);
		$this->assertEquals($result['subscriber']['first_name'], $firstName);
	}

	/**
	 * Test that update_subscriber() works when updating the subscriber's email address.
	 *
	 * @since   1.0.0
	 *
	 * @return void
	 */
	public function testUpdateSubscriberEmailAddress()
	{
		// Add a subscriber.
		$emailAddress = $this->generateEmailAddress();
		$result       = $this->api->create_subscriber(
			$emailAddress
		);
		$this->assertNotInstanceOf(WP_Error::class, $result);
		$this->assertIsArray($result);

		// Set subscriber_id to ensure subscriber is unsubscribed after test.
		$this->subscriber_ids[] = $result['subscriber']['id'];

		// Assert subscriber created.
		$this->assertEquals($result['subscriber']['email_address'], $emailAddress);

		// Get subscriber ID.
		$subscriberID = $result['subscriber']['id'];

		// Update subscriber's email address.
		$newEmail = $this->generateEmailAddress();
		$result   = $this->api->update_subscriber(
			$subscriberID,
			'', // First name.
			$newEmail
		);

		// Assert changes were made.
		$this->assertEquals($result['subscriber']['id'], $subscriberID);
		$this->assertEquals($result['subscriber']['email_address'], $newEmail);
	}

	/**
	 * Test that update_subscriber() works when updating the subscriber's custom fields.
	 *
	 * @since   1.0.0
	 *
	 * @return void
	 */
	public function testUpdateSubscriberCustomFields()
	{
		// Add a subscriber.
		$lastName     = 'LastName';
		$emailAddress = $this->generateEmailAddress();
		$result       = $this->api->create_subscriber(
			$emailAddress
		);
		$this->assertNotInstanceOf(WP_Error::class, $result);
		$this->assertIsArray($result);

		// Set subscriber_id to ensure subscriber is unsubscribed after test.
		$this->subscriber_ids[] = $result['subscriber']['id'];

		// Assert subscriber created.
		$this->assertEquals($result['subscriber']['email_address'], $emailAddress);

		// Get subscriber ID.
		$subscriberID = $result['subscriber']['id'];

		// Update subscriber's custom fields.
		$result = $this->api->update_subscriber(
			$subscriberID,
			'', // First name.
			'', // Email address.
			[
				'last_name' => $lastName,
			]
		);

		// Assert changes were made.
		$this->assertEquals($result['subscriber']['id'], $subscriberID);
		$this->assertEquals($result['subscriber']['fields']['last_name'], $lastName);
	}

	/**
	 * Test that update_subscriber() returns a WP_Error when an invalid
	 * subscriber ID is specified.
	 *
	 * @since   1.0.0
	 *
	 * @return void
	 */
	public function testUpdateSubscriberWithInvalidSubscriberID()
	{
		$result = $this->api->update_subscriber(12345);
		$this->assertInstanceOf(WP_Error::class, $result);
	}

	/**
	 * Test that unsubscribe_by_email() works with a valid subscriber email address.
	 *
	 * @since   1.0.0
	 *
	 * @return void
	 */
	public function testUnsubscribeByEmail()
	{
		// Avoid a rate limit due to previous tests.
		sleep(2);

		// Add a subscriber.
		$emailAddress = $this->generateEmailAddress();
		$result       = $this->api->create_subscriber(
			$emailAddress
		);
		$this->assertNotInstanceOf(WP_Error::class, $result);
		$this->assertIsArray($result);

		// Unsubscribe.
		$this->assertNull($this->api->unsubscribe_by_email($emailAddress));
	}

	/**
	 * Test that unsubscribe_by_email() returns a WP_Error when an email
	 * address is specified that is not subscribed.
	 *
	 * @since   1.0.0
	 *
	 * @return void
	 */
	public function testUnsubscribeByEmailWithNotSubscribedEmailAddress()
	{
		$result = $this->api->unsubscribe_by_email('not-subscribed@kit.com');
		$this->assertInstanceOf(WP_Error::class, $result);
	}

	/**
	 * Test that unsubscribe_by_email() returns a WP_Error when an invalid
	 * email address is specified.
	 *
	 * @since   1.0.0
	 *
	 * @return void
	 */
	public function testUnsubscribeByEmailWithInvalidEmailAddress()
	{
		$result = $this->api->unsubscribe_by_email('invalid-email');
		$this->assertInstanceOf(WP_Error::class, $result);
	}

	/**
	 * Test that unsubscribe() works with a valid subscriber ID.
	 *
	 * @since   2.0.0
	 *
	 * @return void
	 */
	public function testUnsubscribe()
	{
		// Add a subscriber.
		$emailAddress = $this->generateEmailAddress();
		$result       = $this->api->create_subscriber(
			$emailAddress
		);
		$this->assertNotInstanceOf(WP_Error::class, $result);
		$this->assertIsArray($result);

		// Unsubscribe.
		$this->assertNull($this->api->unsubscribe($result['subscriber']['id']));
	}

	/**
	 * Test that unsubscribe() returns a WP_Error when an invalid
	 * subscriber ID is specified.
	 *
	 * @since   2.0.0
	 *
	 * @return void
	 */
	public function testUnsubscribeWithInvalidSubscriberID()
	{
		$result = $this->api->unsubscribe(12345);
		$this->assertInstanceOf(WP_Error::class, $result);
	}

	/**
	 * Test that get_subscriber_tags() returns the expected data.
	 *
	 * @since   1.0.0
	 *
	 * @return void
	 */
	public function testGetSubscriberTags()
	{
		$result = $this->api->get_subscriber_tags( (int) $_ENV['CONVERTKIT_API_SUBSCRIBER_ID']);

		// Assert tags and pagination exist.
		$this->assertDataExists($result, 'tags');
		$this->assertPaginationExists($result);
	}

	/**
	 * Test that get_subscriber_tags() returns the expected data
	 * when the total count is included.
	 *
	 * @since   2.0.0
	 *
	 * @return void
	 */
	public function testGetSubscriberTagsWithTotalCount()
	{
		$result = $this->api->get_subscriber_tags(
			(int) $_ENV['CONVERTKIT_API_SUBSCRIBER_ID'],
			true
		);

		// Assert tags and pagination exist.
		$this->assertDataExists($result, 'tags');
		$this->assertPaginationExists($result);

		// Assert total count is included.
		$this->assertArrayHasKey('total_count', $result['pagination']);
		$this->assertGreaterThan(0, $result['pagination']['total_count']);
	}

	/**
	 * Test that get_subscriber_tags() returns a WP_Error when an invalid
	 * subscriber ID is specified.
	 *
	 * @since   1.0.0
	 *
	 * @return void
	 */
	public function testGetSubscriberTagsWithInvalidSubscriberID()
	{
		$result = $this->api->get_subscriber_tags(12345);
		$this->assertInstanceOf(WP_Error::class, $result);
	}

	/**
	 * Test that get_subscriber_tags() returns the expected data
	 * when a valid Subscriber ID is specified and pagination parameters
	 * and per_page limits are specified.
	 *
	 * @since   2.0.0
	 *
	 * @return void
	 */
	public function testGetSubscriberTagsPagination()
	{
		$result = $this->api->get_subscriber_tags(
			(int) $_ENV['CONVERTKIT_API_SUBSCRIBER_ID'],
			false, // Include total count.
			'', // After cursor.
			'', // Before cursor.
			1 // Per page.
		);

		// Assert tags and pagination exist.
		$this->assertDataExists($result, 'tags');
		$this->assertPaginationExists($result);

		// Assert a single tag was returned.
		$this->assertCount(1, $result['tags']);

		// Assert has_previous_page and has_next_page are correct.
		$this->assertFalse($result['pagination']['has_previous_page']);
		$this->assertTrue($result['pagination']['has_next_page']);

		// Use pagination to fetch next page.
		$result = $this->api->get_subscriber_tags(
			(int) $_ENV['CONVERTKIT_API_SUBSCRIBER_ID'],
			false, // Include total count.
			$result['pagination']['end_cursor'], // After cursor.
			'', // Before cursor.
			1 // Per page.
		);

		// Assert tags and pagination exist.
		$this->assertDataExists($result, 'tags');
		$this->assertPaginationExists($result);

		// Assert a single tag was returned.
		$this->assertCount(1, $result['tags']);

		// Assert has_previous_page and has_next_page are correct.
		$this->assertTrue($result['pagination']['has_previous_page']);
		$this->assertTrue($result['pagination']['has_next_page']);

		// Use pagination to fetch previous page.
		$result = $this->api->get_subscriber_tags(
			(int) $_ENV['CONVERTKIT_API_SUBSCRIBER_ID'],
			false, // Include total count.
			'', // After cursor.
			$result['pagination']['start_cursor'], // Before cursor.
			1 // Per page.
		);

		// Assert tags and pagination exist.
		$this->assertDataExists($result, 'tags');
		$this->assertPaginationExists($result);

		// Assert a single tag was returned.
		$this->assertCount(1, $result['tags']);
	}

	/**
	 * Test that get_email_templates() returns the expected data.
	 *
	 * @since   2.0.0
	 *
	 * @return void
	 */
	public function testGetEmailTemplates()
	{
		$result = $this->api->get_email_templates();

		// Assert email templates and pagination exist.
		$this->assertDataExists($result, 'email_templates');
		$this->assertPaginationExists($result);
	}

	/**
	 * Test that get_email_templates() returns the expected data
	 * when the total count is included.
	 *
	 * @since   1.0.0
	 *
	 * @return void
	 */
	public function testGetEmailTemplatesWithTotalCount()
	{
		$result = $this->api->get_email_templates(true);

		// Assert email templates and pagination exist.
		$this->assertDataExists($result, 'email_templates');
		$this->assertPaginationExists($result);

		// Assert total count is included.
		$this->assertArrayHasKey('total_count', $result['pagination']);
		$this->assertGreaterThan(0, $result['pagination']['total_count']);
	}

	/**
	 * Test that get_email_templates() returns the expected data
	 * when pagination parameters and per_page limits are specified.
	 *
	 * @since   2.0.0
	 *
	 * @return void
	 */
	public function testGetEmailTemplatesPagination()
	{
		// Return one broadcast.
		$result = $this->api->get_email_templates(false, '', '', 1);

		// Assert email templates and pagination exist.
		$this->assertDataExists($result, 'email_templates');
		$this->assertPaginationExists($result);

		// Assert a single email template was returned.
		$this->assertCount(1, $result['email_templates']);

		// Assert has_previous_page and has_next_page are correct.
		$this->assertFalse($result['pagination']['has_previous_page']);
		$this->assertTrue($result['pagination']['has_next_page']);

		// Use pagination to fetch next page.
		$result = $this->api->get_email_templates(false, $result['pagination']['end_cursor'], '', 1);

		// Assert email templates and pagination exist.
		$this->assertDataExists($result, 'email_templates');
		$this->assertPaginationExists($result);

		// Assert a single email template was returned.
		$this->assertCount(1, $result['email_templates']);

		// Assert has_previous_page and has_next_page are correct.
		$this->assertTrue($result['pagination']['has_previous_page']);
		$this->assertTrue($result['pagination']['has_next_page']);

		// Use pagination to fetch previous page.
		$result = $this->api->get_email_templates(false, '', $result['pagination']['start_cursor'], 1);

		// Assert email templates and pagination exist.
		$this->assertDataExists($result, 'email_templates');
		$this->assertPaginationExists($result);

		// Assert a single email template was returned.
		$this->assertCount(1, $result['email_templates']);

		// Assert has_previous_page and has_next_page are correct.
		$this->assertFalse($result['pagination']['has_previous_page']);
		$this->assertTrue($result['pagination']['has_next_page']);
	}

	/**
	 * Test that get_broadcasts() returns the expected data
	 * when pagination parameters and per_page limits are specified.
	 *
	 * @since   2.0.0
	 *
	 * @return void
	 */
	public function testGetBroadcastsPagination()
	{
		// Return one broadcast.
		$result = $this->api->get_broadcasts(false, '', '', 1);

		// Assert broadcasts and pagination exist.
		$this->assertDataExists($result, 'broadcasts');
		$this->assertPaginationExists($result);

		// Assert a single broadcast was returned.
		$this->assertCount(1, $result['broadcasts']);

		// Assert has_previous_page and has_next_page are correct.
		$this->assertFalse($result['pagination']['has_previous_page']);
		$this->assertTrue($result['pagination']['has_next_page']);

		// Use pagination to fetch next page.
		$result = $this->api->get_broadcasts(false, $result['pagination']['end_cursor'], '', 1);

		// Assert broadcasts and pagination exist.
		$this->assertDataExists($result, 'broadcasts');
		$this->assertPaginationExists($result);

		// Assert a single broadcast was returned.
		$this->assertCount(1, $result['broadcasts']);

		// Assert has_previous_page and has_next_page are correct.
		$this->assertTrue($result['pagination']['has_previous_page']);
		$this->assertTrue($result['pagination']['has_next_page']);

		// Use pagination to fetch previous page.
		$result = $this->api->get_broadcasts(false, '', $result['pagination']['start_cursor'], 1);

		// Assert broadcasts and pagination exist.
		$this->assertDataExists($result, 'broadcasts');
		$this->assertPaginationExists($result);

		// Assert a single broadcast was returned.
		$this->assertCount(1, $result['broadcasts']);

		// Assert has_previous_page and has_next_page are correct.
		$this->assertFalse($result['pagination']['has_previous_page']);
		$this->assertTrue($result['pagination']['has_next_page']);
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
			'Test Subject',
			'Test Content',
			'Test Broadcast from WordPress Libraries',
		);
		$this->assertNotInstanceOf(WP_Error::class, $result);
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
			$broadcastID,
			'New Test Subject',
			'New Test Content',
			'New Test Broadcast from WordPress Libraries'
		);
		$this->assertNotInstanceOf(WP_Error::class, $result);
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
		$this->assertNotInstanceOf(WP_Error::class, $result);
	}

	/**
	 * Test that create_broadcast() works when specifying valid published_at and send_at values.
	 *
	 * @since   2.0.0
	 *
	 * @return void
	 */
	public function testCreatePublicBroadcastWithValidDates()
	{
		// Create DateTime object.
		$publishedAt = new DateTime('now');
		$publishedAt->modify('+7 days');
		$sendAt = new DateTime('now');
		$sendAt->modify('+14 days');

		// Create broadcast first.
		$result = $this->api->create_broadcast(
			'Test Subject',
			'Test Content',
			'Test Broadcast from WordPress Libraries',
			true,
			$publishedAt,
			$sendAt
		);
		$this->assertNotInstanceOf(WP_Error::class, $result);
		$this->assertIsArray($result);

		// Store Broadcast ID.
		$broadcastID = $result['broadcast']['id'];

		// Set broadcast_id to ensure broadcast is deleted after test.
		$this->broadcast_ids[] = $broadcastID;

		// Confirm the Broadcast saved.
		$this->assertArrayHasKey('id', $result['broadcast']);
		$this->assertEquals('Test Subject', $result['broadcast']['subject']);
		$this->assertEquals('Test Content', $result['broadcast']['content']);
		$this->assertEquals('Test Broadcast from WordPress Libraries', $result['broadcast']['description']);
		$this->assertEquals(
			$publishedAt->format('Y-m-d') . 'T' . $publishedAt->format('H:i:s') . 'Z',
			$result['broadcast']['published_at']
		);
		$this->assertEquals(
			$sendAt->format('Y-m-d') . 'T' . $sendAt->format('H:i:s') . 'Z',
			$result['broadcast']['send_at']
		);
	}

	/**
	 * Test that get_broadcast() returns the expected data.
	 *
	 * @since   1.0.0
	 *
	 * @return void
	 */
	public function testGetBroadcast()
	{
		$result = $this->api->get_broadcast($_ENV['CONVERTKIT_API_BROADCAST_ID']);
		$this->assertNotInstanceOf(WP_Error::class, $result);
		$this->assertIsArray($result);
		$this->assertArrayHasKey('id', $result['broadcast']);
		$this->assertEquals($result['broadcast']['id'], $_ENV['CONVERTKIT_API_BROADCAST_ID']);
	}

	/**
	 * Test that get_broadcast() returns a WP_Error when an invalid
	 * broadcast ID is specified.
	 *
	 * @since   2.0.0
	 *
	 * @return void
	 */
	public function testGetBroadcastWithInvalidBroadcastID()
	{
		$result = $this->api->get_broadcast(12345);
		$this->assertInstanceOf(WP_Error::class, $result);
	}

	/**
	 * Test that get_broadcast_stats() returns the expected data.
	 *
	 * @since   2.0.0
	 *
	 * @return void
	 */
	public function testGetBroadcastStats()
	{
		$result = $this->api->get_broadcast_stats($_ENV['CONVERTKIT_API_BROADCAST_ID']);
		$this->assertNotInstanceOf(WP_Error::class, $result);
		$this->assertIsArray($result);

		$this->assertArrayHasKey('broadcast', $result);
		$this->assertArrayHasKey('id', $result['broadcast']);
		$this->assertArrayHasKey('stats', $result['broadcast']);
		$this->assertEquals($result['broadcast']['stats']['recipients'], 1);
		$this->assertEquals($result['broadcast']['stats']['open_rate'], 0);
		$this->assertEquals($result['broadcast']['stats']['click_rate'], 0);
		$this->assertEquals($result['broadcast']['stats']['unsubscribes'], 0);
		$this->assertEquals($result['broadcast']['stats']['total_clicks'], 0);
	}

	/**
	 * Test that get_broadcast_stats() returns a WP_Error when an invalid
	 * broadcast ID is specified.
	 *
	 * @since   2.0.0
	 *
	 * @return void
	 */
	public function testGetBroadcastStatsWithInvalidBroadcastID()
	{
		$result = $this->api->get_broadcast_stats(12345);
		$this->assertInstanceOf(WP_Error::class, $result);
	}

	/**
	 * Test that update_broadcast() returns a WP_Error when an invalid
	 * broadcast ID is specified.
	 *
	 * @since   1.0.0
	 *
	 * @return void
	 */
	public function testUpdateBroadcastWithInvalidBroadcastID()
	{
		$result = $this->api->update_broadcast(12345);
		$this->assertInstanceOf(WP_Error::class, $result);
	}

	/**
	 * Test that delete_broadcast() returns a WP_Error when an invalid
	 * broadcast ID is specified.
	 *
	 * @since   1.0.0
	 *
	 * @return void
	 */
	public function testDeleteBroadcastWithInvalidBroadcastID()
	{
		$result = $this->api->delete_broadcast(12345);
		$this->assertInstanceOf(WP_Error::class, $result);
	}

	/**
	 * Test that get_webhooks() returns the expected data
	 * when pagination parameters and per_page limits are specified.
	 *
	 * @since   2.0.0
	 *
	 * @return void
	 */
	public function testGetWebhooksPagination()
	{
		// Create webhooks first.
		$results = [
			$this->api->create_webhook(
				'https://webhook.site/' . str_shuffle('wfervdrtgsdewrafvwefds'),
				'subscriber.subscriber_activate',
			),
			$this->api->create_webhook(
				'https://webhook.site/' . str_shuffle('wfervdrtgsdewrafvwefds'),
				'subscriber.subscriber_activate',
			),
		];

		// Set webhook_ids to ensure webhooks are deleted after test.
		$this->webhook_ids = [
			$results[0]['webhook']['id'],
			$results[1]['webhook']['id'],
		];

		// Get webhooks.
		$result = $this->api->get_webhooks(false, '', '', 1);

		// Assert webhooks and pagination exist.
		$this->assertDataExists($result, 'webhooks');
		$this->assertPaginationExists($result);

		// Assert a single webhook was returned.
		$this->assertCount(1, $result['webhooks']);

		// Assert has_previous_page and has_next_page are correct.
		$this->assertFalse($result['pagination']['has_previous_page']);
		$this->assertTrue($result['pagination']['has_next_page']);

		// Use pagination to fetch next page.
		$result = $this->api->get_webhooks(false, $result['pagination']['end_cursor'], '', 1);

		// Assert webhooks and pagination exist.
		$this->assertDataExists($result, 'webhooks');
		$this->assertPaginationExists($result);

		// Assert a single webhook was returned.
		$this->assertCount(1, $result['webhooks']);

		// Assert has_previous_page and has_next_page are correct.
		$this->assertTrue($result['pagination']['has_previous_page']);
		$this->assertFalse($result['pagination']['has_next_page']);

		// Use pagination to fetch previous page.
		$result = $this->api->get_webhooks(false, '', $result['pagination']['start_cursor'], 1);

		// Assert webhooks and pagination exist.
		$this->assertDataExists($result, 'webhooks');
		$this->assertPaginationExists($result);

		// Assert a single webhook was returned.
		$this->assertCount(1, $result['webhooks']);
	}

	/**
	 * Test that create_webhook(), get_webhooks() and delete_webhook() works.
	 *
	 * We do both, so we don't end up with unnecessary webhooks remaining
	 * on the ConvertKit account when running tests.
	 *
	 * @since   2.0.0
	 *
	 * @return void
	 */
	public function testCreateGetAndDeleteWebhook()
	{
		// Create a webhook first.
		$result = $this->api->create_webhook(
			'https://webhook.site/' . str_shuffle('wfervdrtgsdewrafvwefds'),
			'subscriber.subscriber_activate',
		);
		$this->assertNotInstanceOf(WP_Error::class, $result);
		$this->assertIsArray($result);

		// Store ID.
		$id = $result['webhook']['id'];

		// Get webhooks.
		$result = $this->api->get_webhooks();

		// Assert webhooks and pagination exist.
		$this->assertDataExists($result, 'webhooks');
		$this->assertPaginationExists($result);

		// Get webhooks including total count.
		$result = $this->api->get_webhooks(true);

		// Assert webhooks and pagination exist.
		$this->assertDataExists($result, 'webhooks');
		$this->assertPaginationExists($result);

		// Assert total count is included.
		$this->assertArrayHasKey('total_count', $result['pagination']);
		$this->assertGreaterThan(0, $result['pagination']['total_count']);

		// Delete the webhook.
		$result = $this->api->delete_webhook($id);
		$this->assertNotInstanceOf(WP_Error::class, $result);
	}

	/**
	 * Test that create_webhook() works with an event parameter.
	 *
	 * @since   2.0.0
	 *
	 * @return void
	 */
	public function testCreateWebhookWithEventParameter()
	{
		// Create a webhook.
		$url    = 'https://webhook.site/' . str_shuffle('wfervdrtgsdewrafvwefds');
		$result = $this->api->create_webhook(
			$url,
			'subscriber.form_subscribe',
			$_ENV['CONVERTKIT_API_FORM_ID']
		);

		// Confirm webhook created with correct data.
		$this->assertArrayHasKey('webhook', $result);
		$this->assertArrayHasKey('id', $result['webhook']);
		$this->assertArrayHasKey('target_url', $result['webhook']);
		$this->assertEquals($result['webhook']['target_url'], $url);
		$this->assertEquals($result['webhook']['event']['name'], 'form_subscribe');
		$this->assertEquals($result['webhook']['event']['form_id'], $_ENV['CONVERTKIT_API_FORM_ID']);

		// Delete the webhook.
		$result = $this->api->delete_webhook($result['webhook']['id']);
	}

	/**
	 * Test that create_webhook() throws an InvalidArgumentException when an invalid
	 * event is specified.
	 *
	 * @since   2.0.0
	 *
	 * @return void
	 */
	public function testCreateWebhookWithInvalidEvent()
	{
		$this->expectException(InvalidArgumentException::class);
		$this->api->create_webhook(
			'https://webhook.site/' . str_shuffle('wfervdrtgsdewrafvwefds'),
			'invalid.event'
		);
	}

	/**
	 * Test that delete_webhook() returns a WP_Error when an invalid
	 * ID is specified.
	 *
	 * @since   2.0.0
	 *
	 * @return void
	 */
	public function testDeleteWebhookWithInvalidID()
	{
		$result = $this->api->delete_webhook(12345);
		$this->assertInstanceOf(WP_Error::class, $result);
		$this->assertEquals($result->get_error_code(), $this->errorCode);
	}

	/**
	 * Test that get_custom_fields() returns the expected data.
	 *
	 * @since   1.0.0
	 *
	 * @return void
	 */
	public function testGetCustomFields()
	{
		$result = $this->api->get_custom_fields();

		// Assert custom fields and pagination exist.
		$this->assertDataExists($result, 'custom_fields');
		$this->assertPaginationExists($result);
	}

	/**
	 * Test that get_custom_fields() returns the expected data
	 * when the total count is included.
	 *
	 * @since   2.0.0
	 *
	 * @return void
	 */
	public function testGetCustomFieldsWithTotalCount()
	{
		$result = $this->api->get_custom_fields(true);

		// Assert custom fields and pagination exist.
		$this->assertDataExists($result, 'custom_fields');
		$this->assertPaginationExists($result);

		// Assert total count is included.
		$this->assertArrayHasKey('total_count', $result['pagination']);
		$this->assertGreaterThan(0, $result['pagination']['total_count']);
	}

	/**
	 * Test that get_custom_fields() returns the expected data
	 * when pagination parameters and per_page limits are specified.
	 *
	 * @since   2.0.0
	 *
	 * @return void
	 */
	public function testGetCustomFieldsPagination()
	{
		// Return one custom field.
		$result = $this->api->get_custom_fields(false, '', '', 1);

		// Assert custom fields and pagination exist.
		$this->assertDataExists($result, 'custom_fields');
		$this->assertPaginationExists($result);

		// Assert a single custom field was returned.
		$this->assertCount(1, $result['custom_fields']);

		// Assert has_previous_page and has_next_page are correct.
		$this->assertFalse($result['pagination']['has_previous_page']);
		$this->assertTrue($result['pagination']['has_next_page']);

		// Use pagination to fetch next page.
		$result = $this->api->get_custom_fields(false, $result['pagination']['end_cursor'], '', 1);

		// Assert custom fields and pagination exist.
		$this->assertDataExists($result, 'custom_fields');
		$this->assertPaginationExists($result);

		// Assert a single custom field was returned.
		$this->assertCount(1, $result['custom_fields']);

		// Assert has_previous_page and has_next_page are correct.
		$this->assertTrue($result['pagination']['has_previous_page']);
		$this->assertTrue($result['pagination']['has_next_page']);

		// Use pagination to fetch previous page.
		$result = $this->api->get_custom_fields(false, '', $result['pagination']['start_cursor'], 1);

		// Assert custom fields and pagination exist.
		$this->assertDataExists($result, 'custom_fields');
		$this->assertPaginationExists($result);

		// Assert a single custom field was returned.
		$this->assertCount(1, $result['custom_fields']);

		// Assert has_previous_page and has_next_page are correct.
		$this->assertFalse($result['pagination']['has_previous_page']);
		$this->assertTrue($result['pagination']['has_next_page']);
	}

	/**
	 * Test that create_custom_field() works.
	 *
	 * @since   1.0.0
	 *
	 * @return void
	 */
	public function testCreateCustomField()
	{
		$label  = 'Custom Field ' . wp_rand();
		$result = $this->api->create_custom_field($label);

		// Test array was returned.
		$this->assertNotInstanceOf(WP_Error::class, $result);
		$this->assertIsArray($result);

		// Set custom_field_ids to ensure custom fields are deleted after test.
		$this->custom_field_ids[] = $result['custom_field']['id'];

		$this->assertArrayHasKey('custom_field', $result);
		$this->assertArrayHasKey('id', $result['custom_field']);
		$this->assertArrayHasKey('name', $result['custom_field']);
		$this->assertArrayHasKey('key', $result['custom_field']);
		$this->assertArrayHasKey('label', $result['custom_field']);
		$this->assertEquals($result['custom_field']['label'], $label);
	}

	/**
	 * Test that create_custom_field() returns a WP_Error when a blank
	 * label is specified.
	 *
	 * @since   2.0.0
	 *
	 * @return void
	 */
	public function testCreateCustomFieldWithBlankLabel()
	{
		$result = $this->api->create_custom_field('');
		$this->assertInstanceOf(WP_Error::class, $result);
		$this->assertEquals($result->get_error_code(), $this->errorCode);
	}

	/**
	 * Test that create_custom_fields() works.
	 *
	 * @since   2.0.0
	 *
	 * @return void
	 */
	public function testCreateCustomFields()
	{
		$labels = [
			'Custom Field ' . wp_rand(),
			'Custom Field ' . wp_rand(),
		];
		$result = $this->api->create_custom_fields($labels);

		// Test array was returned.
		$this->assertNotInstanceOf(WP_Error::class, $result);
		$this->assertIsArray($result);

		// Set custom_field_ids to ensure custom fields are deleted after test.
		foreach ($result['custom_fields'] as $index => $customField) {
			$this->custom_field_ids[] = $customField['id'];
		}

		// Assert no failures.
		$this->assertCount(0, $result['failures']);

		// Confirm result is an array comprising of each custom field that was created.
		$this->assertIsArray($result['custom_fields']);
	}

	/**
	 * Test that update_custom_field() works.
	 *
	 * @since   2.0.0
	 *
	 * @return void
	 */
	public function testUpdateCustomField()
	{
		// Create custom field.
		$label  = 'Custom Field ' . wp_rand();
		$result = $this->api->create_custom_field($label);

		// Test array was returned.
		$this->assertNotInstanceOf(WP_Error::class, $result);
		$this->assertIsArray($result);

		// Store ID.
		$id = $result['custom_field']['id'];

		// Set custom_field_ids to ensure custom fields are deleted after test.
		$this->custom_field_ids[] = $result['custom_field']['id'];

		// Change label.
		$newLabel = 'Custom Field ' . wp_rand();
		$this->api->update_custom_field($id, $newLabel);

		// Confirm label changed.
		$customFields = $this->api->get_custom_fields();
		foreach ($customFields['custom_fields'] as $customField) {
			if ($customField['id'] === $id) {
				$this->assertEquals($customField['label'], $newLabel);
			}
		}
	}

	/**
	 * Test that update_custom_field() returns a WP_Error when an
	 * invalid custom field ID is specified.
	 *
	 * @since   2.0.0
	 *
	 * @return void
	 */
	public function testUpdateCustomFieldWithInvalidID()
	{
		$result = $this->api->update_custom_field(12345, 'Something');
		$this->assertInstanceOf(WP_Error::class, $result);
		$this->assertEquals($result->get_error_code(), $this->errorCode);
	}

	/**
	 * Test that delete_custom_field() works.
	 *
	 * @since   2.0.0
	 *
	 * @return void
	 */
	public function testDeleteCustomField()
	{
		// Create custom field.
		$label  = 'Custom Field ' . wp_rand();
		$result = $this->api->create_custom_field($label);

		// Test array was returned.
		$this->assertNotInstanceOf(WP_Error::class, $result);
		$this->assertIsArray($result);

		// Store ID.
		$id = $result['custom_field']['id'];

		// Delete custom field as tests passed.
		$this->api->delete_custom_field($id);

		// Confirm custom field no longer exists.
		$customFields = $this->api->get_custom_fields();
		foreach ($customFields['custom_fields'] as $customField) {
			$this->assertNotEquals($customField['id'], $id);
		}
	}

	/**
	 * Test that delete_custom_field() returns a WP_Error when an
	 * invalid custom field ID is specified.
	 *
	 * @since   2.0.0
	 *
	 * @return void
	 */
	public function testDeleteCustomFieldWithInvalidID()
	{
		$result = $this->api->delete_custom_field(12345);
		$this->assertInstanceOf(WP_Error::class, $result);
		$this->assertEquals($result->get_error_code(), $this->errorCode);
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
			$_ENV['CONVERTKIT_API_FORM_ID'],
			$emailAddress,
			'First',
			[
				'last_name' => 'Last',
			]
		);

		// Test array was returned.
		$this->assertNotInstanceOf(WP_Error::class, $result);
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
			12345,
			$this->generateEmailAddress()
		);
		$this->assertInstanceOf(WP_Error::class, $result);
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
			$_ENV['CONVERTKIT_API_LEGACY_FORM_ID'],
			$this->generateEmailAddress()
		);
		$this->assertInstanceOf(WP_Error::class, $result);
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
			$_ENV['CONVERTKIT_API_FORM_ID'],
			'not-a-valid-email'
		);
		$this->assertInstanceOf(WP_Error::class, $result);
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
			$_ENV['CONVERTKIT_API_LEGACY_FORM_ID'],
			$emailAddress,
			'First',
			[
				'last_name' => 'Last',
			]
		);

		// Test array was returned.
		$this->assertNotInstanceOf(WP_Error::class, $result);
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
			12345,
			$this->generateEmailAddress()
		);
		$this->assertInstanceOf(WP_Error::class, $result);
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
			$_ENV['CONVERTKIT_API_FORM_ID'],
			$this->generateEmailAddress()
		);
		$this->assertInstanceOf(WP_Error::class, $result);
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
			$_ENV['CONVERTKIT_API_LEGACY_FORM_ID'],
			'not-a-valid-email'
		);
		$this->assertInstanceOf(WP_Error::class, $result);
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
			$_ENV['CONVERTKIT_API_TAG_ID'],
			$emailAddress,
			'First',
			[
				'last_name' => 'Last',
			]
		);

		// Test array was returned.
		$this->assertNotInstanceOf(WP_Error::class, $result);
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
			12345,
			$this->generateEmailAddress()
		);
		$this->assertInstanceOf(WP_Error::class, $result);
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
			$_ENV['CONVERTKIT_API_TAG_ID'],
			'not-a-valid-email'
		);
		$this->assertInstanceOf(WP_Error::class, $result);
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
			$_ENV['CONVERTKIT_API_SEQUENCE_ID'],
			$emailAddress,
			'First',
			[
				'last_name' => 'Last',
			]
		);

		// Test array was returned.
		$this->assertNotInstanceOf(WP_Error::class, $result);
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
			12345,
			$this->generateEmailAddress()
		);
		$this->assertInstanceOf(WP_Error::class, $result);
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
			$_ENV['CONVERTKIT_API_TAG_ID'],
			'not-a-valid-email'
		);
		$this->assertInstanceOf(WP_Error::class, $result);
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
		$this->assertNotInstanceOf(WP_Error::class, $result);
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
	 * Test that the `get_posts()` function returns a blank array when no data
	 * exists on the ConvertKit account.
	 *
	 * @since   1.0.0
	 */
	public function testGetPostsNoData()
	{
		$result = $this->api_no_data->get_posts();
		$this->assertNotInstanceOf(WP_Error::class, $result);
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
		$this->assertNotInstanceOf(WP_Error::class, $result);
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
		$this->assertInstanceOf(WP_Error::class, $result);
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
		$this->assertInstanceOf(WP_Error::class, $result);
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
		$this->assertInstanceOf(WP_Error::class, $result);
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
		$this->assertNotInstanceOf(WP_Error::class, $result);
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
		$this->assertNotInstanceOf(WP_Error::class, $result);
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
		$this->assertNotInstanceOf(WP_Error::class, $result);
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
		$this->assertInstanceOf(WP_Error::class, $result);
		$this->assertEquals($result->get_error_code(), $this->errorCode);
		$this->assertEquals('get_all_posts(): the posts_per_request parameter must be equal to or greater than 1.', $result->get_error_message());

		// Test with a number greater than 50.
		$result = $this->api->get_all_posts(51);
		$this->assertInstanceOf(WP_Error::class, $result);
		$this->assertEquals($result->get_error_code(), $this->errorCode);
		$this->assertEquals('get_all_posts(): the posts_per_request parameter must be equal to or less than 50.', $result->get_error_message());
	}

	/**
	 * Test that the `get_post()` function returns expected data.
	 *
	 * @since   1.3.8
	 */
	public function testGetPostByID()
	{
		$result = $this->api->get_post($_ENV['CONVERTKIT_API_POST_ID']);
		$this->assertNotInstanceOf(WP_Error::class, $result);
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
	 * Test that the `get_post()` function returns a WP_Error when an invalid
	 * Post ID is specified.
	 *
	 * @since   1.3.8
	 */
	public function testGetPostByInvalidID()
	{
		$result = $this->api->get_post(12345);
		$this->assertInstanceOf(WP_Error::class, $result);
		$this->assertEquals($result->get_error_code(), $this->errorCode);
	}

	/**
	 * Test that the `get_products()` function returns expected data.
	 *
	 * @since   1.1.0
	 */
	public function testGetProducts()
	{
		$result = $this->api->get_products();
		$this->assertNotInstanceOf(WP_Error::class, $result);
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
		$this->assertNotInstanceOf(WP_Error::class, $result);
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
			$_ENV['CONVERTKIT_API_SUBSCRIBER_EMAIL'],
			$_ENV['TEST_SITE_WP_URL']
		);
		$this->assertNotInstanceOf(WP_Error::class, $result);
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
			'email-not-subscribed@kit.com',
			$_ENV['TEST_SITE_WP_URL']
		);
		$this->assertInstanceOf(WP_Error::class, $result);
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
			'',
			$_ENV['TEST_SITE_WP_URL']
		);
		$this->assertInstanceOf(WP_Error::class, $result);
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
			'not-an-email-address',
			$_ENV['TEST_SITE_WP_URL']
		);
		$this->assertInstanceOf(WP_Error::class, $result);
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
			$_ENV['CONVERTKIT_API_SUBSCRIBER_EMAIL'],
			'not-a-valid-url'
		);
		$this->assertInstanceOf(WP_Error::class, $result);
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
			$_ENV['CONVERTKIT_API_SUBSCRIBER_TOKEN'],
			'subscriberCode'
		);
		$this->assertInstanceOf(WP_Error::class, $result);
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
			'',
			'subscriberCode'
		);
		$this->assertInstanceOf(WP_Error::class, $result);
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
			'token',
			''
		);
		$this->assertInstanceOf(WP_Error::class, $result);
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
			'invalidToken',
			'invalidSubscriberCode'
		);
		$this->assertInstanceOf(WP_Error::class, $result);
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
		$result = $this->api->profile( $_ENV['CONVERTKIT_API_SIGNED_SUBSCRIBER_ID'] );
		$this->assertNotInstanceOf(WP_Error::class, $result);
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
		$this->assertInstanceOf(WP_Error::class, $result);
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
		$this->assertInstanceOf(WP_Error::class, $result);
		$this->assertEquals($result->get_error_code(), $this->errorCode);
	}

	/**
	 * Test that get_purchases() returns the expected data.
	 *
	 * @since   2.0.0
	 *
	 * @return void
	 */
	public function testGetPurchases()
	{
		$result = $this->api->get_purchases();

		// Assert purchases and pagination exist.
		$this->assertDataExists($result, 'purchases');
		$this->assertPaginationExists($result);
	}

	/**
	 * Test that get_purchases() returns the expected data
	 * when the total count is included.
	 *
	 * @since   2.0.0
	 *
	 * @return void
	 */
	public function testGetPurchasesWithTotalCount()
	{
		$result = $this->api->get_purchases(true);

		// Assert purchases and pagination exist.
		$this->assertDataExists($result, 'purchases');
		$this->assertPaginationExists($result);

		// Assert total count is included.
		$this->assertArrayHasKey('total_count', $result['pagination']);
		$this->assertGreaterThan(0, $result['pagination']['total_count']);
	}

	/**
	 * Test that get_purchases() returns the expected data
	 * when pagination parameters and per_page limits are specified.
	 *
	 * @since   2.0.0
	 *
	 * @return void
	 */
	public function testGetPurchasesPagination()
	{
		$result = $this->api->get_purchases(false, '', '', 1);

		// Assert purchases and pagination exist.
		$this->assertDataExists($result, 'purchases');
		$this->assertPaginationExists($result);

		// Assert a single purchase was returned.
		$this->assertCount(1, $result['purchases']);

		// Assert has_previous_page and has_next_page are correct.
		$this->assertFalse($result['pagination']['has_previous_page']);
		$this->assertTrue($result['pagination']['has_next_page']);

		// Use pagination to fetch next page.
		$result = $this->api->get_purchases(false, $result['pagination']['end_cursor'], '', 1);

		// Assert purchases and pagination exist.
		$this->assertDataExists($result, 'purchases');
		$this->assertPaginationExists($result);

		// Assert a single purchase was returned.
		$this->assertCount(1, $result['purchases']);

		// Assert has_previous_page and has_next_page are correct.
		$this->assertTrue($result['pagination']['has_previous_page']);
		$this->assertTrue($result['pagination']['has_next_page']);

		// Use pagination to fetch previous page.
		$result = $this->api->get_purchases(false, '', $result['pagination']['end_cursor'], 1);

		// Assert purchases and pagination exist.
		$this->assertDataExists($result, 'purchases');
		$this->assertPaginationExists($result);

		// Assert a single purchase was returned.
		$this->assertCount(1, $result['purchases']);

		// Assert has_previous_page and has_next_page are correct.
		$this->assertFalse($result['pagination']['has_previous_page']);
		$this->assertTrue($result['pagination']['has_next_page']);
	}

	/**
	 * Test that get_purchases() returns the expected data.
	 *
	 * @since   2.0.0
	 *
	 * @return void
	 */
	public function testGetPurchase()
	{
		$result = $this->api->get_purchases(false, '', '', 1);

		// Assert purchases and pagination exist.
		$this->assertDataExists($result, 'purchases');
		$this->assertPaginationExists($result);

		// Assert a single purchase was returned.
		$this->assertCount(1, $result['purchases']);

		// Get ID.
		$id = $result['purchases'][0]['id'];

		// Get purchase.
		$result = $this->api->get_purchase($id);
		$this->assertNotInstanceOf(WP_Error::class, $result);
		$this->assertIsArray($result);
		$this->assertEquals($result['purchase']['id'], $id);
	}

	/**
	 * Test that get_purchases() returns a WP_Error when an invalid
	 * purchase ID is specified.
	 *
	 * @since   2.0.0
	 *
	 * @return void
	 */
	public function testGetPurchaseWithInvalidID()
	{
		$result = $this->api->get_purchase(12345);
		$this->assertInstanceOf(WP_Error::class, $result);
		$this->assertEquals($result->get_error_code(), $this->errorCode);
	}

	/**
	 * Test that create_purchase() returns the expected data.
	 *
	 * @since   1.0.0
	 *
	 * @return void
	 */
	public function testCreatePurchase()
	{
		$result = $this->api->create_purchase(
			// Required fields.
			$this->generateEmailAddress(),
			str_shuffle('wfervdrtgsdewrafvwefds'), // transaction ID.
			[ // products.
				[
					'name'       => 'Floppy Disk (512k)',
					'sku'        => '7890-ijkl',
					'pid'        => 9999,
					'lid'        => 7777,
					'quantity'   => 2,
					'unit_price' => 5.00,
				],
				[
					'name'       => 'Telephone Cord (data)',
					'sku'        => 'mnop-1234',
					'pid'        => 5555,
					'lid'        => 7778,
					'quantity'   => 1,
					'unit_price' => 10.00,
				],
			],
			// Optional fields.
			'usd', // currency.
			'Tim', // first name.
			'paid', // status.
			20.00, // subtotal.
			2.00, // tax.
			2.00, // shipping.
			3.00, // discount.
			21.00, // total.
			new DateTime('now'), // transaction time.
		);

		$this->assertNotInstanceOf(WP_Error::class, $result);
		$this->assertIsArray($result);
		$this->assertArrayHasKey('transaction_id', $result['purchase']);
	}

	/**
	 * Test that create_purchase() returns a WP_Error when an invalid
	 * email address is specified.
	 *
	 * @since   2.0.0
	 *
	 * @return void
	 */
	public function testCreatePurchaseWithInvalidEmailAddress()
	{
		$result = $this->api->create_purchase(
			'not-an-email-address',
			str_shuffle('wfervdrtgsdewrafvwefds'), // transaction ID.
			[ // products.
				[
					'name'       => 'Floppy Disk (512k)',
					'sku'        => '7890-ijkl',
					'pid'        => 9999,
					'lid'        => 7777,
					'quantity'   => 2,
					'unit_price' => 5.00,
				],
			]
		);
		$this->assertInstanceOf(WP_Error::class, $result);
		$this->assertEquals($result->get_error_code(), $this->errorCode);
	}

	/**
	 * Test that create_purchase() returns a WP_Error when a blank
	 * transaction ID is specified.
	 *
	 * @since   2.0.0
	 *
	 * @return void
	 */
	public function testCreatePurchaseWithBlankTransactionID()
	{
		$result = $this->api->create_purchase(
			$this->generateEmailAddress(),
			'', // transaction ID.
			[ // products.
				[
					'name'       => 'Floppy Disk (512k)',
					'sku'        => '7890-ijkl',
					'pid'        => 9999,
					'lid'        => 7777,
					'quantity'   => 2,
					'unit_price' => 5.00,
				],
			]
		);
		$this->assertInstanceOf(WP_Error::class, $result);
		$this->assertEquals($result->get_error_code(), $this->errorCode);
	}

	/**
	 * Test that create_purchase() returns a WP_Error when no products
	 * are specified.
	 *
	 * @since   2.0.0
	 *
	 * @return void
	 */
	public function testCreatePurchaseWithNoProducts()
	{
		$result = $this->api->create_purchase(
			$this->generateEmailAddress(),
			str_shuffle('wfervdrtgsdewrafvwefds'),
			array()
		);
		$this->assertInstanceOf(WP_Error::class, $result);
		$this->assertEquals($result->get_error_code(), $this->errorCode);
	}

	/**
	 * Test that get_segments() returns the expected data.
	 *
	 * @since   2.0.0
	 *
	 * @return void
	 */
	public function testGetSegments()
	{
		$result = $this->api->get_segments();

		// Assert segments and pagination exist.
		$this->assertDataExists($result, 'segments');
		$this->assertPaginationExists($result);
	}

	/**
	 * Test that get_segments() returns the expected data
	 * when the total count is included.
	 *
	 * @since   1.0.0
	 *
	 * @return void
	 */
	public function testGetSegmentsWithTotalCount()
	{
		$result = $this->api->get_segments(true);

		// Assert segments and pagination exist.
		$this->assertDataExists($result, 'segments');
		$this->assertPaginationExists($result);

		// Assert total count is included.
		$this->assertArrayHasKey('total_count', $result['pagination']);
		$this->assertGreaterThan(0, $result['pagination']['total_count']);
	}

	/**
	 * Test that get_segments() returns the expected data
	 * when pagination parameters and per_page limits are specified.
	 *
	 * @since   2.0.0
	 *
	 * @return void
	 */
	public function testGetSegmentsPagination()
	{
		$result = $this->api->get_segments(false, '', '', 1);

		// Assert segments and pagination exist.
		$this->assertDataExists($result, 'segments');
		$this->assertPaginationExists($result);

		// Assert a single segment was returned.
		$this->assertCount(1, $result['segments']);

		// Assert has_previous_page and has_next_page are correct.
		$this->assertFalse($result['pagination']['has_previous_page']);
		$this->assertTrue($result['pagination']['has_next_page']);

		// Use pagination to fetch next page.
		$result = $this->api->get_segments(false, $result['pagination']['end_cursor'], '', 1);

		// Assert segments and pagination exist.
		$this->assertDataExists($result, 'segments');
		$this->assertPaginationExists($result);

		// Assert a single segment was returned.
		$this->assertCount(1, $result['segments']);

		// Assert has_previous_page and has_next_page are correct.
		$this->assertTrue($result['pagination']['has_previous_page']);
		$this->assertTrue($result['pagination']['has_next_page']);

		// Use pagination to fetch previous page.
		$result = $this->api->get_segments(false, '', $result['pagination']['start_cursor'], 1);

		// Assert segments and pagination exist.
		$this->assertDataExists($result, 'segments');
		$this->assertPaginationExists($result);

		// Assert a single segment was returned.
		$this->assertCount(1, $result['segments']);

		// Assert has_previous_page and has_next_page are correct.
		$this->assertFalse($result['pagination']['has_previous_page']);
		$this->assertTrue($result['pagination']['has_next_page']);
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
		$this->assertNotInstanceOf(WP_Error::class, $result);
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
		$this->assertNotInstanceOf(WP_Error::class, $result);
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
		$result = $this->api->get_form_html($_ENV['CONVERTKIT_API_LEGACY_FORM_ID'], $_ENV['CONVERTKIT_API_KEY']);
		$this->assertNotInstanceOf(WP_Error::class, $result);
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
		$result = $this->api->get_form_html('11111', $_ENV['CONVERTKIT_API_KEY']);
		$this->assertInstanceOf(WP_Error::class, $result);
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
		$this->assertNotInstanceOf(WP_Error::class, $result);
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
		$this->assertNotInstanceOf(WP_Error::class, $result);
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
		$this->assertNotInstanceOf(WP_Error::class, $result);

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
		$this->assertInstanceOf(WP_Error::class, $result);
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

	/**
	 * Helper method to assert the given key exists as an array
	 * in the API response.
	 *
	 * @since   2.0.0
	 *
	 * @param   array  $result     API Result.
	 * @param   string $key        Key.
	 */
	private function assertDataExists($result, $key)
	{
		$this->assertNotInstanceOf(WP_Error::class, $result);
		$this->assertArrayHasKey($key, $result);
		$this->assertIsArray($result[ $key ]);
	}

	/**
	 * Helper method to assert pagination object exists in response.
	 *
	 * @since   2.0.0
	 *
	 * @param   array $result     API Result.
	 */
	private function assertPaginationExists($result)
	{
		$this->assertArrayHasKey('pagination', $result);
		$pagination = $result['pagination'];
		$this->assertArrayHasKey('has_previous_page', $pagination);
		$this->assertArrayHasKey('has_next_page', $pagination);
		$this->assertArrayHasKey('start_cursor', $pagination);
		$this->assertArrayHasKey('end_cursor', $pagination);
		$this->assertArrayHasKey('per_page', $pagination);
	}

	/**
	 * Generates a unique email address for use in a test, comprising of a prefix,
	 * date + time and PHP version number.
	 *
	 * This ensures that if tests are run in parallel, the same email address
	 * isn't used for two tests across parallel testing runs.
	 *
	 * @since   2.0.0
	 *
	 * @param   string $domain     Domain (default: kit.com).
	 *
	 * @return  string
	 */
	private function generateEmailAddress($domain = 'kit.com')
	{
		return 'php-sdk-' . date('Y-m-d-H-i-s') . '-php-' . PHP_VERSION_ID . '@' . $domain;
	}
}
