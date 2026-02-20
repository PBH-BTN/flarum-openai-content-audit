<?php

/**
 * Debug script to test avatar URL generation
 * Run: php debug-avatar.php
 */

// Simulate User model behavior
class FakeUser {
    private $attributes = [];
    private $original = [];
    private $dirty = [];
    
    public function __construct() {
        $this->attributes = ['avatar_url' => 'test123.png'];
        $this->original = $this->attributes;
    }
    
    public function setAttribute($key, $value) {
        if (!isset($this->original[$key])) {
            $this->original[$key] = null;
        }
        $this->attributes[$key] = $value;
        if ($this->attributes[$key] !== $this->original[$key]) {
            $this->dirty[$key] = true;
        }
    }
    
    public function getAttribute($key) {
        $value = $this->attributes[$key] ?? null;
        
        // Simulate getAvatarUrlAttribute accessor
        if ($key === 'avatar_url' && $value && !str_contains($value, '://')) {
            return 'https://example.com/assets/avatars/' . $value;
        }
        
        return $value;
    }
    
    public function isDirty($key) {
        return isset($this->dirty[$key]);
    }
    
    public function getAttributes() {
        return $this->attributes;
    }
    
    public function __get($name) {
        return $this->getAttribute($name);
    }
    
    public function __set($name, $value) {
        $this->setAttribute($name, $value);
    }
}

// Test 1: Check if isDirty works correctly
echo "Test 1: isDirty detection\n";
$user = new FakeUser();
echo "Initial isDirty('avatar_url'): " . ($user->isDirty('avatar_url') ? 'true' : 'false') . "\n";

$user->avatar_url = 'newavatar456.png';
echo "After change isDirty('avatar_url'): " . ($user->isDirty('avatar_url') ? 'true' : 'false') . "\n";
echo "Value via getAttribute: " . $user->getAttribute('avatar_url') . "\n";
echo "Value via property: " . $user->avatar_url . "\n";

// Test 2: Check field existence
echo "\nTest 2: Field existence check\n";
echo "array_key_exists('avatar_url'): " . (array_key_exists('avatar_url', $user->getAttributes()) ? 'true' : 'false') . "\n";
echo "array_key_exists('cover'): " . (array_key_exists('cover', $user->getAttributes()) ? 'true' : 'false') . "\n";

// Test 3: Check isDirty on non-existent field
echo "\nTest 3: isDirty on non-existent field\n";
echo "isDirty('cover'): " . ($user->isDirty('cover') ? 'true' : 'false') . "\n";

echo "\n✓ All tests completed\n";
