<?php

namespace App\Domain\Academic\Http\Controllers\Concerns;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * The app renders JSON errors only under api/*, so the academic JSON endpoints
 * validate themselves and answer the contract's {error, message} shape with 422.
 */
trait ValidatesJson
{
    /**
     * @param  array<string, mixed>  $rules
     * @return array<string, mixed>|JsonResponse
     */
    protected function validateJson(Request $request, array $rules): array|JsonResponse
    {
        $validator = Validator::make($request->all(), $rules);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'validation_failed',
                'message' => $validator->errors()->first(),
                'errors' => $validator->errors()->toArray(),
            ], 422);
        }

        return $validator->validated();
    }
}
