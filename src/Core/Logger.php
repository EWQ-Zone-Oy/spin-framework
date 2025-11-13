<?php declare(strict_types=1);

/**
 * Logger class that extends Monologger
 *
 * Constructor will auto-configure based on configuration options
 *
 * @package  Spin
 */

namespace Spin\Core;

use \Monolog\Handler\StreamHandler;
use \Monolog\Handler\ErrorLogHandler;
use \Monolog\Handler\BufferHandler;
use \Monolog\Formatter\LineFormatter;
use \Monolog\Logger as MonoLogger;
use \Elastic\Monolog\Formatter\ElasticCommonSchemaFormatter;

class Logger extends MonoLogger
{
  /**
   * Logger Constructor
   *
   * @param string $loggerName  Name of the Logger
   * @param array|null $options Array with options from config
   * @param string $basePath    The base path
   */
  public function __construct(string $loggerName, ?array $options=[], $basePath='')
  {
    parent::__construct($loggerName);

    # Get the options - Set defaults if not present
    $logLevel = $options['level'] ?? 'error';
    $logDriver = $options['driver'] ?? 'php';

    # Buffer and Overflow parameters
    $Log_max_buffered_lines = $options['drivers'][$logDriver]['max_buffered_lines'] ?? 0; // Default = 0 - buffer everything
    $Log_flush_overflow_to_disk = $options['drivers'][$logDriver]['flush_overflow_to_disk'] ?? false; // Default = false - Discard overflow (if bufferd lines >0)

    # Check if ECS driver is requested
    if (\strcasecmp($logDriver,"ecs") === 0) {
      # ECS Logging configuration
      $driverOptions = $options['drivers'][$logDriver] ?? [];
      $ecsOutput = $driverOptions['output'] ?? 'stdout';
      $ecsTags = $driverOptions['tags'] ?? [];
      $ecsService = $driverOptions['service'] ?? [];

      # Create ECS formatter
      $formatter = new ElasticCommonSchemaFormatter($ecsTags);

      # Create handler based on output type
      if (\strcasecmp($ecsOutput, "file") === 0) {
        # File-based logging
        $logFilePath = $driverOptions['file_path'] ?? 'storage/log';
        $logFileFormat = $driverOptions['file_format'] ?? 'Y-m-d';
        $file = $basePath . \DIRECTORY_SEPARATOR . $logFilePath . \DIRECTORY_SEPARATOR . \date($logFileFormat) . '.log';
        $handler = new StreamHandler($file, self::toMonologLevel($logLevel));
      } elseif (\strcasecmp($ecsOutput, "stdout") === 0) {
        $handler = new StreamHandler('php://stdout', self::toMonologLevel($logLevel));
      } elseif (\strcasecmp($ecsOutput, "stderr") === 0) {
        $handler = new StreamHandler('php://stderr', self::toMonologLevel($logLevel));
      } elseif (\strcasecmp($ecsOutput, "php") === 0) {
        $handler = new ErrorLogHandler(ErrorLogHandler::OPERATING_SYSTEM, self::toMonologLevel($logLevel));
      } else {
        # Fallback to stdout
        $handler = new StreamHandler('php://stdout', self::toMonologLevel($logLevel));
      }

      # Set formatter
      $handler->setFormatter($formatter);

      # Add processor to protect the original message from being overridden by context
      $this->pushProcessor(function($record) {
        # If context contains a 'message' key, rename it to avoid conflicts
        # Monolog 3.x uses immutable LogRecord, so we need to use with() method
        $context = $record->context;

        if (isset($context['message'])) {
          $context['custom_message'] = $context['message'];
          unset($context['message']);

          # Return new record with modified context
          return $record->with(context: $context);
        }

        return $record;
      });

      # Add service information processor if configured
      if (!empty($ecsService)) {
        $this->pushProcessor(function($record) use ($ecsService) {
          # Add service metadata to context
          $context = $record->context;

          if (!isset($context['service'])) {
            $context['service'] = [];
          }

          if (!empty($ecsService['name'])) {
            $context['service']['name'] = $ecsService['name'];
          }
          if (!empty($ecsService['version'])) {
            $context['service']['version'] = $ecsService['version'];
          }
          if (!empty($ecsService['environment'])) {
            $context['service']['environment'] = $ecsService['environment'];
          }
          if (!empty($ecsService['type'])) {
            $context['service']['type'] = $ecsService['type'];
          }

          # Return new record with modified context
          return $record->with(context: $context);
        });
      }

      # Push Buffer Handler if buffering is enabled
      if ($Log_max_buffered_lines > 0) {
        $this->pushHandler(
          new BufferHandler(
            $handler,
            $Log_max_buffered_lines,
            $this->toMonologLevel($logLevel),
            true,
            $Log_flush_overflow_to_disk
          )
        );
      } else {
        # No buffering
        $this->pushHandler($handler);
      }

      # Add initial log entry
      $this->debug('ECS Logger initialized', [
        'logger.name' => $loggerName,
        'logger.level' => $logLevel,
        'logger.output' => $ecsOutput
      ]);

    } else {
      # Traditional file or php logging (backward compatible)
      $logDateFormat = $options['drivers'][$logDriver]['line_datetime'] ?? 'Y-m-d H:i:s';
      $logLineFormat = $options['drivers'][$logDriver]['line_format'] ?? '[%channel%] [%level_name%] %message% %context% %extra%';

      # Create a Line formatter
      $formatter = new LineFormatter($logLineFormat, $logDateFormat);

      # Set options based on FILE or PHP
      if (\strcasecmp($logDriver,"file") === 0) {
        $logFilePath = $options['drivers'][$logDriver]['file_path'] ?? 'storage/log';
        $logFileFormat = $options['drivers'][$logDriver]['file_format'] ?? 'Y-m-d';

        # Construct the filename
        $file = $basePath . \DIRECTORY_SEPARATOR . $logFilePath . \DIRECTORY_SEPARATOR . \date($logFileFormat) . '.log';

        # Create the Stream Handler
        $handler = new StreamHandler($file, self::toMonologLevel($logLevel));

      } elseif (\strcasecmp($logDriver,"php") === 0) {
        # Create the Log Handler
        $handler = new ErrorLogHandler( ErrorLogHandler::OPERATING_SYSTEM, self::toMonologLevel($logLevel) );
      } else {
        # Fallback handler is PHP own logfile
        $handler = new ErrorLogHandler( ErrorLogHandler::OPERATING_SYSTEM, self::toMonologLevel($logLevel) );
      }

      # Set Formatter for $handler
      $handler->setFormatter($formatter);

      # Push Buffer Handler that buffers before the actual user-defined handler
      $this->pushHandler(new BufferHandler($handler, $Log_max_buffered_lines, self::toMonologLevel($logLevel), true, $Log_flush_overflow_to_disk));

      # Add a log entry
      $this->debug('Logger created successfully');
    }
  }

}
