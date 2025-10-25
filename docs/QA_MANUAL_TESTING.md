# Smart Alt Tag Optimizer - QA Manual Testing Guide

## Prerequisites
- WordPress 5.9+ installed locally or staging server
- WooCommerce (optional, for WooCommerce tests)
- At least 5 test posts with images
- Admin access

## Test Scenarios

### 1. General Settings & Admin Page

**Test 1.1: Access Settings Page**
- [ ] Navigate to WordPress admin > Smart Alt menu
- [ ] Verify all tabs load: General, AI Configuration, Bulk Update, Logs & Stats
- [ ] Verify dashboard widget appears on WordPress dashboard

**Test 1.2: Save General Settings**
- [ ] Enable/disable plugin
- [ ] Toggle alt source: Post Title vs AI
- [ ] Change injection method
- [ ] Modify max alt length (test with 50, 125, 500)
- [ ] Save and refresh page - verify values persist

**Test 1.3: AI Configuration**
- [ ] Fill in test endpoint: `https://api.openai.com/v1/chat/completions`
- [ ] Set HTTP method: POST
- [ ] Add sample headers JSON
- [ ] Fill request template with placeholders
- [ ] Set response JSON path
- [ ] Verify "Test Connection" button response (if API configured)
- [ ] Test "Clear AI Cache" button

### 2. Post Saving & Alt Generation

**Test 2.1: Post Save with Attached Image (Post Title)**
- [ ] Create a new post titled "Beautiful Garden Scene"
- [ ] Upload an image via featured image
- [ ] Publish post
- [ ] Edit attachment > Media settings
- [ ] Verify alt text automatically set to "Beautiful Garden Scene" (or variation)
- [ ] Verify in database: `wp_postmeta` key `_wp_attachment_image_alt`

**Test 2.2: Post Save with Inline Images**
- [ ] Create a post with body HTML content: `<img src="test.jpg" />`
- [ ] Insert an image into media library
- [ ] Update post
- [ ] Check logs: verify inline image alt was processed

**Test 2.3: Skip Autosave**
- [ ] Open a post in editor
- [ ] Wait for autosave (5 seconds)
- [ ] Check logs: verify no alt processing logged for autosaves
- [ ] Manually save post: verify alt processing occurs

**Test 2.4: Multiple Images per Post**
- [ ] Create post with 3 images (featured + gallery)
- [ ] Publish
- [ ] Verify all 3 attachments have alt text set

### 3. Bulk Update Operations

**Test 3.1: Dry Run Preview**
- [ ] Navigate to Bulk Update tab
- [ ] Click "Dry Run (Preview)"
- [ ] Verify preview shows first 10 items with "Would change" indicators
- [ ] No actual data modified

**Test 3.2: Bulk Update Run**
- [ ] Set scope: "Attached to Posts"
- [ ] Set batch size: 50
- [ ] Uncheck "Force Update"
- [ ] Click "Run Bulk Update Now"
- [ ] Monitor progress bar
- [ ] Verify completion message
- [ ] Check database for new alt texts
- [ ] Verify logs recorded

**Test 3.3: Force Update**
- [ ] Set "Force Update Existing Alt Text" checkbox
- [ ] Run bulk update
- [ ] Verify existing alts are overwritten
- [ ] Check logs show updates on previously-set alts

**Test 3.4: Different Scopes**
- [ ] Test scope: "All Media Library"
- [ ] Test scope: "Attached Only"
- [ ] Test scope: "WooCommerce Products Only" (if WooCommerce active)
- [ ] Verify correct items processed in each

### 4. Frontend Output Buffering (SEO)

**Test 4.1: Server-Side Injection**
- [ ] Set injection method to "Server-side Buffer"
- [ ] Create post with image missing alt
- [ ] View post on frontend
- [ ] Inspect HTML source: verify `alt="..."` present in `<img>` tags
- [ ] Check that alt matches configured source
- [ ] Verify no performance degradation (TTFB ~same as before)

**Test 4.2: Caching**
- [ ] View post 3 times
- [ ] Check database query count: should be similar (cached)
- [ ] Modify post content
- [ ] Clear cache (Settings > Clear HTML Cache)
- [ ] Verify cache is regenerated

**Test 4.3: Skip Buffering Conditions**
- [ ] Set injection method to "Server-side Buffer"
- [ ] Access RSS feed: should NOT buffer
- [ ] Access post preview: should buffer (optional, depends on settings)
- [ ] Access category/search page: should NOT buffer (not singular)

### 5. WooCommerce Integration (If Installed)

**Test 5.1: Product Featured Image**
- [ ] Create WooCommerce product "Blue Shirt"
- [ ] Upload featured image
- [ ] Publish product
- [ ] Verify featured image alt = "Blue Shirt" (or product name)

