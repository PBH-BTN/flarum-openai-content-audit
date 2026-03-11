<?php

/*
 * This file is part of ghostchu/openai-content-audit.
 *
 * Copyright (c) 2026 Ghost_chu.
 *
 * For the full copyright and license information, please view the LICENSE.md
 * file that was distributed with this source code.
 */

/**
 * Upgrade migration: change oaicontaudit_logs.user_id from unsignedInteger (32-bit)
 * to unsignedBigInteger (64-bit) to match Flarum 2.x's users.id column type.
 *
 * This migration is required for installations that were set up under Flarum 1.x
 * and are upgrading to Flarum 2.x. Fresh Flarum 2.x installations already create
 * the column as bigint via the original create migration.
 *
 * Requires doctrine/dbal for ->change() support (already present in Flarum's vendor).
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Drop foreign key constraint before changing column type
        Schema::table('oaicontaudit_logs', function (Blueprint $table) {
            // Drop the foreign key referencing users.id
            $table->dropForeign(['user_id']);
            // Drop composite index that includes user_id
            $table->dropIndex(['user_id', 'created_at']);
            // Drop the plain user_id index
            $table->dropIndex(['user_id']);
        });

        Schema::table('oaicontaudit_logs', function (Blueprint $table) {
            // Change column type from int(10) unsigned to bigint(20) unsigned
            $table->unsignedBigInteger('user_id')->index()->change();
        });

        Schema::table('oaicontaudit_logs', function (Blueprint $table) {
            // Re-create foreign key now that types match
            $table->foreign('user_id')
                ->references('id')
                ->on('users')
                ->onDelete('cascade');
            // Re-create composite index
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('oaicontaudit_logs', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->dropIndex(['user_id', 'created_at']);
            $table->dropIndex(['user_id']);
        });

        Schema::table('oaicontaudit_logs', function (Blueprint $table) {
            $table->unsignedInteger('user_id')->index()->change();
        });

        Schema::table('oaicontaudit_logs', function (Blueprint $table) {
            $table->foreign('user_id')
                ->references('id')
                ->on('users')
                ->onDelete('cascade');
            $table->index(['user_id', 'created_at']);
        });
    }
};
