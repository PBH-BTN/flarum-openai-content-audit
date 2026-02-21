<?php

/*
 * This file is part of ghostchu/openai-content-audit.
 *
 * Copyright (c) 2024 Ghost_chu.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ghostchu\Openaicontentaudit\Model;

use Flarum\Database\AbstractModel;
use Flarum\User\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $content_type
 * @property int|null $content_id
 * @property int $user_id
 * @property array|null $audited_content
 * @property array|null $api_request
 * @property array|null $api_response
 * @property string|null $response_format_version
 * @property float|null $confidence
 * @property array|null $actions_taken
 * @property string|null $conclusion
 * @property array|null $execution_log
 * @property string $status
 * @property int $retry_count
 * @property string|null $error_message
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 * @property-read User $user
 */
class AuditLog extends AbstractModel
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'oaicontaudit_logs';

    /**
     * The attributes that should be cast.
     *
     * @var array
     */
    protected $casts = [
        'audited_content' => 'array',
        'api_request' => 'array',
        'api_response' => 'array',
        'actions_taken' => 'array',
        'execution_log' => 'array',
        'confidence' => 'decimal:4',
        'retry_count' => 'integer',
        'response_format_version' => 'string',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'content_type',
        'content_id',
        'user_id',
        'audited_content',
        'api_request',
        'api_response',
        'response_format_version',
        'confidence',
        'actions_taken',
        'conclusion',
        'status',
        'retry_count',
        'error_message',
    ];

    /**
     * Get the user who created the content.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Scope a query to only include logs of a given content type.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param string $type
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeByContentType($query, string $type)
    {
        return $query->where('content_type', $type);
    }

    /**
     * Scope a query to only include failed audits.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeFailed($query)
    {
        return $query->where('status', 'failed');
    }

    /**
     * Scope a query to only include pending audits.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    /**
     * Scope a query to only include completed audits.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeCompleted($query)
    {
        return $query->where('status', 'completed');
    }

    /**
     * Check if the audit has failed.
     *
     * @return bool
     */
    public function isFailed(): bool
    {
        return $this->status === 'failed';
    }

    /**
     * Check if the audit is pending.
     *
     * @return bool
     */
    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    /**
     * Check if the audit is completed.
     *
     * @return bool
     */
    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }

    /**
     * Mark the audit as failed.
     *
     * @param string|null $errorMessage
     * @return void
     */
    public function markAsFailed(?string $errorMessage = null): void
    {
        $this->status = 'failed';
        $this->error_message = $errorMessage;
        $this->retry_count++;
        $this->save();
    }

    /**
     * Mark the audit as completed.
     *
     * @return void
     */
    public function markAsCompleted(): void
    {
        $this->status = 'completed';
        $this->error_message = null;
        $this->save();
    }

    /**
     * Mark the audit as retrying.
     *
     * @return void
     */
    public function markAsRetrying(): void
    {
        $this->status = 'retrying';
        $this->save();
    }
}