**Test 5.2: Product Gallery**
- [ ] Add 2 images to product gallery
- [ ] Publish/update
- [ ] Verify both gallery images have alt set to product name
- [ ] Check no duplicate processing (deduplication working)

**Test 5.3: Product Description HTML**
- [ ] Add inline image to product description HTML
- [ ] Save product
- [ ] Verify inline image alt processed

### 6. Logging & Audit Trail

**Test 6.1: View Logs**
- [ ] Navigate to Logs & Stats tab
- [ ] Verify statistics card shows: Total Logged, AI Generated, Errors, Last Run
- [ ] View log table: columns include Time, Attachment, Source, Old Alt, New Alt, Status

**Test 6.2: Revert Entry**
- [ ] Find a successful log entry with "old_alt" populated
- [ ] Click "Revert" button
- [ ] Verify attachment alt reverted to old value
- [ ] New log entry created with status "reverted"

**Test 6.3: Log Pruning**
- [ ] Set log retention to 7 days
- [ ] Manually run pruning (if button available) or wait for cron
- [ ] Verify old logs deleted
- [ ] Recent logs retained

**Test 6.4: Log Level Filtering**
- [ ] Set log level to "errors only"
- [ ] Run post save: should not log success
- [ ] Trigger error (disconnect AI): should log
- [ ] Verify logs list shows only errors

### 7. Security Tests

**Test 7.1: Nonce Verification**
- [ ] Attempt AJAX request without nonce: should fail
- [ ] Attempt bulk update as non-admin user: should fail
- [ ] Verify capability checks enforced

**Test 7.2: Sanitization**
- [ ] Enter malicious alt text in settings (e.g., `<script>alert(1)</script>`)
- [ ] Save and view: should be sanitized (no script tags)
- [ ] Check database: raw value should not contain code

**Test 7.3: API Key Security**
- [ ] Set API key via environment variable `SMARTALT_AI_KEY`
- [ ] Verify settings page shows "Using environment variable"
- [ ] Verify key never exposed in HTML or logs
- [ ] Export database: ensure key is encrypted or not present

### 8. Performance Tests

**Test 8.1: TTFB Impact**
- [ ] Disable plugin, measure TTFB on typical post: baseline
- [ ] Enable plugin with server-side buffering, measure TTFB: should be ≤5ms slower
- [ ] Disable buffering: should be near-zero impact

**Test 8.2: Database Queries**
- [ ] With buffering enabled, view post: count queries
- [ ] View same post again: should use cache (fewer queries)
- [ ] Modify post: cache invalidated, new queries

**Test 8.3: Memory Usage**
- [ ] Monitor PHP memory on bulk update with 500 items
- [ ] Should not exceed 64MB allocated
- [ ] Batch processing should keep memory stable

### 9. Multisite Tests (If Applicable)

**Test 9.1: Per-Site Settings**
- [ ] Set different alt source per site (Site A: Post Title, Site B: AI)
- [ ] Create posts on each site
- [ ] Verify settings respected per-site

**Test 9.2: Network Settings**
- [ ] (Optional) Set network-wide option lock
- [ ] Verify per-site admins cannot override

**Test 9.3: Bulk Run Per-Site**
- [ ] Run bulk on Site A only
- [ ] Verify Site B unaffected
- [ ] Check logs: include site_id

### 10. Error Handling

**Test 10.1: AI API Timeout**
- [ ] Set AI endpoint to unreachable URL
- [ ] Try to generate alt via AI
- [ ] Should fallback to post_title gracefully
- [ ] Should log error

**Test 10.2: Malformed JSON Response**
- [ ] Mock AI endpoint returning invalid JSON
- [ ] Process post
- [ ] Should handle gracefully, log error
- [ ] Post should not fail

**Test 10.3: Missing Configuration**
- [ ] Delete all settings
- [ ] Try to use plugin
- [ ] Should use sensible defaults or disable gracefully

---

## Performance Baselines

- **TTFB without plugin:** ~100ms (baseline)
- **TTFB with plugin (buffering disabled):** ~100-102ms (negligible)
- **TTFB with plugin (buffering enabled):** ~105-110ms (acceptable)
- **Bulk update 500 items:** ~30-60 seconds (batched)
- **Memory per batch (100 items):** ~4-8MB

## Checklist Before Release

- [ ] All unit tests pass
- [ ] All integration tests pass
- [ ] Manual QA tests complete
- [ ] No PHP warnings/notices
- [ ] Code follows WordPress coding standards
- [ ] Security best practices verified
- [ ] Performance acceptable
- [ ] Documentation complete
- [ ] Changelog updated
- [ ] Version bumped

---