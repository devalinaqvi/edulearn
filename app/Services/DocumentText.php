<?php

namespace App\Services;

use DOMDocument;
use DOMXPath;
use Illuminate\Validation\ValidationException;
use ZipArchive;

/**
 * Extracts plain text from the learning-document formats this application accepts.
 *
 * Supported for AI study notes:
 *  - TXT and Markdown (UTF-8)
 *  - DOCX  (Office Open XML WordprocessingML text runs)
 *  - PPTX  (Office Open XML DrawingML text runs, slide order preserved)
 *  - PDF   (uncompressed and Flate-compressed content streams with text-showing operators)
 *
 * Deliberately NOT supported, and reported as such rather than silently producing noise:
 *  - Scanned/image-only PDFs. There is no OCR in this application.
 *  - Encrypted or password-protected PDFs.
 *  - Any other format.
 */
class DocumentText
{
    /** Guard against decompression bombs in Office parts and PDF streams. */
    private const MAX_EXTRACTED_BYTES = 8 * 1024 * 1024;

    public function extract(string $absolutePath, string $format): string
    {
        return match (strtolower($format)) {
            'txt', 'md' => $this->plainText($absolutePath),
            'docx' => $this->docx($absolutePath),
            'pptx' => $this->pptx($absolutePath),
            'pdf' => $this->pdf($absolutePath),
            default => throw ValidationException::withMessages([
                'source' => 'Study notes support lesson text, TXT, Markdown, DOCX, PPTX and text-based PDF sources.',
            ]),
        };
    }

    private function plainText(string $path): string
    {
        $text = file_get_contents($path);
        if ($text === false) {
            throw ValidationException::withMessages(['source' => 'The source file could not be read.']);
        }

        return $text;
    }

    private function docx(string $path): string
    {
        $xml = $this->officePart($path, ['word/document.xml']);
        $document = $this->loadXml($xml[0] ?? '');
        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');

        $paragraphs = [];
        foreach ($xpath->query('//w:p') ?: [] as $paragraph) {
            $runs = [];
            foreach ($xpath->query('.//w:t|.//w:tab|.//w:br', $paragraph) ?: [] as $node) {
                $runs[] = $node->nodeName === 'w:t' ? $node->textContent : ' ';
            }
            $line = trim(preg_replace('/\s+/u', ' ', implode('', $runs)) ?? '');
            if ($line !== '') {
                $paragraphs[] = $line;
            }
        }

        return $this->finish(implode("\n\n", $paragraphs), 'This DOCX contains no extractable text. It may hold only images or drawings.');
    }

    private function pptx(string $path): string
    {
        $parts = $this->officePart($path, null, '/^ppt\/slides\/slide\d+\.xml$/');
        $slides = [];
        foreach ($parts as $index => $xml) {
            $document = $this->loadXml($xml);
            $xpath = new DOMXPath($document);
            $xpath->registerNamespace('a', 'http://schemas.openxmlformats.org/drawingml/2006/main');
            $lines = [];
            foreach ($xpath->query('//a:p') ?: [] as $paragraph) {
                $text = '';
                foreach ($xpath->query('.//a:t', $paragraph) ?: [] as $node) {
                    $text .= $node->textContent;
                }
                $text = trim(preg_replace('/\s+/u', ' ', $text) ?? '');
                if ($text !== '') {
                    $lines[] = $text;
                }
            }
            if ($lines) {
                $slides[] = 'Slide '.($index + 1).":\n".implode("\n", $lines);
            }
        }

        return $this->finish(implode("\n\n", $slides), 'This PPTX contains no extractable text. It may hold only images.');
    }

