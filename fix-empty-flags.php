<?php

/*
 * Script to fix existing openai-audit flags with empty reason_detail
 */

use Flarum\Flags\Flag;
use Ghostchu\Openaicontentaudit\Model\AuditLog;

require __DIR__.'/flarum-instance/vendor/autoload.php';

$app = require __DIR__.'/flarum-instance/site.php';
$app = $app->boot();

try {
    // Get all openai-audit flags
    $flags = Flag::where('type', 'openai-audit')->get();
    
    if ($flags->isEmpty()) {
        echo "⚠️ No openai-audit flags found\n";
        exit(0);
    }
    
    echo "Found " . $flags->count() . " openai-audit flag(s)\n\n";
    
    $fixed = 0;
    
    foreach ($flags as $flag) {
        echo "Checking Flag #{$flag->id} (Post #{$flag->post_id})...\n";
        
        // Check if reason_detail is empty
        if (empty($flag->reason_detail)) {
            echo "  ⚠️ Empty reason_detail detected\n";
            
            // Try to find associated audit log
            $log = AuditLog::where('content_type', 'post')
                ->where('content_id', $flag->post_id)
                ->orderBy('created_at', 'desc')
                ->first();
            
            if ($log) {
                $conclusion = $log->conclusion ?? 'AI 检测到可能的违规内容';
                $confidence = $log->confidence ?? 0;
                $confidencePercent = number_format($confidence * 100, 1);
                
                $newDetail = sprintf(
                    "[审核日志 #%d] %s\n置信度: %s%%",
                    $log->id,
                    $conclusion,
                    $confidencePercent
                );
                
                $flag->reason_detail = $newDetail;
                $flag->save();
                
                echo "  ✅ Updated with audit log #{$log->id}\n";
                echo "  Content: " . substr($newDetail, 0, 100) . "...\n";
                $fixed++;
            } else {
                // No audit log found, use pending message
                $flag->reason_detail = '等待 AI 审核完成 / Pending AI audit';
                $flag->save();
                
                echo "  ✅ Updated with pending message\n";
                $fixed++;
            }
        } else {
            echo "  ✅ Already has reason_detail: " . substr($flag->reason_detail, 0, 50) . "...\n";
        }
        
        echo "\n";
    }
    
    echo "\n=== Summary ===\n";
    echo "Total flags: {$flags->count()}\n";
    echo "Fixed: {$fixed}\n";
    echo "✅ Done!\n";
    
} catch (\Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
    exit(1);
}
