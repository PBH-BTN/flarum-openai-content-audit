<?php

/*
 * This file is part of ghostchu/openai-content-audit.
 *
 * Copyright (c) 2024 Ghost_chu.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ghostchu\Openaicontentaudit\Service;

use Flarum\Settings\SettingsRepositoryInterface;
use OpenAI\Client;
use Psr\Log\LoggerInterface;

class OpenAIClient
{
    private const DEFAULT_ENDPOINT = 'https://api.openai.com/v1';
    private const DEFAULT_MODEL = 'gpt-4o';
    private const DEFAULT_TEMPERATURE = 0.3;
    private const DEFAULT_MAX_TOKENS = 4096;
    private const DEFAULT_TIMEOUT = 60;
    
    // Response format version for tracking
    private const FORMAT_JSON_SCHEMA = 'json_schema';

    public function __construct(
        private SettingsRepositoryInterface $settings,
        private LoggerInterface $logger
    ) {
    }

    /**
     * Audit content using OpenAI-compatible API with Structured Outputs.
     *
     * @param array $messages Array of message objects with 'role' and 'content'
     * @return array Parsed JSON response from LLM with format_version key
     * @throws \Exception If API call fails or response is invalid
     */
    public function auditContent(array $messages): array
    {
        $client = $this->createClient();

        $model = $this->getSetting('model', self::DEFAULT_MODEL);
        $temperature = (float) $this->getSetting('temperature', self::DEFAULT_TEMPERATURE);
        $maxTokens = (int) $this->getSetting('max_tokens', self::DEFAULT_MAX_TOKENS);

        $this->logger->debug('[OpenAI Content Audit] Sending audit request', [
            'model' => $model,
            'temperature' => $temperature,
            'messages_count' => count($messages),
            'format_version' => self::FORMAT_JSON_SCHEMA,
        ]);

        try {
            $response = $client->chat()->create([
                'model' => $model,
                'messages' => $messages,
                'temperature' => $temperature,
                'max_tokens' => $maxTokens,
                'response_format' => [
                    'type' => 'json_schema',
                    'json_schema' => $this->getAuditResponseSchema(),
                ],
            ]);

            $content = $response->choices[0]->message->content ?? null;

            if (empty($content)) {
                throw new \Exception('Empty response from OpenAI API');
            }

            $this->logger->debug('[OpenAI Content Audit] Received response', [
                'finish_reason' => $response->choices[0]->finishReason ?? 'unknown',
                'format_version' => self::FORMAT_JSON_SCHEMA,
                'usage' => [
                    'prompt_tokens' => $response->usage->promptTokens ?? 0,
                    'completion_tokens' => $response->usage->completionTokens ?? 0,
                ],
            ]);

            $result = json_decode($content, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new \Exception('Invalid JSON response: ' . json_last_error_msg());
            }

            // Minimal validation - Structured Outputs guarantees schema compliance
            if (!$this->validateResponse($result)) {
                throw new \Exception('Response missing required fields');
            }

            // Add format version to result for tracking
            $result['_format_version'] = self::FORMAT_JSON_SCHEMA;

            return $result;
        } catch (\Exception $e) {
            $this->logger->error('[OpenAI Content Audit] API request failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }
    
    /**
     * Create OpenAI client with configured settings.
     *
     * @return Client
     */
    private function createClient(): Client
    {
        $apiKey = $this->getSetting('api_key');
        $baseUrl = $this->getSetting('api_endpoint', self::DEFAULT_ENDPOINT);
        $timeout = (int) $this->getSetting('timeout', self::DEFAULT_TIMEOUT);

        if (empty($apiKey)) {
            throw new \Exception('OpenAI API key not configured');
        }

        return \OpenAI::factory()
            ->withApiKey($apiKey)
            ->withBaseUri($baseUrl)
            ->withHttpClient(new \GuzzleHttp\Client([
                'timeout' => $timeout,
                'connect_timeout' => 10,
            ]))
            ->make();
    }

    /**
     * Get the JSON Schema for audit response (Structured Outputs).
     *
     * @return array
     */
    private function getAuditResponseSchema(): array
    {
        return [
            'name' => 'content_audit_response',
            'strict' => true,
            'schema' => [
                'type' => 'object',
                'properties' => [
                    'confidence' => [
                        'type' => 'number',
                        'description' => 'Confidence level of the violation detection, between 0.0 and 1.0',
                    ],
                    'actions' => [
                        'type' => 'array',
                        'description' => 'Array of actions to take based on the content analysis',
                        'items' => [
                            'type' => 'string',
                            'enum' => ['hide', 'suspend', 'delete', 'none'],
                        ],
                    ],
                    'conclusion' => [
                        'type' => 'string',
                        'description' => 'Brief explanation of the moderation decision (1-2 sentences)',
                    ],
                ],
                'required' => ['confidence', 'actions', 'conclusion'],
                'additionalProperties' => false,
            ],
        ];
    }
    
    /**
     * Build system prompt from settings.
     *
     * @return string
     */
    public function getSystemPrompt(): string
    {
        $prompt = $this->getSetting('system_prompt', $this->getDefaultSystemPrompt());
        
        // If prompt is empty string (not null), use default
        if (empty($prompt)) {
            return $this->getDefaultSystemPrompt();
        }
        
        return $prompt;
    }

    /**
     * Get default system prompt.
     *
     * @return string
     */
    private function getDefaultSystemPrompt(): string
    {
        return <<<'PROMPT'
You are a content moderation assistant for an online community forum. Your task is to analyze user-generated content and determine if it violates community guidelines.

Analyze the provided content and context carefully. Consider:
- Hate speech, harassment, or discrimination
- Spam or promotional content
- Inappropriate sexual content
- Violence or threats
- Personal information disclosure (doxxing)
- Misinformation or harmful content

For each piece of content, evaluate:
1. **Confidence**: How certain are you that the content violates policies? (0.0 = no violation, 1.0 = definite violation)
2. **Actions**: What actions should be taken?
   - "none": Content is acceptable
   - "hide": Hide/unapprove the content
   - "suspend": Temporarily suspend the user
   - "delete": Permanently delete the content
3. **Conclusion**: Provide a clear, brief explanation (1-2 sentences) of your decision

Guidelines:
- Be strict but fair
- Err on the side of caution for borderline cases
- Consider context and intent, not just keywords
- For images, analyze visual content thoroughly
- Multiple violations should result in stricter actions
PROMPT;
    }

    /**
     * Get confidence threshold from settings.
     *
     * @return float
     */
    public function getConfidenceThreshold(): float
    {
        return (float) $this->getSetting('confidence_threshold', 0.7);
    }

    /**
     * Validate API response structure.
     * Structured Outputs guarantees schema compliance, so this is minimal.
     *
     * @param array $response
     * @return bool
     */
    private function validateResponse(array $response): bool
    {
        // Basic field presence check - API guarantees types via schema
        return isset($response['confidence'])
            && isset($response['actions'])
            && isset($response['conclusion'])
            && is_numeric($response['confidence'])
            && is_array($response['actions'])
            && is_string($response['conclusion']);
    }

    /**
     * Get setting value with optional default.
     *
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    private function getSetting(string $key, $default = null)
    {
        return $this->settings->get("ghostchu.openaicontentaudit.{$key}", $default);
    }

    /**
     * Check if the service is properly configured.
     *
     * @return bool
     */
    public function isConfigured(): bool
    {
        return !empty($this->getSetting('api_key'));
    }

    /**
     * Test the API connection.
     *
     * @return array Result with 'success' boolean and optional 'error' message
     */
    public function testConnection(): array
    {
        try {
            $result = $this->auditContent([
                ['role' => 'system', 'content' => 'You are a test assistant.'],
                ['role' => 'user', 'content' => 'Respond with a valid JSON object: {"confidence": 0.0, "actions": ["none"], "conclusion": "This is a test"}'],
            ]);

            return ['success' => true, 'response' => $result];
        } catch (\Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
}
