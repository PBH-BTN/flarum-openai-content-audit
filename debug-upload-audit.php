<?php

/*
 * This file is part of ghostchu/openai-content-audit.
 *
 * Copyright (c) 2024 Ghost_chu.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Flarum\Foundation\Config;
use Flarum\Foundation\Paths;
use Illuminate\Database\Capsule\Manager as Capsule;

require __DIR__.'/flarum-instance-2.0/vendor/autoload.php';

$paths = new Paths([
    'base' => __DIR__.'/flarum-instance-2.0',
    'public' => __DIR__.'/flarum-instance-2.0/public',
    'storage' => __DIR__.'/flarum-instance-2.0/storage',
]);

$config = [];
if (file_exists($configFile = $paths->base . '/config.php')) {
    $config = include $configFile;
}

$site = Flarum\Foundation\Site::fromPaths($paths, $config);

$db = $site->bootApp()->getContainer()->make('db');

echo "=== OpenAI Content Audit - Upload Audit Debug ===\n\n";

// Check if fof/upload is installed
echo "1. Checking fof/upload installation...\n";
$uploadExtension = $db->table('extensions')->where('id', 'fof-upload')->first();
if ($uploadExtension) {
    echo "   ✓ fof/upload is installed and " . ($uploadExtension->enabled ? "ENABLED" : "DISABLED") . "\n";
    echo "   Version: " . ($uploadExtension->version ?? 'unknown') . "\n";
} else {
    echo "   ✗ fof/upload is NOT installed\n";
    echo "   → Please install fof/upload first: composer require fof/upload\n";
    exit(1);
}

// Check if our extension is enabled
echo "\n2. Checking openai-content-audit installation...\n";
$auditExtension = $db->table('extensions')->where('id', 'ghostchu-openaicontentaudit')->first();
if ($auditExtension) {
    echo "   ✓ openai-content-audit is installed and " . ($auditExtension->enabled ? "ENABLED" : "DISABLED") . "\n";
    if (!$auditExtension->enabled) {
        echo "   → Please enable the extension in Flarum admin panel\n";
        exit(1);
    }
} else {
    echo "   ✗ openai-content-audit is NOT installed\n";
    echo "   → Please install the extension first\n";
    exit(1);
}

// Check settings
echo "\n3. Checking upload audit configuration...\n";
$uploadAuditEnabled = $db->table('settings')
    ->where('key', 'ghostchu-openaicontentaudit.upload_audit_enabled')
    ->value('value');

if ($uploadAuditEnabled === '1' || $uploadAuditEnabled === 'true') {
    echo "   ✓ Upload audit is ENABLED\n";
} else {
    echo "   ✗ Upload audit is DISABLED\n";
    echo "   → Please enable it in Admin > OpenAI Content Audit > Upload Audit Settings\n";
    echo "   → Or run: UPDATE settings SET value='1' WHERE key='ghostchu-openaicontentaudit.upload_audit_enabled';\n";
}

$imageMaxSize = $db->table('settings')
    ->where('key', 'ghostchu-openaicontentaudit.upload_audit_image_max_size')
    ->value('value') ?? '10';
echo "   Image max size: {$imageMaxSize} MB\n";

$textMaxSize = $db->table('settings')
    ->where('key', 'ghostchu-openaicontentaudit.upload_audit_text_max_size')
    ->value('value') ?? '64';
echo "   Text max size: {$textMaxSize} KB\n";

// Check if FileWasSaved event class exists
echo "\n4. Checking event class availability...\n";
if (class_exists('FoF\\Upload\\Events\\File\\WasSaved')) {
    echo "   ✓ FoF\\Upload\\Events\\File\\WasSaved class exists\n";
} else {
    echo "   ✗ FoF\\Upload\\Events\\File\\WasSaved class NOT found\n";
    echo "   → Composer autoload might need to be refreshed\n";
}

// Check recent file uploads
echo "\n5. Checking recent file uploads...\n";
$recentFiles = $db->table('fof_upload_files')
    ->orderBy('created_at', 'desc')
    ->limit(5)
    ->get(['id', 'base_name', 'type', 'size', 'hidden', 'actor_id', 'created_at']);

if ($recentFiles->isEmpty()) {
    echo "   No files found in database\n";
} else {
    echo "   Recent uploads:\n";
    foreach ($recentFiles as $file) {
        $hidden = $file->hidden ? 'HIDDEN' : 'visible';
        $sizeMB = round($file->size / 1024 / 1024, 2);
        echo "   - ID: {$file->id} | {$file->base_name} | {$file->type} | {$sizeMB}MB | {$hidden}\n";
        echo "     Uploaded: {$file->created_at} by user {$file->actor_id}\n";
    }
}

// Check audit logs for uploads
echo "\n6. Checking audit logs...\n";
$auditLogs = $db->table('audit_logs')
    ->where('content_type', 'upload')
    ->orderBy('created_at', 'desc')
    ->limit(5)
    ->get(['id', 'content_id', 'user_id', 'status', 'conclusion', 'created_at']);

if ($auditLogs->isEmpty()) {
    echo "   ✗ No upload audit logs found\n";
    echo "   → This confirms that uploads are not being audited\n";
} else {
    echo "   ✓ Found " . $auditLogs->count() . " upload audit logs:\n";
    foreach ($auditLogs as $log) {
        echo "   - ID: {$log->id} | File ID: {$log->content_id} | Status: {$log->status}\n";
        echo "     User: {$log->user_id} | Conclusion: {$log->conclusion ?? 'pending'}\n";
    }
}

// Check queue jobs
echo "\n7. Checking queue for pending audit jobs...\n";
$queueJobs = $db->table('jobs')
    ->where('payload', 'like', '%AuditContentJob%')
    ->where('payload', 'like', '%upload%')
    ->count();

echo "   Pending upload audit jobs in queue: {$queueJobs}\n";

// Final diagnosis
echo "\n=== DIAGNOSIS ===\n";
if (!$uploadExtension || !$uploadExtension->enabled) {
    echo "❌ fof/upload is not installed or not enabled\n";
} elseif (!$auditExtension || !$auditExtension->enabled) {
    echo "❌ openai-content-audit is not installed or not enabled\n";
} elseif ($uploadAuditEnabled !== '1' && $uploadAuditEnabled !== 'true') {
    echo "❌ Upload audit is disabled in settings\n";
    echo "\nTO FIX: Enable upload audit in admin panel or run:\n";
    echo "UPDATE settings SET value='1' WHERE key='ghostchu-openaicontentaudit.upload_audit_enabled';\n";
} elseif ($auditLogs->isEmpty()) {
    echo "⚠️ Configuration looks correct, but no audit logs found\n";
    echo "\nPossible issues:\n";
    echo "- Event listener might not be registered (try clearing Flarum cache)\n";
    echo "- Queue worker might not be running\n";
    echo "- Files uploaded were not supported types or exceeded size limits\n";
    echo "\nTO FIX:\n";
    echo "1. Clear Flarum cache: php flarum cache:clear\n";
    echo "2. Start queue worker: php flarum queue:work\n";
    echo "3. Upload a test image (< {$imageMaxSize}MB) and check logs\n";
} else {
    echo "✅ Upload audit appears to be working correctly!\n";
}

echo "\n=== END ===\n";
