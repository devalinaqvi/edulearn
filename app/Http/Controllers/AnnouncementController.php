<?php

namespace App\Http\Controllers;

use App\Http\Requests\PublishAnnouncementRequest;
use App\Models\Announcement;
use App\Models\Course;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class AnnouncementController extends Controller
{
    public function index(Request $request): View
    {
        $announcements = Announcement::visibleTo($request->user())->with(['course', 'author'])->withExists(['readers as is_read' => fn ($query) => $query->where('users.id', $request->user()->id)])->orderByDesc('published_at')->paginate(20);

        return view('announcements.index', compact('announcements'));
    }

    public function store(PublishAnnouncementRequest $request, ?Course $course = null): RedirectResponse
    {
        DB::transaction(function () use ($request, $course) {
            DB::table('lms_write_locks')->where('id', 1)->lockForUpdate()->first();
            $actor = $request->user()->fresh();
            abort_unless($actor->is_active, 403);
            if ($course) {
                Gate::forUser($actor)->authorize('manage', $course->fresh());
            } else {
                abort_unless($actor->role === 'admin', 403);
            }
            Announcement::create($request->validated() + ['course_id' => $course?->id, 'author_id' => $actor->id, 'published_at' => now()]);
        });

        return back()->with('status', 'Announcement published.');
    }

    public function read(Request $request, Announcement $announcement): RedirectResponse
    {
        DB::transaction(function () use ($request, $announcement) {
            DB::table('lms_write_locks')->where('id', 1)->lockForUpdate()->first();
            $actor = $request->user()->fresh();
            abort_unless($actor->is_active && Announcement::visibleTo($actor)->whereKey($announcement->id)->exists(), 403);
            DB::table('announcement_reads')->insertOrIgnore(['announcement_id' => $announcement->id, 'user_id' => $actor->id, 'read_at' => now()]);
        });

        return back()->with('status', 'Announcement marked as read.');
    }
}
