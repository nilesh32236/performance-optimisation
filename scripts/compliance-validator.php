<?php
/**
 * WordPress.org Compliance Validator
 *
 * Validates plugin compliance with WordPress.org directory requirements.
 *
 * @package PerformanceOptimisation
 * @since 2.0.0
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Compliance Validator Class
 */
class ComplianceValidator {

	/**
	 * Plugin path.
	 *
	 * @var string
	 */
	private string $plugin_path;

	/**
	 * Validation results.
	 *
	 * @var array<string, mixed>
	 */
	private array $results = array();

	/**
	 * Constructor.
	 *
	 * @param string $plugin_path Plugin path.
	 */
	public function __construct( string $plugin_path ) {
		$this->plugin_path = $plugin_path;
	}

	/**
	 * Run all compliance validations.
	 *
	 * @return array<string, mixed> Validation results.
	 */
	public function validate(): array {
		$this->validate_plugin_headers();
		$this->validate_readme_file();
		$this->validate_license_compliance();
		$this->validate_security_practices();
		$this->validate_wordpress_standards();
		$this->validate_file_structure();
		$this->validate_functionality();

		return $this->results;
	}

	/**
	 * Validate plugin headers.
	 *
	 * @return void
	 */
	private function validate_plugin_headers(): void {
		$main_file = $this->plugin_path . '/performance-optimisation.php';
		$content   = file_get_contents( $main_file );

		$required_headers = array(
			'Plugin Name' => '/Plugin Name:\s*(.+)/i',
			'Plugin URI'  => '/Plugin URI:\s*(.+)/i',
			'Description' => '/Description:\s*(.+)/i',
			'Version'     => '/Version:\s*(.+)/i',
			'Author'      => '/Author:\s*(.+)/i',
			'License'     => '/License:\s*(.+)/i',
			'Text Domain' => '/Text Domain:\s*(.+)/i',
		);

		$missing_headers = array();
		foreach ( $required_headers as $header => $pattern ) {
			if ( ! preg_match( $pattern, $content ) ) {
				$missing_headers[] = $header;
			}
		}

		$this->results['plugin_headers'] = array(
			'status'          => empty( $missing_headers ) ? 'pass' : 'fail',
			'missing_headers' => $missing_headers,
			'message'         => empty( $missing_headers )
				? 'All required plugin headers are present'
				: 'Missing required headers: ' . implode( ', ', $missing_headers ),
		);
	}

	/**
	 * Validate readme.txt file.
	 *
	 * @return void
	 */
	private function validate_readme_file(): void {
		$readme_file = $this->plugin_path . '/readme.txt';

		if ( ! file_exists( $readme_file ) ) {
			$this->results['readme_file'] = array(
				'status'  => 'fail',
				'message' => 'readme.txt file is missing',
			);
			return;
		}

		$content = file_get_contents( $readme_file );
		$issues  = array();

		// Check required sections
		$required_sections = array(
			'=== Plugin Name ===' => '/===\s*(.+?)\s*===/i',
			'Contributors'        => '/Contributors:\s*(.+)/i',
			'Tags'                => '/Tags:\s*(.+)/i',
			'Requires at least'   => '/Requires at least:\s*(.+)/i',
			'Tested up to'        => '/Tested up to:\s*(.+)/i',
			'Stable tag'          => '/Stable tag:\s*(.+)/i',
			'License'             => '/License:\s*(.+)/i',
			'== Description =='   => '/==\s*Description\s*==/i',
			'== Installation =='  => '/==\s*Installation\s*==/i',
			'== Changelog =='     => '/==\s*Changelog\s*==/i',
		);

		foreach ( $required_sections as $section => $pattern ) {
			if ( ! preg_match( $pattern, $content ) ) {
				$issues[] = "Missing section: {$section}";
			}
		}

		// Check for proper formatting
		if ( ! preg_match( '/^=== .+ ===$/m', $content ) ) {
			$issues[] = 'Plugin name header not properly formatted';
		}

		$this->results['readme_file'] = array(
			'status'  => empty( $issues ) ? 'pass' : 'fail',
			'issues'  => $issues,
			'message' => empty( $issues )
				? 'readme.txt file is properly formatted'
				: 'readme.txt has formatting issues',
		);
	}

