<?php

namespace App\Domain\Money\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * A money action was refused for a reason a person can act on. `code` is stable and machine readable;
 * `status` is the HTTP status an endpoint answers with.
 */
class CheckoutRefused extends RuntimeException
{
    /**
     * @param  array<string, scalar|null>  $context
     */
    public function __construct(public readonly string $reason, string $message, public readonly int $status = 422, public readonly array $context = [])
    {
        parent::__construct($message);
    }

    /**
     * @param  array<string, scalar|null>  $context
     */
    public static function because(string $reason, string $message, int $status = 422, array $context = []): self
    {
        return new self($reason, $message, $status, $context);
    }

    /** JSON for the API ({error, message}), a redirect with the message for a form. */
    public function render(Request $request): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson() || $request->is('api/*')) {
            return response()->json(['error' => $this->reason, 'message' => $this->getMessage()] + $this->context, $this->status);
        }

        return back()->withErrors(['money' => $this->getMessage()]);
    }
}
