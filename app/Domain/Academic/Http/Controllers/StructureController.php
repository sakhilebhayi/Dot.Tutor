<?php

namespace App\Domain\Academic\Http\Controllers;

use App\Domain\Academic\Contracts\AcademicScope;
use App\Domain\Academic\Models\AcademicLevel;
use App\Domain\Academic\Models\Curriculum;
use App\Domain\Academic\Models\SubjectOffering;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Read-only JSON of the academic structure for pickers (curricula by country, levels, subjects at a level, topics). */
class StructureController extends Controller
{
    public function curricula(Request $request): JsonResponse
    {
        $curricula = Curriculum::query()->active()->availableIn($request->integer('country') ?: null)->orderBy('sort')
            ->with(['levels' => fn ($q) => $q->active()->with('stage')])->get();

        return response()->json(['data' => $curricula->map(fn (Curriculum $c) => [
            'id' => $c->id, 'code' => $c->code, 'name' => $c->name, 'short_name' => $c->short_name, 'international' => $c->isInternational(),
            'levels' => $c->levels->map(fn (AcademicLevel $l) => ['id' => $l->id, 'code' => $l->code, 'name' => $l->name, 'stage' => $l->stage->code])->values(),
        ])->values()]);
    }

    public function offerings(AcademicScope $scope, AcademicLevel $level): JsonResponse
    {
        return response()->json(['data' => $scope->offeringsAtLevel($level->id)->map(fn (SubjectOffering $o) => [
            'offering_id' => $o->id, 'subject_id' => $o->subject_id, 'name' => $o->subject->name,
            'category' => $o->subject->category, 'compulsory' => $o->is_compulsory,
        ])->values()]);
    }

    public function topics(SubjectOffering $offering): JsonResponse
    {
        $topics = $offering->topics()->where('is_active', true)->get(['id', 'parent_id', 'name', 'position']);

        return response()->json(['data' => $topics->whereNull('parent_id')->map(fn ($t) => [
            'id' => $t->id, 'name' => $t->name,
            'children' => $topics->where('parent_id', $t->id)->map(fn ($c) => ['id' => $c->id, 'name' => $c->name])->values(),
        ])->values()]);
    }
}