	/**
	 * Validate license compliance.
	 *
	 * @return void
	 */
	private function validate_license_compliance(): void {
		$issues = array();

		// Check for LICENSE file
		$license_files    = array( 'LICENSE', 'LICENSE.txt', 'license.txt' );
		$has_license_file = false;

		foreach ( $license_files as $license_file ) {
			if ( file_exists( $this->plugin_path . '/' . $license_file ) ) {
				$has_license_file = true;
				break;
			}
		}

		if ( ! $has_license_file ) {
			$issues[] = 'Missing LICENSE file';
		}

		// Check main plugin file for GPL license
		$main_file = $this->plugin_path . '/performance-optimisation.php';
		$content   = file_get_contents( $main_file );

		if ( ! preg_match( '/GPL|General Public License/i', $content ) ) {
			$issues[] = 'Main plugin file missing GPL license information';
		}

		// Check for proper license headers in PHP files
		$php_files             = $this->get_php_files();
		$files_without_license = array();

		foreach ( array_slice( $php_files, 0, 10 ) as $file ) { // Check first 10 files
			$file_content = file_get_contents( $file );
			if ( ! preg_match( '/@license|GPL|General Public License/i', $file_content ) ) {
				$files_without_license[] = basename( $file );
			}
		}

		if ( ! empty( $files_without_license ) ) {
			$issues[] = 'Some PHP files missing license headers: ' . implode( ', ', $files_without_license );
		}

		$this->results['license_compliance'] = array(
			'status'  => empty( $issues ) ? 'pass' : 'fail',
			'issues'  => $issues,
			'message' => empty( $issues )
				? 'License compliance is correct'
				: 'License compliance issues found',
		);
	}

	/**
	 * Validate security practices.
	 *
	 * @return void
	 */
	private function validate_security_practices(): void {
		// Run the security check
		require_once $this->plugin_path . '/scripts/security-check.php';
		$security_check   = new SecurityCheck( $this->plugin_path );
		$security_results = $security_check->run_checks();

		$this->results['security_practices'] = array(
			'status'       => $security_results['passed'] ? 'pass' : 'fail',
			'issues_found' => $security_results['issues_found'],
			'details'      => $security_results['issues'],
			'message'      => $security_results['passed']
				? 'All security checks passed'
				: "Security issues found: {$security_results['issues_found']}",
		);
	}

	/**
	 * Validate WordPress coding standards.
	 *
	 * @return void
	 */
	private function validate_wordpress_standards(): void {
		$issues = array();

		// Check for WordPress functions usage
		$php_files = array_slice( $this->get_php_files(), 0, 5 ); // Check first 5 files

		foreach ( $php_files as $file ) {
			$content = file_get_contents( $file );

			// Check for proper WordPress hooks usage
			if ( strpos( $content, 'add_action' ) !== false || strpos( $content, 'add_filter' ) !== false ) {
				// Good - using WordPress hooks
			}

			// Check for direct database access
			if ( preg_match( '/mysql_|mysqli_/', $content ) ) {
				$issues[] = basename( $file ) . ': Direct MySQL usage detected';
			}

			// Check for proper nonce usage in forms
			if ( preg_match( '/<form/i', $content ) && ! preg_match( '/wp_nonce_field|wp_create_nonce/', $content ) ) {
				$issues[] = basename( $file ) . ': Form without nonce protection';
			}
		}

		$this->results['wordpress_standards'] = array(
			'status'  => empty( $issues ) ? 'pass' : 'warning',
			'issues'  => $issues,
			'message' => empty( $issues )
				? 'WordPress coding standards followed'
				: 'Some WordPress standards issues found',
		);
	}

	/**
	 * Validate file structure.
	 *
	 * @return void
	 */
	private function validate_file_structure(): void {
		$issues = array();

		// Check for required files
		$required_files = array(
			'performance-optimisation.php' => 'Main plugin file',
			'readme.txt'                   => 'Plugin readme file',
		);

		foreach ( $required_files as $file => $description ) {
			if ( ! file_exists( $this->plugin_path . '/' . $file ) ) {
				$issues[] = "Missing {$description}: {$file}";
			}
		}

		// Check for proper directory structure
		$expected_dirs = array( 'includes', 'admin', 'assets' );
		foreach ( $expected_dirs as $dir ) {
			if ( ! is_dir( $this->plugin_path . '/' . $dir ) ) {
				$issues[] = "Missing directory: {$dir}";
			}
		}

		// Check for unwanted files
		$unwanted_patterns = array( '.DS_Store', 'Thumbs.db', '*.log', 'node_modules' );
		foreach ( $unwanted_patterns as $pattern ) {
			$files = glob( $this->plugin_path . '/' . $pattern );
			if ( ! empty( $files ) ) {
				$issues[] = "Unwanted files found: {$pattern}";
			}
		}

		$this->results['file_structure'] = array(
			'status'  => empty( $issues ) ? 'pass' : 'fail',
			'issues'  => $issues,
			'message' => empty( $issues )
				? 'File structure is correct'
				: 'File structure issues found',
		);
	}

	/**
	 * Validate functionality.
	 *
	 * @return void
	 */
	private function validate_functionality(): void {
		$issues = array();

		// Check main plugin file can be loaded
		$main_file = $this->plugin_path . '/performance-optimisation.php';

		// Basic syntax check
		$output = shell_exec( "php -l {$main_file} 2>&1" );
		if ( strpos( $output, 'No syntax errors' ) === false ) {
			$issues[] = 'Main plugin file has syntax errors';
		}

		// Check for activation/deactivation hooks
		$content = file_get_contents( $main_file );
		if ( ! preg_match( '/register_activation_hook|register_deactivation_hook/', $content ) ) {
			$issues[] = 'Missing activation/deactivation hooks';
		}

		// Check for proper plugin initialization
		if ( ! preg_match( '/defined.*ABSPATH.*exit/', $content ) ) {
			$issues[] = 'Missing direct access protection';
		}

		$this->results['functionality'] = array(
			'status'  => empty( $issues ) ? 'pass' : 'fail',
			'issues'  => $issues,
			'message' => empty( $issues )
				? 'Basic functionality checks passed'
				: 'Functionality issues found',
		);
	}

