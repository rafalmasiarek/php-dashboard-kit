<?php

namespace rafalmasiarek\DashboardKit\Log;

use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;
use Stringable;

/**
 * Minimal PSR-3 logger that writes to stderr.
 *
 * Suitable for Docker and CLI environments where stderr is captured by the
 * container runtime.
 *
 * @package rafalmasiarek\DashboardKit\Log
 */
class StderrLogger extends AbstractLogger
{
    private const LEVELS = [
        LogLevel::EMERGENCY => 0,
        LogLevel::ALERT     => 1,
        LogLevel::CRITICAL  => 2,
        LogLevel::ERROR     => 3,
        LogLevel::WARNING   => 4,
        LogLevel::NOTICE    => 5,
        LogLevel::INFO      => 6,
        LogLevel::DEBUG     => 7,
    ];

    /**
     * @param string $minLevel Minimum log level to output (PSR-3 level constant).
     */
    public function __construct(private readonly string $minLevel = LogLevel::DEBUG)
    {
    }

    /**
     * Writes a log entry to stderr.
     *
     * @param  mixed              $level   PSR-3 log level.
     * @param  string|Stringable  $message Log message.
     * @param  array<string, mixed> $context Additional context values.
     * @return void
     */
    public function log($level, string|Stringable $message, array $context = []): void
    {
        if ((self::LEVELS[$level] ?? 7) > (self::LEVELS[$this->minLevel] ?? 7)) {
            return;
        }

        $line = sprintf(
            "[%s] %s: %s%s\n",
            date('Y-m-d H:i:s'),
            strtoupper((string) $level),
            $this->interpolate((string) $message, $context),
            isset($context['exception']) && $context['exception'] instanceof \Throwable
                ? ' | ' . $context['exception']->getFile() . ':' . $context['exception']->getLine()
                : ''
        );

        static $stream = null;
        $stream ??= fopen('php://stderr', 'a');
        fwrite($stream, $line);
    }

    /**
     * Replaces {placeholder} tokens in a message with context values.
     *
     * @param  string               $message
     * @param  array<string, mixed> $context
     * @return string
     */
    private function interpolate(string $message, array $context): string
    {
        $replace = [];
        foreach ($context as $key => $value) {
            if (!is_array($value) && (!is_object($value) || method_exists($value, '__toString'))) {
                $replace['{' . $key . '}'] = (string) $value;
            }
        }
        return strtr($message, $replace);
    }
}
