<?php

/*
 * This file is part of ghostchu/openai-content-audit.
 *
 * Copyright (c) 2024 Ghost_chu.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ghostchu\Openaicontentaudit\Listener;

use Flarum\Discussion\Event\Saving as DiscussionSaving;
use Flarum\Flags\Flag;
use Flarum\Post\Event\Saving as PostSaving;
use Flarum\Post\CommentPost;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\Event\AvatarChanged;
use Flarum\User\Event\Saving as UserSaving;
use Ghostchu\Openaicontentaudit\Job\AuditContentJob;
use Ghostchu\Openaicontentaudit\Service\FlagService;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Queue\Queue;
use Psr\Log\LoggerInterface;

class QueueContentAudit
{
    public function __construct(
        private Queue $queue,
        private SettingsRepositoryInterface $settings,
        private LoggerInterface $logger,
        private FlagService $flagService
    ) {
    }

    /**
     * Subscribe to events.
     *
     * @param Dispatcher $events
     * @return void
     */
    public function subscribe(Dispatcher $events): void
    {
        $events->listen(PostSaving::class, [$this, 'handlePostSaving']);
        $events->listen(DiscussionSaving::class, [$this, 'handleDiscussionSaving']);
        $events->listen(UserSaving::class, [$this, 'handleUserSaving']);
        $events->listen(AvatarChanged::class, [$this, 'handleAvatarChanged']);
    }

    /**
     * Handle post saving event.
     *
     * @param PostSaving $event
     * @return void
     */
    public function handlePostSaving(PostSaving $event): void
    {
        $post = $event->post;
        $actor = $event->actor;

        // Only audit comment posts
        if (!$post instanceof CommentPost) {
            return;
        }

        // Check if user can bypass audit
        if ($this->canBypassAudit($actor, 'post')) {
            $this->logger->debug('[Queue Content Audit] User bypassed post audit', [
                'user_id' => $actor->id,
                'post_id' => $post->id,
            ]);
            return;
        }

        // Check if this is a new post or edited post
        $isNew = !$post->exists;
        $isEdited = $post->exists && $post->isDirty('content');

        if (!$isNew && !$isEdited) {
            return;
        }

        // Apply pre-approve if enabled
        if ($isNew && $this->isPreApproveEnabled() && !$this->canBypassPreApprove($actor)) {
            $post->is_approved = false;
            $this->logger->debug('[Queue Content Audit] Pre-approve: Post marked as unapproved', [
                'post_id' => $post->id,
                'user_id' => $actor->id,
            ]);

            // Create flag for pre-approval
            $post->afterSave(function ($post) {
                $this->flagService->createAuditFlag($post, null, 'pre-approval');
            });
        }

        // Queue audit after save
        $post->afterSave(function ($post) {
            $this->logger->info('[Queue Content Audit] Queueing post audit', [
                'post_id' => $post->id,
                'user_id' => $post->user_id,
            ]);

            $this->queue->push(new AuditContentJob(
                'post',
                $post->id,
                $post->user_id,
                ['content' => $post->content]
            ));
        });
    }

    /**
     * Handle discussion saving event.
     *
     * @param DiscussionSaving $event
     * @return void
     */
    public function handleDiscussionSaving(DiscussionSaving $event): void
    {
        $discussion = $event->discussion;
        $actor = $event->actor;

        // Check if user can bypass audit
        if ($this->canBypassAudit($actor, 'discussion')) {
            $this->logger->debug('[Queue Content Audit] User bypassed discussion audit', [
                'user_id' => $actor->id,
                'discussion_id' => $discussion->id,
            ]);
            return;
        }

        // Check if title changed
        $isNew = !$discussion->exists;
        $titleChanged = $discussion->exists && $discussion->isDirty('title');

        if (!$isNew && !$titleChanged) {
            return;
        }

        // Apply pre-approve if enabled
        if ($isNew && $this->isPreApproveEnabled() && !$this->canBypassPreApprove($actor)) {
            $discussion->is_approved = false;
            $this->logger->debug('[Queue Content Audit] Pre-approve: Discussion marked as unapproved', [
                'discussion_id' => $discussion->id,
                'user_id' => $actor->id,
            ]);

            // Create flag for pre-approval
            $discussion->afterSave(function ($discussion) {
                $this->flagService->createAuditFlag($discussion, null, 'pre-approval');
            });
        }

        // Queue audit after save
        $discussion->afterSave(function ($discussion) {
            $this->logger->info('[Queue Content Audit] Queueing discussion audit', [
                'discussion_id' => $discussion->id,
                'user_id' => $discussion->user_id,
            ]);

            $this->queue->push(new AuditContentJob(
                'discussion',
                $discussion->id,
                $discussion->user_id,
                ['title' => $discussion->title]
            ));
        });
    }

    /**
     * Handle user saving event.
     *
     * @param UserSaving $event
     * @return void
     */
    public function handleUserSaving(UserSaving $event): void
    {
        $user = $event->user;
        $actor = $event->actor;

        // Check if user can bypass audit
        if ($this->canBypassAudit($actor, 'user_profile')) {
            $this->logger->debug('[Queue Content Audit] User bypassed profile audit', [
                'user_id' => $actor->id,
            ]);
            return;
        }

        // Check for changed auditable fields
        // Note: Only check fields that may exist
        // - username, display_name are core fields
        // - avatar_url is handled by handleAvatarChanged() listener
        // - bio requires fof/user-bio extension  
        // - cover requires sycho/flarum-profile-cover extension
        $auditableFields = ['username', 'display_name'];
        
        // Check optional fields from extensions
        if (isset($user->bio) || array_key_exists('bio', $user->getAttributes())) {
            $auditableFields[] = 'bio';
        }
        if (isset($user->cover) || array_key_exists('cover', $user->getAttributes())) {
            $auditableFields[] = 'cover';
        }
        
        $changes = [];

        foreach ($auditableFields as $field) {
            if ($user->isDirty($field)) {
                // Get attribute value (will use accessor if defined)
                $changes[$field] = $user->getAttribute($field);
            }
        }

        if (empty($changes)) {
            return;
        }

        // Queue audit after save
        $user->afterSave(function ($user) use ($changes) {
            // Re-fetch changed fields and mark local files
            $finalChanges = [];
            foreach (array_keys($changes) as $field) {
                $value = $user->getAttribute($field);
                
                // For cover field, mark as local file if it's not an external URL
                if ($field === 'cover' && $value && !str_contains($value, '://')) {
                    $finalChanges[$field] = [
                        '_local_file' => true,
                        '_disk' => 'sycho-profile-cover',
                        '_path' => $value,
                        'url' => null, // Will generate if needed
                    ];
                } else {
                    $finalChanges[$field] = $value;
                }
            }
            
            $this->logger->info('[Queue Content Audit] Queueing user profile audit', [
                'user_id' => $user->id,
                'changed_fields' => array_keys($finalChanges),
            ]);

            $this->queue->push(new AuditContentJob(
                'user_profile',
                null, // No specific content ID for user profiles
                $user->id,
                $finalChanges
            ));
        });
    }

    /**
     * Check if user can bypass audit.
     *
     * @param \Flarum\User\User $user
     * @param string $contentType
     * @return bool
     */
    private function canBypassAudit($user, string $contentType): bool
    {
        // Administrators always bypass
        if ($user->isAdmin()) {
            return true;
        }

        // Check specific permission
        return $user->hasPermission('ghostchu-openaicontentaudit.bypassAudit');
    }

    /**
     * Check if user can bypass pre-approve.
     *
     * @param \Flarum\User\User $user
     * @return bool
     */
    private function canBypassPreApprove($user): bool
    {
        // Administrators always bypass
        if ($user->isAdmin()) {
            return true;
        }

        // Check specific permission
        return $user->hasPermission('ghostchu-openaicontentaudit.bypassPreApprove');
    }

    /**
     * Check if pre-approve is enabled.
     *
     * @return bool
     */
    private function isPreApproveEnabled(): bool
    {
        return (bool) $this->settings->get('ghostchu.openaicontentaudit.pre_approve_enabled', false);
    }

    /**
     * Note: Avatar and Cover auditing is handled by handleAvatarChanged() and handleUserSaving() methods.
     * 
     * In Flarum 1.8.x:
     * - Avatar changes trigger AvatarChanged event after the file is saved
     * - Cover changes update the user model and trigger UserSaving event
     * 
     * By monitoring both events, we can properly audit these changes after the files are saved.
     */

    /**
     * Handle avatar changed event.
     *
     * @param AvatarChanged $event
     * @return void
     */
    public function handleAvatarChanged(AvatarChanged $event): void
    {
        $user = $event->user;
        $actor = $event->actor ?? $user;

        // Check if user can bypass audit
        if ($this->canBypassAudit($actor, 'user_profile')) {
            $this->logger->debug('[Queue Content Audit] User bypassed avatar audit', [
                'user_id' => $actor->id,
            ]);
            return;
        }

        $avatarUrl = $user->avatar_url;
        
        if (!$avatarUrl) {
            $this->logger->debug('[Queue Content Audit] Avatar URL is empty, skipping audit', [
                'user_id' => $user->id,
            ]);
            return;
        }

        $this->logger->info('[Queue Content Audit] Queueing avatar audit', [
            'user_id' => $user->id,
            'actor_id' => $actor->id,
        ]);

        // Prepare avatar data for audit
        $changes = [];
        
        // Check if avatar_url is a local file (no protocol)
        if (!str_contains($avatarUrl, '://')) {
            $changes['avatar_url'] = [
                '_local_file' => true,
                '_disk' => 'flarum-avatars',
                '_path' => $avatarUrl,
                'url' => $avatarUrl,
            ];
        } else {
            $changes['avatar_url'] = $avatarUrl;
        }

        $this->queue->push(new AuditContentJob(
            'user_profile',
            null,
            $user->id,
            $changes
        ));
    }
}

