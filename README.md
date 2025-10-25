# Smart Alt Tag Optimizer

SEO-optimized WordPress plugin for automatic alt text generation with AI integration, server-side HTML injection, and WooCommerce support.

## Features

✅ **Automatic Alt Text Generation**
- Post title-based (fast, no API needed)
- AI-powered (GPT-4 Vision, custom endpoints)
- WooCommerce product support

✅ **Performance Optimized**
- Server-side output buffering (best for SEO)
- Smart caching (post-level HTML, 7-day TTL)
- Transient-based URL-to-attachment lookups
- Zero impact on TTFB (negligible overhead)

✅ **Enterprise Features**
- Bulk update with dry-run preview
- Scheduled daily/weekly processing
- Comprehensive logging & audit trail
- Revert functionality for all changes
- Multisite support

✅ **Security Hardened**
- Nonce-protected AJAX endpoints
- Proper capability checks (manage_options)
- Input sanitization & output escaping
- API key encryption (with env var support)
- No autoloaded bloat

## Installation

### Via Composer (Recommended)
```bash
composer require smartalt/smart-alt-tag-optimizer
cd smart-alt-tag-optimizer
composer install
```

Then activate in WordPress admin.

### Manual Installation

1. Download or clone to `wp-content/plugins/smart-alt-tag-optimizer`
2. Run `composer install` in plugin directory
3. Activate in WordPress plugins admin

## Configuration

### 1. Basic Setup

- Navigate to **Smart Alt** admin menu
- **General tab:**
  - Enable plugin
  - Choose alt source: Post Title (recommended for fast setup) or AI
  - Set max alt length (default 125 for SEO)

### 2. AI Integration (Optional)

If you want AI-generated alt text:

1. **Get API endpoint:**
   - OpenAI: `https://api.openai.com/v1/chat/completions`
   - Custom service: your endpoint URL

2. **Set credentials:**
   - **AI Configuration tab:**
     - Endpoint URL
     - HTTP method (POST recommended)
     - Request body template (JSON with placeholders)
     - Response JSON path (e.g., `choices[0].message.content`)
   
3. **Secure API key:**
   - **Option A (Recommended):** Add to `wp-config.php`:
```php
     define( 'SMARTALT_AI_KEY', 'your-api-key-here' );
```
   - **Option B:** Enter in Settings > AI Configuration (encrypted in database)

4. **Test connection:**
   - Click "Test Connection" button
   - Should return success message

### 3. Bulk Updates

- **Bulk Update tab:**
  - Set scope: All Media / Attached Only (recommended) / WooCommerce Only
  - Configure batch size (default 100)
  - Optional: Check "Force Update" to overwrite existing alts
  - Optional: Enable scheduled daily/weekly processing

### 4. Frontend Injection

- **General tab:**
  - Injection method: "Server-side Buffer" (recommended for SEO)
  - Plugin automatically injects missing alts on page render
  - Server-side = crawlers see alt immediately
  - JS fallback available (not recommended for SEO)

## Usage

### Manual Post Save
- Create/edit a post with images
- Publish or update
- Plugin automatically generates alt text based on settings
- View in Media library or Attachment settings

### Bulk Update Now
1. Go to **Bulk Update** tab
2. Click **"▶ Run Bulk Update Now"**
3. Monitor progress bar
4. Check **Logs & Stats** tab for results

### Dry Run Preview
1. Go to **Bulk Update** tab
2. Click **"👁 Dry Run (Preview)"**
3. See first 10 items that would be changed (no modifications)

### View Logs & Revert
1. **Logs & Stats** tab shows all changes
2. Statistics card displays: Total Logged, AI Generated, Errors, Last Run
3. Click **"Revert"** on any log entry to restore previous alt text

### Clear AI Cache
- **AI Configuration** tab > **"Clear AI Cache"** button
- Forces fresh AI generation for all attachments
- Useful after upgrading AI model

## API Integration Examples

### OpenAI GPT-4 Vision

**Request Template:**
```json
{
  "model": "gpt-4-vision-preview",
  "messages": [
    {
      "role": "user",
      "content": [
        {
          "type": "text",
          "text": "Generate a concise alt text (max {max_length} chars) for this image in context: {post_title}. Be specific and descriptive."
        },
        {
          "type": "image_url",
          "image_url": {
            "url": "{image_url}"
          }
        }
      ]
    }
  ],
  "max_tokens": 100
}
```

**Response Path:** `choices[0].message.content`

**Headers:**
```json
{
  "Authorization": "Bearer YOUR_API_KEY"
}
```

### Custom HTTP Endpoint

**Request Template:**
```json
{
  "image_url": "{image_url}",
  "prompt": "Describe this image for alt text",
  "context": "{post_title}",
  "max_length": {max_length}
}
```

**Response:**
```json
{
  "success": true,
  "data": {
    "alt_text": "Your generated alt text here"
  }
}
```

**Response Path:** `data.alt_text`

## Performance

### TTFB Impact
- **Without plugin:** baseline
- **With plugin (buffering disabled):** +0-2ms
- **With plugin (buffering enabled):** +5-10ms (cached)
- **With plugin (first visit, no cache):** +10-20ms

