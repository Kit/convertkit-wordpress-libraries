<?php

namespace Tests;

use lucatume\WPBrowser\TestCase\WPTestCase;

/**
 * Tests for the ConvertKit_Log class.
 *
 * @since   2.6.1
 */
class LogTest extends WPTestCase
{
	/**
	 * The testing implementation.
	 *
	 * @var \IntegrationTester
	 */
	protected $tester;

	/**
	 * Holds the ConvertKit Log class.
	 *
	 * @since   2.6.1
	 *
	 * @var     ConvertKit_Log
	 */
	private $log;

	/**
	 * The path to a directory used in tests to mimic a Plugin's directory.
	 *
	 * @since   2.6.1
	 *
	 * @var     string
	 */
	private $plugin_path;

	/**
	 * Holds the WP_Filesystem global, which one test replaces.
	 *
	 * @since   2.6.1
	 *
	 * @var     mixed
	 */
	private $wpFilesystem;

	/**
	 * Recursively deletes the given directory and its contents.
	 *
	 * @since   2.6.1
	 *
	 * @param   string $path   Directory path.
	 */
	private function deleteDirectory($path)
	{
		if ( ! is_dir($path)) {
			return;
		}

		foreach (scandir($path) as $file) {
			if ($file === '.' || $file === '..') {
				continue;
			}

			$filePath = trailingslashit($path) . $file;

			if (is_dir($filePath)) {
				$this->deleteDirectory($filePath);
				continue;
			}

			unlink($filePath); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		}

		rmdir($path); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
	}

	/**
	 * Performs actions before each test.
	 *
	 * @since   2.6.1
	 */
	public function setUp(): void
	{
		parent::setUp();

		// Include class from /src to test.
		require_once 'src/class-convertkit-log.php';

		// Store the WP_Filesystem global, as one test replaces it.
		$this->wpFilesystem = $GLOBALS['wp_filesystem'] ?? null;

		// Create a directory to mimic a Plugin's directory.
		$this->plugin_path = trailingslashit(WP_CONTENT_DIR) . 'plugins/convertkit-log-test';
		wp_mkdir_p($this->plugin_path);
	}

	/**
	 * Performs actions after each test.
	 *
	 * @since   2.6.1
	 */
	public function tearDown(): void
	{
		// Restore the WP_Filesystem global.
		$GLOBALS['wp_filesystem'] = $this->wpFilesystem; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited

		// Delete the log file.
		if ($this->log) {
			$this->log->delete();
			unset($this->log);
		}

		// Delete the directory mimicking a Plugin's directory.
		$this->deleteDirectory($this->plugin_path);

		parent::tearDown();
	}

	/**
	 * Test that the log file is stored in the uploads directory, and not in the
	 * Plugin's directory.
	 *
	 * Storing the log file in the Plugin's directory results in
	 * `wp plugin verify-checksums` reporting the Plugin as modified.
	 *
	 * @since   2.6.1
	 */
	public function testLogFileIsStoredInUploadsDirectory()
	{
		$this->log = new \ConvertKit_Log($this->plugin_path);
		$this->log->add('Log entry');

		// Confirm the log file is in the uploads directory.
		$uploadDir = wp_upload_dir();
		$this->assertStringStartsWith(
			trailingslashit($uploadDir['basedir']) . 'kit-logs/',
			$this->log->get_filename()
		);
		$this->assertTrue($this->log->exists());

		// Confirm the log file's name includes the Plugin's directory name, so that
		// each Kit Plugin writes to its own log file.
		$this->assertStringContainsString('convertkit-log-test-', basename($this->log->get_filename()));
		$this->assertStringEndsWith('.log', $this->log->get_filename());

		// Confirm no log file or directory was created in the Plugin's directory.
		$this->assertFileDoesNotExist(trailingslashit($this->plugin_path) . 'log.txt');
		$this->assertDirectoryDoesNotExist(trailingslashit($this->plugin_path) . 'log');
	}

	/**
	 * Test that the uploads directory containing the log file has .htaccess and
	 * index.html files, to prevent listing and access on Apache.
	 *
	 * @since   2.6.1
	 */
	public function testLogDirectoryIsProtected()
	{
		$this->log = new \ConvertKit_Log($this->plugin_path);

		$uploadDir = wp_upload_dir();
		$this->assertFileExists(trailingslashit($uploadDir['basedir']) . 'kit-logs/.htaccess');
		$this->assertFileExists(trailingslashit($uploadDir['basedir']) . 'kit-logs/index.html');
	}

