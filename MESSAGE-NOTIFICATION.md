# 私信通知功能使用说明

## 功能概述

当内容审核检测到违规并采取措施（隐藏、封禁等）后，系统会自动向用户发送私信，告知违规原因和相关信息。

## 前置条件

**必需**：安装 `flarum/messages` 扩展

```bash
composer require flarum/messages
php flarum migrate
php flarum cache:clear
```

## 配置步骤

### 1. 启用私信通知

在管理后台 → OpenAI Content Audit 设置中：

- **发送违规通知私信**：开启此选项
- **系统用户 ID**：设置用于发送私信的用户 ID（默认为 1，即管理员）
- **私信模板**：自定义发送给用户的消息内容（可选，留空使用默认模板）

### 2. 创建专用机器人账号（推荐）

为了更专业的体验，建议创建一个专门的系统账号：

1. 创建新用户（例如：系统通知、Content Moderator）
2. 记下该用户的 ID
3. 在设置中将"系统用户 ID"改为该用户的 ID

## 消息模板

### 默认模板

```
您好，

系统检测到您发布的内容（类型：{content_type}）可能违反了社区规范。

**违规原因：**
{violations}

**置信度：** {confidence}

您的内容已被自动隐藏，请修改后重新发布。如有疑问，请联系管理员。

感谢您的理解与配合。
```

### 可用占位符

- `{content_type}` - 内容类型（帖子回复、讨论主题、个人资料）
- `{violations}` - 违规原因列表（每行一条）
- `{confidence}` - LLM 的置信度百分比

### 自定义示例

**简洁版：**
```
您的{content_type}因以下reasons被系统标记：
{violations}

请修改后重新发布。
```

**详细版：**
```
尊敬的用户：

我们的 AI 内容审核系统检测到您发布的内容（{content_type}）可能存在以下问题：

{violations}

检测置信度：{confidence}

根据社区规范，相关内容已被自动处理。如果您认为这是误判，请联系管理团队复核。

——社区审核团队
```

**英文版：**
```
Hello,

Our AI moderation system has flagged your {content_type} for the following reasons:

{violations}

Confidence: {confidence}

Your content has been hidden. Please review and modify it before reposting.

If you believe this was a mistake, please contact the moderation team.

Thank you for your cooperation.
```

## 工作流程

```
1. 用户发布内容
   ↓
2. OpenAI 审核检测到违规
   ↓
3. 执行隐藏/封禁等操作
   ↓
4. 创建或查找与用户的对话
   ↓
5. 发送违规通知私信
   ↓
6. 用户在私信中收到通知
```

## 技术实现

### MessageNotifier 服务

新增的 `MessageNotifier` 服务位于 [src/Service/MessageNotifier.php](../src/Service/MessageNotifier.php)，主要功能：

- **sendViolationNotice()** - 发送违规通知
- **formatMessage()** - 格式化消息内容（替换占位符）
- **isEnabled()** - 检查功能是否启用

### 与 flarum/messages 的集成

```php
// 查找或创建对话
$dialog = Dialog::query()
    ->whereRelation('users', 'user_id', $sender->id)
    ->whereRelation('users', 'user_id', $user->id)
    ->where('type', 'direct')
    ->first();

if (!$dialog) {
    $dialog = new Dialog();
    $dialog->type = 'direct';
    $dialog->save();
    $dialog->users()->syncWithPivotValues([$sender->id, $user->id], ['joined_at' => Carbon::now()]);
}

// 创建消息
$message = new DialogMessage();
$message->dialog_id = $dialog->id;
$message->user_id = $sender->id;
$message->setContentAttribute($messageContent, $sender);
$message->save();

// 更新对话状态
$dialog->setLastMessage($message);
$dialog->save();

// 触发通知事件
$this->events->dispatch(new DialogMessage\Event\Posted($message));
```

### 在 AuditResultHandler 中的调用

