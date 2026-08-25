<?php
/**
 * ConvertKit Log class.
 *
 * @package ConvertKit
 * @author ConvertKit
 */

/**
 * Class to read and write to the ConvertKit log file.
 *
 * @since   1.0.0
 */
class ConvertKit_Log {

	/**
	 * The path to the directory that will contain the log file.
	 *
	 * @since   1.4.2
	 *
	 * @var     string
	 */
	private $path = '';

	/**
	 * The path and filename of the log file.
	 *
	 * @since   1.0.0
	 *
	 * @var     string
	 */
	private $log_file = '';

	/**
	 * Constructor. Defines the log file location.
	 *
	 * @since   1.0.0
	 *
	 * @param   string $path   Path to where log file should be created/edited/read.
	 */
	public function __construct( $path ) {

		// If historic log files exist in the Plugin's directory, delete them now.
		$this->maybe_delete_historic_log_files( $path );

		// Fetch the uploads directory.
		$upload_dir = wp_upload_dir();

		// Bail if the uploads directory is unavailable.
		if ( ! empty( $upload_dir['error'] ) || empty( $upload_dir['basedir'] ) ) {
			return;
		}

		// Define location of log file.
		$this->path     = trailingslashit( $upload_dir['basedir'] ) . 'kit-logs/';
		$this->log_file = $this->path . $this->get_log_file_name( $path );

		// If the secure log directory does not exist, create it now.
		$this->maybe_create_secure_log_directory();

	}

	/**
	 * Deletes log files stored in the Plugin's directory by earlier versions of
	 * this class.
	 *
	 * Deletes:
	 * - `log.txt`, used prior to 1.4.2, which has no .htaccess or index.html protection,
	 * - `log` directory and its contents, used from 1.4.2 to 2.6.0.
	 *
	 * @since   1.4.2
	 *
	 * @param   string $path   Path to the Plugin.
	 */
	private function maybe_delete_historic_log_files( $path ) {

		// If a log.txt file exists in the Plugin's directory (i.e. from 1.4.2 or earlier), delete it.
		$historic_file = trailingslashit( $path ) . 'log.txt';
		if ( file_exists( $historic_file ) ) {
			wp_delete_file( $historic_file );
		}

		// If a log directory exists in the Plugin's directory (i.e. from 1.4.2 to 2.6.0), delete it and its contents.
		$historic_path = trailingslashit( $path ) . 'log';
		if ( is_dir( $historic_path ) ) {
			// Delete the files this class created in the log directory.
			foreach ( array( 'log.txt', '.htaccess', 'index.html' ) as $file ) {
				if ( file_exists( trailingslashit( $historic_path ) . $file ) ) {
					wp_delete_file( trailingslashit( $historic_path ) . $file );
				}
			}

			// Delete the log directory.
			rmdir( trailingslashit( $path ) . 'log' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
		}

	}

	/**
	 * Creates a directory to store the log file, with .htaccess and index.html
	 * files to protect the log file, as WooCommerce does.
	 *
	 * Disables logging if the directory could not be created, or isn't writable.
	 *
	 * @since   1.4.2
	 */
	private function maybe_create_secure_log_directory() {

		// Create directory.
		wp_mkdir_p( $this->path );

		// Disable logging if the directory doesn't exist or isn't writable.
		if ( ! is_dir( $this->path ) || ! wp_is_writable( $this->path ) ) {
			$this->path     = '';
			$this->log_file = '';
			return;
		}

		// Define files to protect the directory.
		// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $this->path . '.htaccess', 'deny from all' );
		file_put_contents( $this->path . 'index.html', '' );
		// phpcs:enable WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

	}

	/**
	 * Returns the path and filename of the log file.
	 *
	 * @since   1.0.0
	 *
	 * @return string
	 */
	public function get_filename() {

		return $this->log_file;

	}

	/**
	 * Whether the log file exists.
	 *
	 * @since   1.0.0
	 *
	 * @return  bool
	 */
	public function exists() {

		// Bail if logging is disabled.
		if ( ! $this->log_file ) {
			return false;
		}

		return file_exists( $this->get_filename() );

	}

	/**
	 * Adds an entry to the log file.
	 *
	 * @since   1.0.0
	 *
	 * @param   string $entry  Log Line Entry.
	 */
	public function add( $entry ) {

		// Bail if logging is disabled.
		if ( ! $this->log_file ) {
			return;
		}

		// Prefix the entry with a date and time.
		$entry = '(' . gmdate( 'Y-m-d H:i:s' ) . ') ' . $entry . "\n";

		// Mask email addresses that may be contained within the entry.
		$entry = preg_replace_callback(
			'^[_a-z0-9-]+(\.[_a-z0-9-]+)*@[a-z0-9-]+(\.[a-z0-9-]+)*(\.[a-z]{2,3})^',
			function ( $matches ) {
				return preg_replace( '/\B[^@.]/', '*', $matches[0] );
			},
			$entry
		);

		// Append entry.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $this->get_filename(), $entry, FILE_APPEND );

	}

	/**
	 * Reads the given number of lines from the log file.
	 *
	 * @since   1.0.0
	 *
	 * @param   int $number_of_lines    Number of Lines.
	 * @return  string                      Log file data
	 */
	public function read( $number_of_lines = 500 ) {

		// Bail if the log file does not exist.
		if ( ! $this->exists() ) {
			return '';
		}

		// Open log file.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file
		$log = file( $this->get_filename() );

		// Bail if the log file is empty.
		if ( ! is_array( $log ) || ! count( $log ) ) {
			return '';
		}

		// Return a limited number of log lines for output.
		return implode( '', array_slice( $log, 0, $number_of_lines ) );

	}

	/**
	 * Clears the log file without deleting the log file.
	 *
	 * @since   1.0.0
	 */
	public function clear() {

		// Bail if logging is disabled.
		if ( ! $this->log_file ) {
			return;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $this->get_filename(), '' );

	}

	/**
	 * Deletes the log file.
	 *
	 * @since   1.0.0
	 */
	public function delete() {

		// Bail if logging is disabled.
		if ( ! $this->log_file ) {
			return;
		}

		wp_delete_file( $this->get_filename() );

	}

	/**
	 * Returns the log file's name for the Plugin at the given path.
	 *
	 * @since   2.6.1
	 *
	 * @param   string $path   Path to the Plugin.
	 * @return  string
	 */
	private function get_log_file_name( $path ) {

		$slug = sanitize_key( basename( untrailingslashit( $path ) ) );

		return $slug . '-' . wp_hash( 'convertkit-log-' . $slug ) . '.log';

	}

}