    /**
     * Read named or pattern-matched parts out of an Office Open XML package, in sorted order.
     *
     * @param  list<string>|null  $names
     * @return list<string>
     */
    private function officePart(string $path, ?array $names, ?string $pattern = null): array
    {
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            throw ValidationException::withMessages(['source' => 'This Office file could not be opened. It may be corrupted.']);
        }
        try {
            $matched = [];
            if ($names !== null) {
                foreach ($names as $name) {
                    $index = $zip->locateName($name);
                    if ($index !== false) {
                        $matched[$name] = $index;
                    }
                }
            } else {
                for ($i = 0; $i < $zip->numFiles; $i++) {
                    $name = $zip->getNameIndex($i);
                    if ($name !== false && preg_match($pattern ?? '/^$/', $name)) {
                        $matched[$name] = $i;
                    }
                }
                uksort($matched, static fn ($a, $b) => strnatcmp($a, $b));
            }
            if (! $matched) {
                throw ValidationException::withMessages(['source' => 'This Office file has no readable document part.']);
            }

            $total = 0;
            $parts = [];
            foreach ($matched as $index) {
                $stat = $zip->statIndex($index);
                $total += $stat['size'] ?? 0;
                if ($total > self::MAX_EXTRACTED_BYTES) {
                    throw ValidationException::withMessages(['source' => 'This document is too large to extract text from.']);
                }
                $contents = $zip->getFromIndex($index);
                if ($contents !== false) {
                    $parts[] = $contents;
                }
            }

            return $parts;
        } finally {
            $zip->close();
        }
    }

    private function loadXml(string $xml): DOMDocument
    {
        if ($xml === '' || stripos($xml, '<!DOCTYPE') !== false || stripos($xml, '<!ENTITY') !== false) {
            throw ValidationException::withMessages(['source' => 'This document could not be parsed safely.']);
        }
        $previous = libxml_use_internal_errors(true);
        try {
            $document = new DOMDocument;
            if (! $document->loadXML($xml, LIBXML_NONET | LIBXML_NOENT)) {
                throw ValidationException::withMessages(['source' => 'This document could not be parsed.']);
            }

            return $document;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    /**
     * Extract text from a PDF's content streams.
     *
     * Only text-based PDFs are supported. A PDF whose pages are scanned images yields no
     * text operators, and is reported as needing OCR rather than returning an empty note.
     */
    private function pdf(string $path): string
    {
        $raw = file_get_contents($path);
        if ($raw === false || ! str_starts_with($raw, '%PDF-')) {
            throw ValidationException::withMessages(['source' => 'This file is not a readable PDF.']);
        }
        if (preg_match('/\/Encrypt\s+\d+\s+\d+\s+R/', $raw)) {
            throw ValidationException::withMessages(['source' => 'This PDF is encrypted or password-protected and cannot be read. Upload an unprotected copy.']);
        }

        $text = '';
        $length = 0;
        if (preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $raw, $matches)) {
            foreach ($matches[1] as $stream) {
                $decoded = @gzuncompress($stream);
                if ($decoded === false) {
                    $decoded = @gzinflate($stream);
                }
                if ($decoded === false) {
                    // An uncompressed content stream is usable as-is; other filters are skipped.
                    $decoded = str_contains($stream, 'Tj') || str_contains($stream, 'TJ') ? $stream : '';
                }
                if ($decoded === '') {
                    continue;
                }
                $length += strlen($decoded);
                if ($length > self::MAX_EXTRACTED_BYTES) {
                    throw ValidationException::withMessages(['source' => 'This PDF is too large to extract text from.']);
                }
                $text .= $this->pdfOperators($decoded);
            }
        }

        $text = trim(preg_replace('/[ \t]+/', ' ', $text) ?? '');
        if ($text === '') {
            throw ValidationException::withMessages([
                'source' => 'No text could be read from this PDF. Scanned or image-only PDFs need optical character recognition, which this application does not provide.',
            ]);
        }

        return $text;
    }

    /** Pull literal strings out of Tj / TJ / ' / " text-showing operators. */
    private function pdfOperators(string $content): string
    {
        $output = '';
        if (preg_match_all('/(?:\[((?:[^\[\]\\\\]|\\\\.)*)\]\s*TJ)|(?:\(((?:[^()\\\\]|\\\\.)*)\)\s*(?:Tj|\'|"))/s', $content, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                if (($match[1] ?? '') !== '') {
                    if (preg_match_all('/\(((?:[^()\\\\]|\\\\.)*)\)/s', $match[1], $pieces)) {
                        $output .= implode('', array_map($this->pdfString(...), $pieces[1]));
                    }
                    $output .= ' ';
                } elseif (isset($match[2])) {
                    $output .= $this->pdfString($match[2]).' ';
                }
            }
        }

        return $output === '' ? '' : $output."\n";
    }

    private function pdfString(string $value): string
    {
        $replacements = ['\\n' => "\n", '\\r' => "\r", '\\t' => "\t", '\\(' => '(', '\\)' => ')', '\\\\' => '\\'];
        $value = strtr($value, $replacements);
        $value = preg_replace_callback('/\\\\([0-7]{1,3})/', static fn ($m) => chr(octdec($m[1])), $value) ?? $value;

        return mb_check_encoding($value, 'UTF-8') ? $value : mb_convert_encoding($value, 'UTF-8', 'Windows-1252');
    }

    private function finish(string $text, string $emptyMessage): string
    {
        if (trim($text) === '') {
            throw ValidationException::withMessages(['source' => $emptyMessage]);
        }

        return $text;
    }
}
