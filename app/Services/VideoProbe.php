<?php

namespace App\Services;

use RuntimeException;

/**
 * Validates and inspects uploaded MP4 media by parsing the ISO base media file format
 * (ISO/IEC 14496-12) box tree directly in PHP.
 *
 * ffprobe is deliberately not used: it is not a guaranteed dependency of this application's
 * hosting, and shelling out to it with attacker-influenced input is a risk this module avoids
 * entirely. Nothing here executes a subprocess or interpolates an uploaded filename.
 *
 * @phpstan-type ProbeResult array{container: string, brand: string, video_codec: string, audio_codec: string|null, duration_seconds: int}
 */
class VideoProbe
{
    /** Brands whose files browsers reliably treat as progressive MP4. */
    private const ACCEPTED_BRANDS = ['isom', 'iso2', 'iso4', 'iso5', 'iso6', 'mp41', 'mp42', 'avc1', 'M4V ', 'mmp4', 'dash'];

    /** H.264 sample entry formats. HEVC is excluded: Firefox and many Linux browsers cannot play it. */
    private const VIDEO_CODECS = ['avc1' => 'h264', 'avc3' => 'h264'];

    /** AAC sample entry formats. */
    private const AUDIO_CODECS = ['mp4a' => 'aac'];

    private const MAX_BOXES = 20000;

    private const MAX_DEPTH = 8;

    private int $boxesRead = 0;

