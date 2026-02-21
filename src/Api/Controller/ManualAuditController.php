<?php

/*
 * This file is part of ghostchu/openai-content-audit.
 *
 * Copyright (c) 2024 Ghost_chu.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ghostchu\Openaicontentaudit\Api\Controller;

use Flarum\Api\Controller\AbstractCreateController;
use Flarum\Discussion\Discussion;
use Flarum\Http\RequestUtil;
use Flarum\Post\Post;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\User;
use Ghostchu\Openaicontentaudit\Job\AuditContentJob;
use Illuminate\Contracts\Queue\Queue;
use Illuminate\Support\Arr;
use Psr\Http\Message\ServerRequestInterface;
use Tobscure\JsonApi\Document;

class ManualAuditController extends AbstractCreateController
{
    public $serializer = 'Flarum\Api\Serializer\BasicSerializer';

    public function __construct(
        private Queue $queue,
        private SettingsRepositoryInterface $settings
    ) {
    }

    /**
     * {@inheritdoc}
     */
    protected function data(ServerRequestInterface $request, Document $document)
    {
        $actor = RequestUtil::getActor($request);
        
        // Check permission
        $actor->assertCan('ghostchu-openai-content-audit.manualAudit');

        $body = $request->getParsedBody();
        $contentType = Arr::get($body, 'contentType');
        $contentId = (int) Arr::get($body, 'contentId');
        $fields = Arr::get($body, 'fields', []);

        if (!$contentType || !$contentId) {
            throw new \InvalidArgumentException('contentType and contentId are required');
        }

        // Validate content type
        if (!in_array($contentType, ['post', 'discussion', 'user_profile_username', 'user_profile_avatar', 'user_profile_cover'])) {
            throw new \InvalidArgumentException('Invalid content type');
        }

        // Load and verify content exists
        $content = $this->loadContent($contentType, $contentId);
        $user = $this->getUserFromContent($contentType, $content);

        // Build fields array for specific user profile audits
        $auditFields = $this->buildAuditFields($contentType, $content, $fields);

        // Queue audit job
        $this->queue->push(new AuditContentJob(
            $contentType,
            $contentId,
            $user->id,
            $auditFields
        ));

        return (object) [
            'message' => 'Audit queued successfully',
            'contentType' => $contentType,
            'contentId' => $contentId,
        ];
    }

    /**
     * Load content based on type.
     *
     * @param string $contentType
     * @param int $contentId
     * @return mixed
     */
    private function loadContent(string $contentType, int $contentId)
    {
        return match ($contentType) {
            'post' => Post::findOrFail($contentId),
            'discussion' => Discussion::findOrFail($contentId),
            'user_profile_username', 'user_profile_avatar', 'user_profile_cover' => User::findOrFail($contentId),
            default => throw new \InvalidArgumentException('Invalid content type'),
        };
    }

    /**
     * Get user from content.
     *
     * @param string $contentType
     * @param mixed $content
     * @return User
     */
    private function getUserFromContent(string $contentType, $content): User
    {
        if ($content instanceof User) {
            return $content;
        }

        if ($content instanceof Post || $content instanceof Discussion) {
            return $content->user;
        }

        throw new \InvalidArgumentException('Cannot determine user from content');
    }

    /**
     * Build fields array for audit based on content type.
     *
     * @param string $contentType
     * @param mixed $content
     * @param array $requestedFields
     * @return array
     */
    private function buildAuditFields(string $contentType, $content, array $requestedFields): array
    {
        if (!$content instanceof User) {
            return [];
        }

        $fields = [];

        switch ($contentType) {
            case 'user_profile_username':
                $fields['username'] = $content->username;
                $fields['display_name'] = $content->display_name;
                break;

            case 'user_profile_avatar':
                if ($content->avatar_url) {
                    $fields['avatar_url'] = $content->avatar_url;
                }
                break;

            case 'user_profile_cover':
                // Check if cover field exists (sycho/flarum-profile-cover)
                if (property_exists($content, 'cover') && $content->cover) {
                    $fields['cover'] = $content->cover;
                }
                break;
        }

        return $fields;
    }
}
