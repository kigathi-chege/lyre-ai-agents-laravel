<?php

namespace Lyre\AiAgents\Services;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\ClientException as GuzzleClientException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class OpenAIClient
{
    public function createResponse(array $payload, array $clientConfig = []): array
    {
        $response = $this->baseRequest($clientConfig)
            ->post($this->baseUrl($clientConfig).'/responses', $payload);

        if ($response->failed()) {
            throw new RuntimeException(sprintf(
                'OpenAI Responses API error (%d): %s',
                $response->status(),
                $this->truncateForLog($response->body())
            ));
        }

        return $response->json();
    }

    public function retrieveAssistant(string $assistantId, array $clientConfig = []): ?array
    {
        $response = $this->baseRequest($clientConfig)
            ->withHeaders($this->assistantsHeaders($clientConfig))
            ->get($this->baseUrl($clientConfig).'/assistants/'.$assistantId);

        if ($response->status() === 404) {
            return null;
        }

        if ($response->failed()) {
            throw new RuntimeException(sprintf(
                'OpenAI assistant retrieval error (%d): %s',
                $response->status(),
                $this->truncateForLog($response->body())
            ));
        }

        return $response->json();
    }

    public function createVectorStore(array $payload = [], array $clientConfig = []): array
    {
        $response = $this->baseRequest($clientConfig)
            ->withHeaders($this->assistantsHeaders($clientConfig))
            ->post($this->baseUrl($clientConfig).'/vector_stores', $payload);

        if ($response->failed()) {
            throw new RuntimeException(sprintf(
                'OpenAI vector store create error (%d): %s',
                $response->status(),
                $this->truncateForLog($response->body())
            ));
        }

        return $response->json();
    }

    public function uploadFile(string $absolutePath, ?string $originalFilename = null, string $purpose = 'assistants', array $clientConfig = []): array
    {
        if (!is_file($absolutePath)) {
            throw new RuntimeException("File does not exist: {$absolutePath}");
        }

        $filename = $originalFilename ?: basename($absolutePath);
        $handle = fopen($absolutePath, 'r');
        if ($handle === false) {
            throw new RuntimeException("Unable to read file: {$absolutePath}");
        }

        try {
            $response = Http::withHeaders($this->headers($clientConfig, false))
                ->timeout((int) $this->resolveClientConfigValue('timeout', $clientConfig, 60))
                ->attach('file', $handle, $filename)
                ->post($this->baseUrl($clientConfig).'/files', [
                    'purpose' => $purpose,
                ]);
        } finally {
            fclose($handle);
        }

        if ($response->failed()) {
            throw new RuntimeException(sprintf(
                'OpenAI file upload error (%d): %s',
                $response->status(),
                $this->truncateForLog($response->body())
            ));
        }

        return $response->json();
    }

    public function attachFileToVectorStore(string $vectorStoreId, string $fileId, array $payload = [], array $clientConfig = []): array
    {
        $response = $this->baseRequest($clientConfig)
            ->withHeaders($this->assistantsHeaders($clientConfig))
            ->post($this->baseUrl($clientConfig)."/vector_stores/{$vectorStoreId}/files", array_merge([
                'file_id' => $fileId,
            ], $payload));

        if ($response->failed()) {
            throw new RuntimeException(sprintf(
                'OpenAI vector store file attach error (%d): %s',
                $response->status(),
                $this->truncateForLog($response->body())
            ));
        }

        return $response->json();
    }

    public function streamResponse(array $payload, array $clientConfig = []): \Generator
    {
        $payload['stream'] = true;

        $client = new GuzzleClient([
            // Guzzle needs trailing slash on base_uri to preserve the /v1 path segment.
            'base_uri' => rtrim($this->baseUrl($clientConfig), '/').'/',
            'timeout' => (int) $this->resolveClientConfigValue('timeout', $clientConfig, 60),
        ]);

        try {
            $response = $client->request('POST', 'responses', [
                'headers' => $this->headers($clientConfig),
                'json' => $payload,
                'stream' => true,
            ]);
        } catch (GuzzleClientException $e) {
            $response = $e->getResponse();
            $status = $response?->getStatusCode() ?? 0;
            $body = $response ? (string) $response->getBody() : $e->getMessage();

            throw new RuntimeException(sprintf(
                'OpenAI Responses stream error (%d): %s',
                $status,
                $this->truncateForLog($body)
            ), 0, $e);
        }

        $body = $response->getBody();

        while (!$body->eof()) {
            $chunk = $body->read(1024);
            if ($chunk !== '') {
                yield $chunk;
            }
        }

        $body->close();
    }

