<?php
/**
 * ConvertKit Review Request class.
 *
 * @package ConvertKit
 * @author ConvertKit
 */

/**
 * Displays a one time review request notification in the WordPress
 * Administration interface.
 *
 * @package ConvertKit
 * @author ConvertKit
 */
class ConvertKit_Review_Request {

	/**
	 * Holds the Plugin name.
	 *
	 * @since   1.0.0
	 *
	 * @var     string
	 */
	private $plugin_name;

	/**
	 * Holds the Plugin slug.
	 *
	 * @since   1.0.0
	 *
	 * @var     string
	 */
	private $plugin_slug;

	/**
	 * Holds the text items to display on the review request notification.
	 *
	 * @since   1.3.4
	 *
	 * @var     array
	 */
	private $text_items;

	/**
	 * Holds the number of days after the Plugin requests a review to then
	 * display the review notification in WordPress' Administration interface.
	 *
	 * @since   1.0.0
	 *
	 * @var     int
	 */
	private $number_of_days_in_future = 3;

	/**
	 * Registers action and filter hooks.
	 *
	 * @since   1.0.0
	 *
	 * @param   string $plugin_name    Plugin Name (e.g. ConvertKit).
	 * @param   string $plugin_slug    Plugin Slug (e.g. convertkit).
	 * @param   string $plugin_path    Plugin Path (unused, but kept for backward compat. with Plugins that include this argument).
	 */
	public function __construct( $plugin_name, $plugin_slug, $plugin_path ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter, @phpstan-ignore-line

		// Store the Plugin name, slug and text items.
		$this->plugin_name = $plugin_name;
		$this->plugin_slug = $plugin_slug;
		$this->text_items  = array(
			'message'       => sprintf(
				'We\'d be super grateful if you could spread the word about %s and give it a 5 star rating on WordPress?',
				$this->plugin_name
			),
			'leave_review'  => 'Yes, leave review',
			'having_issues' => sprintf(
				'No, I\'m having issues with %s',
				$this->plugin_name
			),
		);

		// Register an AJAX action to dismiss the review.
		add_action( 'wp_ajax_' . str_replace( '-', '_', $this->plugin_slug ) . '_dismiss_review', array( $this, 'dismiss_review' ) );

		// Maybe display a review request in the WordPress Admin notices.
		add_action( 'admin_notices', array( $this, 'maybe_display_review_request' ) );

	}

	/**
	 * Displays a dismissible WordPress Administration notice requesting a review, if requested
	 * by the main Plugin and the Review Request hasn't been disabled.
	 *
	 * @since   1.0.0
	 */
	public function maybe_display_review_request() {

		// If we're not an Admin user, bail.
		if ( ! function_exists( 'current_user_can' ) ) {
			return;
		}
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		// Don't display a review request on multisite.  This is so that existing Plugin
		// users who existed prior to this feature don't get bombarded with the same
		// notification across 100+ of their sites on a multisite network.
		if ( is_multisite() ) {
			return;
		}

		// If the review request was dismissed by the user, bail.
		if ( $this->dismissed_review() ) {
			return;
		}

		// If no review request has been set by the plugin, bail.
		if ( ! $this->requested_review() ) {
			return;
		}

		// If here, display the request for a review.
		include_once 'views/review-request.php';

	}

	/**
	 * Returns the text to display at the start of the review request notification.
	 *
	 * @since   1.3.4
	 *
	 * @return  string
	 */
	public function get_message_text() {

		// Return blank string if message text doesn't exist in the array.
		if ( ! array_key_exists( 'message', $this->text_items ) ) {
			return '';
		}

		return $this->text_items['message'];

	}

	/**
	 * Returns the text to display prompting the user to leave a review.
	 *
	 * @since   1.3.4
	 *
	 * @return  string
	 */
	public function get_leave_review_text() {

		// Return blank string if leave review text doesn't exist in the array.
		if ( ! array_key_exists( 'leave_review', $this->text_items ) ) {
			return '';
		}

		return $this->text_items['leave_review'];

	}

	/**
	 * Returns the text to display if the user is having issues with the Plugin.
	 *
	 * @since   1.3.4
	 *
	 * @return  string
	 */
	public function get_having_issues_text() {

		// Return blank string if leave review text doesn't exist in the array.
		if ( ! array_key_exists( 'having_issues', $this->text_items ) ) {
			return '';
		}

		return $this->text_items['having_issues'];

	}

	/**
	 * Sets a flag in the options table requesting a review notification be displayed
	 * in the WordPress Administration.
	 *
	 * @since   1.0.0
	 */
	public function request_review() {

		// If a review has already been requested, bail.
		$time = get_option( $this->plugin_slug . '-review-request' );
		if ( ! empty( $time ) ) {
			return;
		}

		// Request a review notification to be displayed beginning at a future timestamp.
		update_option( $this->plugin_slug . '-review-request', time() + ( $this->number_of_days_in_future * DAY_IN_SECONDS ) );

	}

	/**
	 * Flag to indicate whether a review has been requested by the Plugin,
	 * and the minimum time has passed between the Plugin requesting a review
	 * and now.
	 *
	 * @since   1.0.0
	 *
	 * @return  bool    Review Requested
	 */
	public function requested_review() {

		// Bail if no review was requested by the Plugin.
		$start_displaying_review_at = get_option( $this->plugin_slug . '-review-request' );
		if ( empty( $start_displaying_review_at ) ) {
			return false;
		}

		// Bail if a review was requested by the Plugin, but it's too early to display it.
		if ( $start_displaying_review_at > time() ) {
			return false;
		}

		// The Plugin requested a review and it's time to display the notification.
		return true;

	}

	/**
	 * Dismisses the review notification, so it isn't displayed again.
	 *
	 * @since   1.0.0
	 */
	public function dismiss_review() {

		update_option( $this->plugin_slug . '-review-dismissed', 1 );

		// Send success response if called via AJAX.
		if ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() ) {
			wp_send_json_success( 1 );
		}

	}

	/**
	 * Flag to indicate whether a review request has been dismissed by the user.
	 *
	 * @since   1.0.0
	 *
	 * @return  bool    Review Dismissed
	 */
	public function dismissed_review() {

		return get_option( $this->plugin_slug . '-review-dismissed' );

	}

	/**
	 * Returns the Review URL for this Plugin.
	 *
	 * @since   1.0.0
	 *
	 * @return  string  Review URL
	 */
	public function get_review_url() {

		return 'https://wordpress.org/support/plugin/' . $this->plugin_slug . '/reviews/?filter=5#new-post';

	}

	/**
	 * Returns the Support URL for this Plugin.
	 *
	 * @since   1.0.0
	 *
	 * @return  string  Review URL
	 */
	public function get_support_url() {

		return 'https://kit.com/support';

	}

}
