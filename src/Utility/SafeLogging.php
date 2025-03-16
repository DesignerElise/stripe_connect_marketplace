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
    $sanitized = [];

    foreach ($variables as $key => $value) {
      // Handle null values
      if ($value === NULL) {
        $variables[$key] = '(null)';
        continue;
      }
      
      // Handle objects that aren't stringable
      if (is_object($value)) {
        if (method_exists($value, '__toString')) {
          try {
            $sanitized[$key] = (string) $value;
          } catch (\Exception $e) {
            $sanitized[$key] = get_class($value) . ' object (toString exception)';
          }
        } else {
          // Try to provide a meaningful representation
          if (method_exists($value, 'id')) {
            try {
              $id = $value->id();
              if ($id === NULL) {
                $sanitized[$key] = get_class($value) . ' object (null id)';
              } else {
                $sanitized[$key] = get_class($value) . ':' . $id;
              }
            } catch (\Exception $e) {
              $sanitized[$key] = get_class($value) . ' object (id exception)';
            }
          } else {
            $sanitized[$key] = get_class($value) . ' object';
          }
        }
        continue;
      }
      
      // Handle arrays
      if (is_array($value)) {
        $sanitized[$key] = print_r($value, TRUE);
        continue;
      }
      
      // Handle other types
      if (!is_string($value) && !is_numeric($value)) {
        $sanitized[$key] = '(' . gettype($value) . ')';
      } else {
        $sanitized[$key] = $value;
      }
    }
    
    return $sanitized;
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
    // Ensure message is a string
    if (!is_string($message)) {
      if (is_null($message)) {
        $message = '(null message)';
      } else {
        $message = '(non-string message: ' . gettype($message) . ')';
      }
    }
    
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
      
      case 'debug':
        $logger->debug($message, $safe_variables);
        break;
      
      case 'error':
      default:
        $logger->error($message, $safe_variables);
        break;
    }
  }
  
  /**
   * Static helper method for logging from module files or other non-class contexts.
   *
   * @param string $message
   *   The message to log.
   * @param array $variables
   *   The variables for the message.
   * @param string $level
   *   The log level (error, warning, notice, info).
   */
  public static function moduleLog($message, array $variables = [], $level = 'error') {
    $logger = \Drupal::logger('stripe_connect_marketplace');
    self::log($logger, $message, $variables, $level);
  }
}
