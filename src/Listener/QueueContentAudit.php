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
use FoF\Upload\Events\File\WasSaved as FileWasSaved;
use FoF\Upload\File;

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
        
        // Also listen for cover upload if sycho/flarum-profile-cover is installed
        if (class_exists('SychO\\ProfileCover\\Event\\CoverSaving')) {
            $events->listen('SychO\\ProfileCover\\Event\\CoverSaving', [$this, 'handleCoverSaving']);
        }

        // Also listen for file uploads if fof/upload is installed
        if (class_exists('FoF\\Upload\\Events\\File\\WasSaved')) {
            $events->listen(FileWasSaved::class, [$this, 'handleFileWasSaved']);
        }
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
        $auditableFields = ['username', 'display_name', 'bio', 'cover'];
        
        $this->logger->debug('[Queue Content Audit] Checking user fields for changes', [
            'user_id' => $user->id,
            'dirty_attributes' => array_keys($user->getDirty()),
            'auditable_fields' => $auditableFields,
        ]);
        
        $changes = [];

        foreach ($auditableFields as $field) {
            if ($user->isDirty($field)) {
                // Get attribute value (will use accessor if defined)
                $changes[$field] = $user->getAttribute($field);
                
                $this->logger->debug('[Queue Content Audit] Field changed', [
                    'field' => $field,
                    'value' => is_string($changes[$field]) ? $changes[$field] : gettype($changes[$field]),
                ]);
            }
        }

        if (empty($changes)) {
            $this->logger->debug('[Queue Content Audit] No profile changes to audit', [
                'user_id' => $user->id,
            ]);
            return;
        }

        // Queue audit after save
        $user->afterSave(function ($user) use ($changes) {
            // Re-fetch changed fields and mark local files
            $finalChanges = [];
            foreach (array_keys($changes) as $field) {
                // For cover field, get raw value to check if it's local
                if ($field === 'cover') {
                    $coverPath = $user->getRawOriginal('cover') ?? $user->getAttribute($field);
                    
                    if ($coverPath && !str_contains($coverPath, '://')) {
                        // Local file
                        $finalChanges[$field] = [
                            '_local_file' => true,
                            '_disk' => 'sycho-profile-cover',
                            '_path' => $coverPath,
                            'url' => null, // Will generate URL in ContentExtractor if needed
                        ];
                    } else {
                        // External URL
                        $finalChanges[$field] = $coverPath;
                    }
                } else {
                    $finalChanges[$field] = $user->getAttribute($field);
                }
            }
            
            $this->logger->info('[Queue Content Audit] Queueing user profile audit', [
                'user_id' => $user->id,
                'changed_fields' => array_keys($finalChanges),
                'final_changes' => array_map(function($v) {
                    return is_array($v) ? '[local_file]' : (is_string($v) ? substr($v, 0, 50) : gettype($v));
                }, $finalChanges),
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

        // Get raw avatar path (not the URL from accessor)
        $avatarPath = $user->getRawOriginal('avatar_url');
        
        if (!$avatarPath) {
            $this->logger->debug('[Queue Content Audit] Avatar path is empty, skipping audit', [
                'user_id' => $user->id,
            ]);
            return;
        }

        $this->logger->info('[Queue Content Audit] Queueing avatar audit', [
            'user_id' => $user->id,
            'actor_id' => $actor->id,
            'avatar_path' => $avatarPath,
        ]);

        // Prepare avatar data for audit
        $changes = [];
        
        // Check if avatar_path is a local file (no protocol in raw value)
        if (!str_contains($avatarPath, '://')) {
            $changes['avatar_url'] = [
                '_local_file' => true,
                '_disk' => 'flarum-avatars',
                '_path' => $avatarPath,
                'url' => $user->avatar_url, // Use accessor for fallback URL
            ];
        } else {
            // External URL (e.g., from OAuth providers)
            $changes['avatar_url'] = $avatarPath;
        }

        $this->queue->push(new AuditContentJob(
            'user_profile',
            null,
            $user->id,
            $changes
        ));
    }

    /**
     * Handle cover saving event.
     *
     * @param \SychO\ProfileCover\Event\CoverSaving $event
     * @return void
     */
    public function handleCoverSaving($event): void
    {
        $user = $event->user;
        $actor = $event->actor;

        // Check if user can bypass audit
        if ($this->canBypassAudit($actor, 'user_profile')) {
            $this->logger->debug('[Queue Content Audit] User bypassed cover audit', [
                'user_id' => $actor->id,
                'target_user_id' => $user->id,
            ]);
            return;
        }

        // Queue audit after save (cover is set by uploader after this event)
        $user->afterSave(function ($user) {
            // Get raw cover path
            $coverPath = $user->getRawOriginal('cover') ?? $user->getAttribute('cover');
            
            if (!$coverPath) {
                $this->logger->debug('[Queue Content Audit] Cover path is empty, skipping audit', [
                    'user_id' => $user->id,
                ]);
                return;
            }

            $this->logger->info('[Queue Content Audit] Queueing cover audit', [
                'user_id' => $user->id,
                'cover_path' => $coverPath,
            ]);

            // Prepare cover data for audit
            $changes = [];
            
            // Cover path should be a local file (no protocol in raw value)
            if (!str_contains($coverPath, '://')) {
                $changes['cover'] = [
                    '_local_file' => true,
                    '_disk' => 'sycho-profile-cover',
                    '_path' => $coverPath,
                    'url' => null,
                ];
            } else {
                // External URL (unlikely for cover)
                $changes['cover'] = $coverPath;
            }

            $this->queue->push(new AuditContentJob(
                'user_profile',
                null,
                $user->id,
                $changes
            ));
        });
    }

    /**
     * Handle file upload event from fof/upload.
     *
     * @param FileWasSaved $event
     * @return void
     */
    public function handleFileWasSaved(FileWasSaved $event): void
    {
        $file = $event->file;
        $actor = $event->actor;
        $mime = $event->mime;

        // Check if upload audit is enabled
        if (!$this->settings->get('ghostchu-openaicontentaudit.upload_audit_enabled', false)) {
            $this->logger->debug('[Queue Content Audit] Upload audit is disabled', [
                'file_id' => $file->id,
            ]);
            return;
        }

        // Check if user can bypass audit
        if ($this->canBypassAudit($actor, 'upload')) {
            $this->logger->debug('[Queue Content Audit] User bypassed upload audit', [
                'user_id' => $actor->id,
                'file_id' => $file->id,
            ]);
            return;
        }

        // Check if file is already hidden (might be re-saving)
        if ($file->hidden) {
            $this->logger->debug('[Queue Content Audit] File is hidden, skipping audit', [
                'file_id' => $file->id,
            ]);
            return;
        }

        // Determine if this file type should be audited
        $shouldAudit = false;
        $fileType = null;

        // Check if it's an image
        if (str_starts_with($mime, 'image/')) {
            $supportedImageTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif'];
            if (in_array($mime, $supportedImageTypes)) {
                $maxSize = (int)$this->settings->get('ghostchu-openaicontentaudit.upload_audit_image_max_size', 10) * 1024 * 1024; // Convert MB to bytes
                if ($file->size <= $maxSize) {
                    $shouldAudit = true;
                    $fileType = 'image';
                } else {
                    $this->logger->debug('[Queue Content Audit] Image exceeds max size', [
                        'file_id' => $file->id,
                        'file_size' => $file->size,
                        'max_size' => $maxSize,
                    ]);
                }
            } else {
                $this->logger->debug('[Queue Content Audit] Unsupported image type', [
                    'file_id' => $file->id,
                    'mime' => $mime,
                ]);
            }
        }
        // Check if it's a text file
        elseif (in_array($mime, [
            'text/plain',
            'text/markdown',
            'text/csv',
            'application/x-bittorrent',
        ])) {
            $maxSize = (int)$this->settings->get('ghostchu-openaicontentaudit.upload_audit_text_max_size', 64) * 1024; // Convert KB to bytes
            if ($file->size <= $maxSize) {
                $shouldAudit = true;
                $fileType = 'text';
            } else {
                $this->logger->debug('[Queue Content Audit] Text file exceeds max size', [
                    'file_id' => $file->id,
                    'file_size' => $file->size,
                    'max_size' => $maxSize,
                ]);
            }
        } else {
            $this->logger->debug('[Queue Content Audit] File type not supported for audit', [
                'file_id' => $file->id,
                'mime' => $mime,
            ]);
        }

        if (!$shouldAudit) {
            return;
        }

        // Queue audit job
        $this->logger->info('[Queue Content Audit] Queueing file upload audit', [
            'file_id' => $file->id,
            'file_name' => $file->base_name,
            'file_type' => $fileType,
            'file_size' => $file->size,
            'mime' => $mime,
            'user_id' => $actor->id,
        ]);

        $this->queue->push(new AuditContentJob(
            'upload',
            $file->id,
            $actor->id,
            [
                'file_name' => $file->base_name,
                'file_type' => $fileType,
                'mime' => $mime,
                'size' => $file->size,
                'path' => $file->path,
                'url' => $file->url,
                'upload_method' => $file->upload_method,
            ]
        ));
    }
}
