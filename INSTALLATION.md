# Installation and Testing Guide

## Quick Installation

### 1. Install the Extension

In your Flarum instance directory:

```bash
composer require ghostchu/openai-content-audit
```

### 2. Run Migrations

```bash
php flarum migrate
```

This will create the `oaicontaudit_logs` table in your database.

### 3. Clear Cache

```bash
php flarum cache:clear
```

### 4. Enable the Extension

Go to Admin Panel > Extensions and enable "OpenAI Content Audit".

## Configuration

### Basic Setup

1. Navigate to **Admin Panel > Extensions > OpenAI Content Audit**

2. **API Configuration:**
   - **API Endpoint**: `https://api.openai.com/v1` (or your compatible endpoint)
   - **API Key**: Your OpenAI API key (get from https://platform.openai.com/api-keys)
   - **Model**: `gpt-4o` or `gpt-4-turbo` (recommended for best results)
   - **Temperature**: `0.3` (lower = more consistent, higher = more creative)

3. **Audit Policy:**
   - **System Prompt**: Leave empty to use default, or customize
   - **Confidence Threshold**: `0.7` (70% confidence to take action)

4. **Behavior Settings:**
   - ☐ **Pre-Approval Mode**: Enable to hold content until audit completes
   - ☑ **Download Images**: Enable for avatar moderation
   - **Suspension Duration**: `7` days

5. Click **Submit** to save settings.

### Queue Configuration

The extension **requires** a queue worker to function. Add to your `config.php` if not already present:

```php
'queue' => [
    'default' => 'database',
    'connections' => [
        'database' => [
            'driver' => 'database',
            'table' => 'queue_jobs',
            'queue' => 'default',
            'retry_after' => 90,
        ],
    ],
],
```

### Running the Queue Worker

**Development/Testing:**

```bash
php flarum queue:work
```

**Production (with Supervisor):**

Create `/etc/supervisor/conf.d/flarum-queue.conf`:

```ini
[program:flarum-queue]
command=php /path/to/flarum/flarum queue:work --daemon --sleep=3 --tries=3
autostart=true
autorestart=true
user=www-data
numprocs=1
redirect_stderr=true
stdout_logfile=/path/to/flarum/storage/logs/queue.log
```

Then:

```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start flarum-queue
```

## Testing

### 1. Test API Connection

After configuration, the extension should log API calls. Check logs:

```bash
tail -f storage/logs/flarum.log | grep "OpenAI Content Audit"
```

### 2. Create Test Content

1. **Create a new discussion** with a title like "Hello World"
2. **Check queue jobs:**
   ```bash
   php flarum queue:work
   ```
3. **Check logs** to see audit results

### 3. Test Violations

Create content with violations (for testing only):

```
Title: Buy cheap products now! Click here!
Content: Hello everyone, visit https://spam-site.com for amazing deals!
```

If configured correctly:
- Content should be hidden/unapproved (if confidence ≥ 0.7)
- Check audit logs via API: `GET /api/audit-logs`

### 4. View Audit Logs

**Via API (using browser or Postman):**

```
GET /api/audit-logs
GET /api/audit-logs/{id}
```

**Via Database:**

```sql
SELECT * FROM oaicontaudit_logs ORDER BY created_at DESC LIMIT 10;
```

## Permissions Setup

Navigate to **Admin Panel > Permissions**:

### Recommended Permissions:

| Permission | Admin | Moderator | Member | Guest |
|------------|-------|-----------|--------|-------|
| View audit logs | ✓ | ✓ | ✗ | ✗ |
| View full audit logs | ✓ | ✗ | ✗ | ✗ |
| Retry failed audits | ✓ | ✓ | ✗ | ✗ |
| Bypass content audit | ✓ | ✓ | ✗ | ✗ |
| Bypass pre-approval | ✓ | ✓ | ✗ | ✗ |

**Note:** "Bypass content audit" is useful for trusted users to reduce API costs.

## Troubleshooting

### Extension Not Working

**Symptom:** Content not being audited.

**Solutions:**
1. Check queue worker is running: `ps aux | grep queue:work`
2. Verify API key: `curl -H "Authorization: Bearer YOUR_KEY" https://api.openai.com/v1/models`
3. Check logs: `tail -f storage/logs/flarum.log`
4. Verify migrations ran: `SELECT * FROM migrations WHERE migration LIKE '%audit%'`

### API Errors

**Symptom:** Logs show API errors.

**Common Issues:**
- **401 Unauthorized**: Invalid API key
- **429 Rate Limit**: Too many requests, add delays or upgrade plan
- **500 Server Error**: OpenAI API issues, check status.openai.com
- **Timeout**: Increase timeout in settings

### Queue Not Processing

**Symptom:** Jobs stuck in `queue_jobs` table.

**Solutions:**
1. Check for failed jobs: `SELECT * FROM queue_failed_jobs`
2. Restart queue worker
3. Check PHP memory limit (increase to 256M or higher)
4. Verify database connection

### High API Costs

**Symptom:** Unexpectedly high OpenAI bills.

**Solutions:**
1. Increase confidence threshold (e.g., 0.8 or 0.9)
2. Disable image downloads
3. Use cheaper model (e.g., `gpt-3.5-turbo`)
4. Enable "Bypass content audit" for trusted users
5. Limit content types audited (modify event listeners)

## Development Testing

### Run Unit Tests

```bash
composer test:unit
```

### Run Integration Tests

```bash
composer test:integration
```

### Build Frontend During Development

```bash
cd js
npm run dev  # Watch mode
```

### Clear All Caches

```bash
php flarum cache:clear
rm -rf storage/cache/*
rm -rf storage/views/*
```

## Monitoring

### Check Queue Status

```bash
# View pending jobs
php flarum queue:work --once

# Check failed jobs
SELECT * FROM queue_failed_jobs ORDER BY failed_at DESC;
```

### Monitor API Usage

Track audit logs to estimate costs:

```sql
SELECT 
    DATE(created_at) as date,
    status,
    COUNT(*) as count,
    AVG(confidence) as avg_confidence
FROM oaicontaudit_logs 
WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
GROUP BY DATE(created_at), status
ORDER BY date DESC;
```

### Performance

Check queue job processing time:

```sql
SELECT 
    AVG(TIMESTAMPDIFF(SECOND, created_at, updated_at)) as avg_processing_seconds
FROM oaicontaudit_logs 
WHERE status = 'completed'
AND created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY);
```

## Next Steps

1. **Customize System Prompt**: Tailor to your community's guidelines
2. **Set Up Monitoring**: Track API usage and costs
3. **Configure Permissions**: Grant appropriate access
4. **Test Thoroughly**: Create various test cases
5. **Train Moderators**: Explain how the system works

## Support

For issues or questions:
- GitHub Issues: https://github.com/ghostchu/openai-content-audit/issues
- Flarum Discuss: (Coming soon)

## Privacy Considerations

⚠️ **Important**: This extension sends user content to OpenAI. Ensure:
- Privacy policy disclosure
- User consent mechanisms
- GDPR compliance
- Data Processing Agreement with OpenAI

Consider adding a notice to your Terms of Service about AI-powered moderation.
