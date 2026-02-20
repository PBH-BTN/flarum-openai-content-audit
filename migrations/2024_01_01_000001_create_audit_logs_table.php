<?php

/*
 * This file is part of ghostchu/openai-content-audit.
 *
 * Copyright (c) 2024 Ghost_chu.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

return Migration::createTable('oaicontaudit_logs', function (Blueprint $table) {
    $table->increments('id');
    
    // Content identification
    $table->string('content_type', 50)->index(); // post, discussion, user_profile, avatar, etc.
    $table->unsignedBigInteger('content_id')->nullable()->index(); // ID of the content
    $table->unsignedInteger('user_id')->index(); // User who created the content
    
    // Audit data
    $table->text('audited_content')->nullable(); // JSON: Snapshot of content audited
    $table->text('api_request')->nullable(); // JSON: Full request sent to LLM
    $table->text('api_response')->nullable(); // JSON: Raw response from LLM
    
    // Audit results
    $table->decimal('confidence', 5, 4)->nullable(); // 0.0000 to 1.0000 (0% to 100%)
    $table->text('actions_taken')->nullable(); // JSON: Array of actions taken
    $table->text('conclusion')->nullable(); // Text: Audit conclusion
    
    // Status tracking
    $table->string('status', 20)->default('pending')->index(); // pending, completed, failed, retrying
    $table->unsignedInteger('retry_count')->default(0);
    $table->text('error_message')->nullable();
    
    // Timestamps
    $table->timestamp('created_at')->nullable();
    $table->timestamp('updated_at')->nullable();
    
    // Foreign keys
    $table->foreign('user_id')
        ->references('id')
        ->on('users')
        ->onDelete('cascade');
    
    // Composite indexes for common queries
    $table->index(['content_type', 'status']);
    $table->index(['user_id', 'created_at']);
});
