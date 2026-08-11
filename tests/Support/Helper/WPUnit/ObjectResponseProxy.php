<?php
namespace Helper\WPUnit;

/**
 * WP Libs' `request()` returns associative arrays (WordPress convention);
 * the PHP SDK returns `stdClass` objects (Guzzle convention). The shared
 * `TestsTrait.php` is copied verbatim from the SDK and therefore uses object
 * access throughout ($result->forms, $result->pagination->end_cursor, etc.).
 *
 * This proxy exists solely to bridge that gap in test context — it lets the
 * trait run unmodified against WP Libs. Plugin code that consumes WP Libs
 * in production is unaffected; only the test suite ever sees the proxy.
 *
 * `WP_Error` and other non-array returns pass through untouched.
 *
 * @since   2.6.0
 */
class ObjectResponseProxy
{
	/**
	 * The underlying `ConvertKit_API_V4` instance.
	 *
	 * @var ConvertKit_API_V4
	 */
	private $api;

	/**
	 * Constructor.
	 *
	 * @param ConvertKit_API_V4 $api API Instance.
	 */
	public function __construct($api)
	{
		$this->api = $api;
	}

	/**
	 * Forward every method call to the wrapped API. If the return value is
	 * an array, convert it recursively to a nested `stdClass` so the trait's
	 * object-style assertions work verbatim.
	 *
	 * @param   string       $name Method name.
	 * @param   array<mixed> $args Positional arguments.
	 * @return  mixed
	 */
	public function __call($name, $args)
	{
		$result = $this->api->$name(...$args);

		if (is_array($result)) {
			// Recursive array -> nested stdClass via a round-trip through JSON.
			// Cheap, dependency-free, and preserves nested pagination objects.
			return json_decode(wp_json_encode($result));
		}

		return $result;
	}

	/**
	 * Forward property reads (e.g. debug flags, tokens) to the wrapped API.
	 *
	 * @param   string $name Property name.
	 * @return  mixed
	 */
	public function __get($name)
	{
		return $this->api->$name;
	}

	/**
	 * Forward property writes to the wrapped API.
	 *
	 * @param   string $name  Property name.
	 * @param   mixed  $value Value.
	 * @return  void
	 */
	public function __set($name, $value)
	{
		$this->api->$name = $value;
	}

	/**
	 * Expose the wrapped raw API. Useful for tests that occasionally need
	 * the array-returning behaviour.
	 *
	 * @return  object
	 */
	public function raw()
	{
		return $this->api;
	}
}
