<?php
/**
 * ConvertKit Resource class.
 *
 * @package ConvertKit
 * @author ConvertKit
 */

/**
 * Abstract class defining variables and functions for a ConvertKit API Resource
 * (forms, landing pages, tags), which is stored in the WordPress option table.
 *
 * @since   1.0.0
 */
class ConvertKit_Resource_V4 {

	/**
	 * Holds the key that stores the resources in the option database table.
	 *
	 * @var     string
	 */
	public $settings_name = '';

	/**
	 * The type of resource
	 *
	 * @var     string
	 */
	public $type = '';

	/**
	 * The API class
	 *
	 * @var     bool|ConvertKit_API_V4
	 */
	public $api = false;

	/**
	 * The number of seconds resources are valid, before they should be
	 * fetched again from the API.
	 *
	 * @var     int
	 */
	public $cache_duration = YEAR_IN_SECONDS;

	/**
	 * How often to refresh this resource through WordPress' Cron.
	 * If false, won't be refreshed through WordPress' Cron
	 * If a string, must be a value from wp_get_schedules().
	 *
	 * @since   1.0.0
	 *
	 * @var     bool|string
	 */
	public $wp_cron_schedule = false;

	/**
	 * Holds the resources from the ConvertKit API
	 *
	 * @var     WP_Error|array|bool|null
	 */
	public $resources = array();

	/**
	 * The key to use when alphabetically sorting resources.
	 *
	 * @since   1.3.1
	 *
	 * @var     string
	 */
	public $order_by = 'name';

	/**
	 * The order to return resources.
	 *
	 * @since   1.3.1
	 *
	 * @var     string
	 */
	public $order = 'asc';

	/**
	 * Timestamp for when the resources stored in the option database table
	 * were last queried from the API.
	 *
	 * @since   1.0.0
	 *
	 * @var     int
	 */
	public $last_queried = 0;

	/**
	 * Constructor.
	 *
	 * @since   1.0.0
	 */
	public function __construct() {

		$this->init();

	}

	/**
	 * Initialization routine. Populate the resources array of e.g. forms, landing pages or tags,
	 * depending on whether resources are already cached, if the resources have expired etc.
	 *
	 * @since   1.0.0
	 */
	public function init() {

		// Get last query time and existing resources.
		$this->last_queried = get_option( $this->settings_name . '_last_queried' );
		$this->resources    = get_option( $this->settings_name );

		// If no last query time exists, refresh the resources now, which will set
		// a last query time.  This handles upgrades from < 1.9.7.4 where resources
		// would never expire.
		if ( ! $this->last_queried ) {
			return $this->refresh();
		}

		// If the resources have expired, refresh them now.
		if ( time() > ( $this->last_queried + $this->cache_duration ) ) {
			return $this->refresh();
		}

	}

	/**
	 * Returns all resources based on the sort order.
	 *
	 * @since   1.0.0
	 *
	 * @return  array
	 */
	public function get() {

		// Don't mutate the underlying resources, so multiple calls to get()
		// with different order_by and order properties are supported.
		$resources = $this->resources;

		// Don't attempt sorting if no resources exist.
		if ( ! $this->exist() ) {
			return $resources;
		}

		// Return resources sorted by order_by and order.
		return $this->sort( $resources );

	}

	/**
	 * Returns an individual resource by its ID.
	 *
	 * @since   1.0.0
	 *
	 * @param   int $id     Resource ID (Form, Tag, Sequence).
	 * @return  mixed           bool | array
	 */
	public function get_by_id( $id ) {

		foreach ( $this->get() as $resource ) {
			// If this resource's ID matches the ID we're looking for, return it.
			if ( (int) $resource['id'] === $id ) {
				return $resource;
			}
		}

		return false;

	}

