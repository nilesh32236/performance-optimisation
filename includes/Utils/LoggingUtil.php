<?php
/**
 * Logging Utility
 *
 * @package PerformanceOptimisation\Utils
 * @since 2.0.0
 */

namespace PerformanceOptimisation\Utils;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Class LoggingUtil
 *
 * @package PerformanceOptimisation\Utils
 */
class LoggingUtil
{

	private const LOG_LEVELS = array('debug', 'info', 'warning', 'error', 'critical');
	private const LOG_OPTION = 'wppo_activity_logs';
	private const MAX_LOGS = 1000;

	/**
	 * Log a message with context.
	 *
	 * @param string $message Log message.
	 * @param string $level Log level.
	 * @param array  $context Additional context.
	 */
	public static function log(string $message, string $level = 'info', array $context = array()): void
	{
		$level = strtolower($level);
		if (!in_array($level, self::LOG_LEVELS, true)) {
			$level = 'info';
		}

		$log_entry = array(
			'id' => wp_generate_uuid4(),
			'timestamp' => current_time('mysql'),
			'level' => $level,
			'message' => $message,
			'context' => $context,
		);

		// Store in database
		self::storeLogs($log_entry);

		// Also log to error_log if debug is enabled
		if (defined('WP_DEBUG') && WP_DEBUG) {
			$log_message = sprintf('WPPO [%s]: %s', strtoupper($level), $message);
			if (!empty($context)) {
				$log_message .= ' ' . wp_json_encode($context);
			}
			error_log($log_message);
		}
	}

	/**
	 * Log debug message.
	 *
	 * @param string $message Debug message.
	 * @param array  $context Additional context.
	 */
	public static function debug(string $message, array $context = array()): void
	{
		self::log($message, 'debug', $context);
	}

	/**
	 * Log info message.
	 *
	 * @param string $message Info message.
	 * @param array  $context Additional context.
	 */
	public static function info(string $message, array $context = array()): void
	{
		self::log($message, 'info', $context);
	}

	/**
	 * Log warning message.
	 *
	 * @param string $message Warning message.
	 * @param array  $context Additional context.
	 */
	public static function warning(string $message, array $context = array()): void
	{
		self::log($message, 'warning', $context);
	}

	/**
	 * Log error message.
	 *
	 * @param string $message Error message.
	 * @param array  $context Additional context.
	 */
	public static function error(string $message, array $context = array()): void
	{
		self::log($message, 'error', $context);
	}

	/**
	 * Log critical message.
	 *
	 * @param string $message Critical message.
	 * @param array  $context Additional context.
	 */
	public static function critical(string $message, array $context = array()): void
	{
		self::log($message, 'critical', $context);
	}

	/**
	 * Get recent logs.
	 *
	 * @param int $limit Number of logs to retrieve.
	 * @param int $offset Offset for pagination.
	 * @return array
	 */
	public static function getRecentLogs(int $limit = 100, int $offset = 0): array
	{
		$logs = get_option(self::LOG_OPTION, array());

		// Sort by timestamp (newest first)
		usort(
			$logs,
			function ($a, $b) {
				return strtotime($b['timestamp']) - strtotime($a['timestamp']);
			}
		);

		return array_slice($logs, $offset, $limit);
	}

	/**
	 * Clear all logs.
	 *
	 * @return bool
	 */
	public static function clearLogs(): bool
	{
		return delete_option(self::LOG_OPTION);
	}

	/**
	 * Export logs in specified format.
	 *
	 * @since 2.0.0
	 *
	 * @param string $format Export format (json, csv, txt).
	 * @param int    $limit  Number of logs to export.
	 * @return string Exported logs data.
	 */
	public static function exportLogs(string $format = 'json', int $limit = 1000): string
	{
		$logs = self::getRecentLogs($limit);

		switch (strtolower($format)) {
			case 'csv':
				return self::exportToCsv($logs);

			case 'txt':
				return self::exportToText($logs);

			case 'json':
			default:
				return wp_json_encode($logs, JSON_PRETTY_PRINT);
		}
	}

	/**
	 * Set minimum log level.
	 *
	 * @since 2.0.0
	 *
	 * @param string $level Minimum log level.
	 * @return void
	 */
	public static function setLogLevel(string $level): void
	{
		$level = strtolower($level);
		if (in_array($level, self::LOG_LEVELS, true)) {
			update_option('wppo_log_level', $level);
		}
	}

	/**
	 * Get current log level.
	 *
	 * @since 2.0.0
	 *
	 * @return string Current log level.
	 */
	public static function getLogLevel(): string
	{
		return get_option('wppo_log_level', 'info');
	}

	/**
	 * Check if log level should be logged.
	 *
	 * @since 2.0.0
	 *
	 * @param string $level Log level to check.
	 * @return bool True if should be logged, false otherwise.
	 */
	public static function shouldLog(string $level): bool
	{
		$current_level = self::getLogLevel();
		$current_index = array_search($current_level, self::LOG_LEVELS, true);
		$level_index = array_search($level, self::LOG_LEVELS, true);

		return $level_index !== false && $level_index >= $current_index;
	}

