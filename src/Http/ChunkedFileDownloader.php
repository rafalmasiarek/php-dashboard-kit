<?php

declare(strict_types=1);

namespace rafalmasiarek\DashboardKit\Http;

/**
 * Downloads a large file in range-requested chunks, retrying failed chunks
 * individually instead of restarting the whole transfer, and resuming from
 * an existing partial file when the destination already exists.
 *
 * Falls back to a single, unranged request when the server ignores the
 * Range header (responds 200 instead of 206) — but only when starting from
 * scratch; a server that can't resume mid-download can't be resumed at all.
 *
 * @package rafalmasiarek\DashboardKit\Http
 */
final class ChunkedFileDownloader
{
    /**
     * @param HttpClientInterface $http               Client used to fetch each chunk.
     * @param int                 $chunkSize          Bytes requested per range, e.g. 8 MiB.
     * @param int                 $maxRetriesPerChunk Attempts per chunk before giving up.
     */
    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly int $chunkSize = 8 * 1024 * 1024,
        private readonly int $maxRetriesPerChunk = 3,
    ) {
    }

    /**
     * Downloads $url to $destinationPath.
     *
     * @param  string               $url             Absolute URL.
     * @param  string               $destinationPath File path to write to; resumed if it already exists.
     * @param  array<string, mixed> $requestOptions  Extra HttpClientInterface::request() options
     *                                                (e.g. headers, timeout, local_cert) merged into
     *                                                every chunk request. A 'Range' header, if present,
     *                                                is overridden per chunk.
     * @return void
     * @throws DownloadException On a write failure, an unexpected status code, a server that can't
     *                           resume a partial download, or a chunk that exhausts its retries.
     */
    public function download(string $url, string $destinationPath, array $requestOptions = []): void
    {
        $offset = \is_file($destinationPath) ? (int) \filesize($destinationPath) : 0;

        $handle = \fopen($destinationPath, $offset > 0 ? 'ab' : 'wb');
        if ($handle === false) {
            throw new DownloadException("Unable to open \"{$destinationPath}\" for writing.");
        }

        try {
            $total = null;

            while ($total === null || $offset < $total) {
                $end = $offset + $this->chunkSize - 1;
                if ($total !== null) {
                    // Some servers reject a range end past the resource size with 416
                    // instead of clamping it themselves, so clamp the final chunk here.
                    $end = \min($end, $total - 1);
                }
                $rangeHeader = "bytes={$offset}-{$end}";
                $chunk = $this->fetchChunkWithRetry($url, $rangeHeader, $requestOptions);

                if ($chunk->statusCode === 200) {
                    if ($offset > 0) {
                        throw new DownloadException(
                            "Server does not support range requests; cannot resume partial download of \"{$url}\"."
                        );
                    }
                    if (\fwrite($handle, $chunk->body) === false) {
                        throw new DownloadException("Write failure to \"{$destinationPath}\".");
                    }
                    return;
                }

                if ($chunk->statusCode === 416) {
                    if ($total === null) {
                        throw new DownloadException(
                            "Server rejected the range request for \"{$url}\" (416) before the total size was known."
                        );
                    }
                    break;
                }

                if ($chunk->statusCode !== 206) {
                    throw new DownloadException(
                        "Unexpected status {$chunk->statusCode} fetching \"{$url}\" range \"{$rangeHeader}\"."
                    );
                }

                if ($total === null) {
                    $total = $this->parseTotalSize($chunk->getHeaderLine('Content-Range'));
                }

                if (\fwrite($handle, $chunk->body) === false) {
                    throw new DownloadException("Write failure to \"{$destinationPath}\".");
                }

                $received = \strlen($chunk->body);
                if ($received === 0) {
                    break;
                }

                $offset += $received;
            }
        } finally {
            \fclose($handle);
        }
    }

    /**
     * Fetches one chunk, retrying on transport failure.
     *
     * @param  string               $url
     * @param  string               $rangeHeader
     * @param  array<string, mixed> $requestOptions
     * @return HttpResponse
     * @throws DownloadException When every attempt fails.
     */
    private function fetchChunkWithRetry(string $url, string $rangeHeader, array $requestOptions): HttpResponse
    {
        $lastError = null;

        for ($attempt = 1; $attempt <= $this->maxRetriesPerChunk; $attempt++) {
            $options = $requestOptions;
            $options['headers'] = \array_merge((array) ($options['headers'] ?? []), ['Range' => $rangeHeader]);

            $response = $this->http->request('GET', $url, $options);
            if ($response->error === null) {
                return $response;
            }

            $lastError = $response->error;
        }

        throw new DownloadException(
            "Failed to fetch range \"{$rangeHeader}\" from \"{$url}\" after {$this->maxRetriesPerChunk} attempt(s): {$lastError}"
        );
    }

    /**
     * Extracts the total resource size from a Content-Range header value ("bytes start-end/total").
     *
     * @param  string $contentRange
     * @return int|null
     */
    private function parseTotalSize(string $contentRange): ?int
    {
        return \preg_match('#/(\d+)$#', $contentRange, $m) === 1 ? (int) $m[1] : null;
    }
}
