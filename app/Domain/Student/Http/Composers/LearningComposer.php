<?php

namespace App\Domain\Student\Http\Composers;

use App\Domain\Academic\Models\Curriculum;
use App\Domain\Academic\Models\Language;
use App\Domain\Ai\Services\AiGateway;
use App\Models\Subject;
use Illuminate\View\View;

/** Choice lists for the course catalogue and the study library (levels by curriculum, languages, subjects). */
class LearningComposer
{
    public function compose(View $view): void
    {
        $curricula = Curriculum::query()->active()->orderBy('sort')->with(['levels' => fn ($q) => $q->active()])->get();

        $view->with([
            'curriculumOptions' => $curricula->mapWithKeys(fn ($c) => [$c->id => $c->label()])->all(),
            'levelOptions' => $curricula->flatMap(fn ($c) => $c->levels->map(fn ($l) => ['value' => $l->id, 'label' => $l->name, 'group' => $c->label()]))->values()->all(),
            'languageNames' => Language::query()->pluck('name_native', 'code')->all(),
            'languageOptions' => Language::query()->active()->orderBy('sort')->get(['code', 'name_en', 'name_native'])
                ->mapWithKeys(fn ($l) => [$l->code => $l->name_native.($l->name_native !== $l->name_en ? ' ('.$l->name_en.')' : '')])->all(),
            'subjectOptions' => Subject::query()->where('is_active', true)->orderBy('name')->pluck('name', 'id')->all(),
            'aiConfigured' => class_exists(AiGateway::class) && app(AiGateway::class)->configured(),
        ]);
    }
}
