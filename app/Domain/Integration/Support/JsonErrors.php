<?php

namespace App\Domain\Integration\Support;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * One error policy for every JSON answer (architecture section 2: {error, message} with a proper status). A request
 * gets JSON when it is under api/* or asks for JSON (Accept: application/json, XHR); web forms keep redirects with
 * validation errors and HTML error pages. Exceptions that render themselves (money's CheckoutRefused, safety's
 * SafeguardingDenied) and domain exceptions with their own renderer (marketplace) keep their own error codes:
 * Laravel calls an exception's render() first, and these callbacks only answer the framework's own exceptions.
 */
final class JsonErrors
{
    public static function wantsJson(Request $request): bool
    {
        return $request->is('api/*') || $request->expectsJson();
    }

    public static function render(Throwable $e, Request $request): ?JsonResponse
    {
        if (! self::wantsJson($request)) {
            return null;
        }

        return match (true) {
            $e instanceof ValidationException => response()->json([
                'error' => 'validation_failed', 'message' => $e->getMessage(), 'errors' => $e->errors(),
            ], $e->status),
            $e instanceof AuthenticationException => response()->json(['error' => 'unauthenticated', 'message' => 'Sign in first.'], 401),
            $e instanceof HttpExceptionInterface => response()->json([
                'error' => self::code($e->getStatusCode()),
                'message' => $e->getMessage() !== '' ? $e->getMessage() : self::defaultMessage($e->getStatusCode()),
            ], $e->getStatusCode(), $e->getHeaders()),
            // Domain exceptions (marketplace) have their own renderers registered by their providers; anything else
            // falls through to Laravel and gets an error code from finish() below.
            default => null,
        };
    }

    /**
     * Last step for every rendered error: a JSON error body without an "error" key (Laravel's own server error, or
     * an endpoint that answered {message} only) gets one from its status, so every JSON error carries {error, message}.
     */
    public static function finish(Response $response, Request $request): Response
    {
        if ($response->getStatusCode() < 400 || ! $response instanceof JsonResponse) {
            return $response;
        }

        $data = $response->getData(true);

        if (is_array($data) && ! array_key_exists('error', $data)) {
            $status = $response->getStatusCode();
            $message = $status >= 500 && ! config('app.debug') ? 'Something went wrong on our side. Try again later.' : ($data['message'] ?? self::defaultMessage($status));
            $response->setData(['error' => $status >= 500 ? 'server_error' : self::code($status), 'message' => $message] + $data);
        }

        return $response;
    }

    public static function code(int $status): string
    {
        return match ($status) {
            400 => 'bad_request',
            401 => 'unauthenticated',
            403 => 'forbidden',
            404 => 'not_found',
            405 => 'method_not_allowed',
            409 => 'conflict',
            413 => 'payload_too_large',
            419 => 'session_expired',
            422 => 'unprocessable',
            428 => 'precondition_required',
            429 => 'too_many_requests',
            503 => 'service_unavailable',
            default => 'http_'.$status,
        };
    }

    private static function defaultMessage(int $status): string
    {
        return match ($status) {
            401 => 'Sign in first.',
            403 => 'You are not allowed to do this.',
            404 => 'Not found.',
            419 => 'Your session expired. Reload the page and try again.',
            429 => 'Too many requests. Wait a minute and try again.',
            default => 'Request refused.',
        };
    }
}
