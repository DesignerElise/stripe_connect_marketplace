<?php

namespace Drupal\stripe_connect_marketplace\Utility;

/**
 * Provides utility functions for safe logging.
 */
class SafeLogging {

  /**
   * Sanitizes log variables to prevent null values from causing errors.
   *
   * @param array $variables
   *   The variables to sanitize.
   *
   * @return array
   *   The sanitized variables.
   */
  public static function sanitizeLogVariables(array $variables) {
    foreach ($variables as $key => $value) {
      // Handle null values
      if ($value === NULL) {
        $variables[$key] = '(null)';
        continue;
      }
      
      // Handle objects that aren't stringable
      if (is_object($value) && !method_exists($value, '__toString')) {
        // Try to provide a meaningful representation
        if (method_exists($value, 'id')) {
          $variables[$key] = get_class($value) . ':' . $value->id();
        } else {
          $variables[$key] = get_class($value) . ' object';
        }
        continue;
      }
      
      // Handle arrays
      if (is_array($value)) {
        $variables[$key] = print_r($value, TRUE);
        continue;
      }
    }
    
    return $variables;
  }
  
  /**
   * Logs an error message with sanitized variables.
   *
   * @param \Drupal\Core\Logger\LoggerChannelInterface $logger
   *   The logger channel.
   * @param string $message
   *   The message to log.
   * @param array $variables
   *   The variables for the message.
   * @param string $level
   *   The log level (error, warning, notice, info).
   */
  public static function log($logger, $message, array $variables = [], $level = 'error') {
    // Sanitize the variables
    $safe_variables = self::sanitizeLogVariables($variables);
    
    // Log with the appropriate level
    switch ($level) {
      case 'warning':
        $logger->warning($message, $safe_variables);
        break;
      
      case 'notice':
        $logger->notice($message, $safe_variables);
        break;
      
      case 'info':
        $logger->info($message, $safe_variables);
        break;
      
      case 'error':
      default:
        $logger->error($message, $safe_variables);
        break;
    }
  } 
}
