<?php
namespace Helper\WPUnit;

/**
 * Wraps `ConvertKit_API_V4` and converts every array response to a nested `stdClass`
 * to mirror the PHP SDK's responses, ensuring TestsTrait tests can be used across
 * both WordPress Libraries and the PHP SDK.
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
