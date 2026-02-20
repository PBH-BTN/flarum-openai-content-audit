# 图片审核修复说明

## 问题描述
图片字段（avatar_url、cover）审核不工作，没有生成审核日志。

## 根本原因

### 1. Cover 字段的 URL 处理问题
`sycho/flarum-profile-cover` 扩展的 `cover` 字段行为与 `avatar_url` 不同：

- **avatar_url**: 有 `getAvatarUrlAttribute()` accessor，自动转换为完整 URL
  ```php
  $user->avatar_url  // 返回: https://example.com/assets/avatars/abc123.png
  ```

- **cover**: 直接存储文件名，无 accessor
  ```php
  $user->cover  // 返回: abc123.jpg (只是文件名！)
  ```

之前的代码直接将文件名传递给队列任务，导致：
- `ContentExtractor::downloadImage()` 尝试下载 "abc123.jpg" 失败
- 无法进行 Vision API 审核
- 回退到 URL 模式，但 "abc123.jpg" 不是有效 URL

### 2. 字段存在性检查缺失
代码对所有字段（包括 cover、bio）进行 `isDirty()` 检查，但这些字段需要扩展支持：
- `bio` - 需要 `fof/user-bio`
- `cover` - 需要 `sycho/flarum-profile-cover`

如果扩展未安装，可能导致错误或静默失败。

## 修复内容

### 修复 1: QueueContentAudit.php - 动态字段检测和 URL 转换

**位置**: [src/Listener/QueueContentAudit.php](src/Listener/QueueContentAudit.php#L176-L207)

**改动**:
```php
// 1. 动态检测可用字段
$auditableFields = ['username', 'display_name', 'avatar_url'];

// 只在扩展存在时添加可选字段
if (method_exists($user, 'bio') || array_key_exists('bio', $user->getAttributes())) {
    $auditableFields[] = 'bio';
}
if (method_exists($user, 'cover') || array_key_exists('cover', $user->getAttributes())) {
    $auditableFields[] = 'cover';
}

// 2. 在 afterSave 中转换 cover 文件名为完整 URL
$user->afterSave(function ($user) use ($changes) {
    $finalChanges = [];
    foreach (array_keys($changes) as $field) {
        $value = $user->getAttribute($field);
        
        // 转换 cover 文件名为完整 URL
        if ($field === 'cover' && $value && !str_contains($value, '://')) {
            $filesystem = resolve(\Illuminate\Contracts\Filesystem\Factory::class);
            $coversDir = $filesystem->disk('sycho-profile-cover');
            $finalChanges[$field] = $coversDir->url($value);
        } else {
            $finalChanges[$field] = $value;
        }
    }
    
    $this->queue->push(new AuditContentJob(
        'user_profile',
        null,
        $user->id,
        $finalChanges  // 现在包含完整 URL
    ));
});
```

**效果**:
- ✅ 自动检测已安装的扩展
- ✅ 将 cover 文件名转换为完整 URL
- ✅ ContentExtractor 现在能正确下载图片

### 修复 2: AuditResultHandler.php - 正确的默认值

**位置**: [src/Service/AuditResultHandler.php](src/Service/AuditResultHandler.php#L330-L343)

**改动**:
```php
case 'cover':
    if (property_exists($user, 'cover') || isset($user->cover)) {
        $revertedFields[$field] = [
            'old' => $user->cover ?? null,
            'new' => null,  // 改为 null，而不是 ''
        ];
        $user->cover = null;  // 改为 null
        $changed = true;
    }
    break;
```

**原因**: 
- `sycho/flarum-profile-cover` 的 `CoverUploader::remove()` 使用 `null`
- `UserResourceFields` 检查 `$user->cover ? ...` 时，空字符串 `''` 会被视为 truthy
- 使用 `null` 才能正确表示"无封面"

## 验证步骤

1. **测试头像审核**:
   ```bash
   # 上传头像，检查日志
   tail -f flarum-instance/storage/logs/flarum-*.log | grep "Queue Content Audit"
   ```

2. **测试封面审核**:
   - 前提：已安装 `sycho/flarum-profile-cover`
   - 上传封面图片
   - 检查队列任务是否包含完整 URL

3. **检查审核日志**:
   ```sql
   SELECT id, content_type, user_id, audited_content, api_request 
   FROM oaicontaudit_logs 
   WHERE content_type = 'user_profile' 
   ORDER BY created_at DESC 
   LIMIT 5;
   ```

4. **验证 Vision API 调用**:
   - 日志中应该包含 base64 编码的图片数据
   - 或包含完整的 https:// URL

## 技术细节

### Flarum 字段访问器系统
Flarum 的 User 模型使用 Laravel Eloquent 的 accessor/mutator 系统：

```php
// 定义 accessor
public function getAvatarUrlAttribute(?string $value): ?string {
    if ($value && !str_contains($value, '://')) {
        return resolve(Factory::class)->disk('flarum-avatars')->url($value);
    }
    return $value;
}

// 访问时自动调用
$user->avatar_url  // 调用 getAvatarUrlAttribute()
$user->getAttribute('avatar_url')  // 也调用 accessor
```

`cover` 字段没有定义 accessor，所以需要手动转换。

### Filesystem Disk 系统
```php
// sycho/flarum-profile-cover 注册的 disk
(new Extend\Filesystem())
    ->disk('sycho-profile-cover', function (Paths $paths, UrlGenerator $url) {
        return [
            'root' => "$paths->public/assets/covers",
            'url'  => $url->to('forum')->path('assets/covers')
        ];
    });

// 使用
$filesystem = resolve(\Illuminate\Contracts\Filesystem\Factory::class);
$coversDir = $filesystem->disk('sycho-profile-cover');
$fullUrl = $coversDir->url('abc123.jpg');
// 结果: https://example.com/assets/covers/abc123.jpg
```

## 相关文件
- [src/Listener/QueueContentAudit.php](src/Listener/QueueContentAudit.php)
- [src/Service/AuditResultHandler.php](src/Service/AuditResultHandler.php)
- [src/Service/ContentExtractor.php](src/Service/ContentExtractor.php)

## 依赖扩展
- **fof/user-bio** (可选) - 提供 `bio` 字段
- **sycho/flarum-profile-cover** (可选) - 提供 `cover` 字段
- **flarum/nicknames** (可选) - 允许自定义 `display_name`

如果这些扩展未安装，对应字段的审核会自动跳过，不会报错。
