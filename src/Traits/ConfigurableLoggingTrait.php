<?php

namespace Drupal\ai_conversation\Traits;

/**
 * Trait for implementing controlled logging in ai_conversation.
 * 
 * This trait provides standard logging methods that respect the platform's
 * logging configuration to reduce log noise in production environments.
 */
trait ConfigurableLoggingTrait {

  /**
   * Get the current logging level for this module.
   *
   * @return int
   *   The logging level.
   */
  protected function getLogLevel(): int {
    $config = \Drupal::config('thetruthperspective.logging');
    
    // Check for module-specific override first
    $module_level = $config->get('modules.ai_conversation.level');
    if ($module_level !== NULL) {
      return (int) $module_level;
    }
    
    // Fall back to global level (default to ERROR only)
    return (int) $config->get('global.level') ?? 1;
  }

  /**
   * Check if a log level should be recorded.
   *
   * @param int $message_level
   *   The level of the message being logged (1=error, 2=warning, 3=info, 4=debug).
   *
   * @return bool
   *   TRUE if the message should be logged.
   */
  protected function shouldLog(int $message_level): bool {
    $configured_level = $this->getLogLevel();
    return $message_level <= $configured_level;
  }

  /**
   * Log an error message (always logs).
   *
   * @param string $message
   *   The message to log.
   * @param array $context
   *   The context array.
   */
  protected function logError(string $message, array $context = []): void {
    if (isset($this->logger)) {
      $this->logger->error($message, $context);
    }
  }

  /**
   * Log a warning message (only if warnings enabled).
   *
   * @param string $message
   *   The message to log.
   * @param array $context
   *   The context array.
   */
  protected function logWarning(string $message, array $context = []): void {
    if ($this->shouldLog(2) && isset($this->logger)) {
      $this->logger->warning($message, $context);
    }
  }

  /**
   * Log an info message (only if info enabled).
   *
   * @param string $message
   *   The message to log.
   * @param array $context
   *   The context array.
   */
  protected function logInfo(string $message, array $context = []): void {
    if ($this->shouldLog(3) && isset($this->logger)) {
      $this->logger->info($message, $context);
    }
  }

  /**
   * Log a debug message (only if debug enabled).
   *
   * @param string $message
   *   The message to log.
   * @param array $context
   *   The context array.
   */
  protected function logDebug(string $message, array $context = []): void {
    if ($this->shouldLog(4) && isset($this->logger)) {
      $this->logger->debug($message, $context);
    }
  }

}
