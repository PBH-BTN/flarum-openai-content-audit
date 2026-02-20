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
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\User;
use Illuminate\Contracts\Events\Dispatcher;
use Psr\Log\LoggerInterface;

class MessageNotifier
{
    public function __construct(
        private SettingsRepositoryInterface $settings,
        private Dispatcher $events,
        private LoggerInterface $logger
    ) {
    }

    /**
     * Send a private message to user about audit violation.
     *
     * @param User $user The user who violated rules
     * @param User $sender The system user who sends the message
     * @param string $contentType Type of content (post, discussion, user_profile)
     * @param array $violations Array of violation reasons
     * @param float $confidence Confidence score
     * @return bool Whether the message was sent successfully
     */
    public function sendViolationNotice(
        User $user,
        User $sender,
        string $contentType,
        array $violations,
        float $confidence
    ): bool {
        // Check if message notification is enabled
        if (!$this->isEnabled()) {
            $this->logger->debug('[Message Notifier] Message notification is disabled');
            return false;
        }

        // Check if flarum/messages extension is available
        if (!class_exists('Flarum\\Messages\\Dialog')) {
            $this->logger->warning('[Message Notifier] flarum/messages extension not found');
            return false;
        }

        try {
            // Format message content
            $messageContent = $this->formatMessage($contentType, $violations, $confidence);

            // Find or create dialog
            $dialog = \Flarum\Messages\Dialog::query()
                ->whereRelation('users', 'user_id', $sender->id)
                ->whereRelation('users', 'user_id', $user->id)
                ->where('type', 'direct')
                ->first();

            if (!$dialog) {
                $dialog = new \Flarum\Messages\Dialog();
                $dialog->type = 'direct';
                $dialog->save();

                // Add users to dialog
                $dialog->users()->syncWithPivotValues(
                    [$sender->id, $user->id],
                    ['joined_at' => Carbon::now()]
                );
            }

            // Create message
            $message = new \Flarum\Messages\DialogMessage();
            $message->dialog_id = $dialog->id;
            $message->user_id = $sender->id;
            $message->setContentAttribute($messageContent, $sender);
            $message->save();

            // Update dialog last message
            if (!$dialog->first_message_id) {
                $dialog->setFirstMessage($message);
            }
            $dialog->setLastMessage($message);
            $dialog->save();

            // Dispatch events for notifications
            $this->events->dispatch(
                new \Flarum\Messages\DialogMessage\Event\Created($message)
            );

            $this->logger->info('[Message Notifier] Violation notice sent', [
                'user_id' => $user->id,
                'content_type' => $contentType,
                'message_id' => $message->id,
            ]);

            return true;
        } catch (\Exception $e) {
            $this->logger->error('[Message Notifier] Failed to send message', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return false;
        }
    }

    /**
     * Check if message notification is enabled.
     *
     * @return bool
     */
    private function isEnabled(): bool
    {
        return (bool) $this->settings->get('ghostchu.openaicontentaudit.send_message_notification', true);
    }

    /**
     * Format message content based on template.
     *
     * @param string $contentType
     * @param array $violations
     * @param float $confidence
     * @return string
     */
    private function formatMessage(string $contentType, array $violations, float $confidence): string
    {
        // Get custom template or use default
        $template = $this->settings->get('ghostchu.openaicontentaudit.message_template');

        if (empty($template)) {
            $template = $this->getDefaultTemplate();
        }

        // Format violations list
        $violationsList = '';
        foreach ($violations as $violation) {
            $violationsList .= '- ' . $violation . "\n";
        }

        // Replace placeholders
        $message = str_replace(
            ['{content_type}', '{violations}', '{confidence}'],
            [
                $this->translateContentType($contentType),
                trim($violationsList),
                number_format($confidence * 100, 1) . '%'
            ],
            $template
        );

        return $message;
    }

    /**
     * Get default message template.
     *
     * @return string
     */
    private function getDefaultTemplate(): string
    {
        return <<<'TEXT'
您好，

系统检测到您发布的内容（类型：{content_type}）可能违反了社区规范。

**违规原因：**
{violations}

**置信度：** {confidence}

您的内容已被自动隐藏，请修改后重新发布。如有疑问，请联系管理员。

感谢您的理解与配合。
TEXT;
    }

    /**
     * Translate content type to human-readable format.
     *
     * @param string $contentType
     * @return string
     */
    private function translateContentType(string $contentType): string
    {
        return match ($contentType) {
            'post' => '帖子回复',
            'discussion' => '讨论主题',
            'user_profile' => '个人资料',
            default => $contentType,
        };
    }
}
