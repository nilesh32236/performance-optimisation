<?php
/**
 * Security and Compliance Check Script
 *
 * Validates plugin security and WordPress.org compliance.
 *
 * @package PerformanceOptimisation
 * @since 2.0.0
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Security Check Class
 */
class SecurityCheck {

	/**
	 * Plugin path.
	 *
	 * @var string
	 */
	private string $plugin_path;

	/**
	 * Issues found.
	 *
	 * @var array<string, array<string>>
	 */
	private array $issues = array();

	/**
	 * Constructor.
	 *
	 * @param string $plugin_path Plugin path.
	 */
	public function __construct( string $plugin_path ) {
		$this->plugin_path = $plugin_path;
	}

	/**
	 * Run all security checks.
	 *
	 * @return array<string, mixed> Check results.
	 */
	public function run_checks(): array {
		$this->check_file_permissions();
		$this->check_input_validation();
		$this->check_output_escaping();
		$this->check_nonce_verification();
		$this->check_capability_checks();
		$this->check_sql_injection_prevention();
		$this->check_file_inclusion_security();
		$this->check_wordpress_functions_usage();
		$this->check_license_compliance();

		return array(
			'total_checks' => 9,
			'issues_found' => count( $this->get_all_issues() ),
			'issues'       => $this->issues,
			'passed'       => empty( $this->get_all_issues() ),
		);
	}

	/**
	 * Check file permissions.
	 *
	 * @return void
	 */
	private function check_file_permissions(): void {
		$files = $this->get_php_files();

		foreach ( $files as $file ) {
			$perms = fileperms( $file );
			if ( $perms & 0x0002 ) { // World writable
				$this->add_issue( 'file_permissions', "File is world writable: {$file}" );
			}
		}
	}

	/**
	 * Check input validation.
	 *
	 * @return void
	 */
	private function check_input_validation(): void {
		$files = $this->get_php_files();

		foreach ( $files as $file ) {
			$content = file_get_contents( $file );

			// Check for direct $_POST, $_GET, $_REQUEST usage without sanitization
			if ( preg_match_all( '/\$_(POST|GET|REQUEST|COOKIE)\[/', $content, $matches, PREG_OFFSET_CAPTURE ) ) {
				foreach ( $matches[0] as $match ) {
					$line_number = substr_count( substr( $content, 0, $match[1] ), "\n" ) + 1;

					// Check if it's followed by sanitization
					$context = substr( $content, $match[1], 200 );
					if ( ! preg_match( '/sanitize_|wp_unslash|absint|intval/', $context ) ) {
						$this->add_issue( 'input_validation', "Unsanitized input usage in {$file}:{$line_number}" );
					}
				}
			}
		}
	}

	/**
	 * Check output escaping.
	 *
	 * @return void
	 */
	private function check_output_escaping(): void {
		$files = $this->get_php_files();

		foreach ( $files as $file ) {
			$content = file_get_contents( $file );

			// Check for echo/print without escaping
			if ( preg_match_all( '/echo\s+\$|print\s+\$/', $content, $matches, PREG_OFFSET_CAPTURE ) ) {
				foreach ( $matches[0] as $match ) {
					$line_number = substr_count( substr( $content, 0, $match[1] ), "\n" ) + 1;

					// Check if it's escaped
					$context = substr( $content, max( 0, $match[1] - 50 ), 150 );
					if ( ! preg_match( '/esc_html|esc_attr|esc_url|wp_kses/', $context ) ) {
						$this->add_issue( 'output_escaping', "Unescaped output in {$file}:{$line_number}" );
					}
				}
			}
		}
	}

	/**
	 * Check nonce verification.
	 *
	 * @return void
	 */
	private function check_nonce_verification(): void {
		$files = $this->get_php_files();

		foreach ( $files as $file ) {
			$content = file_get_contents( $file );

			// Check for AJAX handlers without nonce verification
			if ( preg_match_all( '/wp_ajax_|wp_ajax_nopriv_/', $content, $matches, PREG_OFFSET_CAPTURE ) ) {
				foreach ( $matches[0] as $match ) {
					$line_number = substr_count( substr( $content, 0, $match[1] ), "\n" ) + 1;

					// Check for nonce verification in the same function
					$function_content = $this->extract_function_content( $content, $match[1] );
					if ( ! preg_match( '/wp_verify_nonce|check_ajax_referer/', $function_content ) ) {
						$this->add_issue( 'nonce_verification', "Missing nonce verification in AJAX handler at {$file}:{$line_number}" );
					}
				}
			}
		}
	}

