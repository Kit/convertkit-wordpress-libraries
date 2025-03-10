<?php
/**
 * Tests for the ConvertKit_API class when using a blank
 * ConvertKit account.
 *
 * @since   2.0.0
 */
class APINoDataTest extends \Codeception\TestCase\WPTestCase
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
	 * @since   2.0.0
	 *
	 * @var     ConvertKit_API
	 */
	private $api;

	/**
	 * Holds the expected WP_Error code.
	 *
	 * @since   2.0.0
	 *
	 * @var     string
	 */
	private $errorCode = 'convertkit_api_error';

	/**
	 * Performs actions before each test.
	 *
	 * @since   2.0.0
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
			$_ENV['CONVERTKIT_OAUTH_ACCESS_TOKEN_NO_DATA'],
			$_ENV['CONVERTKIT_OAUTH_REFRESH_TOKEN_NO_DATA']
		);
	}

	/**
	 * Test that get_custom_fields() returns a blank array when no data
	 * exists on the ConvertKit account.
	 *
	 * @since   2.0.0
	 *
	 * @return void
	 */
	public function testGetCustomFields()
	{
		$result = $this->api->get_custom_fields();
		$this->assertNoData($result, 'custom_fields');
	}

	/**
	 * Test that get_subscribers() returns a blank array when no data
	 * exists on the ConvertKit account.
	 *
	 * @since   2.0.0
	 *
	 * @return void
	 */
	public function testGetSubscribers()
	{
		$result = $this->api->get_subscribers();
		$this->assertNoData($result, 'subscribers');
	}

	/**
	 * Test that get_tags() returns a blank array when no data
	 * exists on the ConvertKit account.
	 *
	 * @since   2.0.0
	 *
	 * @return void
	 */
	public function testGetTags()
	{
		$result = $this->api->get_tags();
		$this->assertNoData($result, 'tags');
	}

	/**
	 * Test that get_email_templates() returns a blank array when no data
	 * exists on the ConvertKit account.
	 *
	 * @since   2.0.0
	 *
	 * @return void
	 */
	public function testGetEmailTemplates()
	{
		$result = $this->api->get_email_templates();

		// The default text only template always exists in ConvertKit
		// and cannot be deleted, so check one template is returned.
		$this->assertNoData($result, 'email_templates', 1);
	}

	/**
	 * Test that get_forms() returns a blank array when no data
	 * exists on the ConvertKit account.
	 *
	 * @since   2.0.0
	 *
	 * @return void
	 */
	public function testGetForms()
	{
		$result = $this->api->get_forms();

		// The Creator Profile Form always exists in ConvertKit
		// and cannot be deleted, so check one template is returned.
		$this->assertNoData($result, 'forms', 1);
	}

	/**
	 * Test that get_landing_pages() returns a blank array when no data
	 * exists on the ConvertKit account.
	 *
	 * @since   2.0.0
	 *
	 * @return void
	 */
	public function testGetLandingPages()
	{
		$result = $this->api->get_landing_pages();
		$this->assertNoData($result, 'forms');
	}

	/**
	 * Test that get_purchases() returns a blank array when no data
	 * exists on the ConvertKit account.
	 *
	 * @since   2.0.0
	 *
	 * @return void
	 */
	public function testGetPurchases()
	{
		$result = $this->api->get_purchases();
		$this->assertNoData($result, 'purchases');
	}

	/**
	 * Test that get_segments() returns a blank array when no data
	 * exists on the ConvertKit account.
	 *
	 * @since   2.0.0
	 *
	 * @return void
	 */
	public function testGetSegments()
	{
		$result = $this->api->get_segments();
		$this->assertNoData($result, 'segments');
	}

	/**
	 * Test that get_sequences() returns a blank array when no data
	 * exists on the ConvertKit account.
	 *
	 * @since   2.0.0
	 *
	 * @return void
	 */
	public function testGetSequences()
	{
		$result = $this->api->get_sequences();
		$this->assertNoData($result, 'sequences');
	}

	/**
	 * Test that get_webhooks() returns a blank array when no data
	 * exists on the ConvertKit account.
	 *
	 * @since   2.0.0
	 *
	 * @return void
	 */
	public function testGetWebhooks()
	{
		$result = $this->api->get_webhooks();
		$this->assertNoData($result, 'webhooks');
	}

	/**
	 * Test that the `get_posts()` function returns a blank array when no data
	 * exists on the ConvertKit account.
	 *
	 * @since   2.0.0
	 */
	public function testGetPostsNoData()
	{
		$result = $this->api->get_posts();
		$this->assertNotInstanceOf(WP_Error::class, $result);
		$this->assertIsArray($result);
		$this->assertCount(0, $result);
	}

	/**
	 * Test that the `get_all_posts()` function returns a blank array when no data
	 * exists on the ConvertKit account.
	 *
	 * @since   2.0.0
	 */
	public function testGetAllPostsNoData()
	{
		$result = $this->api->get_all_posts();
		$this->assertNotInstanceOf(WP_Error::class, $result);
		$this->assertIsArray($result);
		$this->assertCount(0, $result);
	}

	/**
	 * Test that the `get_products()` function returns a blank array when no data
	 * exists on the ConvertKit account.
	 *
	 * @since   1.1.0
	 */
	public function testGetProducts()
	{
		$result = $this->api->get_products();
		$this->assertNotInstanceOf(WP_Error::class, $result);
		$this->assertIsArray($result);
		$this->assertCount(0, $result);
	}

	/**
	 * Test that get_broadcasts() returns a blank array when no data
	 * exists on the ConvertKit account.
	 *
	 * @since   2.0.0
	 *
	 * @return void
	 */
	public function testGetBroadcasts()
	{
		$result = $this->api->get_broadcasts();
		$this->assertNotInstanceOf(WP_Error::class, $result);
		$this->assertIsArray($result['broadcasts']);
		$this->assertCount(1, $result['broadcasts']);
	}

	/**
	 * Assert that the given API result is not a WP_Error and contains
	 * no results in the array.
	 *
	 * @since   2.0.0
	 *
	 * @param   array  $result     API Result.
	 * @param   string $key        Results Key.
	 * @param   int    $count      Expected Count.
	 */
	private function assertNoData($result, $key, $count = 0)
	{
		$this->assertNotInstanceOf(WP_Error::class, $result);
		$this->assertIsArray($result);
		$this->assertCount($count, $result[ $key ]);
	}
}
