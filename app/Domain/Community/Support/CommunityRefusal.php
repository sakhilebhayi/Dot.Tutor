<?php

namespace App\Domain\Community\Support;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** A refused community action with a machine code. JSON callers get {error, message}; forms get a flash error. */
class CommunityRefusal extends HttpException
{
    /** @param array<string, mixed> $data */
    public function __construct(public readonly string $errorCode, string $message, int $status = 422, public readonly array $data = [])
    {
        parent::__construct($status, $message);
    }

    /** @param array<string, mixed> $data */
    public static function because(string $code, string $message, int $status = 422, array $data = []): self
    {
        return new self($code, $message, $status, $data);
    }

    public function render(Request $request): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson() || $request->is('api/*')) {
            return response()->json(array_filter([
                'error' => $this->errorCode,
                'message' => $this->getMessage(),
                'data' => $this->data ?: null,
            ], fn ($v) => $v !== null), $this->getStatusCode());
        }

        return back(303)->withInput()->withErrors(['community' => $this->getMessage()]);
    }
}
