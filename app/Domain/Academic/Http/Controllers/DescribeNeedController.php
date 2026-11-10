<?php

namespace App\Domain\Academic\Http\Controllers;

use App\Domain\Academic\Http\Controllers\Concerns\ValidatesJson;
use App\Domain\Academic\Models\LearnerProfile;
use App\Domain\Academic\Services\NeedParser;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** JSON: level, subject and teaching-language candidates for a plain-words need. Deterministic, no AI. */
class DescribeNeedController extends Controller
{
    use ValidatesJson;

    public function __invoke(Request $request, NeedParser $parser): JsonResponse
    {
        $data = $this->validateJson($request, ['text' => ['required', 'string', 'max:500']]);
        if ($data instanceof JsonResponse) {
            return $data;
        }
        $curriculumId = LearnerProfile::query()->where('user_id', $request->user()->id)->value('curriculum_id');

        return response()->json($parser->parse($data['text'], $curriculumId));
    }
}
