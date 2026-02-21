<?php

/*
 * Debug script to check Flag reason_detail
 */

use Flarum\Flags\Flag;

require __DIR__.'/flarum-instance/vendor/autoload.php';

$app = require __DIR__.'/flarum-instance/site.php';
$app = $app->boot();

try {
    // Get all flags with type 'openai-audit'
    $flags = Flag::where('type', 'openai-audit')->get();
    
    if ($flags->isEmpty()) {
        echo "⚠️ No openai-audit flags found\n";
        echo "Please trigger the audit system first.\n";
        exit(0);
    }
    
    echo "Found " . $flags->count() . " openai-audit flag(s):\n\n";
    
    foreach ($flags as $flag) {
        echo "=== Flag #{$flag->id} ===\n";
        echo "Post ID: {$flag->post_id}\n";
        echo "Type: {$flag->type}\n";
        echo "User ID: " . ($flag->user_id ?? 'null') . "\n";
        echo "Reason: {$flag->reason}\n";
        echo "Reason Detail Length: " . strlen($flag->reason_detail ?? '') . "\n";
        echo "Reason Detail: " . var_export($flag->reason_detail, true) . "\n";
        echo "Created At: {$flag->created_at}\n";
        echo "\n";
    }
    
    // Test translator
    echo "=== Testing Translator ===\n";
    $translator = $app->getContainer()->make('translator');
    
    $testKeys = [
        'ghostchu-openai-content-audit.flags.openai_audit_reason',
        'ghostchu-openai-content-audit.flags.openai_audit_pending',
        'ghostchu-openai-content-audit.flags.audit_log',
        'ghostchu-openai-content-audit.flags.confidence',
    ];
    
    foreach ($testKeys as $key) {
        $translation = $translator->trans($key);
        echo "$key => " . var_export($translation, true) . "\n";
    }
    
} catch (\Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
    exit(1);
}
