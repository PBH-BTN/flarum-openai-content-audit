<?php

/*
 * This file is part of ghostchu/openai-content-audit.
 *
 * Copyright (c) 2026 Ghost_chu.
 *
 * For the full copyright and license information, please view the LICENSE.md
 * file that was distributed with this source code.
 */

namespace Ghostchu\Openaicontentaudit\Notification;

use Flarum\Notification\Blueprint\BlueprintInterface;
use Flarum\Notification\MailableInterface;
use Flarum\User\User;
use Ghostchu\Openaicontentaudit\Model\AuditLog;
use Symfony\Contracts\Translation\TranslatorInterface;

class ContentViolationBlueprint implements BlueprintInterface, MailableInterface
{
    public function __construct(
        public AuditLog $auditLog,
        public ?User $sender = null
    ) {
    }

    /**
     * Get the user that sent the notification.
     */
    public function getSender(): ?User
    {
        if ($this->sender) {
            return $this->sender;
        }
        // Return the system user who performs the audit action
        return User::find($this->auditLog->auditor_id) ?? User::find(1);
    }

    /**
     * Get the subject model for the notification.
     */
    public function getSubject(): AuditLog
    {
        return $this->auditLog;
    }

    /**
     * Get additional data to be stored with the notification.
     */
    public function getData(): ?array
    {
        return [
            'contentType' => $this->auditLog->content_type,
            'confidence' => $this->auditLog->confidence,
            'conclusion' => $this->auditLog->conclusion,
        ];
    }

    /**
     * Get the notification type identifier.
     */
    public static function getType(): string
    {
        return 'contentViolation';
    }

    /**
     * Get the subject model class name.
     */
    public static function getSubjectModel(): string
    {
        return AuditLog::class;
    }

    /**
     * Get the email view for the notification.
     */
    public function getEmailView(): array
    {
        return ['text' => 'ghostchu-openai-content-audit::emails.contentViolation'];
    }

    /**
     * Get the email subject for the notification.
     */
    public function getEmailSubject(TranslatorInterface $translator): string
    {
        return $translator->trans('ghostchu-openai-content-audit.email.subject.violation');
    }

    /**
     * Get the user that the email should be sent from.
     */
    public function getFromUser(): ?User
    {
        return $this->getSender();
    }
}
