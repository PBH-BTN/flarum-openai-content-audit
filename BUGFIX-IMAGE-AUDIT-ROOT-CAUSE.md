# 图片审核不工作 - 根本原因修复

## 问题根源

**头像和封面上传不会触发 `User\Event\Saving` 事件！**

### 技术原因

Flarum 2.0 中，头像和封面上传使用**独立的 Command Handler**，不经过标准的 API Resource update 流程：

#### 1. 头像上传流程
```
前端上传头像
  ↓
POST /api/users/{id}/avatar
  ↓
UploadAvatarHandler::handle()
  ├─ 触发 AvatarSaving 事件 ✓
  ├─ 调用 AvatarUploader::upload($user, $image)
  ├─ 调用 $user->save()  ← 不触发 User\Event\Saving ✗
  └─ 调用 dispatchEventsFor($user, $actor)
```

#### 2. 封面上传流程
```
前端上传封面
  ↓
POST /api/sycho-profile-cover/covers (custom endpoint)
  ↓
UploadCoverHandler::handle()
  ├─ 触发 CoverSaving 事件 ✓
  ├─ 调用 CoverUploader::upload($user, $image)
  ├─ 调用 $user->save()  ← 不触发 User\Event\Saving ✗
  └─ 调用 dispatchEventsFor($user, $actor)
```

#### 3. 为什么 User\Event\Saving 不触发？

`User\Event\Saving` 只在以下情况触发：
- **API Resource 标准更新**：通过 `UserResource::saving()` 手动触发
- **不是** Eloquent 自动事件（Flarum 2.0 移除了 Eloquent 事件）

`UploadAvatarHandler` 和 `UploadCoverHandler` 直接调用 `$user->save()`，**不经过** `UserResource::saving()`，所以不触发该事件。

`dispatchEventsFor()` 只分发通过 `$user->raise()` 累积的事件（如 `AvatarChanged`），而不是 `Saving` 事件。

## 修复方案

### 修改的文件

[src/Listener/QueueContentAudit.php](src/Listener/QueueContentAudit.php)

### 修复内容

#### 1. 添加新的事件监听

```php
use Flarum\User\Event\AvatarSaving;  // ← 新增

public function subscribe(Dispatcher $events): void
{
    $events->listen(PostSaving::class, [$this, 'handlePostSaving']);
    $events->listen(DiscussionSaving::class, [$this, 'handleDiscussionSaving']);
    $events->listen(UserSaving::class, [$this, 'handleUserSaving']);
    $events->listen(AvatarSaving::class, [$this, 'handleAvatarSaving']);  // ← 新增
    
    // 动态监听封面事件（如果扩展已安装）
    if (class_exists('SychO\\ProfileCover\\Event\\CoverSaving')) {  // ← 新增
        $events->listen('SychO\\ProfileCover\\Event\\CoverSaving', [$this, 'handleCoverSaving']);
    }
}
```

#### 2. 新增头像审核处理器

```php
public function handleAvatarSaving(AvatarSaving $event): void
{
    $user = $event->user;
    $actor = $event->actor;

    // 检查权限
    if ($this->canBypassAudit($actor, 'user_profile')) {
        return;
    }

    // 在保存后获取完整 URL
    $user->afterSave(function ($user) {
        $avatarUrl = $user->getAttribute('avatar_url');  // 自动转换为完整 URL
        
        if (!$avatarUrl) return;

        $this->logger->info('[Queue Content Audit] Queueing avatar audit', [
            'user_id' => $user->id,
            'avatar_url' => $avatarUrl,
        ]);

        $this->queue->push(new AuditContentJob(
            'user_profile',
            null,
            $user->id,
            ['avatar_url' => $avatarUrl]
        ));
    });
}
```

#### 3. 新增封面审核处理器

```php
public function handleCoverSaving($event): void
{
    $user = $event->user;
    $actor = $event->actor;

    // 检查权限
    if ($this->canBypassAudit($actor, 'user_profile')) {
        return;
    }

    // 在保存后获取完整 URL
    $user->afterSave(function ($user) {
        $cover = $user->getAttribute('cover');  // 只是文件名
        
        if (!$cover) return;

        // 转换文件名为完整 URL
        $coverUrl = $cover;
        if (!str_contains($cover, '://')) {
            $filesystem = resolve(\Illuminate\Contracts\Filesystem\Factory::class);
            $coversDir = $filesystem->disk('sycho-profile-cover');
            $coverUrl = $coversDir->url($cover);  // 转换为完整 URL
        }

        $this->logger->info('[Queue Content Audit] Queueing cover audit', [
            'user_id' => $user->id,
            'cover_url' => $coverUrl,
        ]);

        $this->queue->push(new AuditContentJob(
            'user_profile',
            null,
            $user->id,
            ['cover' => $coverUrl]
        ));
    });
}
```