	/**
	 * Get log statistics.
	 *
	 * @since 2.0.0
	 *
	 * @return array Log statistics.
	 */
	public static function getLogStats(): array
	{
		$logs = get_option(self::LOG_OPTION, array());
		$stats = array(
			'total_logs' => count($logs),
			'levels' => array_fill_keys(self::LOG_LEVELS, 0),
			'oldest_log' => null,
			'newest_log' => null,
		);

		if (!empty($logs)) {
			// Sort by timestamp
			usort(
				$logs,
				function ($a, $b) {
					return strtotime($a['timestamp']) - strtotime($b['timestamp']);
				}
			);

			$stats['oldest_log'] = $logs[0]['timestamp'];
			$stats['newest_log'] = end($logs)['timestamp'];

			// Count by level
			foreach ($logs as $log) {
				if (isset($stats['levels'][$log['level']])) {
					++$stats['levels'][$log['level']];
				}
			}
		}

		return $stats;
	}

	/**
	 * Rotate logs if they exceed maximum count.
	 *
	 * @since 2.0.0
	 *
	 * @return void
	 */
	public static function rotateLogs(): void
	{
		$logs = get_option(self::LOG_OPTION, array());

		if (count($logs) > self::MAX_LOGS) {
			// Sort by timestamp (newest first)
			usort(
				$logs,
				function ($a, $b) {
					return strtotime($b['timestamp']) - strtotime($a['timestamp']);
				}
			);

			// Keep only the most recent logs
			$logs = array_slice($logs, 0, self::MAX_LOGS);
			update_option(self::LOG_OPTION, $logs);

			self::info('Log rotation completed', array('kept_logs' => count($logs)));
		}
	}

	/**
	 * Search logs by criteria.
	 *
	 * @since 2.0.0
	 *
	 * @param array $criteria Search criteria.
	 * @return array Matching logs.
	 */
	public static function searchLogs(array $criteria): array
	{
		$logs = get_option(self::LOG_OPTION, array());
		$results = array();

		foreach ($logs as $log) {
			$match = true;

			// Check level filter
			if (isset($criteria['level']) && $log['level'] !== $criteria['level']) {
				$match = false;
			}

			// Check message filter
			if (isset($criteria['message']) && stripos($log['message'], $criteria['message']) === false) {
				$match = false;
			}

			// Check date range
			if (isset($criteria['date_from'])) {
				$log_time = strtotime($log['timestamp']);
				$from_time = strtotime($criteria['date_from']);
				if ($log_time < $from_time) {
					$match = false;
				}
			}

			if (isset($criteria['date_to'])) {
				$log_time = strtotime($log['timestamp']);
				$to_time = strtotime($criteria['date_to']);
				if ($log_time > $to_time) {
					$match = false;
				}
			}

			// Check context filter
			if (isset($criteria['context']) && is_array($criteria['context'])) {
				foreach ($criteria['context'] as $key => $value) {
					if (!isset($log['context'][$key]) || $log['context'][$key] !== $value) {
						$match = false;
						break;
					}
				}
			}

			if ($match) {
				$results[] = $log;
			}
		}

		return $results;
	}

	/**
	 * Store log entry in database.
	 *
	 * @param array $log_entry Log entry to store.
	 */
	private static function storeLogs(array $log_entry): void
	{
		// Check if we should log this level
		if (!self::shouldLog($log_entry['level'])) {
			return;
		}

		$logs = get_option(self::LOG_OPTION, array());

		// Add new log entry
		$logs[] = $log_entry;

		// Keep only the most recent logs
		if (count($logs) > self::MAX_LOGS) {
			// Sort by timestamp and keep newest
			usort(
				$logs,
				function ($a, $b) {
					return strtotime($b['timestamp']) - strtotime($a['timestamp']);
				}
			);
			$logs = array_slice($logs, 0, self::MAX_LOGS);
		}

		update_option(self::LOG_OPTION, $logs);
	}

	/**
	 * Export logs to CSV format.
	 *
	 * @since 2.0.0
	 *
	 * @param array $logs Logs to export.
	 * @return string CSV formatted logs.
	 */
	private static function exportToCsv(array $logs): string
	{
		$csv = "ID,Timestamp,Level,Message,Context\n";

		foreach ($logs as $log) {
			$context = !empty($log['context']) ? wp_json_encode($log['context']) : '';
			$csv .= sprintf(
				'"%s","%s","%s","%s","%s"' . "\n",
				$log['id'] ?? '',
				$log['timestamp'] ?? '',
				$log['level'] ?? '',
				str_replace('"', '""', $log['message'] ?? ''),
				str_replace('"', '""', $context)
			);
		}

		return $csv;
	}

	/**
	 * Export logs to text format.
	 *
	 * @since 2.0.0
	 *
	 * @param array $logs Logs to export.
	 * @return string Text formatted logs.
	 */
	private static function exportToText(array $logs): string
	{
		$text = "Performance Optimisation Plugin Logs\n";
		$text .= 'Generated: ' . current_time('mysql') . "\n";
		$text .= str_repeat('=', 50) . "\n\n";

		foreach ($logs as $log) {
			$text .= sprintf(
				"[%s] %s: %s\n",
				$log['timestamp'] ?? '',
				strtoupper($log['level'] ?? ''),
				$log['message'] ?? ''
			);

			if (!empty($log['context'])) {
				$text .= 'Context: ' . wp_json_encode($log['context'], JSON_PRETTY_PRINT) . "\n";
			}

			$text .= "\n";
		}

		return $text;
	}
}
