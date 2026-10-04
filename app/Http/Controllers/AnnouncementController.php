<?php

namespace App\Http\Controllers;

use App\Actions\WriteLock;
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

        // Drafts and scheduled items are visible only to the author, and to administrators for
        // platform-wide notices. Nobody else can see an announcement before it is released.
        $pending = $request->user()->role === 'student'
            ? collect()
            : Announcement::pending()->with('course')
                ->where(fn ($own) => $own->where('author_id', $request->user()->id)
                    ->when($request->user()->role === 'admin', fn ($admin) => $admin->orWhereNull('course_id')))
                ->orderByRaw('published_at is null desc')->orderBy('published_at')->get();

        return view('announcements.index', compact('announcements', 'pending'));
    }

    public function store(PublishAnnouncementRequest $request, ?Course $course = null): RedirectResponse
    {
        DB::transaction(function () use ($request, $course) {
            WriteLock::acquire();
            $actor = $request->user()->fresh();
            abort_unless($actor->is_active, 403);
            if ($course) {
                Gate::forUser($actor)->authorize('manage', $course->fresh());
            } else {
                abort_unless($actor->role === 'admin', 403);
            }
            Announcement::create(collect($request->validated())->only(['title', 'body'])->all() + [
                'course_id' => $course?->id,
                'author_id' => $actor->id,
                'published_at' => $request->publicationTime(),
            ]);
        });

        return back()->with('status', match ($request->input('state', 'now')) {
            'draft' => 'Saved as a draft. It is not visible to anyone until you publish it.',
            'schedule' => 'Scheduled. It will appear for its audience at the time you chose, with no further action needed.',
            default => 'Announcement published.',
        });
    }

    /**
     * Edit an announcement that nobody has seen yet.
     *
     * Once published it is left alone: people have already read it, and silently rewriting a
     * message they acted on is worse than publishing a correction.
     */
    public function update(PublishAnnouncementRequest $request, Announcement $announcement): RedirectResponse
    {
        DB::transaction(function () use ($request, $announcement) {
            WriteLock::acquire();
            $current = Announcement::whereKey($announcement->id)->lockForUpdate()->firstOrFail();
            $actor = $request->user()->fresh();
            abort_unless($actor->is_active, 403);
            abort_unless($current->isPending(), 409, 'This announcement has already been published and can no longer be edited.');

            $current->update(collect($request->validated())->only(['title', 'body'])->all() + ['published_at' => $request->publicationTime()]);
        });

        return back()->with('status', 'Announcement updated.');
    }

    public function destroy(Request $request, Announcement $announcement): RedirectResponse
    {
        DB::transaction(function () use ($request, $announcement) {
            WriteLock::acquire();
            $current = Announcement::whereKey($announcement->id)->lockForUpdate()->firstOrFail();
            $actor = $request->user()->fresh();
            abort_unless($actor->is_active, 403);
            abort_unless($current->course_id ? Gate::forUser($actor)->allows('manage', $current->course) : $actor->role === 'admin', 403);
            // Only an unpublished announcement may be discarded; a released one is on record.
            abort_unless($current->isPending(), 409, 'A published announcement cannot be withdrawn. Publish a correction instead.');
            $current->delete();
        });

        return redirect()->route('announcements.index')->with('status', 'Unpublished announcement discarded.');
    }

    public function read(Request $request, Announcement $announcement): RedirectResponse
    {
        DB::transaction(function () use ($request, $announcement) {
            WriteLock::acquire();
            $actor = $request->user()->fresh();
            abort_unless($actor->is_active && Announcement::visibleTo($actor)->whereKey($announcement->id)->exists(), 403);
            DB::table('announcement_reads')->insertOrIgnore(['announcement_id' => $announcement->id, 'user_id' => $actor->id, 'read_at' => now()]);
        });

        return back()->with('status', 'Announcement marked as read.');
    }
}
