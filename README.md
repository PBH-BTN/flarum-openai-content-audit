# OpenAI Content Audit

![License](https://img.shields.io/badge/license-MIT-blue.svg) [![Latest Stable Version](https://img.shields.io/packagist/v/ghostchu/openai-content-audit.svg)](https://packagist.org/packages/ghostchu/openai-content-audit) [![Total Downloads](https://img.shields.io/packagist/dt/ghostchu/openai-content-audit.svg)](https://packagist.org/packages/ghostchu/openai-content-audit)

A [Flarum](https://flarum.org) 2.0 extension that automatically moderates user-generated content using OpenAI-compatible LLM APIs. The extension audits posts, discussions, user profiles, avatars, profile covers, and more through an AI model, taking automated actions based on confidence levels.

## Features

- 🤖 **AI-Powered Moderation**: Uses OpenAI or compatible LLM providers to analyze content
- 🔍 **Comprehensive Auditing**: Monitors posts, discussions, usernames, nicknames, bios, avatars, and profile covers
- ⚡ **Asynchronous Processing**: Queue-based system prevents blocking user actions
- 🎯 **Configurable Actions**: Automatically hide/unapprove content or suspend users based on violations
- 🖼️ **Vision Support**: Optional image download for avatar and cover image moderation with GPT-4 Vision
- 🛡️ **Pre-Approval Mode**: Hold new content until AI audit completes
- 📊 **Full Audit Logs**: Track all moderation decisions with confidence scores
- 🔐 **Permission System**: Granular permissions for bypassing audits and viewing logs
- 🔄 **Retry Logic**: Automatic retry with exponential backoff for failed API calls
- 🌍 **Database Agnostic**: Works with MySQL, PostgreSQL, and SQLite

## Requirements

- Flarum 2.0 or higher
- PHP 8.1 or higher
- OpenAI API key or compatible provider (e.g., Azure OpenAI, local LLM endpoints)
- Queue worker configured (database driver or Redis)

### Required Extensions

- `flarum/approval` - For content approval queue
- `flarum/suspend` - For user suspension

### Optional Extensions

- `fof/user-bio` - For bio field auditing
- `flarum/nicknames` - For nickname auditing

## Installation

Install with composer:

```sh
composer require ghostchu/openai-content-audit
php flarum migrate
php flarum cache:clear
```

## Configuration

### 1. Basic Setup

Navigate to **Admin Panel > Extensions > OpenAI Content Audit** to configure:

#### API Configuration
- **API Endpoint**: Base URL for your OpenAI-compatible API (default: `https://api.openai.com/v1`)
- **API Key**: Your API key (required)
- **Model**: Model name (e.g., `gpt-4o`, `gpt-4-turbo`)
- **Temperature**: Randomness control (0.0-2.0, recommended: 0.3)

#### Audit Policy
- **System Prompt**: Custom instructions for the LLM (leave empty for default)
  - Must request JSON response with `confidence`, `actions`, and `conclusion` fields
  - Available actions: `none`, `hide`, `suspend`
- **Confidence Threshold**: Minimum confidence (0.0-1.0) to take action (recommended: 0.7)

#### Behavior Settings
- **Pre-Approval Mode**: Require audit before content becomes visible
- **Download Images**: Download avatars and cover images for analysis (requires Vision API)
- **Suspension Duration**: Days to suspend users (default: 7)

#### Default Values
- **Default Display Name**: Replacement for violating display names
- **Default Bio**: Replacement for violating bio content

### 2. Queue Setup

The extension requires a working queue system. Configure in `config.php`:

```php
// Database queue (default)
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

**Run the queue worker:**

```sh
php flarum queue:work --daemon
```

For production, use a process manager like Supervisor:

```ini
[program:flarum-queue]
command=php /path/to/flarum/flarum queue:work --daemon --sleep=3 --tries=3
autostart=true
autorestart=true
user=www-data
redirect_stderr=true
stdout_logfile=/path/to/flarum/storage/logs/queue.log
```

### 3. Permissions

Configure permissions in **Admin Panel > Permissions**:

- **View audit logs**: See basic audit logs (moderate permission group)
- **View full audit logs**: See API requests/responses (admin recommended)
- **Retry failed audits**: Manually retry failed audits
- **Bypass content audit**: Skip AI moderation entirely
- **Bypass pre-approval**: Skip pre-approval mode even when enabled

### 4. System Prompt Example

A good system prompt ensures consistent moderation:

```
You are a content moderation assistant for an online community forum. Analyze user-generated content and determine if it violates community guidelines.

Consider:
- Hate speech, harassment, or discrimination
- Spam or promotional content
- Inappropriate sexual content
- Violence or threats
- Personal information disclosure
- Misinformation or harmful content

Respond ONLY with valid JSON:
{
  "confidence": 0.85,
  "actions": ["hide"],
  "conclusion": "Brief explanation"
}

Fields:
- confidence: 0.0-1.0 indicating violation certainty
- actions: Array with "hide", "suspend", or "none"
- conclusion: 1-2 sentence explanation

Be strict but fair. Err on caution for borderline cases.
```

## How It Works

1. **Content Creation/Edit**: User creates or edits content (post, discussion, profile)
2. **Event Trigger**: Extension listens to Flarum events (`Post\Event\Saving`, etc.)
3. **Pre-Approval** (if enabled): Content marked as unapproved immediately
4. **Queue Job**: Audit job dispatched to queue (non-blocking)
5. **Content Extraction**: Job extracts content and builds context (e.g., discussion title for post replies)
6. **API Call**: Sends content to LLM with system prompt
7. **Response Parsing**: Validates JSON response structure
8. **Log Creation**: Stores audit log with confidence, actions, and conclusion
9. **Action Execution**: If confidence ≥ threshold:
   - **Hide**: Set `is_approved = false` on content, or revert profile fields to defaults
   - **Suspend**: Set `suspended_until` on user
10. **Retry Logic**: Failed audits retry with exponential backoff (1min, 5min, 15min)

## Audit Logs

Access audit logs via API:

```
GET /api/audit-logs
GET /api/audit-logs/{id}
POST /api/audit-logs/{id}/retry
```

Filters: `contentType`, `status`, `userId`, `minConfidence`

## Troubleshooting

### Queue jobs not processing
- Ensure queue worker is running: `php flarum queue:work`
- Check logs: `storage/logs/flarum.log`
- Verify database `queue_jobs` table exists

### API errors
- Verify API key is correct
- Check endpoint URL (must end with `/v1` for OpenAI)
- Test with: `curl -H "Authorization: Bearer YOUR_KEY" https://api.openai.com/v1/models`
- Review logs for detailed error messages

### Content not being audited
- Check permissions: user might have "Bypass content audit"
- Verify extension is enabled
- Ensure queue worker is running
- Check audit logs for pending/failed entries

### High API costs
- Increase confidence threshold to reduce actions
- Disable image downloads if not needed
- Use cheaper models (e.g., `gpt-3.5-turbo`)
- Implement rate limiting externally

## Development

### Running Tests

```sh
composer test:unit
composer test:integration
```

### Building Frontend

```sh
cd js
npm install
npm run build
```

## Database Schema

The extension adds one table with prefix `oaicontaudit_`:

- `oaicontaudit_logs`: Stores all audit records with API requests/responses

## Privacy & GDPR

⚠️ **Important**: This extension sends user content to external AI providers. Ensure compliance with:
- User consent for AI processing
- Privacy policy disclosure
- Data Processing Agreements (DPA) with API provider
- GDPR Article 22 (automated decision-making)

Consider:
- Adding consent checkbox during registration
- Providing opt-out for trusted users
- Regular audit log cleanup
- Anonymizing logs for long-term storage

## Links

- [Packagist](https://packagist.org/packages/ghostchu/openai-content-audit)
- [GitHub](https://github.com/ghostchu/openai-content-audit)
- [OpenAI API Documentation](https://platform.openai.com/docs/api-reference)

## License

MIT License. See [LICENSE.md](LICENSE.md) for details.

## Support

For issues, questions, or feature requests, please use GitHub Issues.

