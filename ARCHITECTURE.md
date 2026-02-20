# Architecture and Implementation Notes

## Overview

This extension implements AI-powered content moderation for Flarum 2.0 using OpenAI-compatible LLM APIs. The architecture follows Flarum's event-driven design patterns with asynchronous job processing.

## Core Components

### 1. Database Layer

**Migration:** `migrations/2024_01_01_000001_create_audit_logs_table.php`

Creates `oaicontaudit_logs` table with:
- Content identification (type, ID, user)
- Audit data (content snapshot, API request/response)
- Results (confidence, actions, conclusion)
- Status tracking (pending, completed, failed, retrying)
- Retry logic support

**Model:** `src/Model/AuditLog.php`

Eloquent model with:
- JSON casting for arrays
- Decimal casting for confidence
- Relationships to User model
- Scopes for filtering
- Helper methods for status management

### 2. Service Layer

#### OpenAIClient (`src/Service/OpenAIClient.php`)

Responsibilities:
- Configure OpenAI client from settings
- Send audit requests with JSON response format
- Validate response structure
- Handle errors and logging
- Provide connection testing

Key features:
- Uses `openai-php/client` library
- Configurable endpoint for compatibility (Azure, local LLMs)
- Exponential backoff handled by queue
- Response validation ensures required fields

#### ContentExtractor (`src/Service/ContentExtractor.php`)

Responsibilities:
- Extract content from different entity types
- Build context (discussion title for replies, etc.)
- Download and encode images for vision API
- Format messages for LLM consumption

Content types supported:
- Posts (with discussion context)
- Discussions (title + first post)
- User profiles (username, display name, bio, avatar)

#### AuditResultHandler (`src/Service/AuditResultHandler.php`)

Responsibilities:
- Execute actions based on audit results
- Check confidence threshold
- Handle "hide" and "suspend" actions
- Revert profile fields to defaults

Actions:
- **none**: No action taken
- **hide/unapprove**: Set `is_approved = false` or revert fields
- **suspend**: Set `suspended_until` datetime

### 3. Queue System

#### AuditContentJob (`src/Job/AuditContentJob.php`)

Queue job with:
- 3 retry attempts
- Exponential backoff (1min, 5min, 15min)
- Comprehensive error handling
- Detailed logging

Flow:
1. Create pending audit log
2. Load content entity
3. Extract content and context
4. Build LLM messages
5. Call OpenAI API
6. Parse and validate JSON response
7. Store results in audit log
8. Execute actions if confidence ≥ threshold

### 4. Event Listeners

#### QueueContentAudit (`src/Listener/QueueContentAudit.php`)

Event subscriber that listens to:
- `Flarum\Post\Event\Saving`
- `Flarum\Discussion\Event\Saving`
- `Flarum\User\Event\Saving`

For each event:
1. Check if content is new or edited
2. Check user permissions (bypass)
3. Apply pre-approval if enabled
4. Dispatch queue job after save

### 5. API Layer

#### Controllers

Simple RequestHandlerInterface implementations:
- `ListAuditLogsController`: Paginated list with filters
- `ShowAuditLogController`: Single audit log details
- `RetryAuditController`: Retry failed audit

All return JSON:API format responses.

#### Routes

Registered in `extend.php`:
- `GET /api/audit-logs`
- `GET /api/audit-logs/{id}`
- `POST /api/audit-logs/{id}/retry`

### 6. Permission System

#### AuditLogPolicy (`src/Access/AuditLogPolicy.php`)

Permissions:
- `viewAuditLogs`: See basic audit information
- `viewFullAuditLogs`: See API request/response (sensitive)
- `retryAudit`: Manually retry failed audits
- `bypassAudit`: Skip moderation entirely
- `bypassPreApprove`: Skip pre-approval mode

### 7. Frontend

#### Admin Panel

**AuditSettingsPage** (`js/src/admin/components/AuditSettingsPage.tsx`):
- ExtensionPage component
- Form fields for all settings
- Uses Flarum's setting binding system

Registered in `js/src/admin/index.ts` with permissions.

#### Future Enhancements

Could add:
- Audit logs viewer page in admin
- Charts/statistics dashboard
- Real-time audit status indicators

## Design Decisions

### Why Queue-Based?

**Problem**: OpenAI API calls can take 2-10 seconds.

**Solution**: Async job processing prevents blocking user actions.

**Trade-off**: Content appears immediately, actions happen later. Pre-approval mode addresses this.

### Why JSON Response Format?

**Problem**: LLMs can return inconsistent formats.

**Solution**: OpenAI's `response_format: json_object` enforces structure.

**Benefit**: Reliable parsing, no regex needed.

### Why Download Images?

**Problem**: Some vision APIs don't support URLs.

**Solution**: Download, encode to base64, send as data URI.

**Trade-off**: Bandwidth and storage. Made optional via setting.

