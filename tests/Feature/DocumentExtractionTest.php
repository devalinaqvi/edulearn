<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Material;
use App\Models\StudyNote;
use App\Models\User;
use App\Services\DocumentText;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;
use ZipArchive;

class DocumentExtractionTest extends TestCase
{
    use RefreshDatabase;

    private function write(string $extension, string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'edulearn-doc-').'.'.$extension;
        file_put_contents($path, $contents);

        return $path;
    }

    private function docx(string ...$paragraphs): string
    {
        $body = '';
        foreach ($paragraphs as $paragraph) {
            $body .= '<w:p><w:r><w:t>'.htmlspecialchars($paragraph).'</w:t></w:r></w:p>';
        }
        $path = tempnam(sys_get_temp_dir(), 'edulearn-docx-');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/></Types>');
        $zip->addFromString('word/document.xml', '<?xml version="1.0"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>'.$body.'</w:body></w:document>');
        $zip->close();

        return $path;
    }

    /** @param array<int, list<string>> $slides */
    private function pptx(array $slides): string
    {
        $path = tempnam(sys_get_temp_dir(), 'edulearn-pptx-');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"/>');
        foreach ($slides as $index => $lines) {
            $body = '';
            foreach ($lines as $line) {
                $body .= '<a:p><a:r><a:t>'.htmlspecialchars($line).'</a:t></a:r></a:p>';
            }
            $zip->addFromString('ppt/slides/slide'.($index + 1).'.xml',
                '<?xml version="1.0"?><p:sld xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main" xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main"><p:cSld><p:spTree>'.$body.'</p:spTree></p:cSld></p:sld>');
        }
        $zip->close();

        return $path;
    }

    private function pdf(string $streamContent, bool $compress = false, bool $encrypted = false): string
    {
        $stream = $compress ? gzcompress($streamContent) : $streamContent;
        $trailer = $encrypted ? "trailer<</Encrypt 9 0 R/Root 1 0 R>>\n" : "trailer<</Root 1 0 R>>\n";

        return "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\n4 0 obj<</Length ".strlen($stream).">>\nstream\n".$stream."\nendstream\nendobj\n".$trailer.'%%EOF';
    }

    // ---------------------------------------------------------------- DOCX / PPTX

    public function test_docx_paragraphs_are_extracted_in_order(): void
    {
        $path = $this->docx('Normalisation reduces redundancy.', 'A primary key uniquely identifies a row.');
        $text = app(DocumentText::class)->extract($path, 'docx');
        unlink($path);

        $this->assertStringContainsString('Normalisation reduces redundancy.', $text);
        $this->assertStringContainsString('A primary key uniquely identifies a row.', $text);
        $this->assertLessThan(strpos($text, 'primary key'), strpos($text, 'Normalisation'));
    }

    public function test_pptx_slides_are_extracted_with_slide_numbers_in_natural_order(): void
    {
        $path = $this->pptx([['Week one', 'Introduction'], ['Week two', 'Relational model']]);
        $text = app(DocumentText::class)->extract($path, 'pptx');
        unlink($path);

        $this->assertStringContainsString('Slide 1:', $text);
        $this->assertStringContainsString('Introduction', $text);
        $this->assertStringContainsString('Slide 2:', $text);
        $this->assertLessThan(strpos($text, 'Slide 2:'), strpos($text, 'Slide 1:'));
    }

    public function test_an_image_only_office_document_reports_that_it_has_no_text(): void
    {
        $path = $this->docx();
        $this->expectException(ValidationException::class);
        try {
            app(DocumentText::class)->extract($path, 'docx');
        } finally {
            unlink($path);
        }
    }

    // ---------------------------------------------------------------- PDF

    public function test_text_based_pdfs_are_extracted_compressed_or_not(): void
    {
        $content = "BT /F1 12 Tf 72 720 Td (Relational algebra basics) Tj ET\n";
        foreach ([false, true] as $compressed) {
            $path = $this->write('pdf', $this->pdf($content, $compressed));
            $text = app(DocumentText::class)->extract($path, 'pdf');
            unlink($path);
            $this->assertStringContainsString('Relational algebra basics', $text, $compressed ? 'compressed stream' : 'raw stream');
        }
    }

    public function test_array_showing_operators_and_escapes_are_decoded(): void
    {
        $path = $this->write('pdf', $this->pdf("BT [(Keys )-250(and )-250(indexes\\(B-tree\\))] TJ ET\n"));
        $text = app(DocumentText::class)->extract($path, 'pdf');
        unlink($path);

        $this->assertStringContainsString('Keys and indexes(B-tree)', $text);
    }

    public function test_a_scanned_pdf_reports_that_ocr_is_not_available(): void
    {
        // An image-only page has no text-showing operators at all.
        $path = $this->write('pdf', $this->pdf("q 612 0 0 792 0 0 cm /Im1 Do Q\n"));
        try {
            app(DocumentText::class)->extract($path, 'pdf');
            $this->fail('expected a validation exception for a scanned PDF');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('optical character recognition', $exception->validator->errors()->first('source'));
        } finally {
            unlink($path);
        }
    }

    public function test_an_encrypted_pdf_is_reported_rather_than_parsed(): void
    {
        $path = $this->write('pdf', $this->pdf("BT (secret) Tj ET\n", false, true));
        try {
            app(DocumentText::class)->extract($path, 'pdf');
            $this->fail('expected a validation exception for an encrypted PDF');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('encrypted', $exception->validator->errors()->first('source'));
        } finally {
            unlink($path);
        }
    }

    public function test_unsupported_formats_are_reported_clearly(): void
    {
        $path = $this->write('xlsx', 'whatever');
        $this->expectException(ValidationException::class);
        try {
            app(DocumentText::class)->extract($path, 'xlsx');
        } finally {
            unlink($path);
        }
    }

    // ---------------------------------------------------------------- end to end

    public function test_a_learner_can_generate_notes_from_an_uploaded_docx(): void
    {
        Storage::fake('local');
        $teacher = User::factory()->create(['role' => 'instructor']);
        $learner = User::factory()->create(['role' => 'student']);
        $course = Course::create(['instructor_id' => $teacher->id, 'title' => 'Databases', 'description' => 'Online', 'status' => 'published']);
        Enrollment::create(['course_id' => $course->id, 'user_id' => $learner->id]);

        $source = $this->docx('Third normal form removes transitive dependencies.');
        $this->actingAs($teacher)->post(route('materials.store', $course), [
            'title' => 'Week two reader',
            'file' => UploadedFile::fake()->createWithContent('reader.docx', file_get_contents($source)),
        ])->assertRedirect();
        unlink($source);

        $material = Material::latest('id')->firstOrFail();
        $this->actingAs($learner)->post(route('notes.store'), ['source_type' => 'material', 'source_id' => $material->id])->assertRedirect();

        $note = StudyNote::where('user_id', $learner->id)->firstOrFail();
        $this->assertSame('completed', $note->status);
        $this->assertStringContainsString('Third normal form removes transitive dependencies.', $note->content);
    }
}