	/**
	 * Check capability checks.
	 *
	 * @return void
	 */
	private function check_capability_checks(): void {
		$files = $this->get_php_files();

		foreach ( $files as $file ) {
			$content = file_get_contents( $file );

			// Check for admin functions without capability checks
			if ( preg_match_all( '/function\s+handle_.*_request|wp_ajax_/', $content, $matches, PREG_OFFSET_CAPTURE ) ) {
				foreach ( $matches[0] as $match ) {
					$line_number = substr_count( substr( $content, 0, $match[1] ), "\n" ) + 1;

					$function_content = $this->extract_function_content( $content, $match[1] );
					if ( ! preg_match( '/current_user_can|user_can|is_admin/', $function_content ) ) {
						$this->add_issue( 'capability_checks', "Missing capability check in {$file}:{$line_number}" );
					}
				}
			}
		}
	}

	/**
	 * Check SQL injection prevention.
	 *
	 * @return void
	 */
	private function check_sql_injection_prevention(): void {
		$files = $this->get_php_files();

		foreach ( $files as $file ) {
			$content = file_get_contents( $file );

			// Check for direct SQL queries without preparation
			if ( preg_match_all( '/\$wpdb->query\s*\(\s*["\']/', $content, $matches, PREG_OFFSET_CAPTURE ) ) {
				foreach ( $matches[0] as $match ) {
					$line_number = substr_count( substr( $content, 0, $match[1] ), "\n" ) + 1;
					$this->add_issue( 'sql_injection', "Direct SQL query without preparation in {$file}:{$line_number}" );
				}
			}

			// Check for string concatenation in SQL
			if ( preg_match_all( '/\$wpdb->.*\.\s*\$/', $content, $matches, PREG_OFFSET_CAPTURE ) ) {
				foreach ( $matches[0] as $match ) {
					$line_number = substr_count( substr( $content, 0, $match[1] ), "\n" ) + 1;
					$this->add_issue( 'sql_injection', "SQL string concatenation in {$file}:{$line_number}" );
				}
			}
		}
	}

	/**
	 * Check file inclusion security.
	 *
	 * @return void
	 */
	private function check_file_inclusion_security(): void {
		$files = $this->get_php_files();

		foreach ( $files as $file ) {
			$content = file_get_contents( $file );

			// Check for include/require with user input
			if ( preg_match_all( '/(include|require).*\$_(GET|POST|REQUEST)/', $content, $matches, PREG_OFFSET_CAPTURE ) ) {
				foreach ( $matches[0] as $match ) {
					$line_number = substr_count( substr( $content, 0, $match[1] ), "\n" ) + 1;
					$this->add_issue( 'file_inclusion', "Potential file inclusion vulnerability in {$file}:{$line_number}" );
				}
			}
		}
	}

	/**
	 * Check WordPress functions usage.
	 *
	 * @return void
	 */
	private function check_wordpress_functions_usage(): void {
		$files = $this->get_php_files();

		foreach ( $files as $file ) {
			$content = file_get_contents( $file );

			// Check for direct database access instead of WordPress functions
			$forbidden_patterns = array(
				'/mysql_connect|mysqli_connect/'       => 'Direct MySQL connection (use $wpdb)',
				'/file_get_contents\s*\(\s*["\']http/' => 'Direct HTTP request (use wp_remote_get)',
				'/curl_init|curl_exec/'                => 'Direct cURL usage (use wp_remote_request)',
				'/mail\s*\(/'                          => 'Direct mail() usage (use wp_mail)',
			);

			foreach ( $forbidden_patterns as $pattern => $message ) {
				if ( preg_match_all( $pattern, $content, $matches, PREG_OFFSET_CAPTURE ) ) {
					foreach ( $matches[0] as $match ) {
						$line_number = substr_count( substr( $content, 0, $match[1] ), "\n" ) + 1;
						$this->add_issue( 'wordpress_functions', "{$message} in {$file}:{$line_number}" );
					}
				}
			}
		}
	}

