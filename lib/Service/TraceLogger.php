<?php

declare(strict_types=1);

namespace OCA\OpenCase\Service;

use OCP\IConfig;

/**
 * Centralized debug/trace logging for the OpenCase app.
 *
 * Logs are written to the data directory as opencase.log
 * when the 'trace_log' config setting is enabled.
 */
class TraceLogger {
	private const LOG_FILENAME = 'opencase.log';

	private ?bool $enabled = null;

	public function __construct(
		private Configuration $configuration,
		private IConfig $config,
	) {}

	/**
	 * Check if trace logging is enabled via the 'trace_log' config setting.
	 */
	public function isEnabled(): bool {
		if ($this->enabled === null) {
			$this->enabled = $this->configuration->getConfigValue('trace_log', '0') === '1';
		}
		return $this->enabled;
	}

	/**
	 * Get the full path to the log file.
	 */
	private function getLogFilePath(): string {
		$dataDirectory = $this->config->getSystemValue('datadirectory', \OC::$SERVERROOT . '/data');
		return $dataDirectory . '/' . self::LOG_FILENAME;
	}

	/**
	 * Log a trace message with optional context data.
	 *
	 * @param string $action The action/event name being logged
	 * @param array $context Additional context data to include in the log entry
	 */
	public function trace(string $action, array $context = []): void {
		if (!$this->isEnabled()) {
			return;
		}

		$logEntry = array_merge(
			[
				'timestamp' => date('Y-m-d H:i:s'),
				'action' => $action,
			],
			$context
		);

		file_put_contents(
			$this->getLogFilePath(),
			json_encode($logEntry, JSON_PRETTY_PRINT) . "\n\n",
			FILE_APPEND
		);
	}

	/**
	 * Log a debug message (alias for trace).
	 *
	 * @param string $action The action/event name being logged
	 * @param array $context Additional context data to include in the log entry
	 */
	public function debug(string $action, array $context = []): void {
		$this->trace($action, $context);
	}

	/**
	 * Log an error with exception details.
	 *
	 * @param string $action The action/event name being logged
	 * @param \Throwable $exception The exception to log
	 * @param array $context Additional context data to include in the log entry
	 */
	public function error(string $action, \Throwable $exception, array $context = []): void {
		$this->trace($action, array_merge($context, [
			'error' => $exception->getMessage(),
			'exception_class' => get_class($exception),
			'file' => $exception->getFile(),
			'line' => $exception->getLine(),
		]));
	}

	/**
	 * Clear/reset the enabled cache (useful if config changes during runtime).
	 */
	public function resetCache(): void {
		$this->enabled = null;
	}
}
