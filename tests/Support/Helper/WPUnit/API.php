<?php
namespace Helper\WPUnit;

/**
 * Helper methods and actions related to the ConvertKit API,
 * which are then available using $I->{yourFunctionName}.
 */
class API extends \Codeception\Module
{
	/**
	 * Sends a request to the ConvertKit API, typically used to read an endpoint to confirm
	 * that data in an Acceptance Test was added/edited/deleted successfully.
	 *
	 * @param   string $endpoint   Endpoint.
	 * @param   string $method     Method (GET|POST|PUT).
	 * @param   array  $params     Endpoint Parameters.
	 * @return  array
	 */
	public function apiRequest($endpoint, $method = 'GET', $params = array())
	{
		// Build query parameters.
		$params = array_merge(
			$params,
			[
				'api_key'    => $_ENV['CONVERTKIT_API_KEY'],
				'api_secret' => $_ENV['CONVERTKIT_API_SECRET'],
			]
		);

		// Send request.
		try {
			$client = new \GuzzleHttp\Client();
			$result = $client->request(
				$method,
				'https://api.kit.com/v3/' . $endpoint . '?' . http_build_query($params),
				[
					'headers' => [
						'Accept-Encoding' => 'gzip',
						'timeout'         => 5,
					],
				]
			);

			// Return JSON decoded response.
			return json_decode($result->getBody()->getContents(), true);
		} catch (\GuzzleHttp\Exception\ClientException $e) {
			return [];
		}
	}
}
