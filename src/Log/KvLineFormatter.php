<?php

namespace rafalmasiarek\DashboardKit\Log;

use Monolog\Formatter\FormatterInterface;
use Monolog\LogRecord;

/**
 * Formats a Monolog record as a key-value line compatible with log_ship.sh's `kv` parser.
 *
 * Output format:
 *   [YYYY-MM-DD HH:MM:SS] [level=info] [channel=audit] event_name key=val key2=val2
 *
 * Configure log_ship.conf PARSERS entry:
 *   "kv | level=level | msg=message"
 *
 * @package rafalmasiarek\DashboardKit\Log
 */
class KvLineFormatter implements FormatterInterface
{
    /**
     * Formats a single log record into a kv line.
     *
     * @param  LogRecord $record
     * @return string
     */
    public function format(LogRecord $record): string
    {
        $ts      = $record->datetime->format('Y-m-d H:i:s');
        $level   = strtolower($record->level->getName());
        $channel = $record->channel;
        $message = $record->message;

        $pairs = '';
        foreach ($record->context as $key => $value) {
            if ($key === 'exception') {
                continue;
            }
            $pairs .= ' ' . $key . '=' . $this->escapeValue($value);
        }

        if (isset($record->context['exception']) && $record->context['exception'] instanceof \Throwable) {
            $e = $record->context['exception'];
            $pairs .= ' exception=' . $this->escapeValue(get_class($e) . ': ' . $e->getMessage());
            $pairs .= ' file=' . $this->escapeValue($e->getFile() . ':' . $e->getLine());
        }

        return "[{$ts}] [level={$level}] [channel={$channel}] {$message}{$pairs}\n";
    }

    /**
     * Formats a batch of log records.
     *
     * @param  LogRecord[] $records
     * @return string
     */
    public function formatBatch(array $records): string
    {
        return implode('', array_map([$this, 'format'], $records));
    }

    /**
     * Escapes a value for safe kv output.
     *
     * Strings with spaces or quotes are double-quoted.
     * Arrays and objects are JSON-encoded.
     *
     * @param  mixed $value
     * @return string
     */
    private function escapeValue(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_null($value)) {
            return 'null';
        }
        if (is_array($value) || is_object($value)) {
            return $this->escapeValue(json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }
        $str = (string) $value;
        if (preg_match('/[\s"=\[\]]/', $str)) {
            return '"' . str_replace('"', '\\"', $str) . '"';
        }
        return $str;
    }
}
