<?php

namespace App\Domain\Academic\Http\Controllers;

use App\Domain\Academic\Translations;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * GET the original and a translation side by side. Catalogue content
 * (academic.*) is readable by any signed-in user; every other registered type
 * must pass its own `view` policy, so a private course's text never leaks
 * through this endpoint.
 */
class TranslationController extends Controller
{
    public function show(Request $request, Translations $translations, string $type, int $id, string $field): JsonResponse
    {
        $data = $request->validate(['locale' => ['required', 'string', 'max:16', Rule::exists('languages', 'code')]]);

        if (! $translations->isTranslatableField($type, $field)) {
            return response()->json(['error' => 'not_translatable', 'message' => 'That content cannot be translated.'], 404);
        }

        $model = $translations->resolve($type, $id);
        if (! $model) {
            return response()->json(['error' => 'not_found', 'message' => 'That content does not exist.'], 404);
        }
        if (! str_starts_with($type, 'academic.') && Gate::denies('view', $model)) {
            return response()->json(['error' => 'forbidden', 'message' => 'You cannot see that content.'], 403);
        }

        return response()->json($translations->sideBySide($model, $field, $data['locale']));
    }
}