	/**
	 * Get all PHP files in the plugin.
	 *
	 * @return array<string> PHP file paths.
	 */
	private function get_php_files(): array {
		$files    = array();
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $this->plugin_path, RecursiveDirectoryIterator::SKIP_DOTS )
		);

		foreach ( $iterator as $file ) {
			if ( $file->getExtension() === 'php' && ! $this->is_excluded_file( $file->getPathname() ) ) {
				$files[] = $file->getPathname();
			}
		}

		return $files;
	}

	/**
	 * Check if file should be excluded.
	 *
	 * @param string $file File path.
	 * @return bool True if file should be excluded.
	 */
	private function is_excluded_file( string $file ): bool {
		$excluded_patterns = array(
			'/vendor/',
			'/node_modules/',
			'/tests/',
			'/\.git/',
		);

		foreach ( $excluded_patterns as $pattern ) {
			if ( strpos( $file, $pattern ) !== false ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Generate compliance report.
	 *
	 * @return string Compliance report.
	 */
	public function generate_report(): string {
		$results = $this->validate();

		$report  = "# WordPress.org Compliance Report\n\n";
		$report .= 'Generated: ' . date( 'Y-m-d H:i:s' ) . "\n";
		$report .= "Plugin: Performance Optimisation\n";
		$report .= "Path: {$this->plugin_path}\n\n";

		$total_checks  = count( $results );
		$passed_checks = 0;
		$failed_checks = 0;
		$warnings      = 0;

		foreach ( $results as $check => $result ) {
			if ( $result['status'] === 'pass' ) {
				++$passed_checks;
			} elseif ( $result['status'] === 'fail' ) {
				++$failed_checks;
			} else {
				++$warnings;
			}
		}

		$report .= "## Summary\n\n";
		$report .= "- Total Checks: {$total_checks}\n";
		$report .= "- Passed: {$passed_checks}\n";
		$report .= "- Failed: {$failed_checks}\n";
		$report .= "- Warnings: {$warnings}\n";
		$report .= '- Overall Status: ' . ( $failed_checks === 0 ? '✅ READY' : '❌ NEEDS WORK' ) . "\n\n";

		$report .= "## Detailed Results\n\n";

		foreach ( $results as $check => $result ) {
			$status_icon = $result['status'] === 'pass' ? '✅' : ( $result['status'] === 'fail' ? '❌' : '⚠️' );
			$check_name  = ucwords( str_replace( '_', ' ', $check ) );

			$report .= "### {$status_icon} {$check_name}\n\n";
			$report .= '**Status:** ' . strtoupper( $result['status'] ) . "\n";
			$report .= "**Message:** {$result['message']}\n";

			if ( ! empty( $result['issues'] ) ) {
				$report .= "**Issues:**\n";
				foreach ( $result['issues'] as $issue ) {
					$report .= "- {$issue}\n";
				}
			}

			$report .= "\n";
		}

		$report .= "## Recommendations\n\n";

		if ( $failed_checks === 0 ) {
			$report .= "🎉 **Congratulations!** Your plugin is ready for WordPress.org submission.\n\n";
			$report .= "### Next Steps:\n";
			$report .= "1. Test the plugin thoroughly on a fresh WordPress installation\n";
			$report .= "2. Create plugin screenshots for the assets folder\n";
			$report .= "3. Submit to WordPress.org plugin directory\n";
			$report .= "4. Monitor for any feedback from the review team\n";
		} else {
			$report .= "### Issues to Fix:\n";
			foreach ( $results as $check => $result ) {
				if ( $result['status'] === 'fail' && ! empty( $result['issues'] ) ) {
					$report .= '**' . ucwords( str_replace( '_', ' ', $check ) ) . ":**\n";
					foreach ( $result['issues'] as $issue ) {
						$report .= "- {$issue}\n";
					}
					$report .= "\n";
				}
			}
		}

		return $report;
	}
}

// Run compliance check if called directly
if ( defined( 'WP_CLI' ) && WP_CLI ) {
	$plugin_path = dirname( __DIR__ );
	$validator   = new ComplianceValidator( $plugin_path );
	$report      = $validator->generate_report();

	WP_CLI::log( $report );

	$results       = $validator->validate();
	$failed_checks = 0;
	foreach ( $results as $result ) {
		if ( $result['status'] === 'fail' ) {
			++$failed_checks;
		}
	}

	if ( $failed_checks > 0 ) {
		WP_CLI::error( "Compliance check failed with {$failed_checks} issues." );
	} else {
		WP_CLI::success( 'Plugin is ready for WordPress.org submission!' );
	}
}