### Memory Usage
- Bulk update 500 items: ~4-8MB
- Single post save: ~1-2MB
- Frontend buffer: ~0.5MB

### Database
- Settings stored with `autoload=false` (no bloat)
- Logs table pruned automatically (retention days configurable)
- Optional transient-based caching

## Troubleshooting

### Alt text not generating

1. **Check plugin enabled:** Settings > General > "Enable Smart Alt Optimizer"
2. **Check alt source:** Settings > General > "Alt Text Source"
   - Post Title: uses post title
   - AI: requires endpoint + API key configured
3. **Check logs:** Logs & Stats tab > look for errors
4. **Check database:** `wp_postmeta` > `meta_key = '_wp_attachment_image_alt'`

### AI connector failing

1. **Test connection:** Settings > AI Configuration > "Test Connection"
2. **Check API key:** Verify key in environment or Settings
3. **Check endpoint URL:** Ensure URL is correct and HTTPS
4. **Check timeout:** Default 15 seconds - increase if API is slow
5. **Check response format:** Verify Response JSON Path matches API response

### Performance issues

1. **Disable buffering:** Settings > General > Injection Method = "Disabled"
2. **Reduce batch size:** Settings > Bulk Update > Batch Size = 50
3. **Check logs table:** May have grown large
   - Manually prune: `DELETE FROM wp_smartalt_logs WHERE time < DATE_SUB(NOW(), INTERVAL 7 DAY);`
4. **Clear caches:** Settings > AI Configuration > "Clear AI Cache"

### WooCommerce integration not working

1. **Verify WooCommerce active:** Check Plugins page
2. **Check scope:** Settings > Bulk Update > Scope = "WooCommerce Products Only"
3. **Manual trigger:** Edit product, click Save - should process immediately
4. **Check logs:** Look for WooCommerce-related entries

## Multisite Support

### Network Admin Setup
1. Activate plugin network-wide
2. Access **Smart Alt** on primary site
3. Configure as needed
4. Settings can be per-site or network-locked

### Per-Site Configuration
- Each site can have different alt source, injection method, etc.
- Logs include `site_id` for per-site tracking
- Bulk runs per-site only (unless admin selects all)

## Database Schema

### Logs Table: `wp_smartalt_logs`

| Column | Type | Description |
|--------|------|-------------|
| id | BIGINT | Log entry ID |
| site_id | INT | Multisite blog ID |
| time | DATETIME | Timestamp of change |
| attachment_id | BIGINT | Attachment post ID |
| post_id | BIGINT | Parent post ID |
| old_alt | LONGTEXT | Previous alt text |
| new_alt | LONGTEXT | New alt text |
| source | VARCHAR(50) | 'post_title', 'ai', 'manual', 'system' |
| model | VARCHAR(100) | AI model used (if applicable) |
| user_id | BIGINT | WordPress user ID |
| status | VARCHAR(20) | 'success', 'error', 'skipped' |
| log_level | VARCHAR(20) | 'info', 'error', 'debug' |
| message | LONGTEXT | Optional error/info message |

### Attachment Meta Keys

| Key | Value | Description |
|-----|-------|-------------|
| `_wp_attachment_image_alt` | string | Alt text (WordPress standard) |
| `_smartalt_ai_cached_at` | datetime | When AI alt was generated |
| `_smartalt_ai_model` | string | Which AI model was used |

## Hooks & Filters

### Filters

#### `smartalt_skip_buffering`
Skip output buffering on specific pages.
```php
add_filter( 'smartalt_skip_buffering', function( $skip ) {
    if ( is_page( 'no-buffer' ) ) {
        return true;
    }
    return $skip;
} );
```

#### `smartalt_ai_connector`
Use custom AI connector implementation.
```php
add_filter( 'smartalt_ai_connector', function( $connector, $type ) {
    if ( 'my_custom' === $type ) {
        return new MyCustomConnector();
    }
    return $connector;
}, 10, 2 );
```

### Actions

#### `smartalt_bulk_cron`
Triggered on scheduled bulk update.

#### `smartalt_prune_logs`
Triggered on log pruning.

## Development

### Testing

Run unit tests:
```bash
composer test
```

Run integration tests:
```bash
composer test:integration
```

### Code Standards

PSR-4 autoloading. Files in `src/` directory. Follow WordPress coding standards.
```bash
composer lint
```

### Building for Production

1. Update version in `smart-alt-tag-optimizer.php`
2. Update `CHANGELOG.md`
3. Run tests: `composer test`
4. Create release tag: `git tag v1.0.0`

## Contributing

Contributions welcome! Please:
1. Follow WordPress coding standards
2. Add tests for new features
3. Update documentation
4. Submit pull request

## License

GPL-2.0-or-later

## Support

- Documentation: See `docs/` folder
- Issues: GitHub issue tracker
- Security: Email security@smartalt.dev

---

## Changelog

### v1.0.0 (2025-01-15)
- Initial release
- Post title-based alt generation
- AI connector with circuit breaker
- Server-side output buffering
- WooCommerce integration
- Bulk update with dry-run
- Comprehensive logging & revert
- Multisite support

---