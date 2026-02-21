<?php

/*
 * This file is part of ghostchu/openai-content-audit.
 *
 * Copyright (c) 2024 Ghost_chu.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ghostchu\Openaicontentaudit\Job;

use Carbon\Carbon;
use Flarum\Discussion\Discussion;
use Flarum\Post\Post;
use Flarum\Queue\AbstractJob;
use Flarum\User\User;
use Ghostchu\Openaicontentaudit\Model\AuditLog;
use Ghostchu\Openaicontentaudit\Service\AuditResultHandler;
use Ghostchu\Openaicontentaudit\Service\ContentExtractor;
use Ghostchu\Openaicontentaudit\Service\OpenAIClient;
use Psr\Log\LoggerInterface;
use FoF\Upload\File;

class AuditContentJob extends AbstractJob
{
    /**
     * Number of times to retry the job.
     *
     * @var int
     */
    public $tries = 3;

    /**
     * Number of seconds to wait before retrying the job.
     *
     * @var int
     */
    public $backoff = 60;

    /**
     * @param string $contentType Type of content (post, discussion, user_profile, avatar)
     * @param int|null $contentId ID of the content
     * @param int $userId User ID who created/modified the content
     * @param array $changes Array of changed attributes
     */
    public function __construct(
        public string $contentType,
        public ?int $contentId,
        public int $userId,
        public array $changes = []
    ) {
    }

    /**
     * Execute the job.
     */
    public function handle(
        OpenAIClient $openAIClient,
        ContentExtractor $extractor,
        AuditResultHandler $resultHandler,
        LoggerInterface $logger
    ): void {
        $logger->info('[Audit Job] Starting content audit', [
            'content_type' => $this->contentType,
            'content_id' => $this->contentId,
            'user_id' => $this->userId,
        ]);

        // Check if OpenAI is configured
        if (!$openAIClient->isConfigured()) {
            $logger->warning('[Audit Job] OpenAI not configured, skipping audit');
            return;
        }

        // Create audit log entry
        $log = new AuditLog([
            'content_type' => $this->contentType,
            'content_id' => $this->contentId,
            'user_id' => $this->userId,
            'status' => 'pending',
            'retry_count' => 0,
        ]);
        $log->save();

        try {
            // Load the content
            $content = $this->loadContent();
            $user = User::findOrFail($this->userId);

            // Extract content and context
            $extractedData = $this->extractContent($content, $extractor);
            
            // Store snapshot of audited content
            $log->audited_content = $extractedData;
            $log->save();

            // Build messages for OpenAI
            $systemPrompt = $openAIClient->getSystemPrompt();
            $messages = $extractor->buildMessages($extractedData, $systemPrompt);

            // Store API request
            $log->api_request = [
                'messages' => $messages,
                'model' => 'configured',
                'timestamp' => Carbon::now()->toIso8601String(),
            ];
            $log->save();

            // Call OpenAI API
            $logger->debug('[Audit Job] Calling OpenAI API', [
                'log_id' => $log->id,
            ]);

            $response = $openAIClient->auditContent($messages);

            // Store API response
            $log->api_response = $response;
            $log->response_format_version = $response['_format_version'] ?? 'json_object';
            $log->confidence = (float) ($response['confidence'] ?? 0);
            $log->actions_taken = $response['actions'] ?? [];
            $log->conclusion = $response['conclusion'] ?? 'No conclusion provided';
            $log->markAsCompleted();

            $logger->info('[Audit Job] Audit completed', [
                'log_id' => $log->id,
                'confidence' => $log->confidence,
                'actions' => $log->actions_taken,
            ]);

            // Handle the result (will check confidence threshold internally)
            $resultHandler->handleResult($log, $user, $content);
        } catch (\Exception $e) {
            $logger->error('[Audit Job] Audit failed', [
                'log_id' => $log->id ?? null,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            if (isset($log)) {
                $log->markAsFailed($e->getMessage());
            }

            // Re-throw to trigger retry
            throw $e;
        }
    }

    /**
     * Load the content being audited.
     *
     * @return mixed
     * @throws \Exception
     */
    private function loadContent()
    {
        return match ($this->contentType) {
            'post' => Post::findOrFail($this->contentId),
            'discussion' => Discussion::findOrFail($this->contentId),
            'user_profile', 'avatar', 'username', 'bio' => User::findOrFail($this->userId),
            'upload' => File::findOrFail($this->contentId),
            default => throw new \Exception("Unknown content type: {$this->contentType}"),
        };
    }

    /**
     * Extract content using the appropriate extractor method.
     *
     * @param mixed $content
     * @param ContentExtractor $extractor
     * @return array
     */
    private function extractContent($content, ContentExtractor $extractor): array
    {
        return match ($this->contentType) {
            'post' => $extractor->extractPost($content),
            'discussion' => $extractor->extractDiscussion($content),
            'user_profile', 'avatar', 'username', 'bio' => $extractor->extractUserProfile($content, $this->changes),
            'upload' => $extractor->extractFile($content, $this->changes),
            default => throw new \Exception("Unknown content type: {$this->contentType}"),
        };
    }

    /**
     * Handle job failure.
     *
     * @param \Throwable $exception
     * @return void
     */
    public function failed(\Throwable $exception): void
    {
        // Try to find and update the log
        try {
            $logs = AuditLog::where('content_type', $this->contentType)
                ->where('content_id', $this->contentId)
                ->where('user_id', $this->userId)
                ->where('status', '!=', 'completed')
                ->orderBy('created_at', 'desc')
                ->first();

            if ($logs) {
                $logs->markAsFailed('All retry attempts exhausted: ' . $exception->getMessage());
            }
        } catch (\Exception $e) {
            // Silently fail - logging might not work here
        }
    }

    /**
     * Get the display name for the job.
     *
     * @return string
     */
    public function displayName(): string
    {
        return "Audit {$this->contentType} #{$this->contentId}";
    }
}
