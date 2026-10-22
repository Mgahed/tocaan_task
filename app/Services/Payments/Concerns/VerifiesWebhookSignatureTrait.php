<?php

declare(strict_types=1);

namespace App\Services\Payments\Concerns;

use Illuminate\Http\Request;

trait VerifiesWebhookSignatureTrait
{
    /**
     * Verify the HMAC-SHA256 webhook signature header (`X-Webhook-Signature`) against the gateway's webhook secret.
     * Supports raw hex or `sha256=<hex>` format, and handles CRLF/LF line-ending normalization cleanly.
     */
    protected function verifyHmacSignature(Request $request, string $secret): bool
    {
        $providedSignature = trim((string) $request->header('X-Webhook-Signature', ''));
        $secret = trim($secret);

        if ($providedSignature === '' || $secret === '') {
            return false;
        }

        // Strip optional "sha256=" prefix if sent by client/provider
        if (str_starts_with(strtolower($providedSignature), 'sha256=')) {
            $providedSignature = substr($providedSignature, 7);
        }

        $rawBody = $request->getContent();

        if ($rawBody === '') {
            return false;
        }

        // Candidate payloads to handle CRLF vs LF differences across Windows/Linux/Postman clients
        $lfBody = str_replace("\r\n", "\n", $rawBody);
        $crlfBody = str_replace("\n", "\r\n", $lfBody);

        $candidates = array_unique([
            $rawBody,
            $lfBody,
            $crlfBody,
        ]);

        // Also include canonical compact JSON encoding of the parsed payload
        $parsed = $request->json()->all();
        if (! empty($parsed)) {
            $compactJson = json_encode($parsed, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if (is_string($compactJson)) {
                $candidates[] = $compactJson;
            }
        }

        foreach ($candidates as $candidatePayload) {
            $expectedSignature = hash_hmac('sha256', $candidatePayload, $secret);
            if (hash_equals($expectedSignature, strtolower($providedSignature))) {
                return true;
            }
        }

        return false;
    }
}
