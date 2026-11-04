<?php
declare(strict_types=1);

namespace NexorAIQ\Connector\Logger;

/**
 * Base for the NexorAIQ file loggers.
 *
 * A failed log write (e.g. var/log/NexoraIQ/*.log not writable by the web
 * server user) must never break the store action that logged it, such as a
 * product save or order placement. Monolog rethrows handler errors by default,
 * so we install an exception handler that drops them instead.
 */
abstract class SafeLogger extends \Monolog\Logger
{
    /**
     * @param string $name
     * @param array $handlers
     * @param array $processors
     * @param \DateTimeZone|null $timezone
     */
    public function __construct(
        string $name,
        array $handlers = [],
        array $processors = [],
        ?\DateTimeZone $timezone = null
    ) {
        parent::__construct($name, $handlers, $processors, $timezone);
        $this->setExceptionHandler(static function (\Throwable $e): void {
            // phpcs:ignore Magento2.Functions.DiscouragedFunction
            error_log('NexorAIQ logger: ' . $e->getMessage());
        });
    }
}