位置：[src/Service/AuditResultHandler.php](../src/Service/AuditResultHandler.php#L131-L164)

```php
// 发送违规通知
if (!empty($executionLog['actions_executed'])) {
    $violations = $this->extractViolations($log);
    $systemUser = $this->getSystemUser();

    if ($systemUser) {
        $sent = $this->messageNotifier->sendViolationNotice(
            $user,
            $systemUser,
            $log->content_type,
            $violations,
            $confidence
        );

        $executionLog['message_sent'] = $sent;
    }
}
```

## 执行日志

发送结果会记录在审核日志的 `execution_log` 字段中：

```json
{
  "timestamp": "2026-02-21T10:30:00+00:00",
  "decision": "violated",
  "actions_executed": [
    {
      "action": "hide",
      "status": "success",
      "timestamp": "2026-02-21T10:30:01+00:00"
    }
  ],
  "message_sent": true
}
```

如果发送失败：

```json
{
  "message_sent": false,
  "message_error": "flarum/messages extension not found"
}
```

## 常见问题

### Q: 私信没有发送？

**检查清单：**
1. 确认 `flarum/messages` 已安装并启用
2. 检查"发送违规通知私信"选项已开启
3. 确认系统用户 ID 存在且有效
4. 查看日志：`storage/logs/flarum-*.log` 中搜索 `[Message Notifier]`

### Q: 如何禁用私信通知？

在管理后台关闭"发送违规通知私信"开关即可，其他审核功能不受影响。

### Q: 可以为不同违规类型设置不同模板吗？

当前版本使用统一模板，但可以通过占位符区分内容类型：

```
{% if content_type == '个人资料' %}
您的个人资料信息需要修改。
{% else %}
您的帖子内容需要调整。
{% endif %}
```

（注：当前不支持条件语法，需要自行实现或等待未来版本。）

### Q: 私信会触发新的审核吗？

**不会**。私信由系统用户发送，系统用户通常具有 `bypassAudit` 权限，不会触发审核。

### Q: 能否发送邮件而不是私信？

当前仅支持私信通知。如需邮件通知，可以：
1. 使用 Flarum 的邮件订阅功能（用户订阅私信时会收到邮件）
2. 或等待未来版本支持直接邮件通知

## 日志示例

**成功发送：**
```
[2026-02-21 10:30:01] flarum.INFO: [Message Notifier] Violation notice sent {"user_id":5,"content_type":"post","message_id":123}
```

**功能禁用：**
```
[2026-02-21 10:30:01] flarum.DEBUG: [Message Notifier] Message notification is disabled
```

**扩展未安装：**
```
[2026-02-21 10:30:01] flarum.WARNING: [Message Notifier] flarum/messages extension not found
```

**发送失败：**
```
[2026-02-21 10:30:01] flarum.ERROR: [Message Notifier] Failed to send message {"user_id":5,"error":"User not found"}
```

## 相关文件

- [src/Service/MessageNotifier.php](../src/Service/MessageNotifier.php) - 私信服务
- [src/Service/AuditResultHandler.php](../src/Service/AuditResultHandler.php) - 审核结果处理器
- [src/Provider/AuditServiceProvider.php](../src/Provider/AuditServiceProvider.php) - 服务提供者
- [js/src/admin/index.ts](../js/src/admin/index.ts) - Admin UI 设置
- [locale/zh-Hans.yml](../locale/zh-Hans.yml) - 中文翻译
- [locale/en.yml](../locale/en.yml) - 英文翻译

## 数据库查询

**查看最近发送的通知：**
```sql
SELECT 
    al.id,
    al.user_id,
    al.content_type,
    al.confidence,
    al.result,
    JSON_EXTRACT(al.execution_log, '$.message_sent') as message_sent,
    al.created_at
FROM oaicontaudit_logs al
WHERE JSON_EXTRACT(al.execution_log, '$.decision') = 'violated'
ORDER BY created_at DESC
LIMIT 20;
```

**统计私信发送成功率：**
```sql
SELECT 
    COUNT(*) as total_violations,
    SUM(CASE WHEN JSON_EXTRACT(execution_log, '$.message_sent') = true THEN 1 ELSE 0 END) as messages_sent,
    ROUND(SUM(CASE WHEN JSON_EXTRACT(execution_log, '$.message_sent') = true THEN 1 ELSE 0 END) * 100.0 / COUNT(*), 2) as success_rate
FROM oaicontaudit_logs
WHERE JSON_EXTRACT(execution_log, '$.decision') = 'violated';
```
