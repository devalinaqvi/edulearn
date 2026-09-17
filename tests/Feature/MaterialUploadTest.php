<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Material;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

class MaterialUploadTest extends TestCase
{
    use RefreshDatabase;

    private function office(string $extension, bool $macro = false): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'edulearn-office-');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::OVERWRITE);
        $part = $extension === 'docx' ? 'word/document.xml' : 'ppt/presentation.xml';
        $type = $extension === 'docx' ? 'wordprocessingml.document' : 'presentationml.presentation';
        $namespace = $extension === 'docx' ? 'wordprocessingml' : 'presentationml';
        $zip->addFromString('[Content_Types].xml', '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Override PartName="/'.$part.'" ContentType="application/vnd.openxmlformats-officedocument.'.$type.'.main+xml"/></Types>');
        $zip->addFromString($part, '<document xmlns="http://schemas.openxmlformats.org/'.$namespace.'/2006/main"/>');
        if ($macro) {
            $zip->addFromString('word/vbaProject.bin', 'unsafe macro payload');
        }
        $zip->close();
        $file = UploadedFile::fake()->createWithContent('lecture.'.$extension, file_get_contents($path));
        unlink($path);

        return $file;
    }

    public function test_office_uploads_record_metadata_and_remain_private(): void
    {
        Storage::fake('local');
        $teacher = User::factory()->create(['role' => 'instructor']);
        $student = User::factory()->create(['role' => 'student']);
        $course = Course::create(['instructor_id' => $teacher->id, 'title' => 'Materials', 'description' => 'Online', 'status' => 'published']);
        foreach (['docx', 'pptx'] as $extension) {
            $file = $this->office($extension);
            $size = $file->getSize();
            $this->actingAs($teacher)->postJson(route('materials.store', $course), ['title' => 'Lecture', 'file' => $file])->assertOk()->assertJsonPath('redirect', route('courses.show', $course));
            $material = Material::latest('id')->firstOrFail();
            $this->assertSame($teacher->id, $material->uploader_id);
            $this->assertSame($size, $material->size_bytes);
            $this->assertNotNull($material->uploaded_at);
            $this->assertNotSame('lecture.'.$extension, basename($material->path));
            Storage::disk('local')->assertExists($material->path);
            $this->actingAs($student)->get(route('materials.download', $material))->assertForbidden();
        }
        $this->post(route('courses.enroll', $course))->assertRedirect();
        $this->get(route('materials.download', $material))->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->post(route('notes.store'), ['source_type' => 'material', 'source_id' => $material->id])->assertSessionHasErrors('source');
    }

    public function test_invalid_office_content_and_macros_are_rejected_without_storing_files(): void
    {
        Storage::fake('local');
        $teacher = User::factory()->create(['role' => 'instructor']);
        $course = Course::create(['instructor_id' => $teacher->id, 'title' => 'Materials', 'description' => 'Online', 'status' => 'published']);
        foreach ([UploadedFile::fake()->createWithContent('fake.docx', 'not a document'), $this->office('docx', true), UploadedFile::fake()->createWithContent('fake.pdf', 'not a PDF')] as $file) {
            $this->actingAs($teacher)->postJson(route('materials.store', $course), ['title' => 'Invalid', 'file' => $file])->assertUnprocessable()->assertJsonValidationErrors('file');
        }
        $this->assertDatabaseCount('materials', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_ten_megabyte_limit_accepts_boundary_and_rejects_larger_files(): void
    {
        Storage::fake('local');
        $teacher = User::factory()->create(['role' => 'instructor']);
        $course = Course::create(['instructor_id' => $teacher->id, 'title' => 'Materials', 'description' => 'Online', 'status' => 'published']);
        $this->actingAs($teacher)->post(route('materials.store', $course), ['title' => 'Large text', 'file' => UploadedFile::fake()->createWithContent('reader.txt', str_repeat('x', 10 * 1024 * 1024))])->assertRedirect();
        $this->post(route('materials.store', $course), ['title' => 'Too large', 'file' => UploadedFile::fake()->create('large.txt', 10241, 'text/plain')])->assertSessionHasErrors('file');
        $this->assertDatabaseCount('materials', 1);
    }
}
