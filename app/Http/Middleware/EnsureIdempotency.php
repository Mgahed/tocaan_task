<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\IdempotencyKey;
use Closure;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

class EnsureIdempotency
{
    /**
     * Handle an incoming request to guarantee idempotent execution when `Idempotency-Key` header is provided.
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! in_array($request->method(), ['POST', 'PUT', 'PATCH'], true)) {
            return $next($request);
        }

        $idempotencyKey = trim((string) $request->header('Idempotency-Key', ''));

        // If no Idempotency-Key header is provided, proceed normally
        if ($idempotencyKey === '') {
            return $next($request);
        }

        if (strlen($idempotencyKey) > 128) {
            return response()->json([
                'success' => false,
                'message' => 'Idempotency-Key header must not exceed 128 characters.',
            ], 422);
        }

        $userId = Auth::guard('api')->id();
        $userScope = $userId !== null ? (string) $userId : 'guest';
        $requestHash = $this->computeRequestHash($request);
        $lockKey = "idempotency_lock:{$userScope}:{$idempotencyKey}";
        $cacheKey = "idempotency_record:{$userScope}:{$idempotencyKey}";
        $lockSeconds = (int) env('IDEMPOTENCY_LOCK_SECONDS', 10);
        $ttlHours = (int) env('IDEMPOTENCY_TTL_HOURS', 24);

        $lock = Cache::lock($lockKey, $lockSeconds);

        try {
            $lock->block(5);

            // 1. Check Redis cache first for fast replay
            /** @var array{request_hash: string, response_code: int, response_body: array<string, mixed>}|null $cached */
            $cached = Cache::get($cacheKey);
            if (is_array($cached)) {
                return $this->replayOrReject($cached['request_hash'], $requestHash, $cached['response_body'], $cached['response_code']);
            }

            // 2. Check database table for persisted idempotency key
            $existing = IdempotencyKey::query()
                ->where('user_id', $userId)
                ->where('key', $idempotencyKey)
                ->first();

            if ($existing !== null) {
                if ($existing->isExpired()) {
                    $existing->delete();
                } else {
                    return $this->replayOrReject(
                        $existing->request_hash,
                        $requestHash,
                        $existing->response_body,
                        $existing->response_code
                    );
                }
            }

            // 3. Execute the actual controller action
            $response = $next($request);

            // 4. Only persist successful/created responses (2xx) so transient validation/server errors can be corrected
            if ($response instanceof JsonResponse && $response->getStatusCode() >= 200 && $response->getStatusCode() < 300) {
                /** @var array<string, mixed> $decodedBody */
                $decodedBody = $response->getData(true);
                $statusCode = $response->getStatusCode();
                $expiresAt = now()->addHours($ttlHours);

                IdempotencyKey::updateOrCreate(
                    [
                        'user_id' => $userId,
                        'key' => $idempotencyKey,
                    ],
                    [
                        'method' => $request->method(),
                        'endpoint' => $request->path(),
                        'request_hash' => $requestHash,
                        'response_code' => $statusCode,
                        'response_body' => $decodedBody,
                        'expires_at' => $expiresAt,
                    ]
                );

                Cache::put($cacheKey, [
                    'request_hash' => $requestHash,
                    'response_code' => $statusCode,
                    'response_body' => $decodedBody,
                ], $expiresAt);

                $response->headers->set('X-Idempotent-Replay', 'false');
            }

            return $response;
        } catch (LockTimeoutException) {
            return response()->json([
                'success' => false,
                'message' => 'A request with the same Idempotency-Key is currently being processed.',
            ], 409);
        } finally {
            $lock->release();
        }
    }

    /**
     * Replay the stored response if the request fingerprint matches, or reject with 422 if reused with different payload.
     *
     * @param array<string, mixed> $responseBody
     */
    private function replayOrReject(
        string $storedHash,
        string $incomingHash,
        array $responseBody,
        int $statusCode
    ): JsonResponse {
        if (! hash_equals($storedHash, $incomingHash)) {
            return response()->json([
                'success' => false,
                'message' => 'Idempotency-Key has already been used with a different request payload or endpoint.',
            ], 422);
        }

        return response()
            ->json($responseBody, $statusCode)
            ->header('X-Idempotent-Replay', 'true');
    }

    /**
     * Compute a deterministic SHA-256 fingerprint of the HTTP method, path, and normalized payload.
     */
    private function computeRequestHash(Request $request): string
    {
        $payload = $request->all();
        $this->ksortRecursive($payload);

        return hash('sha256', implode('|', [
            $request->method(),
            $request->path(),
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]));
    }

    /**
     * Sort array keys recursively for deterministic hashing.
     *
     * @param array<mixed, mixed> $array
     */
    private function ksortRecursive(array &$array): void
    {
        foreach ($array as &$value) {
            if (is_array($value)) {
                $this->ksortRecursive($value);
            }
        }
        ksort($array);
    }
}
