<?php

namespace App\Domain\Learning\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Base for learning controllers: one action answers the JSON API and the web form, decided per request. */
abstract class LearningController extends Controller
{
    protected function wantsJson(Request $request): bool
    {
        return $request->is('api/*') || $request->expectsJson();
    }

    protected function error(string $code, string $message, int $status): JsonResponse
    {
        return response()->json(['error' => $code, 'message' => $message], $status);
    }
}
