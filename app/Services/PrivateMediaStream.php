<?php

namespace App\Services;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Serves a private file with HTTP Range support without ever holding the whole file in memory.
 *
 * Byte delivery is authorized by the caller before this service is reached; nothing here
 * grants access. When `video.x_accel_prefix` is configured the byte pushing is delegated to
 * the web server via X-Accel-Redirect and this class only emits headers.
 */
class PrivateMediaStream
{
    /**
     * @param  string  $absolutePath  a resolved path inside the private storage root
     * @param  string|null  $storageRelativePath  path relative to the private disk root, for X-Accel-Redirect
     */
    public function respond(
        string $absolutePath,
        string $mimeType,
        ?string $rangeHeader,
        bool $headOnly = false,
        ?string $storageRelativePath = null,
    ): Response {
        $size = filesize($absolutePath);
        if ($size === false) {
            abort(404);
        }

        $range = $this->parseRange($rangeHeader, $size);

        if ($range === false) {
            // RFC 9110 §14.4: an unsatisfiable range gets 416 plus the actual length.
            return response('', 416, [
                'Content-Range' => 'bytes */'.$size,
                'Accept-Ranges' => 'bytes',
            ]);
        }

        [$start, $end] = $range ?? [0, $size - 1];
        $length = $end - $start + 1;
        $partial = $range !== null;

        $headers = [
            'Content-Type' => $mimeType,
            'Content-Length' => (string) $length,
            'Accept-Ranges' => 'bytes',
            'Content-Disposition' => 'inline',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store, max-age=0',
        ];
        if ($partial) {
            $headers['Content-Range'] = 'bytes '.$start.'-'.$end.'/'.$size;
        }

        $prefix = (string) config('video.x_accel_prefix');
        if ($prefix !== '' && $storageRelativePath !== null) {
            // nginx re-reads the file and applies the Range itself; it must not receive a body length.
            unset($headers['Content-Length'], $headers['Content-Range']);
            $headers['X-Accel-Redirect'] = rtrim($prefix, '/').'/'.ltrim($storageRelativePath, '/');
            $headers['X-Accel-Buffering'] = 'no';

            return response('', 200, $headers);
        }

        if ($headOnly) {
            return response('', $partial ? 206 : 200, $headers);
        }

        $chunk = max(8192, (int) config('video.stream_chunk_bytes', 262144));

        return new StreamedResponse(function () use ($absolutePath, $start, $length, $chunk) {
            $handle = fopen($absolutePath, 'rb');
            if ($handle === false) {
                return;
            }
            try {
                fseek($handle, $start);
                $remaining = $length;
                while ($remaining > 0 && ! feof($handle) && ! connection_aborted()) {
                    $buffer = fread($handle, (int) min($chunk, $remaining));
                    if ($buffer === false || $buffer === '') {
                        break;
                    }
                    echo $buffer;
                    $remaining -= strlen($buffer);
                    flush();
                }
            } finally {
                fclose($handle);
            }
        }, $partial ? 206 : 200, $headers);
    }

    /**
     * @return array{0: int, 1: int}|null|false null = no range requested, false = unsatisfiable
     */
    private function parseRange(?string $header, int $size): array|null|false
    {
        if ($header === null || $header === '' || $size === 0) {
            return null;
        }
        if (! preg_match('/^bytes=(\d*)-(\d*)$/', trim($header), $matches)) {
            // Multi-range and unknown units are ignored; RFC 9110 permits serving the whole file.
            return null;
        }
        [, $first, $last] = $matches;
        if ($first === '' && $last === '') {
            return null;
        }
        if ($first === '') {
            $suffix = (int) $last;
            if ($suffix === 0) {
                return false;
            }

            return [max(0, $size - $suffix), $size - 1];
        }
        $start = (int) $first;
        if ($start >= $size) {
            return false;
        }
        $end = $last === '' ? $size - 1 : min((int) $last, $size - 1);
        if ($end < $start) {
            return false;
        }

        return [$start, $end];
    }
}
