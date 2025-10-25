# Changelog

All notable changes to Smart Alt Tag Optimizer will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.0] - 2025-01-15

### Added
- Initial public release
- Automatic alt text generation from post titles
- AI-powered alt text via configurable HTTP endpoint
- Generic HTTP AI connector with retry logic and circuit breaker
- Server-side output buffering with smart caching for SEO
- Post-level HTML caching (7-day TTL, content-hash verified)
- Attachment URL-to-ID caching to reduce `attachment_url_to_postid()` calls
- Frontend injection method selector (server-side buffer or JS fallback)
- WooCommerce product image support (featured, gallery, inline)
- WooCommerce deduplication to prevent triple-processing
- Bulk update with dry-run preview mode
- Scheduled bulk processing (daily/weekly) via WP-Cron
- Comprehensive audit logging with statistics
- Log revert functionality (restore previous alt text)
- Log pruning with configurable retention (7-365 days)
- Log level filtering (info, error, debug)
- Admin dashboard widget showing alt coverage %
- Multi-tab admin settings page
- Settings stored with `autoload=false` (no bloat)
- API key encryption with environment variable support (`SMARTALT_AI_KEY`)
- Multisite support with per-site and network-wide options
- Full nonce & capability verification on AJAX endpoints
- Input sanitization & output escaping throughout
- Transient-based session tracking for bulk jobs
- Unit tests for core utilities (Sanitize, AttachmentHandler)
- Integration tests for PostProcessor and Frontend Injector
- PHPUnit configuration with test bootstrap
- Comprehensive manual QA testing guide
- Production-ready documentation and README
- PSR-4 autoloading with Composer
- Zero external dependencies

### Performance
- TTFB impact: +0ms without buffering, +5-10ms with buffering (cached)
- Bulk update 500 items: ~30-60 seconds
- Memory usage: 4-8MB per 100-item batch
- Output buffering only on public posts with images (smart skip)
- Cached URL-to-attachment lookups (24-hour transient)
- Post-level HTML injection cache (7-day transient, content-hashed)

### Security
- Nonce-protected all AJAX endpoints
- Capability checks (manage_options default)
- Input sanitization via WordPress core functions
- Output escaping for HTML contexts
- API key stored encrypted or via environment variable
- SQL injection prevention via `$wpdb->prepare()`
- XSS protection throughout

### SEO Benefits
- Alt text served server-side (crawlers see immediately)
- No render-blocking; fast TTFB
- 125-character default (best practice)
- Consistent alt text across crawl cycles
- Fallback to post title if AI fails

---

## [Unreleased]

### Planned Features
- REST API endpoint for programmatic alt text queries
- Batch alt text import from CSV
- Integration with image recognition APIs (AWS Rekognition, Google Vision)
- Social media alt text optimization
- Automatic alt text translation (WPML integration)
- Advanced reporting dashboard with trends
- Webhook notifications on bulk completion

---
```

---

### File 27: `.gitignore` – Git Ignore Rules
```
# Composer
vendor/
composer.lock

# WordPress
wp-content/plugins/*/
wp-content/themes/*/
wp-config.php
wp-config-sample.php
*.sql
*.sql.gz

# IDE
.vscode/
.idea/
*.swp
*.swo
*~
.DS_Store

# Environment
.env
.env.local
.env.*.local

# Testing
coverage/
.phpunit.result.cache
phpunit.xml

# Logs
*.log
logs/

# OS
Thumbs.db
.DS_Store

# Node (if using build tools)
node_modules/
package-lock.json
yarn.lock