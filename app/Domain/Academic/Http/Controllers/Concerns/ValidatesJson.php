<?php

namespace App\Domain\Academic\Http\Controllers\Concerns;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * Kept as a thin wrapper so the controllers do not change: since R-47 D-1404 the
 * app renders {error, message, errors} for every request that expects JSON, so
 * a failed validation is simply thrown and rendered by bootstrap/app.php.
 */
trait ValidatesJson
{
    /**
     * @param  array<string, mixed>  $rules
     * @return array<string, mixed>|JsonResponse
     */
    protected function validateJson(Request $request, array $rules): array|JsonResponse
    {
        return Validator::make($request->all(), $rules)->validate();
    }
}