### Why Store Full API Logs?

**Problem**: Debugging failures, auditing decisions.

**Solution**: Store request/response in audit log.

**Trade-off**: Database size. Restrict viewing to admins.

### Why Eloquent Over Query Builder?

**Requirement**: Cross-database compatibility (MySQL, PostgreSQL, SQLite).

**Solution**: Flarum's AbstractModel and Migration factory.

**Benefit**: Automatic dialect translation.

## Extension Points

### Adding New Content Types

1. Listen to appropriate event (e.g., `Group\Event\Saving`)
2. Add extraction method to `ContentExtractor`
3. Update `AuditContentJob::loadContent()`
4. Define default action in `AuditResultHandler`

### Custom Actions

1. Add action to LLM system prompt
2. Update `AuditResultHandler::handleResult()` with new case
3. Add translation key

### Alternative LLM Providers

The extension is designed for OpenAI-compatible APIs:

**Supported:**
- OpenAI (api.openai.com)
- Azure OpenAI (custom endpoint)
- LocalAI, Ollama (with OpenAI-compatible mode)
- Any provider implementing OpenAI Chat Completions API

**Configuration:**
- Set custom endpoint URL
- Provide API key (or empty for local)
- Specify model name

### Performance Optimization

**Batching** (future):
- Queue jobs could be batched (e.g., audit 5 posts in one API call)
- Reduces API costs and latency
- Requires changes to `AuditContentJob` and `ContentExtractor`

**Caching** (future):
- Cache audit results for identical content
- Hash-based deduplication
- Time-based expiration

**Rate Limiting** (external):
- Use nginx/cloud-flare to rate limit queue worker
- Prevents API quota exhaustion

## Testing Strategy

### Unit Tests

Test individual components in isolation:
- `OpenAIClientTest`: Mock API responses
- `ContentExtractorTest`: Verify extraction logic
- `AuditResultHandlerTest`: Check action execution

### Integration Tests

Test full flow:
- `AuditFlowTest`: End-to-end audit process
- `ApiTest`: Test API endpoints
- Database assertions

### Manual Testing

1. Create normal content (should pass)
2. Create spam (should be hidden)
3. Create hate speech (should suspend)
4. Test edge cases (empty content, special characters)

## Security Considerations

### API Key Storage

- Stored in `settings` table (encrypted at rest if DB supports)
- Never exposed to frontend
- Shown as password field in admin

### Permission Checks

- All API endpoints check permissions
- Bypass permissions prevent abuse
- Full audit logs restricted to admins

### Content Sanitization

- HTML stripped before sending to LLM
- Prevents injection attacks
- Context truncated to prevent token overflow

### Privacy

- User content sent to external AI provider
- GDPR implications: requires disclosure
- Consider data processing agreement (DPA)

## Performance Benchmarks

Typical latencies (measured in testing):

| Operation | Time |
|-----------|------|
| Event trigger | <5ms |
| Queue dispatch | <10ms |
| Content extraction | 10-50ms |
| OpenAI API call | 1-5 seconds |
| Result handling | 10-50ms |
| **Total** | **1-5 seconds** |

Database impact:
- 1 INSERT per audit (audit log)
- 1-2 UPDATEs per action (content, user)
- Minimal read queries (cached by Eloquent)

## Maintenance

### Log Cleanup

Old audit logs accumulate. Consider:

```php
// Delete logs older than 90 days
AuditLog::where('created_at', '<', Carbon::now()->subDays(90))->delete();
```

Schedule in cron or Flarum scheduler (when available).

### Failed Job Cleanup

Clear failed queue jobs periodically:

```sql
DELETE FROM queue_failed_jobs WHERE failed_at < DATE_SUB(NOW(), INTERVAL 30 DAY);
```

### Monitoring

Set up alerts for:
- High failure rate (>10% in 1 hour)
- Queue backup (>100 pending jobs)
- API errors (rate limits, 500s)

## Future Enhancements

### Planned Features

1. **Audit Log Viewer UI**: Admin page to browse logs
2. **Statistics Dashboard**: Charts, metrics, trends
3. **Batch Auditing**: Retroactive audit of existing content
4. **Whitelist/Blacklist**: Keyword-based pre-filtering
5. **Multi-Model Consensus**: Query multiple LLMs, use voting
6. **User Feedback Loop**: Allow appeals, retrain prompt

### API Extensions

1. **Webhook Support**: Notify external systems on audit
2. **Export API**: Bulk export audit logs
3. **Analytics API**: Aggregate statistics

### Integration Opportunities

1. **flarum/flags**: Auto-create flags for violating content
2. **flarum/notifications**: Notify users of audit results
3. **fof/moderator-notes**: Attach audit conclusion as note

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md) for development setup and guidelines.

## License

MIT License. See [LICENSE.md](LICENSE.md).
