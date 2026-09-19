<?php

declare(strict_types=1);

namespace rafalmasiarek\DashboardKit\Log;

use Monolog\LogRecord;
use rafalmasiarek\DashboardKit\Security\SecretRegistry;

/**
 * Monolog processor that redacts registered secret values (see SecretRegistry)
 * from a record's message and context.
 *
 * SecretRegistry::redactValues() is otherwise only applied on the crash-path
 * (the fatal-error shutdown handler and Slim's error handler) — any ad-hoc
 * $logger->error('...', ['error' => $e->getMessage()]) call in application
 * code bypasses it entirely. Pushing this processor onto a channel closes
 * that gap for every log call on that channel, with no change required at
 * the call site.
 *
 * Recurses into arrays. A Throwable found in context can't be mutated (its
 * message is immutable), so it is replaced by a small class/message/file/line
 * array with the message redacted, rather than being left to serialize its
 * raw message during formatting.
 *
 * @package rafalmasiarek\DashboardKit\Log
 */
final class SecretRedactionProcessor
{
    /**
     * @param  LogRecord $record
     * @return LogRecord
     */
    public function __invoke(LogRecord $record): LogRecord
    {
        return $record->with(
            message: SecretRegistry::redactValues($record->message),
            context: $this->redact($record->context),
        );
    }

    /**
     * @param  array<array-key, mixed> $value
     * @return array<array-key, mixed>
     */
    private function redact(array $value): array
    {
        foreach ($value as $key => $item) {
            $value[$key] = match (true) {
                \is_string($item) => SecretRegistry::redactValues($item),
                \is_array($item) => $this->redact($item),
                $item instanceof \Throwable => [
                    'class'   => $item::class,
                    'message' => SecretRegistry::redactValues($item->getMessage()),
                    'file'    => $item->getFile(),
                    'line'    => $item->getLine(),
                ],
                default => $item,
            };
        }

        return $value;
    }
}
