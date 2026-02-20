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
use Flarum\Foundation\ValidationException;
use Flarum\Post\Post;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\User;
use Ghostchu\Openaicontentaudit\Model\AuditLog;
use Illuminate\Contracts\Events\Dispatcher;
use Psr\Log\LoggerInterface;

class AuditResultHandler
{
    public function __construct(
        private SettingsRepositoryInterface $settings,
        private Dispatcher $events,
        private LoggerInterface $logger,
        private MessageNotifier $messageNotifier
    ) {
    }

    /**
     * Handle audit result and execute appropriate actions.
     *
     * @param AuditLog $log
     * @param User $user
     * @param mixed $content The content being audited (Post, Discussion, or User)
     * @return void
     */
    public function handleResult(AuditLog $log, User $user, $content): void
    {
        $actions = $log->actions_taken ?? [];
        $confidence = $log->confidence ?? 0;
        $threshold = $this->getConfidenceThreshold();
        $executionLog = [];

        // Record decision context
        $executionLog['timestamp'] = \Carbon\Carbon::now()->toIso8601String();
        $executionLog['threshold'] = $threshold;
        $executionLog['confidence'] = $confidence;
        $executionLog['llm_actions'] = $actions;
        $executionLog['actions_executed'] = [];

        // Check if content should be approved (passed audit)
        $shouldApprove = $this->shouldApproveContent($confidence, $actions, $threshold);

        // Only take action if confidence meets threshold
        if ($confidence < $threshold) {
            $executionLog['decision'] = 'approved';
            $executionLog['reason'] = 'confidence_below_threshold';
            
            // Approve content if it was pre-unapproved
            if ($shouldApprove) {
                $this->approveContent($content, $executionLog);
            }
            
            $log->execution_log = $executionLog;
            $log->save();

            $this->logger->info('[Audit Result Handler] Confidence below threshold, content approved', [
                'log_id' => $log->id,
                'confidence' => $confidence,
                'threshold' => $threshold,
            ]);
            return;
        }

        // If actions only contain "none" or are empty, approve the content
        if ($shouldApprove) {
            $executionLog['decision'] = 'approved';
            $executionLog['reason'] = 'no_violations_found';
            $this->approveContent($content, $executionLog);
            $log->execution_log = $executionLog;
            $log->save();

            $this->logger->info('[Audit Result Handler] Content passed audit, approved', [
                'log_id' => $log->id,
                'confidence' => $confidence,
                'actions' => $actions,
            ]);
            return;
        }

        // Execute violation actions
        $executionLog['decision'] = 'violated';

        foreach ($actions as $action) {
            $actionResult = [
                'action' => $action,
                'status' => 'success',
                'timestamp' => \Carbon\Carbon::now()->toIso8601String(),
            ];

            try {
                match ($action) {
                    'hide', 'unapprove' => $this->handleHideAction($log, $content, $actionResult),
                    'suspend' => $this->handleSuspendAction($log, $user, $actionResult),
                    'none' => $actionResult['details'] = 'no_action_taken',
                    default => [
                        $actionResult['status'] = 'unknown',
                        $actionResult['error'] = 'unknown_action_type',
                        $this->logger->warning('[Audit Result Handler] Unknown action', [
                            'action' => $action,
                            'log_id' => $log->id,
                        ]),
                    ],
                };
            } catch (\Exception $e) {
                $actionResult['status'] = 'failed';
                $actionResult['error'] = $e->getMessage();
                $this->logger->error('[Audit Result Handler] Failed to execute action', [
                    'action' => $action,
                    'log_id' => $log->id,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
            }

            $executionLog['actions_executed'][] = $actionResult;
        }

        // Send violation notification via private message
        if (!empty($executionLog['actions_executed'])) {
            try {
                $systemUser = $this->getSystemUser();

                if ($systemUser) {
                    // Use AI conclusion as the main violation reason
                    $conclusion = $log->conclusion ?? '内容可能违反社区规范';
                    
                    $sent = $this->messageNotifier->sendViolationNotice(
                        $user,
                        $systemUser,
                        $log->content_type,
                        $conclusion,
                        $confidence
                    );

                    $executionLog['message_sent'] = $sent;
                    
                    if ($sent) {
                        $this->logger->info('[Audit Result Handler] Violation notice sent to user', [
                            'log_id' => $log->id,
                            'user_id' => $user->id,
                        ]);
                    }
                } else {
                    $executionLog['message_sent'] = false;
                    $executionLog['message_error'] = 'system_user_not_found';
                }
            } catch (\Exception $e) {
                $executionLog['message_sent'] = false;
                $executionLog['message_error'] = $e->getMessage();
                $this->logger->error('[Audit Result Handler] Failed to send violation notice', [
                    'log_id' => $log->id,
                    'user_id' => $user->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Save execution log
        $log->execution_log = $executionLog;
        $log->save();
    }

    /**
     * Check if content should be approved based on audit results.
     *
     * @param float $confidence
     * @param array $actions
     * @param float $threshold
     * @return bool
     */
    private function shouldApproveContent(float $confidence, array $actions, float $threshold): bool
    {
        // Approve if confidence is below threshold
        if ($confidence < $threshold) {
            return true;
        }

        // Approve if no actions or only "none"
        if (empty($actions)) {
            return true;
        }

        $violationActions = array_diff($actions, ['none']);
        return empty($violationActions);
    }

    /**
     * Approve content (post, discussion, or user profile).
     *
     * @param mixed $content
     * @param array &$executionLog
     * @return void
     */
    private function approveContent($content, array &$executionLog): void
    {
        if ($content instanceof Post || $content instanceof Discussion) {
            if ($content->is_approved === false) {
                $content->is_approved = true;
                $content->save();

                $executionLog['content_approved'] = true;
                $executionLog['content_type'] = $content instanceof Post ? 'post' : 'discussion';
                $executionLog['content_id'] = $content->id;

                $this->logger->info('[Audit Result Handler] Content approved after passing audit', [
                    'content_type' => $executionLog['content_type'],
                    'content_id' => $content->id,
                ]);
            } else {
                $executionLog['content_approved'] = false;
                $executionLog['reason'] = 'already_approved';
            }
        }
    }

    /**
     * Handle hide/unapprove action.
     *
     * @param AuditLog $log
     * @param mixed $content
     * @param array &$actionResult
     * @return void
     */
    private function handleHideAction(AuditLog $log, $content, array &$actionResult): void
    {
        if ($content instanceof Post || $content instanceof Discussion) {
            // Set content as unapproved
            $content->is_approved = false;
            $content->save();

            $actionResult['details'] = 'content_hidden';
            $actionResult['content_type'] = $log->content_type;
            $actionResult['content_id'] = $log->content_id;

            $this->logger->info('[Audit Result Handler] Content hidden/unapproved', [
                'log_id' => $log->id,
                'content_type' => $log->content_type,
                'content_id' => $log->content_id,
            ]);
        } elseif ($content instanceof User) {
            // For user profile changes, revert to default values
            $this->revertUserProfileToDefaults($log, $content, $actionResult);
        }
    }

    /**
     * Handle suspend action.
     *
     * @param AuditLog $log
     * @param User $user
     * @param array &$actionResult
     * @return void
     */
    private function handleSuspendAction(AuditLog $log, User $user, array &$actionResult): void
    {
        $suspendDays = (int) $this->settings->get(
            'ghostchu.openaicontentaudit.suspend_days',
            7
        );

        // Get AI conclusion as suspend reason
        $suspendReason = $log->conclusion ?? '违反社区规范';
        
        $user->suspended_until = Carbon::now()->addDays($suspendDays);
        $user->suspend_reason = $suspendReason;  // Set suspend reason
        $user->suspend_message = $suspendReason; // Set suspend message for user visibility
        $user->save();

        $actionResult['details'] = 'user_suspended';
        $actionResult['user_id'] = $user->id;
        $actionResult['suspend_days'] = $suspendDays;
        $actionResult['suspend_reason'] = $suspendReason;
        $actionResult['suspended_until'] = $user->suspended_until->toIso8601String();

        $this->logger->info('[Audit Result Handler] User suspended', [
            'log_id' => $log->id,
            'user_id' => $user->id,
            'suspend_reason' => $suspendReason,
            'suspended_until' => $user->suspended_until->toIso8601String(),
        ]);

        // Dispatch suspended event if extension is available
        try {
            if (class_exists('Flarum\Suspend\Event\Suspended')) {
                $actor = $this->getSystemUser();
                $this->events->dispatch(
                    new \Flarum\Suspend\Event\Suspended($user, $actor)
                );
            }
        } catch (\Exception $e) {
            $this->logger->warning('[Audit Result Handler] Failed to dispatch suspend event', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Revert user profile fields to default values.
     *
     * @param AuditLog $log
     * @param User $user
     * @param array &$actionResult
     * @return void
     */
    private function revertUserProfileToDefaults(AuditLog $log, User $user, array &$actionResult): void
    {
        $auditedContent = $log->audited_content ?? [];
        $changed = false;
        $revertedFields = [];

        foreach ($auditedContent['content'] ?? [] as $field => $value) {
            switch ($field) {
                case 'display_name':
                    $default = $this->settings->get(
                        'ghostchu.openaicontentaudit.default_display_name',
                        $user->username
                    );
                    $revertedFields[$field] = [
                        'old' => $user->display_name,
                        'new' => $default,
                    ];
                    $user->display_name = $default;
                    $changed = true;
                    $this->logger->info('[Audit Result Handler] Reverted display name', [
                        'log_id' => $log->id,
                        'user_id' => $user->id,
                    ]);
                    break;

                case 'bio':
                    $default = $this->settings->get(
                        'ghostchu.openaicontentaudit.default_bio',
                        ''
                    );
                    // Only set if bio field exists
                    if (property_exists($user, 'bio') || isset($user->bio)) {
                        $revertedFields[$field] = [
                            'old' => $user->bio ?? '',
                            'new' => $default,
                        ];
                        $user->bio = $default;
                        $changed = true;
                        $this->logger->info('[Audit Result Handler] Reverted bio', [
                            'log_id' => $log->id,
                            'user_id' => $user->id,
                        ]);
                    }
                    break;

                case 'avatar_url':
                    // Remove avatar
                    $revertedFields[$field] = [
                        'old' => $user->avatar_url,
                        'new' => null,
                    ];
                    $user->avatar_url = null;
                    $changed = true;
                    $this->logger->info('[Audit Result Handler] Removed avatar', [
                        'log_id' => $log->id,
                        'user_id' => $user->id,
                    ]);
                    break;

                case 'cover':
                    // Remove cover image (from sycho/flarum-profile-cover extension)
                    if (property_exists($user, 'cover') || isset($user->cover)) {
                        $revertedFields[$field] = [
                            'old' => $user->cover ?? null,
                            'new' => null,
                        ];
                        $user->cover = null;
                        $changed = true;
                        $this->logger->info('[Audit Result Handler] Removed cover image', [
                            'log_id' => $log->id,
                            'user_id' => $user->id,
                        ]);
                    }
                    break;
            }
        }

        if ($changed) {
            $user->save();
            $actionResult['details'] = 'profile_reverted';
            $actionResult['user_id'] = $user->id;
            $actionResult['reverted_fields'] = $revertedFields;
        } else {
            $actionResult['details'] = 'no_changes_needed';
        }
    }

    /**
     * Get confidence threshold from settings.
     *
     * @return float
     */
    private function getConfidenceThreshold(): float
    {
        return (float) $this->settings->get(
            'ghostchu.openaicontentaudit.confidence_threshold',
            0.7
        );
    }

    /**
     * Get system user for automated actions.
     *
     * @return User
     */
    private function getSystemUser(): User
    {
        // Try to get configured bot user, fallback to admin (ID 1)
        $userId = (int) $this->settings->get(
            'ghostchu.openaicontentaudit.system_user_id',
            1
        );

        try {
            return User::findOrFail($userId);
        } catch (\Exception $e) {
            $this->logger->warning('[Audit Result Handler] System user not found, using ID 1', [
                'configured_id' => $userId,
            ]);
            return User::findOrFail(1);
        }
    }

    /**
     * Check if an action should be taken based on confidence.
     *
     * @param float $confidence
     * @return bool
     */
    public function shouldTakeAction(float $confidence): bool
    {
        return $confidence >= $this->getConfidenceThreshold();
    }

    /**
     * Get available actions from settings or defaults.
     *
     * @return array
     */
    public function getAvailableActions(): array
    {
        return ['none', 'hide', 'unapprove', 'suspend'];
    }
}