    /**
     * @return ProbeResult
     *
     * @throws RuntimeException with a learner-safe, specific reason.
     */
    public function inspect(string $absolutePath): array
    {
        $handle = @fopen($absolutePath, 'rb');
        if ($handle === false) {
            throw new RuntimeException('The uploaded video could not be read.');
        }
        try {
            $size = filesize($absolutePath);
            if ($size === false || $size < 32) {
                throw new RuntimeException('The uploaded file is too small to be a video.');
            }
            $this->boxesRead = 0;
            $top = $this->children($handle, 0, $size, 0);

            $brand = $this->brand($handle, $top);
            $moov = $top['moov'][0] ?? null;
            if (! $moov) {
                throw new RuntimeException('This MP4 has no movie header. Re-export it as a complete MP4 file.');
            }

            $moovChildren = $this->children($handle, $moov['start'], $moov['end'], 1);
            $duration = $this->duration($handle, $moovChildren);

            $video = null;
            $audio = null;
            foreach ($moovChildren['trak'] ?? [] as $trak) {
                [$handler, $format] = $this->track($handle, $trak);
                if ($handler === 'vide' && $format !== null && $video === null) {
                    $video = $format;
                }
                if ($handler === 'soun' && $format !== null && $audio === null) {
                    $audio = $format;
                }
            }

            if ($video === null) {
                throw new RuntimeException('No video track was found in this file.');
            }
            if (! isset(self::VIDEO_CODECS[$video])) {
                throw new RuntimeException('Unsupported video codec "'.$video.'". Re-encode the lecture as H.264 (AVC) in an MP4 container.');
            }
            if ($audio !== null && ! isset(self::AUDIO_CODECS[$audio])) {
                throw new RuntimeException('Unsupported audio codec "'.$audio.'". Re-encode the lecture audio as AAC.');
            }
            if ($duration === null || $duration <= 0) {
                throw new RuntimeException('This file reports no playable duration. Re-export it as a complete MP4 file.');
            }

            return [
                'container' => 'mp4',
                'brand' => $brand,
                'video_codec' => self::VIDEO_CODECS[$video],
                'audio_codec' => $audio === null ? null : self::AUDIO_CODECS[$audio],
                'duration_seconds' => $duration,
            ];
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param  array<string, list<array{start: int, end: int}>>  $top
     */
    private function brand(mixed $handle, array $top): string
    {
        $ftyp = $top['ftyp'][0] ?? null;
        if (! $ftyp || $ftyp['start'] !== 8) {
            throw new RuntimeException('This is not an MP4 file. Upload an MP4 (H.264/AAC) lecture recording.');
        }
        $brand = $this->read($handle, $ftyp['start'], 4);
        if (! in_array($brand, self::ACCEPTED_BRANDS, true)) {
            // Compatible brands in the remainder of ftyp are an acceptable fallback.
            $compatible = str_split($this->read($handle, $ftyp['start'] + 8, max(0, min(64, $ftyp['end'] - $ftyp['start'] - 8))), 4);
            if (! array_intersect($compatible, self::ACCEPTED_BRANDS)) {
                throw new RuntimeException('Unsupported MP4 variant "'.trim($brand).'". Re-export the lecture as a standard MP4.');
            }
        }

        return trim($brand);
    }

    /**
     * @param  array<string, list<array{start: int, end: int}>>  $moov
     */
    private function duration(mixed $handle, array $moov): ?int
    {
        $mvhd = $moov['mvhd'][0] ?? null;
        if (! $mvhd || $mvhd['end'] - $mvhd['start'] < 20) {
            return null;
        }
        $version = ord($this->read($handle, $mvhd['start'], 1));
        if ($version === 1) {
            $timescale = $this->uint32($this->read($handle, $mvhd['start'] + 20, 4));
            $raw = $this->read($handle, $mvhd['start'] + 24, 8);
            $duration = (int) hexdec(bin2hex($raw));
        } else {
            $timescale = $this->uint32($this->read($handle, $mvhd['start'] + 12, 4));
            $duration = $this->uint32($this->read($handle, $mvhd['start'] + 16, 4));
        }

        return $timescale > 0 ? (int) round($duration / $timescale) : null;
    }

    /**
     * Resolve one track's handler type and its first sample-entry format.
     *
     * @param  array{start: int, end: int}  $trak
     * @return array{0: string|null, 1: string|null}
     */
    private function track(mixed $handle, array $trak): array
    {
        $mdia = $this->children($handle, $trak['start'], $trak['end'], 2)['mdia'][0] ?? null;
        if (! $mdia) {
            return [null, null];
        }
        $mdiaChildren = $this->children($handle, $mdia['start'], $mdia['end'], 3);
        $hdlr = $mdiaChildren['hdlr'][0] ?? null;
        $handler = $hdlr && $hdlr['end'] - $hdlr['start'] >= 12 ? $this->read($handle, $hdlr['start'] + 8, 4) : null;

        $minf = $mdiaChildren['minf'][0] ?? null;
        if (! $minf) {
            return [$handler, null];
        }
        $stbl = $this->children($handle, $minf['start'], $minf['end'], 4)['stbl'][0] ?? null;
        if (! $stbl) {
            return [$handler, null];
        }
        $stsd = $this->children($handle, $stbl['start'], $stbl['end'], 5)['stsd'][0] ?? null;
        if (! $stsd || $stsd['end'] - $stsd['start'] < 16) {
            return [$handler, null];
        }
        if ($this->uint32($this->read($handle, $stsd['start'] + 4, 4)) < 1) {
            return [$handler, null];
        }

        return [$handler, $this->read($handle, $stsd['start'] + 12, 4)];
    }

    /**
     * Read the direct child boxes between two offsets.
     *
     * @return array<string, list<array{start: int, end: int}>> box type => payload ranges
     */
    private function children(mixed $handle, int $start, int $end, int $depth): array
    {
        if ($depth > self::MAX_DEPTH) {
            return [];
        }
        $boxes = [];
        $offset = $start;
        while ($offset + 8 <= $end) {
            if (++$this->boxesRead > self::MAX_BOXES) {
                throw new RuntimeException('This MP4 has an unexpected structure and was not accepted.');
            }
            $header = $this->read($handle, $offset, 8);
            $size = $this->uint32(substr($header, 0, 4));
            $type = substr($header, 4, 4);
            $payload = $offset + 8;
            if ($size === 1) {
                if ($offset + 16 > $end) {
                    break;
                }
                $size = (int) hexdec(bin2hex($this->read($handle, $offset + 8, 8)));
                $payload = $offset + 16;
            } elseif ($size === 0) {
                $size = $end - $offset;
            }
            if ($size < $payload - $offset || $offset + $size > $end) {
                break; // Truncated or malformed box: stop rather than read past the declared extent.
            }
            if (preg_match('/^[\x20-\x7e]{4}$/', $type) !== 1) {
                break;
            }
            $boxes[$type][] = ['start' => $payload, 'end' => $offset + $size];
            $offset += $size;
        }

        return $boxes;
    }

    private function read(mixed $handle, int $offset, int $length): string
    {
        if ($length <= 0) {
            return '';
        }
        if (fseek($handle, $offset) !== 0) {
            throw new RuntimeException('The uploaded video is truncated.');
        }
        $data = fread($handle, $length);
        if ($data === false || strlen($data) < $length) {
            throw new RuntimeException('The uploaded video is truncated.');
        }

        return $data;
    }

    private function uint32(string $bytes): int
    {
        /** @var array{1: int} $values */
        $values = unpack('N', $bytes);

        return $values[1];
    }
}
