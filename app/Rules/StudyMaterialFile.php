<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;
use ZipArchive;

class StudyMaterialFile implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $value instanceof UploadedFile || ! $value->isValid() || $value->getSize() > 10 * 1024 * 1024) {
            $fail('Choose a valid file no larger than 10 MB.');

            return;
        }
        $extension = strtolower($value->getClientOriginalExtension());
        if (in_array($extension, ['txt', 'md'], true)) {
            $text = file_get_contents($value->getRealPath());
            if (! mb_check_encoding($text, 'UTF-8') || str_contains($text, "\0")) {
                $fail('Text materials must contain readable UTF-8 text.');
            }

            return;
        }
        if ($extension === 'pdf') {
            if ($value->getMimeType() !== 'application/pdf' || ! str_starts_with(file_get_contents($value->getRealPath(), false, null, 0, 5), '%PDF-')) {
                $fail('The file content is not a PDF.');
            }

            return;
        }
        if (! in_array($extension, ['docx', 'pptx'], true) || ! $this->validOfficeDocument($value, $extension)) {
            $fail('Upload a valid PDF, DOCX, PPTX, TXT or Markdown file. Macro-enabled and embedded executable content is not supported.');
        }
    }

    private function validOfficeDocument(UploadedFile $file, string $extension): bool
    {
        $zip = new ZipArchive;
        if ($zip->open($file->getRealPath()) !== true) {
            return false;
        }
        try {
            if ($zip->numFiles > 2000) {
                return false;
            }
            $total = 0;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $entry = $zip->statIndex($i);
                $name = strtolower($entry['name']);
                $total += $entry['size'];
                if ($total > 50 * 1024 * 1024 || str_contains($name, '..') || str_starts_with($name, '/') || str_contains($name, '\\') || str_contains($name, 'vbaproject') || str_contains($name, '/embeddings/') || preg_match('/\.(exe|dll|js|vbs|bat|cmd|ps1)$/', $name)) {
                    return false;
                }
            }
            $main = $extension === 'docx' ? 'word/document.xml' : 'ppt/presentation.xml';
            foreach (['[Content_Types].xml', $main] as $name) {
                $index = $zip->locateName($name);
                if ($index === false || $zip->statIndex($index)['size'] > 8 * 1024 * 1024) {
                    return false;
                }
                $xml = $zip->getFromIndex($index);
                if ($xml === false || stripos($xml, '<!DOCTYPE') !== false || stripos($xml, '<!ENTITY') !== false) {
                    return false;
                }
                $previous = libxml_use_internal_errors(true);
                try {
                    $document = new \DOMDocument;
                    if (! $document->loadXML($xml, LIBXML_NONET)) {
                        return false;
                    }
                    if ($name === '[Content_Types].xml') {
                        $expected = $extension === 'docx' ? 'application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml' : 'application/vnd.openxmlformats-officedocument.presentationml.presentation.main+xml';
                        $found = false;
                        foreach ($document->getElementsByTagName('Override') as $override) {
                            $found = $found || ($override->getAttribute('PartName') === '/'.$main && $override->getAttribute('ContentType') === $expected);
                        }
                        if (! $found || stripos($xml, 'macroEnabled') !== false) {
                            return false;
                        }
                    } elseif ($document->documentElement?->namespaceURI !== ($extension === 'docx' ? 'http://schemas.openxmlformats.org/wordprocessingml/2006/main' : 'http://schemas.openxmlformats.org/presentationml/2006/main')) {
                        return false;
                    }
                } finally {
                    libxml_clear_errors();
                    libxml_use_internal_errors($previous);
                }
            }

            return true;
        } finally {
            $zip->close();
        }
    }
}
