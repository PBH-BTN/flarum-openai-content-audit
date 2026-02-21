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

use Carbon\Carbon;
use Flarum\Discussion\Discussion;
use Flarum\Flags\Flag;
use Flarum\Locale\Translator;
use Flarum\Post\Post;
use Flarum\Settings\SettingsRepositoryInterface;
use Ghostchu\Openaicontentaudit\Model\AuditLog;
use Psr\Log\LoggerInterface;

class FlagService
{
    public function __construct(
        private SettingsRepositoryInterface $settings,
        private LoggerInterface $logger,
        private Translator $translator
    ) {
    }

    /**
     * Create an audit flag for content pending review.
     *
     * @param Post|Discussion $content The content to flag
     * @param AuditLog|null $log The audit log (if available)
     * @param string $stage The stage: 'pre-approval' or 'audit'
     * @return Flag|null The created flag or null if failed/already exists
     */
    public function createAuditFlag($content, ?AuditLog $log = null, string $stage = 'audit'): ?Flag
    {
        // Determine the post to flag
        $post = $this->resolvePost($content);
        
        if (!$post) {
            $this->logger->warning('[Flag Service] Cannot create flag: invalid content type', [
                'content_type' => get_class($content),
            ]);
            return null;
        }

        // Check if flag already exists
        $existingFlag = Flag::where('post_id', $post->id)
            ->where('type', 'openai-audit')
            ->first();

        if ($existingFlag) {
            $this->logger->debug('[Flag Service] Flag already exists, skipping', [
                'post_id' => $post->id,
                'flag_id' => $existingFlag->id,
            ]);
            return $existingFlag;
        }

        try {
            // Create the flag
            $flag = new Flag();
            $flag->post_id = $post->id;
            $flag->type = 'openai-audit';
            $flag->user_id = null; // System flag
            $flag->created_at = Carbon::now();
            
            // Set reason from locale with fallback
            $reason = $this->translator->trans('ghostchu-openai-content-audit.flags.openai_audit_reason');
            // Fallback if translation key is returned as-is or empty
            if (empty($reason) || $reason === 'ghostchu-openai-content-audit.flags.openai_audit_reason') {
                $reason = 'AI 审核检测到违规 / AI audit detected violation';
            }
            $flag->reason = $reason;
            
            // Format reason_detail based on stage
            $reasonDetail = $this->formatReasonDetail($stage, $log);
            $flag->reason_detail = $reasonDetail;
            
            $this->logger->debug('[Flag Service] Flag data before save', [
                'post_id' => $post->id,
                'type' => $flag->type,
                'reason' => $flag->reason,
                'reason_detail' => $reasonDetail,
                'reason_detail_length' => strlen($reasonDetail),
            ]);
            
            $flag->save();

            $this->logger->info('[Flag Service] Audit flag created', [
                'flag_id' => $flag->id,
                'post_id' => $post->id,
                'stage' => $stage,
                'audit_log_id' => $log?->id,
                'reason_detail_length' => strlen($flag->reason_detail ?? ''),
            ]);

            return $flag;
        } catch (\Exception $e) {
            $this->logger->error('[Flag Service] Failed to create flag', [
                'post_id' => $post->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return null;
        }
    }

    /**
     * Delete audit flags for approved content.
     *
     * @param Post|Discussion $content The approved content
     * @return int Number of flags deleted
     */
    public function deleteAuditFlags($content): int
    {
        $post = $this->resolvePost($content);
        
        if (!$post) {
            return 0;
        }

        try {
            $deleted = Flag::where('post_id', $post->id)
                ->where('type', 'openai-audit')
                ->delete();

            if ($deleted > 0) {
                $this->logger->info('[Flag Service] Audit flags deleted', [
                    'post_id' => $post->id,
                    'deleted_count' => $deleted,
                ]);
            }

            return $deleted;
        } catch (\Exception $e) {
            $this->logger->error('[Flag Service] Failed to delete flags', [
                'post_id' => $post->id,
                'error' => $e->getMessage(),
            ]);
            return 0;
        }
    }

    /**
     * Resolve the post from content (handles both Post and Discussion).
     *
     * @param mixed $content
     * @return Post|null
     */
    private function resolvePost($content): ?Post
    {
        if ($content instanceof Post) {
            return $content;
        }

        if ($content instanceof Discussion) {
            // For discussions, flag the first post
            return $content->firstPost ?? $content->posts()->where('number', 1)->first();
        }

        return null;
    }

    /**
     * Format the reason detail based on stage and audit log.
     *
     * @param string $stage
     * @param AuditLog|null $log
     * @return string
     */
    private function formatReasonDetail(string $stage, ?AuditLog $log): string
    {
        if ($stage === 'pre-approval' || !$log) {
            // Pre-approval stage: content is waiting for AI audit
            $pending = $this->translator->trans('ghostchu-openai-content-audit.flags.openai_audit_pending');
            // Fallback if translation not found or empty
            if (empty($pending) || $pending === 'ghostchu-openai-content-audit.flags.openai_audit_pending') {
                return '等待 AI 审核完成 / Pending AI audit';
            }
            return $pending;
        }

        // Audit stage: include log ID, conclusion, and confidence
        $conclusion = $log->conclusion ?? 'No conclusion available';
        if (empty($conclusion)) {
            $conclusion = 'No conclusion available';
        }
        
        $confidence = $log->confidence ?? 0;
        $confidencePercent = number_format($confidence * 100, 1);

        // Get translations with fallbacks
        $auditLogLabel = $this->translator->trans('ghostchu-openai-content-audit.flags.audit_log');
        if (empty($auditLogLabel) || $auditLogLabel === 'ghostchu-openai-content-audit.flags.audit_log') {
            $auditLogLabel = '审核日志';
        }
        
        $confidenceLabel = $this->translator->trans('ghostchu-openai-content-audit.flags.confidence');
        if (empty($confidenceLabel) || $confidenceLabel === 'ghostchu-openai-content-audit.flags.confidence') {
            $confidenceLabel = '置信度';
        }

        $detail = sprintf(
            "[%s #%d] %s\n%s: %s%%",
            $auditLogLabel,
            $log->id,
            $conclusion,
            $confidenceLabel,
            $confidencePercent
        );
        
        // Extra safety check - ensure we never return empty string
        if (empty($detail)) {
            $detail = "AI Audit Log #{$log->id} - Confidence: {$confidencePercent}%";
        }
        
        return $detail;
    }
}