## 关键技术点

### 1. 事件监听对比

| 场景 | 触发的事件 | 之前的监听 | 现在的监听 |  
|-----|-----------|----------|----------|
| 通过 API 修改用户名 | User\Event\Saving | ✓ | ✓ |
| 通过 API 修改简介 | User\Event\Saving | ✓ | ✓ |
| 上传头像 | AvatarSaving | ✗ | **✓** |
| 上传封面 | CoverSaving | ✗ | **✓** |

### 2. afterSave 回调的必要性

```php
// 为什么需要 afterSave？

// 在 AvatarSaving 事件时间点：
$user->avatar_url  // "old_avatar.png" (旧值)

// UploadAvatarHandler 会执行：
$uploader->upload($user, $image);  // 设置新值
$user->save();  // 保存到数据库

// 在 afterSave 回调时间点：
$user->avatar_url  // "https://example.com/assets/avatars/new_avatar.png" (新值，含完整URL)
```

### 3. 动态类检查的原因

```php
// 为什么使用 class_exists？
if (class_exists('SychO\\ProfileCover\\Event\\CoverSaving')) {
    $events->listen(...);
}
```

**原因**：
- `sycho/flarum-profile-cover` 是可选扩展
- 如果未安装，直接 `use SychO\ProfileCover\Event\CoverSaving` 会导致类找不到错误
- 动态检查确保扩展未安装时不报错

## 验证步骤

### 1. 测试头像审核

```bash
# 1. 查看日志
tail -f flarum-instance/storage/logs/flarum-*.log | grep "Queue Content Audit"

# 2. 上传头像（通过 Flarum 前端）
# 3. 应该看到：
[Queue Content Audit] Queueing avatar audit
```

### 2. 测试封面审核

```bash
# 前提：已安装 sycho/flarum-profile-cover

# 1. 上传封面
# 2. 应该看到：
[Queue Content Audit] Queueing cover audit
```

### 3. 检查数据库

```sql
-- 查看审核日志
SELECT 
    id, 
    content_type, 
    user_id,
    JSON_EXTRACT(audited_content, '$.content') as content,
    confidence,
    result,
    created_at
FROM oaicontaudit_logs 
WHERE content_type = 'user_profile'
ORDER BY created_at DESC 
LIMIT 10;
```

### 4. 检查队列任务

```bash
# 查看队列状态
cd flarum-instance
php flarum queue:status

# 查看失败的任务
SELECT * FROM failed_jobs ORDER BY failed_at DESC LIMIT 5;
```

## Flarum 事件系统架构

### Eloquent 事件 vs Flarum 事件

```php
// Laravel/Eloquent 传统事件（Flarum 2.0 已移除）
class User extends Model
{
    protected $dispatchesEvents = [
        'saving' => UserSaving::class,  // ✗ Flarum 2.0 不支持
    ];
}

// Flarum 2.0 事件系统
class UserResource
{
    public function saving(object $model, Context $context): ?object
    {
        // 手动触发事件
        $this->events->dispatch(new Saving($model, ...));  // ✓
        return $model;
    }
}

class UploadAvatarHandler
{
    public function handle(UploadAvatar $command): User
    {
        // 手动触发特定事件
        $this->events->dispatch(new AvatarSaving($user, ...));  // ✓
        
        $this->uploader->upload($user, $image);
        $user->save();  // ← 不自动触发任何事件
        
        return $user;
    }
}
```

## 相关文件

- [src/Listener/QueueContentAudit.php](src/Listener/QueueContentAudit.php) - **已修改**
- [src/Service/ContentExtractor.php](src/Service/ContentExtractor.php) - 处理图片下载
- [src/Service/AuditResultHandler.php](src/Service/AuditResultHandler.php) - 处理审核结果

## Flarum 核心文件参考

- `vendor/flarum/core/src/User/Command/UploadAvatarHandler.php`
- `vendor/flarum/core/src/User/Event/AvatarSaving.php`
- `vendor/sycho/flarum-profile-cover/src/Command/UploadCoverHandler.php`
- `vendor/sycho/flarum-profile-cover/src/Event/CoverSaving.php`
- `vendor/flarum/core/src/Api/Resource/UserResource.php`
- `vendor/flarum/core/src/Foundation/DispatchEventsTrait.php`