	/**
	 * Test that a log.txt file, as created by versions prior to 1.4.2, is deleted
	 * from the Plugin's directory.
	 *
	 * @since   2.6.1
	 */
	public function testHistoricLogFileIsDeleted()
	{
		// Create a log.txt file, as versions prior to 1.4.2 did.
		$historicLogFile = trailingslashit($this->plugin_path) . 'log.txt';
		file_put_contents($historicLogFile, 'Log entry'); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$this->assertFileExists($historicLogFile);

		$this->log = new \ConvertKit_Log($this->plugin_path);

		// Confirm the historic log file was deleted.
		$this->assertFileDoesNotExist($historicLogFile);
	}

	/**
	 * Test that the log directory, as created by versions 1.4.2 to 2.6.0, is deleted
	 * from the Plugin's directory.
	 *
	 * @since   2.6.1
	 */
	public function testHistoricLogDirectoryIsDeleted()
	{
		// Create a log directory, as versions 1.4.2 to 2.6.0 did.
		$historicLogPath = trailingslashit($this->plugin_path) . 'log';
		wp_mkdir_p($historicLogPath);
		foreach ([ 'log.txt', '.htaccess', 'index.html' ] as $file) {
			file_put_contents(trailingslashit($historicLogPath) . $file, ''); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
		$this->assertDirectoryExists($historicLogPath);

		$this->log = new \ConvertKit_Log($this->plugin_path);

		// Confirm the historic log directory and its contents were deleted.
		$this->assertDirectoryDoesNotExist($historicLogPath);
	}

	/**
	 * Test that entries can be added to, read from, cleared and deleted from the log file.
	 *
	 * @since   2.6.1
	 */
	public function testAddReadClearAndDelete()
	{
		$this->log = new \ConvertKit_Log($this->plugin_path);

		// Add.
		$this->log->add('Log entry');
		$this->assertStringContainsString('Log entry', $this->log->read());

		// Clear.
		$this->log->clear();
		$this->assertStringNotContainsString('Log entry', $this->log->read());
		$this->assertTrue($this->log->exists());

		// Delete.
		$this->log->delete();
		$this->assertFalse($this->log->exists());
	}

	/**
	 * Test that the log is written, and historic log files deleted, when WordPress'
	 * WP_Filesystem is unusable.
	 *
	 * On hosts where WordPress selects the FTP transport and no FTP credentials are
	 * defined, $wp_filesystem is a WP_Filesystem_FTPext instance that failed to
	 * connect. Calling any of its methods passes a null connection to PHP's ftp_*
	 * functions, resulting in a fatal error. This class must therefore never call
	 * WP_Filesystem.
	 *
	 * @since   2.6.1
	 */
	public function testLogIsWrittenWhenWPFilesystemIsUnusable()
	{
		// Replace the WP_Filesystem global with an object that fails the test if any
		// of its methods are called.
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$GLOBALS['wp_filesystem'] = new class() {
			/**
			 * Fails the test if any WP_Filesystem method is called.
			 *
			 * @since   2.6.1
			 *
			 * @param   string $name       Method name.
			 * @param   array  $arguments  Method arguments.
			 *
			 * @throws  \RuntimeException   If a WP_Filesystem method is called.
			 */
			public function __call($name, $arguments)
			{
				throw new \RuntimeException(esc_html('ConvertKit_Log must not call WP_Filesystem::' . $name . '()'));
			}
		};

		// Create a historic log directory, to confirm it is deleted without calling
		// WP_Filesystem.
		$historicLogPath = trailingslashit($this->plugin_path) . 'log';
		wp_mkdir_p($historicLogPath);
		file_put_contents(trailingslashit($historicLogPath) . 'log.txt', ''); // phpcs:ignore WordPress.WP.AlternativeFunctions

		$this->log = new \ConvertKit_Log($this->plugin_path);
		$this->log->add('Log entry');

		// Confirm the historic log directory was deleted.
		$this->assertDirectoryDoesNotExist($historicLogPath);

		// Confirm the log was written and can be read.
		$this->assertTrue($this->log->exists());
		$this->assertStringContainsString('Log entry', $this->log->read());
	}

	/**
	 * Test that email addresses are masked in log entries.
	 *
	 * @since   2.6.1
	 */
	public function testEmailAddressesAreMasked()
	{
		$this->log = new \ConvertKit_Log($this->plugin_path);
		$this->log->add('Subscribing user@convertkit.com');

		$this->assertStringNotContainsString('user@convertkit.com', $this->log->read());
	}
}
