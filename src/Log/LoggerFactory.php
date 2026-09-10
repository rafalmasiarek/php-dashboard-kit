<?php

namespace rafalmasiarek\DashboardKit\Log;

use Monolog\Handler\RotatingFileHandler;
use Monolog\Level;
use Monolog\Logger;
use Psr\Log\LogLevel;

/**
 * Builds Monolog channel loggers with KvLineFormatter.
 *
 * Log format is compatible with log_ship.sh's `kv` parser.
 * Recommended PARSERS entry in log_ship.conf:
 *   "kv | level=level | msg=message"
 *
 * @package rafalmasiarek\DashboardKit\Log
 */
class LoggerFactory
{
    /** @var array<string, Level> */
    private const LEVEL_MAP = [
        LogLevel::DEBUG     => Level::Debug,
        LogLevel::INFO      => Level::Info,
        LogLevel::NOTICE    => Level::Notice,
        LogLevel::WARNING   => Level::Warning,
        LogLevel::ERROR     => Level::Error,
        LogLevel::CRITICAL  => Level::Critical,
        LogLevel::ALERT     => Level::Alert,
        LogLevel::EMERGENCY => Level::Emergency,
    ];

    /**
     * Creates a channel logger from a resolved channel config.
     *
     * When $cascadeErrorPath is provided a second ERROR+ handler is added to that file,
     * allowing a channel to write high-severity events to a secondary log file as well.
     *
     * @param  string            $channel          Monolog channel name (used in log lines).
     * @param  string            $path             Absolute path to the primary log file.
     * @param  string            $minLevel         PSR-3 minimum level constant.
     * @param  int               $maxFiles         Number of daily files to retain.
     * @param  string|null       $cascadeErrorPath When set, ERROR+ records are also written here.
     * @param  \DateTimeZone|null $timezone         Timezone for log timestamps; null defaults to UTC.
     * @return Logger
     */
    public static function create(
        string        $channel,
        string        $path,
        string        $minLevel         = LogLevel::DEBUG,
        int           $maxFiles         = 30,
        ?string       $cascadeErrorPath = null,
        ?\DateTimeZone $timezone        = null,
    ): Logger {
        $formatter = new KvLineFormatter();
        $level     = self::LEVEL_MAP[$minLevel] ?? Level::Debug;

        $primary = new RotatingFileHandler($path, $maxFiles, $level);
        $primary->setFormatter($formatter);

        $logger = new Logger($channel);
        $logger->pushHandler($primary);

        if ($timezone !== null) {
            $logger->setTimezone($timezone);
        }

        if ($cascadeErrorPath !== null) {
            $cascade = new RotatingFileHandler($cascadeErrorPath, $maxFiles, Level::Error);
            $cascade->setFormatter($formatter);
            $logger->pushHandler($cascade);
        }

        return $logger;
    }

    /**
     * Maps a PSR-3 level string to a Monolog Level enum.
     *
     * @param  string $level PSR-3 level constant.
     * @return Level
     */
    public static function toMonologLevel(string $level): Level
    {
        return self::LEVEL_MAP[$level] ?? Level::Debug;
    }
}
