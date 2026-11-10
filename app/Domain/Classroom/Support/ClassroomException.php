<?php

namespace App\Domain\Classroom\Support;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/** A refusal from the classroom, rendered as {error, message} (JSON) or a flash error (pages). */
class ClassroomException extends RuntimeException
{
    /** @param array<string, mixed> $extra */
    public function __construct(public readonly string $error, string $message, public readonly int $status = 422, public readonly array $extra = [])
    {
        parent::__construct($message);
    }

    public static function from(Admission $admission): self
    {
        return new self($admission->code ?? 'not_allowed', $admission->message, $admission->status(), ['admission' => $admission->jsonSerialize()]);
    }

    public function render(Request $request): Response
    {
        if ($request->expectsJson()) {
            return response()->json(['error' => $this->error, 'message' => $this->getMessage(), ...$this->extra], $this->status);
        }

        if ($this->status === 403) {
            return app(ExceptionHandler::class)->render($request, new AccessDeniedHttpException($this->getMessage()));
        }

        return back()->with('error', $this->getMessage())->withErrors(['classroom' => $this->getMessage()]);
    }
}
