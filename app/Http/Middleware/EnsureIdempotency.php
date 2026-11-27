<?php

namespace App\Http\Middleware;

use App\Models\IdempotencyKey;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class EnsureIdempotency
{
    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->header('Idempotency-Key');

        // header is optional: without it, checkout behaves as before
        if ($key === null) {
            return $next($request);
        }

        if (trim($key) === '' || strlen($key) > 100) {
            return $this->error('Idempotency-Key must be 1-100 characters.', 422);
        }

        $scope = (string) ($request->user('sanctum')?->id ?? 'guest');
        $hash  = hash('sha256', $request->method().'|'.$request->path().'|'.json_encode($request->all()));

        try {
            // atomic claim: the unique index lets exactly one request through
            $record = IdempotencyKey::create([
                'key' => $key, 'scope' => $scope, 'request_hash' => $hash,
            ]);
        } catch (UniqueConstraintViolationException) {
            return $this->handleExisting($request, $next, $scope, $key, $hash);
        }

        try {
            $response = $next($request);
        } catch (Throwable $e) {
            $record->delete();      // let the client retry
            throw $e;
        }

        if ($response->isSuccessful()) {
            $record->update([
                'response_status' => $response->getStatusCode(),
                'response_body'   => $response->getContent(),
                'completed_at'    => now(),
            ]);
        } else {
            $record->delete();      // failures aren't cached, so the same key can be retried
        }

        return $response;
    }

    private function handleExisting(Request $request, Closure $next, string $scope, string $key, string $hash): Response
    {
        $existing = IdempotencyKey::where('scope', $scope)->where('key', $key)->first();

        // deleted between our insert and this lookup: start over
        if (! $existing) {
            return $this->handle($request, $next);
        }

        if (! hash_equals($existing->request_hash, $hash)) {
            return $this->error('Idempotency-Key was already used with a different request.', 422);
        }

        if ($existing->completed_at === null) {
            // a crashed request would block this key forever, so take over after 1 minute
            if ($existing->created_at->lt(now()->subMinute())) {
                $existing->delete();
                return $this->handle($request, $next);
            }

            return $this->error('A request with this Idempotency-Key is still being processed.', 409)
                ->header('Retry-After', '2');
        }

        return response($existing->response_body, $existing->response_status, [
            'Content-Type'        => 'application/json',
            'Idempotent-Replayed' => 'true',
        ]);
    }

    private function error(string $message, int $status): \Illuminate\Http\JsonResponse
    {
        return response()->json(['message' => $message, 'errors' => (object) []], $status);
    }
}