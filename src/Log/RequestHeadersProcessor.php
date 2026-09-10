<?php

namespace rafalmasiarek\DashboardKit\Log;

use Monolog\LogRecord;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Monolog processor that injects selected request headers into every log record.
 *
 * Configured once at container build time via $headers; populated per-request
 * by calling boot() from RequestHeadersMiddleware.
 *
 * Configuration options for $headers:
 *   []        — no header injection (noop)
 *   '*'       — dump all request headers (prefixed req.*)
 *   string[]  — dump specific headers, e.g. ['User-Agent', 'X-Forwarded-For']
 *
 * Log output example:
 *   req.user_agent="Mozilla/…"  req.x_forwarded_for="1.2.3.4"
 *
 * @package rafalmasiarek\DashboardKit\Log
 */
class RequestHeadersProcessor
{
    /** @var array<string, string> Context injected into every log record for the current request. */
    private array $context = [];

    /**
     * @param array<int, string>|string $headers
     *   []        — noop
     *   '*'       — all request headers
     *   string[]  — specific header names to include
     * @param \Closure|null $ipResolver When provided, called with no arguments and must return string IP.
     *   Injected by Dashboard::buildContainer() as a lazy closure that reads from the container-bound
     *   RealIpResolver so that container overrides made after create() are always respected.
     *   Falls back to REMOTE_ADDR when null (e.g. standalone use without dashboard-kit).
     */
    public function __construct(
        private readonly array|string $headers,
        private readonly ?\Closure $ipResolver = null,
    ) {
    }

    /**
     * Populates the per-request context from the incoming request.
     *
     * Called once per request by RequestHeadersMiddleware.
     *
     * @param ServerRequestInterface $request Incoming PSR-7 request.
     */
    public function boot(ServerRequestInterface $request): void
    {
        $ip  = $this->ipResolver !== null
            ? (string) ($this->ipResolver)()
            : (string) ($request->getServerParams()['REMOTE_ADDR'] ?? '');
        $ctx = [
            'req.ip' => $ip !== '' ? $ip : 'unknown',
        ];

        if ($this->headers === '*') {
            foreach ($request->getHeaders() as $name => $values) {
                $ctx[$this->headerKey($name)] = implode(', ', $values);
            }
        } elseif (is_array($this->headers) && !empty($this->headers)) {
            foreach ($this->headers as $name) {
                $value = $request->getHeaderLine($name);
                if ($value !== '') {
                    $ctx[$this->headerKey($name)] = $value;
                }
            }
        }

        $this->context = $ctx;
    }

    /**
     * Invoked by Monolog for every log record.
     *
     * Merges stored context into the record. Log-call context takes priority —
     * the processor only fills in keys that are not already present.
     *
     * @param  LogRecord $record
     * @return LogRecord
     */
    public function __invoke(LogRecord $record): LogRecord
    {
        if (empty($this->context)) {
            return $record;
        }

        return $record->with(context: $record->context + $this->context);
    }

    /**
     * Normalises a header name to a log context key: "X-Forwarded-For" → "req.x_forwarded_for".
     *
     * @param  string $name Raw header name.
     * @return string
     */
    private function headerKey(string $name): string
    {
        return 'req.' . strtolower(str_replace('-', '_', $name));
    }
}
