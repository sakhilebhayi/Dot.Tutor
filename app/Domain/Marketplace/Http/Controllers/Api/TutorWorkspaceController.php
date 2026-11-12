<?php

namespace App\Domain\Marketplace\Http\Controllers\Api;

use App\Domain\Marketplace\Enums\QualificationKind;
use App\Domain\Marketplace\Models\QualificationEvidence;
use App\Domain\Marketplace\Models\TutorOffering;
use App\Domain\Marketplace\Models\TutorQualification;
use App\Domain\Marketplace\Services\BookingFlow;
use App\Domain\Marketplace\Services\BookingPresenter;
use App\Domain\Marketplace\Services\Idempotency;
use App\Domain\Marketplace\Services\OfferingService;
use App\Domain\Marketplace\Services\PrivateFiles;
use App\Domain\Marketplace\Services\QualificationService;
use App\Domain\Marketplace\Services\TutorPresenter;
use App\Domain\Marketplace\Services\TutorProfileService;
use App\Http\Controllers\Controller;
use App\Models\TutorProfile;
use App\Models\TutorSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The tutor's own workspace (brief sections 7, 16, 25 tutor journey):
 * profile v2, photo, teaching scope, qualifications and evidence, offerings,
 * group lessons, and the bookings they have received.
 */
class TutorWorkspaceController extends Controller
{
    private function profile(Request $request): TutorProfile
    {
        $profile = $request->user()->tutorProfile()->first();
        abort_if($profile === null, 404, 'Apply to become a tutor first.');

        return $profile;
    }

    public function show(Request $request, TutorPresenter $presenter): JsonResponse
    {
        $profile = $this->profile($request);
        $profile->load(['qualificationRecords.evidence', 'teachingScopes', 'offerings.subjects']);

        return response()->json(['data' => [
            ...$presenter->storefront($profile),
            'status' => $profile->status,
            'decision_reason' => $profile->decisionReason(),
            'qualifications' => $profile->qualificationRecords->map(fn ($q) => $presenter->qualification($q, owner: true))->all(),
            'offerings' => $profile->offerings->map(fn ($o) => $presenter->offering($o))->all(),
        ]]);
    }

    public function update(Request $request, TutorProfileService $service, TutorPresenter $presenter): JsonResponse
    {
        $profile = $this->profile($request);
        $data = $request->validate([
            'headline' => ['sometimes', 'string', 'max:160'],
            'introduction' => ['sometimes', 'string', 'max:5000'],
            'teaching_approach' => ['sometimes', 'nullable', 'string', 'max:3000'],
            'experience_summary' => ['sometimes', 'nullable', 'string', 'max:3000'],
            'years_experience' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:70'],
            'teaches_online' => ['sometimes', 'boolean'],
            'teaches_in_person' => ['sometimes', 'boolean'],
            'in_person_area' => ['sometimes', 'nullable', 'string', 'max:120'],
            'group_size_min' => ['sometimes', 'nullable', 'integer', 'min:2', 'max:'.(int) config('marketplace.group_capacity_max')],
            'group_size_max' => ['sometimes', 'nullable', 'integer', 'min:2', 'max:'.(int) config('marketplace.group_capacity_max')],
            'hourly_rate_minor' => ['sometimes', 'integer', 'min:1', 'max:9999999'],
            'public_handle' => ['sometimes', 'nullable', 'string', 'min:3', 'max:60', 'regex:/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/', Rule::unique('tutor_profiles', 'public_handle')->ignore($profile->id)],
        ]);

        return response()->json(['data' => $presenter->storefront($service->update($profile, $data))]);
    }

    public function uploadPhoto(Request $request, TutorProfileService $service): JsonResponse
    {
        $profile = $this->profile($request);
        $request->validate(['photo' => ['required', 'file', 'max:'.(int) config('marketplace.photo_max_kb')]]);
        $service->setPhoto($profile, $request->file('photo'));

        return response()->json(['data' => ['photo_url' => route('marketplace.tutors.photo', $profile)]], 201);
    }

    public function deletePhoto(Request $request, TutorProfileService $service): JsonResponse
    {
        $service->removePhoto($this->profile($request));

        return response()->json(['data' => ['photo_url' => null]]);
    }

    public function scopes(Request $request, TutorProfileService $service, TutorPresenter $presenter): JsonResponse
    {
        $profile = $this->profile($request);
        $data = $request->validate([
            'scopes' => ['required', 'array', 'min:1', 'max:30'],
            'scopes.*.subject_id' => ['required', 'integer', Rule::exists('subjects', 'id')->where('is_active', true)],
            'scopes.*.curriculum_id' => ['nullable', 'integer', 'min:1'],
            'scopes.*.level_min_id' => ['nullable', 'integer', 'min:1'],
            'scopes.*.level_max_id' => ['nullable', 'integer', 'min:1'],
            'scopes.*.notes' => ['nullable', 'string', 'max:300'],
        ]);
        $service->syncTeachingScopes($profile, $data['scopes']);

        return response()->json(['data' => $presenter->storefront($profile->refresh())['teaching_scope']]);
    }

    // Qualifications ---------------------------------------------------

