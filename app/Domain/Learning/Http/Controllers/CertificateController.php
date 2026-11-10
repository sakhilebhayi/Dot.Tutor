<?php

namespace App\Domain\Learning\Http\Controllers;

use App\Domain\Learning\Models\Certificate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Completion certificates: the holder's list, the name-display choice, and the public verification page. */
class CertificateController extends LearningController
{
    public function index(Request $request): JsonResponse
    {
        return response()->json(['data' => Certificate::query()->where('user_id', $request->user()->id)->orderByDesc('issued_at')->get()
            ->map(fn (Certificate $c) => [
                'code' => $c->code, 'course_title' => $c->course_title, 'tutor_name' => $c->tutor_name,
                'issued_at' => $c->issued_at->toIso8601String(), 'show_full_name' => $c->show_full_name,
                'verify_url' => route('learning.certificates.verify', $c->code), 'revoked' => $c->revoked_at !== null,
            ])]);
    }

    public function updateName(Request $request, Certificate $certificate): JsonResponse|RedirectResponse
    {
        abort_unless($certificate->user_id === $request->user()->id, 404);
        $certificate->forceFill($request->validate(['show_full_name' => ['required', 'boolean']]))->save();

        return $this->wantsJson($request) ? response()->json(['data' => ['show_full_name' => $certificate->show_full_name]]) : back();
    }

    /** Public: anyone with the code can check a certificate. Shows initials unless the holder chose the full name. */
    public function verify(Request $request, string $code): JsonResponse|View
    {
        $certificate = Certificate::query()->where('code', strtoupper($code))->first();
        $payload = $certificate === null ? null : [
            'code' => $certificate->code,
            'valid' => $certificate->revoked_at === null,
            'learner' => $certificate->publicName(),
            'course_title' => $certificate->course_title,
            'tutor_name' => $certificate->tutor_name,
            'course_version' => $certificate->course_version_number,
            'issued_at' => $certificate->issued_at->toDateString(),
            'statement' => 'Issued by Dot.Tutor when the learner met the completion rule the course creator set. It records course completion; it is not an accredited qualification.',
        ];
        if ($this->wantsJson($request)) {
            return $payload === null ? $this->error('not_found', 'No certificate has this code.', 404) : response()->json(['data' => $payload]);
        }

        return view('learning.certificates.verify', ['certificate' => $payload, 'code' => $code]);
    }
}