    public function summarizeMessages(array $messages, array $clientConfig = []): ?string
    {
        $model = config('ai-agents.conversation.summary_model');
        if (!$model) {
            return null;
        }

        $response = $this->createResponse([
            'model' => $model,
            'input' => [[
                'role' => 'system',
                'content' => [['type' => 'input_text', 'text' => 'Summarize this conversation context in concise factual bullet points.']],
            ], [
                'role' => 'user',
                'content' => [['type' => 'input_text', 'text' => json_encode($messages)]],
            ]],
            'max_output_tokens' => (int) config('ai-agents.conversation.summary_max_tokens', 400),
        ], $clientConfig);

        return $this->extractText($response);
    }

    public function extractText(array $response): string
    {
        $output = $response['output'] ?? [];

        $texts = [];
        foreach ($output as $item) {
            foreach (($item['content'] ?? []) as $content) {
                if (($content['type'] ?? null) === 'output_text' && isset($content['text'])) {
                    $texts[] = $content['text'];
                }
            }
        }

        return trim(implode("\n", $texts));
    }

    public function extractUsage(array $response): array
    {
        $usage = $response['usage'] ?? [];

        return [
            'prompt_tokens' => (int) ($usage['input_tokens'] ?? 0),
            'completion_tokens' => (int) ($usage['output_tokens'] ?? 0),
            'total_tokens' => (int) ($usage['total_tokens'] ?? 0),
        ];
    }

    protected function baseRequest(array $clientConfig = [])
    {
        return Http::withHeaders($this->headers($clientConfig, true))
            ->timeout((int) $this->resolveClientConfigValue('timeout', $clientConfig, 60));
    }

    protected function baseUrl(array $clientConfig = []): string
    {
        $baseUrl = rtrim((string) $this->resolveClientConfigValue('base_url', $clientConfig, 'https://api.openai.com/v1'), '/');

        $parts = parse_url($baseUrl);
        $path = $parts['path'] ?? '';

        // Allow OPENAI_BASE_URL=https://api.openai.com without breaking /v1 endpoints.
        if ($path === '' || $path === '/') {
            return $baseUrl.'/v1';
        }

        return $baseUrl;
    }

    protected function headers(array $clientConfig = [], bool $json = true): array
    {
        $apiKey = (string) $this->resolveClientConfigValue('api_key', $clientConfig, '');
        if ($apiKey === '') {
            throw new RuntimeException('OpenAI API key is not configured for this request.');
        }

        $headers = [
            'Authorization' => 'Bearer '.$apiKey,
        ];

        if ($json) {
            $headers['Content-Type'] = 'application/json';
        }

        if ($org = $this->resolveClientConfigValue('organization', $clientConfig)) {
            $headers['OpenAI-Organization'] = $org;
        }

        if ($project = $this->resolveClientConfigValue('project', $clientConfig)) {
            $headers['OpenAI-Project'] = $project;
        }

        return $headers;
    }

    protected function assistantsHeaders(array $clientConfig = []): array
    {
        $header = (string) config('ai-agents.openai.assistants_beta_header', 'assistants=v2');
        $headers = [];

        if ($header !== '') {
            $headers['OpenAI-Beta'] = $header;
        }

        return $headers;
    }

    protected function truncateForLog(string $value, int $max = 4000): string
    {
        return mb_strlen($value) > $max
            ? mb_substr($value, 0, $max).'...'
            : $value;
    }

    protected function resolveClientConfigValue(string $key, array $clientConfig, mixed $default = null): mixed
    {
        if (array_key_exists($key, $clientConfig) && $clientConfig[$key] !== null && $clientConfig[$key] !== '') {
            return $clientConfig[$key];
        }

        return config("ai-agents.openai.{$key}", $default);
    }
}
