<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Thin wrapper around the Gemini REST API.
 *
 * Implements 3-attempt exponential-backoff retry (1 s → 2 s → 4 s) for
 * 429 (rate-limit), 503 (overload) and ConnectionException (DNS/network).
 * On final failure the caller receives a \RuntimeException — the controller
 * catches it and builds a structured fallback response instead of a 503.
 */
class GeminiService
{
    private const MODEL      = 'gemini-2.5-flash';
    private const API_URL    = 'https://generativelanguage.googleapis.com/v1beta/models/'
                             . self::MODEL . ':generateContent';
    private const MAX_TRIES  = 3;   // 1 initial + 2 retries
    private const BASE_DELAY = 1;   // seconds; doubles each attempt
    private const MAX_TOKENS = 1024; // keep responses tight

    private string $apiKey;

    public function __construct()
    {
        $this->apiKey = config('services.gemini.key', env('GEMINI_API_KEY', ''));
    }

    /**
     * Send a multi-turn message to Gemini with retry + exponential back-off.
     *
     * @param  string  $systemPrompt  Instructions that govern model behaviour.
     * @param  array   $history       Prior turns: [['role'=>'user'|'assistant','text'=>'...'], ...]
     * @param  string  $userMessage   The new user turn.
     * @return string                 The model's text reply.
     *
     * @throws \RuntimeException on permanent API failure (all retries exhausted).
     */
    public function chat(string $systemPrompt, array $history, string $userMessage): string
    {
        if (!$this->apiKey) {
            throw new \RuntimeException('GEMINI_API_KEY is not configured.');
        }

        $payload    = $this->buildPayload($systemPrompt, $history, $userMessage);
        $sslVerify  = env('CURL_CA_BUNDLE') ?: true;
        $lastError  = null;
        $attempt    = 0;

        while ($attempt < self::MAX_TRIES) {
            $attempt++;

            try {
                $response = Http::timeout(30)
                    ->withOptions(['verify' => $sslVerify])
                    ->post(self::API_URL . '?key=' . $this->apiKey, $payload);

                $status = $response->status();

                // ── Success ──────────────────────────────────────────────────
                if ($response->successful()) {
                    $data = $response->json();
                    return $data['candidates'][0]['content']['parts'][0]['text']
                        ?? '[No response from model]';
                }

                // ── Retryable HTTP errors ────────────────────────────────────
                if (in_array($status, [429, 503], true)) {
                    $lastError = new \RuntimeException(
                        "Gemini API returned HTTP {$status} (attempt {$attempt})"
                    );
                    Log::warning('Gemini retryable error', [
                        'attempt' => $attempt,
                        'status'  => $status,
                        'body'    => substr($response->body(), 0, 300),
                    ]);
                    $this->backoff($attempt);
                    continue;
                }

                // ── Non-retryable HTTP error — fail fast ─────────────────────
                Log::error('Gemini non-retryable error', [
                    'attempt' => $attempt,
                    'status'  => $status,
                    'body'    => substr($response->body(), 0, 300),
                ]);
                throw new \RuntimeException("Gemini API returned HTTP {$status}");

            } catch (ConnectionException $e) {
                $lastError = $e;
                Log::warning('Gemini connection error', [
                    'attempt' => $attempt,
                    'error'   => $e->getMessage(),
                ]);
                if ($attempt < self::MAX_TRIES) {
                    $this->backoff($attempt);
                }
            }
        }

        // All retries exhausted.
        Log::error('Gemini permanently failed', [
            'total_attempts' => $attempt,
            'final_error'    => $lastError?->getMessage(),
        ]);

        throw new \RuntimeException(
            'Gemini AI unavailable after ' . self::MAX_TRIES . ' attempts: '
            . ($lastError?->getMessage() ?? 'unknown error'),
            0,
            $lastError
        );
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    /** Build the Gemini request payload from the conversation parts. */
    private function buildPayload(string $systemPrompt, array $history, string $userMessage): array
    {
        $contents = [];

        foreach ($history as $msg) {
            $contents[] = [
                'role'  => $msg['role'] === 'assistant' ? 'model' : 'user',
                'parts' => [['text' => $msg['text']]],
            ];
        }

        $contents[] = [
            'role'  => 'user',
            'parts' => [['text' => $userMessage]],
        ];

        return [
            'system_instruction' => [
                'parts' => [['text' => $systemPrompt]],
            ],
            'contents'         => $contents,
            'generationConfig' => [
                'temperature'     => 0.3,
                'maxOutputTokens' => self::MAX_TOKENS,
            ],
        ];
    }

    /**
     * Block the current process for an exponentially increasing delay.
     * Attempt 1 → 1 s, attempt 2 → 2 s, attempt 3 → 4 s.
     */
    private function backoff(int $attempt): void
    {
        $seconds = self::BASE_DELAY * (2 ** ($attempt - 1)); // 1, 2, 4
        sleep($seconds);
    }
}
