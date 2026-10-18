<?php

namespace App\Http\Controllers;

use App\Models\LessonResource;
use App\Models\TutorSession;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class LessonResourceController extends Controller
{
    /**
     * Attach a file to a session -- worksheets, notes, recordings. Either
     * party (student or tutor) may upload once the session is confirmed
     * or completed; see TutorSessionPolicy::uploadResource().
     */
    public function store(Request $request, TutorSession $tutorSession): RedirectResponse
    {
        Gate::authorize('uploadResource', $tutorSession);

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'file' => ['required', 'file', 'max:10240', 'mimes:pdf,doc,docx,ppt,pptx,xls,xlsx,png,jpg,jpeg,txt,mp4,mp3'],
        ]);

        $path = $validated['file']->store('', 'lesson-resources');

        LessonResource::create([
            'session_id' => $tutorSession->id,
            'uploader_id' => $request->user()->id,
            'title' => $validated['title'],
            'file_path' => $path,
            'file_type' => $validated['file']->getClientOriginalExtension(),
        ]);

        return redirect()
            ->route('sessions.show', $tutorSession)
            ->with('status', 'Resource uploaded.');
    }

    /**
     * Stream a resource from the private disk to an authorized viewer --
     * the file is never written to a public path, so this is the only way
     * to actually reach the bytes.
     */
    public function download(LessonResource $lessonResource): Response
    {
        Gate::authorize('view', $lessonResource);

        abort_unless(Storage::disk('lesson-resources')->exists($lessonResource->file_path), 404);

        $filename = Str::slug($lessonResource->title).'.'.($lessonResource->file_type ?? 'bin');

        return response(
            Storage::disk('lesson-resources')->get($lessonResource->file_path),
            200,
            [
                'Content-Type' => Storage::disk('lesson-resources')->mimeType($lessonResource->file_path) ?: 'application/octet-stream',
                'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            ],
        );
    }

    public function destroy(LessonResource $lessonResource): RedirectResponse
    {
        Gate::authorize('delete', $lessonResource);

        $session = $lessonResource->session;
        // The model's deleted hook removes the file from the private disk.
        $lessonResource->delete();

        return redirect()
            ->route('sessions.show', $session)
            ->with('status', 'Resource removed.');
    }
}