	/**
	 * Returns resources where the resource's key matches the given value.
	 *
	 * @since   1.3.6
	 *
	 * @param   string       $key    Resource Key.
	 * @param   string|array $value  Value(s).
	 * @return  bool|array
	 */
	public function get_by( $key, $value ) {

		// Don't mutate the underlying resources, so multiple calls to get()
		// with different order_by and order properties are supported.
		$resources = $this->resources;

		// Don't attempt sorting if no resources exist.
		if ( ! $this->exist() ) {
			return $resources;
		}

		foreach ( $resources as $id => $resource ) {
			// Remove this resource if it doesn't have the array key.
			if ( ! array_key_exists( $key, $resource ) ) {
				unset( $resources[ $id ] );
				continue;
			}

			// Remove this resource if the value is an array and none of the array values match.
			if ( is_array( $value ) && ! in_array( $resource[ $key ], $value, true ) ) {
				unset( $resources[ $id ] );
				continue;
			}

			// Remove this resource if the value doesn't match.
			if ( ! is_array( $value ) && $resource[ $key ] !== $value ) {
				unset( $resources[ $id ] );
				continue;
			}
		}

		// If the array is empty, return false.
		if ( empty( $resources ) ) {
			return false;
		}

		// Return resources sorted by order_by and order.
		return $this->sort( $resources );

	}

	/**
	 * Sorts the given array of resources by the class' order_by and order properties.
	 *
	 * @since   1.3.6
	 *
	 * @param   array $resources  Resources.
	 * @return  array               Resources
	 */
	public function sort( $resources ) {

		// Don't attempt sorting if the order_by property doesn't exist as a key
		// in the API response.
		if ( ! array_key_exists( $this->order_by, reset( $resources ) ) ) {
			return $resources;
		}

		// Sort resources ascending by the order_by property.
		uasort(
			$resources,
			function ( $a, $b ) {
				return strcmp( $a[ $this->order_by ], $b[ $this->order_by ] );
			}
		);

		// Reverse the array if the results should be returned in descending order.
		if ( $this->order === 'desc' ) {
			$resources = array_reverse( $resources, true );
		}

		return $resources;

	}

	/**
	 * Returns a paginated subset of resources, including whether
	 * previous and next resources in the array exist.
	 *
	 * @since   1.0.0
	 *
	 * @param   int $page   Current Page.
	 * @param   int $per_page   Number of resources to return per page.
	 * @return  array
	 */
	public function get_paginated_subset( $page, $per_page ) {

		// Calculate the maximum value for $page.
		$total_pages = ( ( $per_page > 0 ) ? ceil( $this->count() / $per_page ) : 1 );

		// If $page exceeds the total number of possible pages, reduce it.
		if ( $page > $total_pages ) {
			$page = $total_pages;
		}

		// If $page is less than 1, set it to 1.
		if ( $page < 1 ) {
			$page = 1;
		}

		return array(
			// The subset of items based on the pagination.
			'items'         => array_slice( $this->get(), ( $page * $per_page ) - $per_page, $per_page ),

			// Sanitized inputs.
			'page'          => $page,
			'per_page'      => $per_page,

			// The total number of pages in the pagination.
			'total_pages'   => $total_pages,

			// If the request page is lower than the total number of pages in the pagination, there's a next page.
			'has_next_page' => ( ( $page < $total_pages ) ? true : false ),

			// If the request page is higher than 1, there's a previous page.
			'has_prev_page' => ( ( $page > 1 ) ? true : false ),
		);

	}

	/**
	 * Returns the number of resources.
	 *
	 * @since   1.0.0
	 *
	 * @return  int
	 */
	public function count() {

		return count( $this->resources );

	}

	/**
	 * Returns whether any resources exist in the options table.
	 *
	 * @since   1.0.0
	 *
	 * @return  bool
	 */
	public function exist() {

		if ( $this->resources === false ) {
			return false;
		}

		if ( is_wp_error( $this->resources ) ) {
			return false;
		}

		if ( is_null( $this->resources ) ) {
			return false;
		}

		return ( count( $this->resources ) ? true : false );

	}

