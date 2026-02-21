<?php

/*
 * Debug script to test Flag creation
 */

use Flarum\Flags\Flag;
use Flarum\Post\Post;
use Carbon\Carbon;

require __DIR__.'/flarum-instance/vendor/autoload.php';

$app = require __DIR__.'/flarum-instance/site.php';
$app = $app->boot();

try {
    // Check if Flag class exists
    if (!class_exists('Flarum\\Flags\\Flag')) {
        echo "❌ Flag class not found\n";
        exit(1);
    } else {
        echo "✅ Flag class found\n";
    }

    // Try to query flags
    $flags = Flag::all();
    echo "✅ Can query Flag model. Current flags count: " . $flags->count() . "\n";

    // Show existing flags
    if ($flags->count() > 0) {
        echo "\nExisting flags:\n";
        foreach ($flags as $flag) {
            echo "  - Flag #{$flag->id}: post_id={$flag->post_id}, type={$flag->type}, reason={$flag->reason}\n";
        }
    }

    // Try to get a post
    $post = Post::first();
    if (!$post) {
        echo "⚠️ No posts found in database to test with\n";
        exit(0);
    }
    echo "✅ Found post #{$post->id} to test with\n";

    // Try to create a test flag
    $testFlag = new Flag();
    $testFlag->post_id = $post->id;
    $testFlag->type = 'test-debug';
    $testFlag->user_id = null;
    $testFlag->reason = 'Debug test flag';
    $testFlag->reason_detail = 'This is a test flag created by debug script';
    $testFlag->created_at = Carbon::now();
    $testFlag->save();

    echo "✅ Successfully created test flag #{$testFlag->id}\n";

    // Clean up - delete the test flag
    $testFlag->delete();
    echo "✅ Test flag deleted\n";

    echo "\n✅ All tests passed! Flag creation should work.\n";

} catch (\Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
    exit(1);
}
