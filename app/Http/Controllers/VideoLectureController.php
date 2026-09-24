<?php

namespace App\Http\Controllers;

use App\Actions\VideoLectureWorkflow;
use App\Models\Course;
use App\Models\VideoLecture;
use App\Models\VideoLectureProgress;
use App\Models\VideoLectureTrack;
use App\Services\PrivateMediaStream;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

class VideoLectureController extends Controller
{
    public function __construct(private VideoLectureWorkflow $workflow) {}

    public function index(Request $request, Course $course): View
    {
        Gate::authorize('view', $course);
        $manage = Gate::allows('manage', $course);
        $lectures = VideoLecture::where('course_id', $course->id)
            ->when(! $manage, fn ($query) => $query->where('status', 'published')->where('processing_status', 'ready'))
            ->with('lesson')
            ->orderBy('position')->orderBy('id')
            ->paginate(20);

        $progress = VideoLectureProgress::where('user_id', $request->user()->id)
            ->whereIn('video_lecture_id', $lectures->pluck('id'))->get()->keyBy('video_lecture_id');

        return view('lectures.index', compact('course', 'manage', 'lectures', 'progress'));
    }

    public function store(Request $request, Course $course): RedirectResponse|JsonResponse
    {
        Gate::authorize('manage', $course);
        abort_unless($request->hasFile('file'), 422, 'Choose an MP4 lecture recording to upload.');
        $lecture = $this->workflow->create($request->user(), $course, $request->all(), $request->file('file'));

        if ($request->expectsJson()) {
            return response()->json(['redirect' => route('lectures.show', $lecture)]);
        }

        return redirect()->route('lectures.show', $lecture)->with('status', 'Lecture uploaded as a draft. Review playback, then publish it.');
    }

    public function show(Request $request, VideoLecture $lecture): View
    {
        $this->authorizeWatch($request, $lecture);
        $manage = Gate::allows('manage', $lecture->course);
        $lecture->load(['tracks', 'lesson', 'uploader']);
        $progress = VideoLectureProgress::where('video_lecture_id', $lecture->id)->where('user_id', $request->user()->id)->first();

        $roster = collect();
        $revisions = collect();
        if ($manage) {
            $roster = VideoLectureProgress::where('video_lecture_id', $lecture->id)->with('user')->orderByDesc('watched_seconds')->paginate(30);
            $revisions = DB::table('video_lecture_revisions')->leftJoin('users', 'users.id', '=', 'video_lecture_revisions.replaced_by')
                ->where('video_lecture_id', $lecture->id)->select('video_lecture_revisions.*', 'users.name as replaced_by_name')
                ->orderByDesc('version')->get();
        }

        return view('lectures.show', compact('lecture', 'manage', 'progress', 'roster', 'revisions'));
    }

    public function update(Request $request, VideoLecture $lecture): RedirectResponse
    {
        Gate::authorize('manage', $lecture->course);
        $this->workflow->updateDetails($request->user(), $lecture, $request->all());

        return back()->with('status', 'Lecture details updated.');
    }

    public function replaceMedia(Request $request, VideoLecture $lecture): RedirectResponse|JsonResponse
    {
        Gate::authorize('manage', $lecture->course);
        abort_unless($request->hasFile('file'), 422, 'Choose a replacement MP4 file.');
        $this->workflow->replaceMedia($request->user(), $lecture, $request->all(), $request->file('file'));

        if ($request->expectsJson()) {
            return response()->json(['redirect' => route('lectures.show', $lecture)]);
        }

        return back()->with('status', 'Lecture media replaced. The previous recording is retained in the revision history.');
    }

    public function status(Request $request, VideoLecture $lecture): RedirectResponse
    {
        Gate::authorize('manage', $lecture->course);
        $this->workflow->changeStatus($request->user(), $lecture, $request->all());

        return back()->with('status', 'Lecture status updated.');
    }

    public function poster(Request $request, VideoLecture $lecture): RedirectResponse
    {
        Gate::authorize('manage', $lecture->course);
        abort_unless($request->hasFile('poster'), 422, 'Choose a poster image.');
        $this->workflow->savePoster($request->user(), $lecture, $request->file('poster'));

        return back()->with('status', 'Poster image saved.');
    }

    public function track(Request $request, VideoLecture $lecture): RedirectResponse
    {
        Gate::authorize('manage', $lecture->course);
        $this->workflow->saveTrack($request->user(), $lecture, $request->all(), $request->file('file'));

        return back()->with('status', 'Captions and transcript saved.');
    }

    /**
     * Authorized, private byte delivery with Range and HEAD support.
     */
    public function stream(Request $request, VideoLecture $lecture, PrivateMediaStream $stream): Response
    {
        $this->authorizeWatch($request, $lecture);
        abort_unless(Storage::disk('local')->exists($lecture->path), 404);

        return $stream->respond(
            Storage::disk('local')->path($lecture->path),
            $lecture->mime_type,
            $request->header('Range'),
            $request->isMethod('HEAD'),
            $lecture->path,
        );
    }

    public function poster_image(Request $request, VideoLecture $lecture): Response
    {
        $this->authorizeWatch($request, $lecture);
        abort_unless($lecture->poster_path && Storage::disk('local')->exists($lecture->poster_path), 404);

        return response(Storage::disk('local')->get($lecture->poster_path), 200, [
            'Content-Type' => 'image/'.pathinfo($lecture->poster_path, PATHINFO_EXTENSION),
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store, max-age=0',
        ]);
    }

    public function captions(Request $request, VideoLecture $lecture, VideoLectureTrack $track): Response
    {
        $this->authorizeWatch($request, $lecture);
        abort_unless($track->video_lecture_id === $lecture->id && $track->kind === 'captions' && $track->path, 404);
        abort_unless(Storage::disk('local')->exists($track->path), 404);

        return response(Storage::disk('local')->get($track->path), 200, [
            'Content-Type' => 'text/vtt; charset=UTF-8',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store, max-age=0',
        ]);
    }

    /** Superseded recordings stay available to course staff only. */
    public function revision(Request $request, int $revision): Response
    {
        $record = DB::table('video_lecture_revisions')->find($revision);
        abort_unless($record, 404);
        $lecture = VideoLecture::findOrFail($record->video_lecture_id);
        Gate::authorize('manage', $lecture->course);
        abort_unless(Storage::disk('local')->exists($record->path), 404);

        return Storage::disk('local')->download($record->path, $record->original_name, ['X-Content-Type-Options' => 'nosniff']);
    }

    public function progress(Request $request, VideoLecture $lecture): JsonResponse
    {
        $this->authorizeWatch($request, $lecture);
        abort_unless($request->user()->role === 'student', 403, 'Only learners record viewing progress.');
        $result = $this->workflow->recordProgress($request->user(), $lecture, $request->all());

        return response()->json($result);
    }

    /**
     * Learners need an active account, a published course, an enrollment and a
     * published, ready lecture. Course staff may watch their own drafts.
     */
    private function authorizeWatch(Request $request, VideoLecture $lecture): void
    {
        Gate::authorize('view', $lecture->course);
        if (! Gate::allows('manage', $lecture->course)) {
            abort_unless($lecture->isPlayable(), 404);
        }
    }
}