	/**
	 * Fetches resources (forms, landing pages or tags) from the API, storing them in the options table
	 * with a last queried timestamp.
	 *
	 * @since   1.0.0
	 *
	 * @return  WP_Error|array
	 */
	public function refresh() {

		// Bail if no API class was defined.
		if ( ! $this->api ) {
			return new WP_Error( 'convertkit_resource_refresh_error', 'Connect the Plugin to your Kit account to refresh resources.' );
		}

		// Fetch resources.
		switch ( $this->type ) {
			case 'forms':
			case 'landing_pages':
				$resources = $this->get_all_resources( $this->type );

				// Bail if an error occured, as we don't want to cache errors.
				if ( is_wp_error( $resources ) ) {
					return $resources;
				}

				// Fetch legacy forms / landing pages.
				$legacy_resources = $this->get_all_resources( 'legacy_' . $this->type );

				// Bail if an error occured, as we don't want to cache errors.
				if ( is_wp_error( $legacy_resources ) ) {
					return $legacy_resources;
				}

				// Combine.
				$results = $resources + $legacy_resources;
				break;

			case 'tags':
			case 'sequences':
			case 'custom_fields':
				$results = $this->get_all_resources( $this->type );
				break;

			case 'posts':
				$results = $this->api->get_all_posts();
				break;

			case 'products':
				$results = $this->api->get_products();
				break;

			default:
				$results = new WP_Error(
					'convertkit_resource_refresh_error',
					sprintf(
						'Resource type %s is not supported in ConvertKit_Resource class.',
						$this->type
					)
				);
				break;
		}

		// Define and store the last query time now.
		// This prevents multiple calls to refresh() when the above returns a 401 error.
		$this->last_queried = time();
		update_option( $this->settings_name . '_last_queried', $this->last_queried );

		// Bail if an error occured, as we don't want to cache errors.
		if ( is_wp_error( $results ) ) {
			return $results;
		}

		// Store resources in the options table.
		// We don't use WordPress' Transients API (i.e. auto expiring options), because they're prone to being
		// flushed by some third party "optimization" Plugins. They're also not guaranteed to remain in the options
		// table for the amount of time specified; any expiry is a maximum, not a minimum.
		// We don't want to keep querying the ConvertKit API for a list of e.g. forms, tags that rarely change as
		// a result of transients not being honored, so storing them as options with a separate, persistent expiry
		// value is more reliable here.
		update_option( $this->settings_name, $results );

		// Store resources in class variable.
		$this->resources = $results;

		/**
		 * Perform any actions immediately after the resource has been refreshed.
		 *
		 * @since   1.2.1
		 *
		 * @param   array   $results    Resources
		 */
		do_action( 'convertkit_resource_refreshed_' . $this->type, $results );

		// Return resources, honoring the order_by and order properties.
		return $this->get();

	}

	/**
	 * Schedules a WordPress Cron event to refresh this resource based on
	 * the resource's $wp_cron_schedule.
	 *
	 * @since   1.0.0
	 */
	public function schedule_cron_event() {

		// Bail if no cron schedule is defined for this resource.
		if ( ! $this->wp_cron_schedule ) {
			return;
		}

		// Bail if the event already exists; we don't need to schedule it again.
		if ( $this->get_cron_event() !== false ) {
			return;
		}

		// Schedule event, starting in an hour's time and recurring for the given $wp_cron_schedule.
		wp_schedule_event(
			strtotime( '+1 hour' ), // Start in an hour's time.
			$this->wp_cron_schedule, // Repeat based on the given schedule e.g. hourly.
			'convertkit_resource_refresh_' . $this->type // Hook name; see includes/cron-functions.php for function that listens to this hook.
		);

	}

	/**
	 * Unschedules a WordPress Cron event to refresh this resource.
	 *
	 * @since   1.0.0
	 */
	public function unschedule_cron_event() {

		wp_clear_scheduled_hook( 'convertkit_resource_refresh_' . $this->type );

	}

	/**
	 * Returns how often the WordPress Cron event will recur for (e.g. daily).
	 *
	 * Returns false if no schedule exists i.e. wp_schedule_event() has not been
	 * called or failed to register a scheduled event.
	 *
	 * @since   1.0.0
	 *
	 * @return  bool|string
	 */
	public function get_cron_event() {

		return wp_get_schedule( 'convertkit_resource_refresh_' . $this->type );

	}

