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
	private $path;

	/**
	 * The path and filename of the log file.
	 *
	 * @since   1.0.0
	 *
	 * @var     string
	 */
	private $log_file;

	/**
	 * Constructor. Defines the log file location.
	 *
	 * @since   1.0.0
	 *
	 * @param   string $path   Path to where log file should be created/edited/read.
	 */
	public function __construct( $path ) {

		// Define location of log file.
		$this->path = $path;
		$this->log_file = trailingslashit( $path ) . 'log.txt';

		// Initialize WP_Filesystem.
		require_once ABSPATH . 'wp-admin/includes/file.php';
		WP_Filesystem();

		// If the path does not exist, create it now.
		$this->maybe_create_log_directory();

	}

	/**
	 * Creates a directory to store the log file, with .htaccess and index.html
	 * files to protect the log file, as WooCommerce does.
	 * 
	 * @since 	1.4.2
	 */
	private function maybe_create_log_directory() {

		// Define files to protect the directory.
		$files = array(
			array(
				'base'    => $this->path,
				'file'    => '.htaccess',
				'content' => 'deny from all',
			),
			array(
				'base'    => $this->path,
				'file'    => 'index.html',
				'content' => '',
			),
		);

		foreach ( $files as $file ) {
			if ( wp_mkdir_p( $file['base'] ) && ! file_exists( trailingslashit( $file['base'] ) . $file['file'] ) ) {
				$file_handle = @fopen( trailingslashit( $file['base'] ) . $file['file'], 'wb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_read_fopen
				if ( $file_handle ) {
					fwrite( $file_handle, $file['content'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_fwrite
					fclose( $file_handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_fclose
				}
			}
		}

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

		// Initialize WordPress file system.
		global $wp_filesystem;

		// Prefix the entry with a date and time.
		$entry = '(' . gmdate( 'Y-m-d H:i:s' ) . ') ' . $entry . "\n";

		// Get any existing log file contents.
		$contents = $wp_filesystem->get_contents( $this->get_filename() );

		// Mask email addresses that may be contained within the entry.
		$entry = preg_replace_callback(
			'^[_a-z0-9-]+(\.[_a-z0-9-]+)*@[a-z0-9-]+(\.[a-z0-9-]+)*(\.[a-z]{2,3})^',
			function( $matches ) {
				return preg_replace( '/\B[^@.]/', '*', $matches[0] );
	        },
	        $entry
	    );

		// Append entry.
		$contents .= $entry;

		// Write contents.
		$wp_filesystem->put_contents( $this->get_filename(), $contents );

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

		// Initialize WordPress file system.
		global $wp_filesystem;

		// Bail if the log file does not exist.
		if ( ! $this->exists() ) {
			return '';
		}

		// Open log file.
		$log = $wp_filesystem->get_contents_array( $this->get_filename() );

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

		// Initialize WordPress file system.
		global $wp_filesystem;

		$wp_filesystem->put_contents( $this->get_filename(), '' );

	}

	/**
	 * Deletes the log file.
	 *
	 * @since   1.0.0
	 */
	public function delete() {

		wp_delete_file( $this->get_filename() );

	}

}
