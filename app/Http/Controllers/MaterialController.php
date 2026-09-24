<?php

namespace App\Http\Controllers;

use App\Http\Requests\ReplaceMaterialRequest;
use App\Http\Requests\UploadMaterialRequest;
use App\Models\Course;
use App\Models\Material;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MaterialController extends Controller
{
    public function store(UploadMaterialRequest $request, Course $course): RedirectResponse|JsonResponse
    {
        Gate::authorize('manage', $course);
        $data = $request->validated();
        $file = $request->file('file');
        $path = $file->store('materials', 'local');
        abort_unless($path, 500, 'Upload failed.');
        try {
            DB::transaction(function () use ($request, $course, $data, $file, $path) {
                DB::table('lms_write_locks')->where('id', 1)->lockForUpdate()->first();
                Gate::forUser($request->user()->fresh())->authorize('manage', $course->fresh());
                $course->materials()->create([
                    'uploader_id' => $request->user()->id,
                    'size_bytes' => $file->getSize(),
                    'uploaded_at' => now(),
                    'title' => $data['title'],
                    'lesson_id' => $data['lesson_id'] ?? null,
                    'path' => $path,
                    'original_name' => $file->getClientOriginalName(),
                    'format' => strtolower($file->getClientOriginalExtension()),
                ]);
            });
        } catch (\Throwable $e) {
            Storage::disk('local')->delete($path);
            throw $e;
        }

        if ($request->expectsJson()) {
            return response()->json(['redirect' => route('courses.show', $course)]);
        }

        return back()->with('status', 'Material uploaded securely. PDF, DOCX, PPTX, TXT and Markdown downloads are supported.');
    }

    /**
     * Replace the file behind a material, retaining the previous file and metadata as a revision.
     */
    public function replace(ReplaceMaterialRequest $request, Material $material): RedirectResponse|JsonResponse
    {
        $data = $request->validated();
        $file = $request->file('file');
        $path = $file->store('materials', 'local');
        abort_unless($path, 500, 'Upload failed.');
        try {
            DB::transaction(function () use ($request, $material, $data, $file, $path) {
                DB::table('lms_write_locks')->where('id', 1)->lockForUpdate()->first();
                $current = Material::whereKey($material->id)->lockForUpdate()->firstOrFail();
                Gate::forUser($request->user()->fresh())->authorize('manage', $current->course);
                abort_if($current->status === 'archived', 409, 'Restore this material before replacing its file.');
                abort_if((int) $data['version'] !== $current->version, 409, 'This material changed. Reload before replacing it.');

                DB::table('material_revisions')->insert([
                    'material_id' => $current->id,
                    'version' => $current->version,
                    'title' => $current->title,
                    'path' => $current->path,
                    'original_name' => $current->original_name,
                    'format' => $current->format,
                    'size_bytes' => $current->size_bytes,
                    'uploader_id' => $current->uploader_id,
                    'uploaded_at' => $current->uploaded_at,
                    'replaced_by' => $request->user()->id,
                    'replaced_at' => now(),
                    'reason' => $data['reason'],
                ]);

                $current->update([
                    'title' => $data['title'],
                    'path' => $path,
                    'original_name' => $file->getClientOriginalName(),
                    'format' => strtolower($file->getClientOriginalExtension()),
                    'size_bytes' => $file->getSize(),
                    'uploader_id' => $request->user()->id,
                    'uploaded_at' => now(),
                    'version' => $current->version + 1,
                ]);
            });
        } catch (\Throwable $e) {
            Storage::disk('local')->delete($path);
            throw $e;
        }

        if ($request->expectsJson()) {
            return response()->json(['redirect' => route('courses.show', $material->course_id)]);
        }

        return back()->with('status', 'Material replaced. The previous file is retained in the revision history.');
    }

    /**
     * Archive or restore a material. Archiving removes learner access and blocks new AI generation,
     * but never deletes the stored file, its revisions, or study notes already generated from it.
     */
    public function archive(Request $request, Material $material): RedirectResponse
    {
        Gate::authorize('manage', $material->course);
        $data = $request->validate([
            'version' => 'required|integer|min:0',
            'action' => 'required|in:archive,restore',
            'reason' => 'required_if:action,archive|nullable|string|max:1000',
        ]);

        DB::transaction(function () use ($request, $material, $data) {
            DB::table('lms_write_locks')->where('id', 1)->lockForUpdate()->first();
            $current = Material::whereKey($material->id)->lockForUpdate()->firstOrFail();
            Gate::forUser($request->user()->fresh())->authorize('manage', $current->course);
            abort_if((int) $data['version'] !== $current->version, 409, 'This material changed. Reload before continuing.');
            $archiving = $data['action'] === 'archive';
            if ($archiving === ($current->status === 'archived')) {
                return; // Idempotent: already in the requested state.
            }
            $current->update([
                'status' => $archiving ? 'archived' : 'active',
                'archived_at' => $archiving ? now() : null,
                'archived_by' => $archiving ? $request->user()->id : null,
            ]);
        });

        return back()->with('status', $data['action'] === 'archive'
            ? 'Material archived. Learners can no longer open it and new AI notes cannot be generated from it. Existing notes are preserved.'
            : 'Material restored for learners.');
    }

    public function download(Request $request, Material $material): StreamedResponse
    {
        Gate::authorize('view', $material->course);
        $manage = Gate::allows('manage', $material->course);
        abort_if($material->status === 'archived' && ! $manage, 404);
        abort_unless(Storage::disk('local')->exists($material->path), 404);

        return Storage::disk('local')->download($material->path, $material->original_name, ['X-Content-Type-Options' => 'nosniff']);
    }

    /**
     * Superseded material files stay available to course staff only, as historical evidence.
     */
    public function revision(Request $request, int $revision): StreamedResponse
    {
        $record = DB::table('material_revisions')->find($revision);
        abort_unless($record, 404);
        $material = Material::findOrFail($record->material_id);
        Gate::authorize('manage', $material->course);
        abort_unless(Storage::disk('local')->exists($record->path), 404);

        return Storage::disk('local')->download($record->path, $record->original_name, ['X-Content-Type-Options' => 'nosniff']);
    }
}