	/**
	 * Returns the timestamp for when the WordPress Cron event is next scheduled to run.
	 *
	 * @since   1.3.8
	 *
	 * @return  bool|int
	 */
	public function get_cron_event_next_scheduled() {

		return wp_next_scheduled( 'convertkit_resource_refresh_' . $this->type );

	}

	/**
	 * Deletes resources (forms, landing pages or tags) from the options table.
	 *
	 * @since   1.0.0
	 */
	public function delete() {

		delete_option( $this->settings_name );
		delete_option( $this->settings_name . '_last_queried' );

	}

	/**
	 * Fetches all resources (forms, landing pages, tags etc) from the API,
	 * using cursor pagination until all results are returned.
	 *
	 * @since   2.0.0
	 *
	 * @param   string $resource_type   Resource (forms,landing_pages,tags,sequences,custom_fields).
	 * @param   int    $per_page        Number of results to return per request.
	 */
	private function get_all_resources( $resource_type, $per_page = 100 ) {

		// Build array of arguments depending on the resource type.
		switch ( $resource_type ) {
			case 'forms':
			case 'landing_pages':
				$args = array(
					'active',
					false,
					'',
					'',
					$per_page,
				);
				break;

			case 'legacy_forms':
			case 'legacy_landing_pages':
				$args = array(
					false,
					'',
					'',
					$per_page,
				);
				break;

			default:
				$args = array(
					false,
					'',
					'',
					$per_page,
				);
				break;
		}

		// Fetch resources.
		$response = call_user_func_array(
			array( $this->api, 'get_' . $resource_type ),
			$args
		);

		// Bail if an error occured.
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		// Append resources to array.
		$items = $this->map( $response, array(), $resource_type );

		// If no further resources to fetch, return.
		if ( ! $response['pagination']['has_next_page'] ) {
			return $items;
		}

		// Further resources need to be fetched.
		while ( $response['pagination']['has_next_page'] ) {
			// Build array of arguments depending on the resource type.
			switch ( $resource_type ) {
				case 'forms':
				case 'landing_pages':
					$args = array(
						'active',
						false,
						$response['pagination']['end_cursor'],
						'',
						$per_page,
					);
					break;

				case 'legacy_forms':
				case 'legacy_landing_pages':
					$args = array(
						false,
						$response['pagination']['end_cursor'],
						'',
						$per_page,
					);
					break;

				default:
					$args = array(
						false,
						$response['pagination']['end_cursor'],
						'',
						$per_page,
					);
					break;
			}

			// Fetch next page of resources.
			$response = call_user_func_array(
				array(
					$this->api,
					'get_' . $resource_type,
				),
				$args
			);

			// Bail if an error occured.
			if ( is_wp_error( $response ) ) {
				return $response;
			}

			// Append resources to array.
			$items = $this->map( $response, $items, $resource_type );
		}

		return $items;

	}

	/**
	 * Helper method to build an array of resources with keys as IDs.
	 *
	 * @since   2.0.0
	 *
	 * @param   array  $response        API Response.
	 * @param   array  $items           Key'd resources.
	 * @param   string $resource_type   Resource (forms,landing_pages,tags,sequences,custom_fields).
	 */
	private function map( $response, $items = array(), $resource_type = 'forms' ) {

		// If we're building an array of landing pages, use the appropriate key.
		switch ( $resource_type ) {
			case 'landing_pages':
				$type = 'forms';
				break;

			case 'legacy_forms':
			case 'legacy_landing_pages':
				$type = 'legacy_landing_pages';
				break;

			default:
				$type = $resource_type;
				break;
		}

		foreach ( $response[ $type ] as $item ) {
			// Exclude Forms that have a null `format` value, as they are Creator Profile / Creator Network
			// forms that we don't need in WordPress.
			// Legacy forms don't have a `format` key, and we always want to include them in the resultset.
			if ( $resource_type === 'forms' && array_key_exists( 'format', $item ) && is_null( $item['format'] ) ) {
				continue;
			}

			$items[ $item['id'] ] = $item;
		}

		return $items;

	}

}