	/**
	 * Check license compliance.
	 *
	 * @return void
	 */
	private function check_license_compliance(): void {
		$main_file = $this->plugin_path . '/performance-optimisation.php';
		$content   = file_get_contents( $main_file );

		// Check for GPL license header
		if ( ! preg_match( '/GPL|General Public License/', $content ) ) {
			$this->add_issue( 'license_compliance', 'Missing GPL license information in main plugin file' );
		}

		// Check for license file
		$license_files    = array( 'LICENSE', 'LICENSE.txt', 'license.txt' );
		$has_license_file = false;

		foreach ( $license_files as $license_file ) {
			if ( file_exists( $this->plugin_path . '/' . $license_file ) ) {
				$has_license_file = true;
				break;
			}
		}

		if ( ! $has_license_file ) {
			$this->add_issue( 'license_compliance', 'Missing LICENSE file' );
		}
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
	 * Check if file should be excluded from security checks.
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
	 * Extract function content from file content.
	 *
	 * @param string $content File content.
	 * @param int    $start_pos Start position.
	 * @return string Function content.
	 */
	private function extract_function_content( string $content, int $start_pos ): string {
		// Find the opening brace
		$brace_pos = strpos( $content, '{', $start_pos );
		if ( $brace_pos === false ) {
			return '';
		}

		$brace_count = 1;
		$pos         = $brace_pos + 1;
		$length      = strlen( $content );

		while ( $pos < $length && $brace_count > 0 ) {
			if ( $content[ $pos ] === '{' ) {
				++$brace_count;
			} elseif ( $content[ $pos ] === '}' ) {
				--$brace_count;
			}
			++$pos;
		}

		return substr( $content, $brace_pos, $pos - $brace_pos );
	}

	/**
	 * Add security issue.
	 *
	 * @param string $category Issue category.
	 * @param string $message Issue message.
	 * @return void
	 */
	private function add_issue( string $category, string $message ): void {
		if ( ! isset( $this->issues[ $category ] ) ) {
			$this->issues[ $category ] = array();
		}
		$this->issues[ $category ][] = $message;
	}

	/**
	 * Get all issues as flat array.
	 *
	 * @return array<string> All issues.
	 */
	private function get_all_issues(): array {
		$all_issues = array();
		foreach ( $this->issues as $category_issues ) {
			$all_issues = array_merge( $all_issues, $category_issues );
		}
		return $all_issues;
	}

	/**
	 * Generate security report.
	 *
	 * @return string Security report.
	 */
	public function generate_report(): string {
		$results = $this->run_checks();

		$report  = "# Performance Optimisation Security Check Report\n\n";
		$report .= 'Generated: ' . date( 'Y-m-d H:i:s' ) . "\n";
		$report .= "Plugin Path: {$this->plugin_path}\n\n";

		$report .= "## Summary\n\n";
		$report .= "- Total Checks: {$results['total_checks']}\n";
		$report .= "- Issues Found: {$results['issues_found']}\n";
		$report .= '- Status: ' . ( $results['passed'] ? '✅ PASSED' : '❌ FAILED' ) . "\n\n";

		if ( ! empty( $results['issues'] ) ) {
			$report .= "## Issues Found\n\n";

			foreach ( $results['issues'] as $category => $issues ) {
				$report .= '### ' . ucwords( str_replace( '_', ' ', $category ) ) . "\n\n";
				foreach ( $issues as $issue ) {
					$report .= "- {$issue}\n";
				}
				$report .= "\n";
			}
		} else {
			$report .= "## ✅ No Security Issues Found\n\n";
			$report .= "All security checks passed successfully!\n\n";
		}

		$report .= "## Recommendations\n\n";
		$report .= "- Run this check regularly during development\n";
		$report .= "- Use WordPress VIP Scanner for additional checks\n";
		$report .= "- Test with Plugin Check plugin before submission\n";
		$report .= "- Review WordPress.org plugin guidelines\n";

		return $report;
	}
}

// Run security check if called directly
if ( defined( 'WP_CLI' ) && WP_CLI ) {
	$plugin_path    = dirname( __DIR__ );
	$security_check = new SecurityCheck( $plugin_path );
	$report         = $security_check->generate_report();

	WP_CLI::log( $report );

	$results = $security_check->run_checks();
	if ( ! $results['passed'] ) {
		WP_CLI::error( "Security check failed with {$results['issues_found']} issues." );
	} else {
		WP_CLI::success( 'All security checks passed!' );
	}
}
