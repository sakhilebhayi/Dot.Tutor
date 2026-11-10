<?php

namespace App\Domain\Safety\Support;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** A 403 carrying the gate's reasons. JSON callers get {error, message, reasons}; web forms get a flash. */
class SafeguardingDenied extends HttpException
{
    public function __construct(public readonly Decision $decision)
    {
        parent::__construct(403, $decision->message());
    }

    public function render(Request $request): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson()) {
            return response()->json([
                'error' => 'safeguarding_denied',
                'message' => $this->decision->message(),
                'reasons' => $this->decision->reasons,
            ], 403);
        }

        return back(303)->withErrors(['safety' => $this->decision->message()])->with('safety_reasons', $this->decision->codes());
    }
}