    /** @return array<string, mixed> */
    private function qualificationRules(bool $partial): array
    {
        $req = $partial ? 'sometimes' : 'required';

        return [
            'kind' => [$req, Rule::in(QualificationKind::values())],
            'title' => [$req, 'string', 'max:200'],
            'institution' => ['sometimes', 'nullable', 'string', 'max:200'],
            'year_awarded' => ['sometimes', 'nullable', 'integer', 'min:1950', 'max:'.((int) date('Y') + 1)],
            'reference' => ['sometimes', 'nullable', 'string', 'max:120'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }

    private function ownQualification(Request $request, TutorQualification $qualification): TutorQualification
    {
        abort_unless($qualification->tutor_profile_id === $this->profile($request)->id, 404);

        return $qualification;
    }

    public function qualifications(Request $request, TutorPresenter $presenter): JsonResponse
    {
        return response()->json(['data' => $this->profile($request)->qualificationRecords()->with('evidence')->get()
            ->map(fn ($q) => $presenter->qualification($q, owner: true))->all()]);
    }

    public function storeQualification(Request $request, QualificationService $service, TutorPresenter $presenter): JsonResponse
    {
        $q = $service->create($this->profile($request), $request->validate($this->qualificationRules(false)));

        return response()->json(['data' => $presenter->qualification($q->load('evidence'), owner: true)], 201);
    }

    public function updateQualification(Request $request, TutorQualification $qualification, QualificationService $service, TutorPresenter $presenter): JsonResponse
    {
        $q = $service->update($this->ownQualification($request, $qualification), $request->validate($this->qualificationRules(true)));

        return response()->json(['data' => $presenter->qualification($q->load('evidence'), owner: true)]);
    }

    public function destroyQualification(Request $request, TutorQualification $qualification, QualificationService $service): JsonResponse
    {
        $service->delete($this->ownQualification($request, $qualification));

        return response()->json(['data' => ['deleted' => true]]);
    }

    public function storeEvidence(Request $request, TutorQualification $qualification, QualificationService $service, TutorPresenter $presenter): JsonResponse
    {
        $q = $this->ownQualification($request, $qualification);
        $request->validate(['file' => ['required', 'file', 'max:'.(int) config('marketplace.evidence_max_kb')]]);
        $service->addEvidence($q, $request->file('file'), $request->user());

        return response()->json(['data' => $presenter->qualification($q->refresh()->load('evidence'), owner: true)], 201);
    }

    public function destroyEvidence(Request $request, QualificationEvidence $evidence, QualificationService $service): JsonResponse
    {
        $this->ownQualification($request, $evidence->qualification);
        $service->removeEvidence($evidence);

        return response()->json(['data' => ['deleted' => true]]);
    }

    public function downloadEvidence(Request $request, QualificationEvidence $evidence, PrivateFiles $files): StreamedResponse
    {
        $this->ownQualification($request, $evidence->qualification);
        abort_unless($files->exists($evidence->path), 404);

        return $files->stream($evidence->path, $evidence->mime, $evidence->original_name);
    }

    public function submitQualification(Request $request, TutorQualification $qualification, QualificationService $service, TutorPresenter $presenter): JsonResponse
    {
        $q = $service->submit($this->ownQualification($request, $qualification));

        return response()->json(['data' => $presenter->qualification($q->load('evidence'), owner: true)]);
    }

    // Offerings ----------------------------------------------------------

    private function ownOffering(Request $request, TutorOffering $offering): TutorOffering
    {
        abort_unless($offering->tutor_profile_id === $this->profile($request)->id, 404);

        return $offering;
    }

    public function offerings(Request $request, TutorPresenter $presenter): JsonResponse
    {
        return response()->json(['data' => $this->profile($request)->offerings()->with('subjects')->get()
            ->map(fn ($o) => $presenter->offering($o))->all()]);
    }

    public function storeOffering(Request $request, OfferingService $service, TutorPresenter $presenter): JsonResponse
    {
        $offering = $service->create($this->profile($request), $request->validate(OfferingService::rules()));

        return response()->json(['data' => $presenter->offering($offering)], 201);
    }

    public function updateOffering(Request $request, TutorOffering $offering, OfferingService $service, TutorPresenter $presenter): JsonResponse
    {
        $offering = $service->update($this->ownOffering($request, $offering), $request->validate(OfferingService::rules(partial: true)));

        return response()->json(['data' => $presenter->offering($offering)]);
    }

    public function archiveOffering(Request $request, TutorOffering $offering, OfferingService $service, TutorPresenter $presenter): JsonResponse
    {
        $offering = $service->archive($this->ownOffering($request, $offering));

        return response()->json(['data' => $presenter->offering($offering->load('subjects'))]);
    }

    public function scheduleGroup(Request $request, TutorOffering $offering, BookingFlow $flow, Idempotency $idempotency, BookingPresenter $presenter): JsonResponse
    {
        $this->ownOffering($request, $offering);
        $data = $request->validate([
            'subject_id' => ['required', 'integer'],
            'starts_at' => ['required', 'date'],
            'location_note' => ['nullable', 'string', 'max:200'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        [$session, $replayed] = $idempotency->run(
            $request->user(), 'group_schedule:'.$offering->id, $request->header('Idempotency-Key'), $data,
            fn () => $flow->scheduleGroup($request->user(), $offering, $data),
            fn (int $id) => TutorSession::query()->find($id),
        );

        return response()->json(['data' => $presenter->session($session, $request->user()), 'replayed' => $replayed], $replayed ? 200 : 201);
    }
}
