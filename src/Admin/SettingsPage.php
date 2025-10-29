<?php

/**
 * SettingsPage - Admin settings UI and form handling.
 *
 * @package SmartAlt
 */

namespace SmartAlt\Admin;

use SmartAlt\Utils\Sanitize;
use SmartAlt\Core\GenericHttpAiConnector;
use SmartAlt\Logger;

/**
 * Admin settings page.
 */
class SettingsPage
{

	/**
	 * Singleton instance.
	 *
	 * @var SettingsPage
	 */
	private static $instance = null;

	/**
	 * Settings option group.
	 *
	 * @var string
	 */
	const OPTION_GROUP = 'smartalt_settings';

	/**
	 * Get singleton instance.
	 *
	 * @return SettingsPage
	 */
	public static function instance()
	{
		if (null === self::$instance) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {}

	/**
	 * Register admin menu.
	 *
	 * @return void
	 */
	public function register_menu()
	{
		add_menu_page(
			__('Smart Alt Optimizer', 'smart-alt-tag-optimizer'),
			__('Smart Alt', 'smart-alt-tag-optimizer'),
			'manage_options',
			'smartalt-settings',
			[$this, 'render_page'],
			'dashicons-images-alt2',
			76
		);
	}

	/**
	 * Register settings fields.
	 *
	 * @return void
	 */
	public function register_settings()
	{
		// Register all individual settings
		$settings = [
			'smartalt_enabled'                     => 1,
			'smartalt_alt_source'                  => 'post_title',
			'smartalt_injection_method'            => 'server_buffer',
			'smartalt_max_alt_length'              => 125,
			'smartalt_cache_ai_results'            => 1,
			'smartalt_ai_cache_ttl_days'           => 90,
			'smartalt_batch_size'                  => 100,
			'smartalt_bulk_schedule'               => 'none',
			'smartalt_bulk_scope'                  => 'attached_only',
			'smartalt_bulk_force_update'           => 0,
			'smartalt_logging_enabled'             => 1,
			'smartalt_log_level'                   => 'info',
			'smartalt_log_retention_days'          => 30,
			'smartalt_ai_endpoint'                 => '',
			'smartalt_ai_method'                   => 'POST',
			'smartalt_ai_headers'                  => '',
			'smartalt_ai_key'                      => '',
			'smartalt_ai_request_template'         => '',
			'smartalt_ai_batch_prompt_template'    => '',
			'smartalt_ai_response_path'            => 'text',
			'smartalt_ai_model_name'               => 'generic_http',
		];

		foreach ($settings as $option => $default) {
			if (! get_option($option)) {
				add_option($option, $default, '', 'no');
			}

			register_setting(
				self::OPTION_GROUP,
				$option,
				[
					'type'              => 'string',
					'sanitize_callback' => [$this, 'sanitize_field'],
					'show_in_rest'      => false,
				]
			);
		}
	}

	/**
	 * Sanitize individual field values based on field type.
	 *
	 * @param mixed $value Field value.
	 *
	 * @return mixed Sanitized value.
	 */
	public function sanitize_field($value)
	{
		// Handle empty values - return as-is to preserve existing values
		if ('' === $value) {
			return $value;
		}

		if (null === $value) {
			return $value;
		}

		// Get the current option name being sanitized from the filter hook
		$option_name = current_filter();
		// Extract option name from "sanitize_option_{option_name}" hook
		if (strpos($option_name, 'sanitize_option_') === 0) {
			$option_name = substr($option_name, strlen('sanitize_option_'));
		}

		switch ($option_name) {
			// Checkboxes: 1 or 0
			case 'smartalt_enabled':
			case 'smartalt_cache_ai_results':
			case 'smartalt_logging_enabled':
			case 'smartalt_bulk_force_update':
				return $value ? 1 : 0;

				// Selects: validate against allowed values
			case 'smartalt_alt_source':
				return in_array($value, ['post_title', 'ai'], true) ? $value : 'post_title';

			case 'smartalt_injection_method':
				return in_array($value, ['server_buffer', 'js_injection'], true) ? $value : 'server_buffer';

			case 'smartalt_bulk_schedule':
				return in_array($value, ['none', 'daily', 'weekly'], true) ? $value : 'none';

			case 'smartalt_bulk_scope':
				return in_array($value, ['all_media', 'attached_only', 'attached_products'], true) ? $value : 'attached_only';

			case 'smartalt_log_level':
				return in_array($value, ['debug', 'info', 'error'], true) ? $value : 'info';

			case 'smartalt_ai_method':
				return in_array(strtoupper($value), ['GET', 'POST'], true) ? strtoupper($value) : 'POST';

				// Numbers: validate ranges
			case 'smartalt_max_alt_length':
				return Sanitize::max_alt_length($value);

			case 'smartalt_batch_size':
				return Sanitize::batch_size($value);

			case 'smartalt_ai_cache_ttl_days':
			case 'smartalt_log_retention_days':
				return Sanitize::retention_days($value);

				// URLs: validate and sanitize
			case 'smartalt_ai_endpoint':
				return Sanitize::endpoint_url($value) ?: '';

				// JSON fields
			case 'smartalt_ai_headers':
				$headers = Sanitize::headers_json($value);
				return $headers !== null ? wp_json_encode($headers) : '';

			case 'smartalt_ai_request_template':
			case 'smartalt_ai_batch_prompt_template':
				$template = Sanitize::request_template($value);
				return $template ?: '';

			case 'smartalt_ai_response_path':
				$path = Sanitize::json_path($value);
				return $path ?: '';

				// API Key: encrypt if new value provided
			case 'smartalt_ai_key':
				// If value is masked (placeholder), keep existing
				if ('••••••••' === $value || ! $value) {
					return get_option('smartalt_ai_key', '');
				}
				// New key provided, encrypt it
				return GenericHttpAiConnector::encrypt_api_key($value);

				// Text fields: sanitize normally
			default:
				return sanitize_text_field($value);
		}
	}

	/**
	 * Render the main settings page.
	 *
	 * @return void
	 */
	public function render_page()
	{
		if (! current_user_can('manage_options')) {
			wp_die(esc_html__('Unauthorized', 'smart-alt-tag-optimizer'));
		}

?>
		<div class="wrap smartalt-settings">
			<h1><?php esc_html_e('Smart Alt Tag Optimizer', 'smart-alt-tag-optimizer'); ?></h1>

			<!-- ✅ ONE SINGLE FORM FOR ALL TABS -->
			<form method="post" action="options.php" class="smartalt-master-form">
				<?php settings_fields(self::OPTION_GROUP); ?>

				<div class="smartalt-tabs">
					<nav class="nav-tab-wrapper">
						<a href="#general" class="nav-tab nav-tab-active"><?php esc_html_e('General', 'smart-alt-tag-optimizer'); ?></a>
						<a href="#ai" class="nav-tab"><?php esc_html_e('AI Configuration', 'smart-alt-tag-optimizer'); ?></a>
						<a href="#bulk" class="nav-tab"><?php esc_html_e('Bulk Update', 'smart-alt-tag-optimizer'); ?></a>
						<a href="#logs" class="nav-tab"><?php esc_html_e('Logs & Stats', 'smart-alt-tag-optimizer'); ?></a>
					</nav>

					<div id="general" class="smartalt-tab-content">
						<?php $this->render_general_settings(); ?>
					</div>

					<div id="ai" class="smartalt-tab-content" style="display:none;">
						<?php $this->render_ai_settings(); ?>
					</div>

					<div id="bulk" class="smartalt-tab-content" style="display:none;">
						<?php $this->render_bulk_settings(); ?>
					</div>

					<div id="logs" class="smartalt-tab-content" style="display:none;">
						<?php $this->render_logs_stats(); ?>
					</div>
				</div>

				<!-- ✅ SINGLE SUBMIT BUTTON FOR ALL TABS -->
				<div style="background: #fff; padding: 20px; border: 1px solid #ccc; border-top: none;">
					<?php submit_button(); ?>
				</div>
			</form>
		</div>

		<style>
			.smartalt-settings {
				max-width: 1000px;
				margin: 20px 0;
			}

			.nav-tab-wrapper {
				border-bottom: 1px solid #ccc;
				background: #fff;
			}

			.smartalt-tab-content {
				background: #fff;
				padding: 20px;
				border: 1px solid #ccc;
				border-top: none;
			}

			.smartalt-field {
				margin: 20px 0;
				padding: 15px;
				background: #f9f9f9;
				border-left: 4px solid #0073aa;
			}

			.smartalt-field label {
				display: block;
				font-weight: bold;
				margin-bottom: 8px;
			}

			.smartalt-field input[type="text"],
			.smartalt-field input[type="number"],
			.smartalt-field input[type="password"],
			.smartalt-field select,
			.smartalt-field textarea {
				width: 100%;
				max-width: 500px;
				padding: 8px;
				border: 1px solid #ddd;
				border-radius: 3px;
				font-family: monospace;
				box-sizing: border-box;
			}

			.smartalt-field textarea {
				height: 150px;
				resize: vertical;
			}

			.smartalt-help {
				font-size: 12px;
				color: #666;
				margin-top: 5px;
				font-style: italic;
			}

			.smartalt-stats {
				display: grid;
				grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
				gap: 20px;
				margin: 20px 0;
			}

			.smartalt-stat-card {
				background: #f0f0f0;
				padding: 20px;
				border-radius: 5px;
				text-align: center;
			}

			.smartalt-stat-card .label {
				font-size: 12px;
				color: #666;
				text-transform: uppercase;
				font-weight: bold;
			}

			.smartalt-stat-card .value {
				font-size: 32px;
				font-weight: bold;
				color: #0073aa;
				margin-top: 10px;
			}

			.button-group {
				margin: 20px 0;
			}

			.button-group .button {
				margin-right: 10px;
			}

			.status-success {
				color: #28a745;
				font-weight: bold;
			}

			.status-error {
				color: #dc3545;
				font-weight: bold;
			}

			.status-skipped {
				color: #ffc107;
				font-weight: bold;
			}

			#smartalt-bulk-progress {
				margin-top: 20px;
			}

			#smartalt-progress-bar {
				width: 100%;
				height: 30px;
			}

			#smartalt-progress-text {
				margin-top: 10px;
				font-size: 14px;
			}
		</style>

		<script>
			document.querySelectorAll('.nav-tab').forEach(tab => {
				tab.addEventListener('click', function(e) {
					e.preventDefault();
					document.querySelectorAll('.nav-tab').forEach(t => t.classList.remove('nav-tab-active'));
					document.querySelectorAll('.smartalt-tab-content').forEach(c => c.style.display = 'none');
					this.classList.add('nav-tab-active');
					const target = document.querySelector(this.getAttribute('href'));
					if (target) {
						target.style.display = 'block';
					}
				});
			});
		</script>
	<?php
	}

	/**
	 * Render general settings section.
	 *
	 * @return void
	 */
	private function render_general_settings()
	{
		$enabled = (bool) get_option('smartalt_enabled');
		$alt_source = get_option('smartalt_alt_source', 'post_title');
		$injection_method = get_option('smartalt_injection_method', 'server_buffer');
		$max_length = (int) get_option('smartalt_max_alt_length', 125);

	?>
		<!-- ✅ NO <form> TAG - All inside master form -->

		<!-- Hidden fields to ensure unchecked checkboxes are submitted as 0 -->
		<input type="hidden" name="smartalt_enabled" value="0" />

		<div class="smartalt-field">
			<label for="smartalt_enabled">
				<input type="checkbox" id="smartalt_enabled" name="smartalt_enabled" value="1" <?php checked($enabled); ?> />
				<?php esc_html_e('Enable Smart Alt Optimizer', 'smart-alt-tag-optimizer'); ?>
			</label>
			<div class="smartalt-help"><?php esc_html_e('Disable to pause all auto-alt functionality.', 'smart-alt-tag-optimizer'); ?></div>
		</div>

		<div class="smartalt-field">
			<label for="smartalt_alt_source"><?php esc_html_e('Alt Text Source', 'smart-alt-tag-optimizer'); ?></label>
			<select id="smartalt_alt_source" name="smartalt_alt_source">
				<option value="post_title" <?php selected($alt_source, 'post_title'); ?>>
					<?php esc_html_e('Post Title (Fast, No API)', 'smart-alt-tag-optimizer'); ?>
				</option'smart-alt-tag-optimizer'				<option value="ai" <?php selected($alt_source, 'ai'); ?>>
					<?php esc_html_e('AI Generated (Requires API, 1 call per page)', 'smart-alt-tag-optimizer'); ?>
				</option>
			</select>
			<div class="smartalt-help">
				<?php esc_html_e('Choose the source for generating alt text. Post Title is instant. AI provides varied, contextual alts but requires an API key.', 'smart-alt-tag-optimizer'); ?>
			</div>
		</div>

		<div class="smartalt-field">
			<label for="smartalt_injection_method"><?php esc_html_e('Frontend Injection Method', 'smart-alt-tag-optimizer'); ?></label>
			<select id="smartalt_injection_method" name="smartalt_injection_method">
				<option value="server_buffer" <?php selected($injection_method, 'server_buffer'); ?>>
					<?php esc_html_e('Server-side Buffer (Recommended)', 'smart-alt-tag-optimizer'); ?>
				</option>
				<option value="js_injection" <?php selected($injection_method, 'js_injection'); ?>>
					<?php esc_html_e('JavaScript (Fallback only)', 'smart-alt-tag-optimizer'); ?>
				</option>
			</select>
			<div class="smartalt-help">
				<?php esc_html_e('Server-side is best for SEO and performance. JS is slower and doesn\'t help crawlers.', 'smart-alt-tag-optimizer'); ?>
			</div>
		</div>

		<div class="smartalt-field">
			<label for="smartalt_max_alt_length"><?php esc_html_e('Max Alt Length', 'smart-alt-tag-optimizer'); ?></label>
			<input type="number" id="smartalt_max_alt_length" name="smartalt_max_alt_length" value="<?php echo esc_attr($max_length); ?>" min="50" max="500" />
			<div class="smartalt-help">
				<?php esc_html_e('Maximum characters for alt text (50-500). Default 125 is SEO best practice.', 'smart-alt-tag-optimizer'); ?>
			</div>
		</div>
	<?php
	}

	/**
	 * Render AI settings section.
	 *
	 * @return void
	 */
	private function render_ai_settings()
	{
		$endpoint = get_option('smartalt_ai_endpoint', '');
		$method = get_option('smartalt_ai_method', 'POST');
		$headers = get_option('smartalt_ai_headers', '');
		$request_template = get_option('smartalt_ai_request_template', '');
		$batch_prompt_template = get_option('smartalt_ai_batch_prompt_template', '');
		$response_path = get_option('smartalt_ai_response_path', 'text');
		$model_name = get_option('smartalt_ai_model_name', 'generic_http');
		$cache_results = (bool) get_option('smartalt_cache_ai_results', 1);
		$cache_ttl = (int) get_option('smartalt_ai_cache_ttl_days', 90);
		$api_key_set = ! empty(get_option('smartalt_ai_key')) || defined('SMARTALT_AI_KEY');

	?>
		<!-- Hidden field for checkbox -->
		<input type="hidden" name="smartalt_cache_ai_results" value="0" />

		<div class="smartalt-field">
			<label for="smartalt_ai_endpoint"><?php esc_html_e('API Endpoint URL', 'smart-alt-tag-optimizer'); ?></label>
			<input type="text" id="smartalt_ai_endpoint" name="smartalt_ai_endpoint" value="<?php echo esc_attr($endpoint); ?>" placeholder="https://api.example.com/generate-alt" />
			<div class="smartalt-help">
				<?php esc_html_e('Full HTTP(S) URL to your AI service endpoint. Only ONE API call is made per page load (batch processing).', 'smart-alt-tag-optimizer'); ?>
			</div>
		</div>

		<div class="smartalt-field">
			<label for="smartalt_ai_method"><?php esc_html_e('HTTP Method', 'smart-alt-tag-optimizer'); ?></label>
			<select id="smartalt_ai_method" name="smartalt_ai_method">
				<option value="POST" <?php selected($method, 'POST'); ?>>POST</option>
				<option value="GET" <?php selected($method, 'GET'); ?>>GET</option>
			</select>
		</div>

		<div class="smartalt-field">
			<label for="smartalt_ai_headers"><?php esc_html_e('HTTP Headers (JSON)', 'smart-alt-tag-optimizer'); ?></label>
			<textarea id="smartalt_ai_headers" name="smartalt_ai_headers" placeholder='{"Content-Type": "application/json"}'><?php echo esc_textarea($headers); ?></textarea>
			<div class="smartalt-help">
				<?php esc_html_e('Custom headers as JSON object. Will be merged with default headers.', 'smart-alt-tag-optimizer'); ?>
			</div>
		</div>

		<div class="smartalt-field">
			<label for="smartalt_ai_request_template"><?php esc_html_e('Request Body Template (Single Image)', 'smart-alt-tag-optimizer'); ?></label>
			<textarea id="smartalt_ai_request_template" name="smartalt_ai_request_template" placeholder='{"image_url": "{image_url}", "prompt": "Describe this image"}'><?php echo esc_textarea($request_template); ?></textarea>
			<div class="smartalt-help">
				<?php esc_html_e('Used for bulk/attachment updates. Placeholders: {image_url}, {post_title}, {post_excerpt}, {post_content}, {image_filename}, {max_length}', 'smart-alt-tag-optimizer'); ?>
			</div>
		</div>

		<div class="smartalt-field">
			<label for="smartalt_ai_batch_prompt_template"><?php esc_html_e('Batch Prompt Template (Frontend)', 'smart-alt-tag-optimizer'); ?></label>
			<textarea id="smartalt_ai_batch_prompt_template" name="smartalt_ai_batch_prompt_template"><?php echo esc_textarea($batch_prompt_template); ?></textarea>
			<div class="smartalt-help">
				<?php esc_html_e('Used for on-the-fly batch processing (ONE API call for all images on a page). Leave empty to use default. Placeholders: {image_count}, {post_title}, {post_excerpt}, {post_content}, {images_json}, {max_length}.', 'smart-alt-tag-optimizer'); ?>
			</div>
			<button type="button" class="button button-small" id="smartalt-reset-batch-template">
				<?php esc_html_e('Reset to Default', 'smart-alt-tag-optimizer'); ?>
			</button>
		</div>

		<div class="smartalt-field">
			<label for="smartalt_ai_response_path"><?php esc_html_e('Response JSON Path', 'smart-alt-tag-optimizer'); ?></label>
			<input type="text" id="smartalt_ai_response_path" name="smartalt_ai_response_path" value="<?php echo esc_attr($response_path); ?>" placeholder="data.alt_text" />
			<div class="smartalt-help">
				<?php esc_html_e('Dot notation path to alt texts in JSON response (e.g., "choices[0].message.content" for OpenAI, or "data.alts" for custom).', 'smart-alt-tag-optimizer'); ?>
			</div>
		</div>

		<div class="smartalt-field">
			<label for="smartalt_ai_model_name"><?php esc_html_e('Model Name/ID', 'smart-alt-tag-optimizer'); ?></label>
			<input type="text" id="smartalt_ai_model_name" name="smartalt_ai_model_name" value="<?php echo esc_attr($model_name); ?>" placeholder="gpt-4-vision" />
			<div class="smartalt-help">
				<?php esc_html_e('Identifier for the AI model (for logging purposes).', 'smart-alt-tag-optimizer'); ?>
			</div>
		</div>

		<div class="smartalt-field">
			<label for="smartalt_ai_key"><?php esc_html_e('API Key', 'smart-alt-tag-optimizer'); ?></label>
			<?php if (defined('SMARTALT_AI_KEY')) : ?>
				<div class="smartalt-help" style="color: #28a745; font-weight: bold;">
					<?php esc_html_e('✓ API key set via environment variable (SMARTALT_AI_KEY)', 'smart-alt-tag-optimizer'); ?>
				</div>
			<?php else : ?>
				<input type="password" id="smartalt_ai_key" name="smartalt_ai_key" value="<?php echo $api_key_set ? '••••••••' : ''; ?>" />
				<div class="smartalt-help">
					<?php esc_html_e('Or set SMARTALT_AI_KEY in wp-config.php for better security (recommended).', 'smart-alt-tag-optimizer'); ?>
				</div>
			<?php endif; ?>
		</div>

		<div class="smartalt-field">
			<label for="smartalt_cache_ai_results">
				<input type="checkbox" id="smartalt_cache_ai_results" name="smartalt_cache_ai_results" value="1" <?php checked($cache_results); ?> />
				<?php esc_html_e('Cache AI Results', 'smart-alt-tag-optimizer'); ?>
			</label>
			<div class="smartalt-help">
				<?php esc_html_e('Cache AI-generated alt text to reduce API calls. TTL can be set below.', 'smart-alt-tag-optimizer'); ?>
			</div>
		</div>

		<div class="smartalt-field">
			<label for="smartalt_ai_cache_ttl_days"><?php esc_html_e('Cache TTL (Days)', 'smart-alt-tag-optimizer'); ?></label>
			<input type="number" id="smartalt_ai_cache_ttl_days" name="smartalt_ai_cache_ttl_days" value="<?php echo esc_attr($cache_ttl); ?>" min="7" max="365" />
			<div class="smartalt-help">
				<?php esc_html_e('How long to keep cached AI results (7-365 days).', 'smart-alt-tag-optimizer'); ?>
			</div>
		</div>

		<div class="button-group">
			<button type="button" class="button button-secondary" id="smartalt-test-connection">
				<?php esc_html_e('Test Connection', 'smart-alt-tag-optimizer'); ?>
			</button>
			<button type="button" class="button button-secondary" id="smartalt-clear-ai-cache">
				<?php esc_html_e('Clear AI Cache', 'smart-alt-tag-optimizer'); ?>
			</button>
		</div>

		<script>
			document.getElementById('smartalt-test-connection')?.addEventListener('click', function() {
				if (confirm('<?php esc_attr_e('Test AI connection? This will make a test API call.', 'smart-alt-tag-optimizer'); ?>')) {
					const nonce = document.querySelector('input[name="_wpnonce"]')?.value;
					fetch(ajaxurl, {
						method: 'POST',
						headers: {
							'Content-Type': 'application/x-www-form-urlencoded'
						},
						body: 'action=smartalt_test_connection&_wpnonce=' + encodeURIComponent(nonce)
					}).then(r => r.json()).then(d => {
						alert(d.data?.message || d.message || 'Response received');
					}).catch(e => alert('Error: ' + e.message));
				}
			});

			document.getElementById('smartalt-clear-ai-cache')?.addEventListener('click', function() {
				if (confirm('<?php esc_attr_e('Clear all cached AI results? This cannot be undone.', 'smart-alt-tag-optimizer'); ?>')) {
					const nonce = document.querySelector('input[name="_wpnonce"]')?.value;
					fetch(ajaxurl, {
						method: 'POST',
						headers: {
							'Content-Type': 'application/x-www-form-urlencoded'
						},
						body: 'action=smartalt_clear_ai_cache&_wpnonce=' + encodeURIComponent(nonce)
					}).then(r => r.json()).then(d => {
						alert(d.data?.message || d.message || 'Cache cleared!');
					}).catch(e => alert('Error: ' + e.message));
				}
			});

			document.getElementById('smartalt-reset-batch-template')?.addEventListener('click', function(e) {
				e.preventDefault();
				const defaultTemplate = JSON.stringify({
					"model": "gpt-4-vision-preview",
					"messages": [{
						"role": "user",
						"content": "Generate concise, varied alt text for {image_count} images on a page about '{post_title}'.\n\nPage context:\n- Title: {post_title}\n- Excerpt: {post_excerpt}\n- Content: {post_content}\n\nImages with details:\n{images_json}\n\nFor each image, consider:\n1. Image filename\n2. Image position on page\n3. Page content context\n4. SEO best practices\n\nGenerate alt texts that are:\n- Descriptive and contextual\n- Under {max_length} characters\n- Varied (not all the same)\n- Helpful for accessibility\n\nReturn ONLY valid JSON: {\"image_url\": \"alt_text\", ...}"
					}],
					"temperature": 0.7
				}, null, 2);
				document.getElementById('smartalt_ai_batch_prompt_template').value = defaultTemplate;
				alert('<?php esc_attr_e('Default template loaded!', 'smart-alt-tag-optimizer'); ?>');
			});
		</script>
	<?php
	}

	/**
	 * Render bulk update settings section.
	 *
	 * @return void
	 */
	private function render_bulk_settings()
	{
		$schedule = get_option('smartalt_bulk_schedule', 'none');
		$scope = get_option('smartalt_bulk_scope', 'attached_only');
		$batch_size = (int) get_option('smartalt_batch_size', 100);
		$force_update = (bool) get_option('smartalt_bulk_force_update');
		$logging_enabled = (bool) get_option('smartalt_logging_enabled');
		$log_level = get_option('smartalt_log_level', 'info');
		$log_retention = (int) get_option('smartalt_log_retention_days', 30);
	?>
		<!-- Hidden fields for checkboxes -->
		<input type="hidden" name="smartalt_bulk_force_update" value="0" />
		<input type="hidden" name="smartalt_logging_enabled" value="0" />

		<div class="smartalt-field">
			<label for="smartalt_bulk_schedule"><?php esc_html_e('Auto-Schedule Bulk Updates', 'smart-alt-tag-optimizer'); ?></label>
			<select id="smartalt_bulk_schedule" name="smartalt_bulk_schedule">
				<option value="none" <?php selected($schedule, 'none'); ?>>
					<?php esc_html_e('Disabled (Manual only)', 'smart-alt-tag-optimizer'); ?>
				</option>
				<option value="daily" <?php selected($schedule, 'daily'); ?>>
					<?php esc_html_e('Daily', 'smart-alt-tag-optimizer'); ?>
				</option>
				<option value="weekly" <?php selected($schedule, 'weekly'); ?>>
					<?php esc_html_e('Weekly', 'smart-alt-tag-optimizer'); ?>
				</option>
			</select>
			<div class="smartalt-help">
				<?php esc_html_e('Automatic bulk updates via WP-Cron. Requires valid wp-cron setup.', 'smart-alt-tag-optimizer'); ?>
			</div>
		</div>

		<div class="smartalt-field">
			<label for="smartalt_bulk_scope"><?php esc_html_e('Bulk Scope', 'smart-alt-tag-optimizer'); ?></label>
			<select id="smartalt_bulk_scope" name="smartalt_bulk_scope">
				<option value="all_media" <?php selected($scope, 'all_media'); ?>>
					<?php esc_html_e('All Media Library', 'smart-alt-tag-optimizer'); ?>
				</option>
				<option value="attached_only" <?php selected($scope, 'attached_only'); ?>>
					<?php esc_html_e('Attached to Posts (Recommended)', 'smart-alt-tag-optimizer'); ?>
				</option>
				<option value="attached_products" <?php selected($scope, 'attached_products'); ?>>
					<?php esc_html_e('WooCommerce Products Only', 'smart-alt-tag-optimizer'); ?>
				</option>
			</select>
			<div class="smartalt-help">
				<?php esc_html_e('Choose which attachments to include in bulk updates.', 'smart-alt-tag-optimizer'); ?>
			</div>
		</div>

		<div class="smartalt-field">
			<label for="smartalt_batch_size"><?php esc_html_e('Batch Size', 'smart-alt-tag-optimizer'); ?></label>
			<input type="number" id="smartalt_batch_size" name="smartalt_batch_size" value="<?php echo esc_attr($batch_size); ?>" min="10" max="500" />
			<div class="smartalt-help">
				<?php esc_html_e('Number of images to process per batch (10-500). Lower = less server load, higher = faster.', 'smart-alt-tag-optimizer'); ?>
			</div>
		</div>

		<div class="smartalt-field">
			<label for="smartalt_bulk_force_update">
				<input type="checkbox" id="smartalt_bulk_force_update" name="smartalt_bulk_force_update" value="1" <?php checked($force_update); ?> />
				<?php esc_html_e('Force Update Existing Alt Text', 'smart-alt-tag-optimizer'); ?>
			</label>
			<div class="smartalt-help">
				<?php esc_html_e('If checked, bulk updates will overwrite existing alt text. Otherwise only empty alts are updated.', 'smart-alt-tag-optimizer'); ?>
			</div>
		</div>

		<div class="smartalt-field">
			<label for="smartalt_logging_enabled">
				<input type="checkbox" id="smartalt_logging_enabled" name="smartalt_logging_enabled" value="1" <?php checked($logging_enabled); ?> />
				<?php esc_html_e('Enable Logging', 'smart-alt-tag-optimizer'); ?>
			</label>
			<div class="smartalt-help">
				<?php esc_html_e('Log all alt text changes for audit trail and reverting.', 'smart-alt-tag-optimizer'); ?>
			</div>
		</div>

		<div class="smartalt-field">
			<label for="smartalt_log_level"><?php esc_html_e('Log Level', 'smart-alt-tag-optimizer'); ?></label>
			<select id="smartalt_log_level" name="smartalt_log_level">
				<option value="debug" <?php selected($log_level, 'debug'); ?>>
					<?php esc_html_e('Debug (All messages)', 'smart-alt-tag-optimizer'); ?>
				</option>
				<option value="info" <?php selected($log_level, 'info'); ?>>
					<?php esc_html_e('Info (Success & errors)', 'smart-alt-tag-optimizer'); ?>
				</option>
				<option value="error" <?php selected($log_level, 'error'); ?>>
					<?php esc_html_e('Error (Errors only)', 'smart-alt-tag-optimizer'); ?>
				</option>
			</select>
			<div class="smartalt-help">
				<?php esc_html_e('Control what level of messages are logged.', 'smart-alt-tag-optimizer'); ?>
			</div>
		</div>

		<div class="smartalt-field">
			<label for="smartalt_log_retention_days"><?php esc_html_e('Log Retention (Days)', 'smart-alt-tag-optimizer'); ?></label>
			<input type="number" id="smartalt_log_retention_days" name="smartalt_log_retention_days" value="<?php echo esc_attr($log_retention); ?>" min="7" max="365" />
			<div class="smartalt-help">
				<?php esc_html_e('How long to keep logs before auto-deletion (7-365 days).', 'smart-alt-tag-optimizer'); ?>
			</div>
		</div>

		<div class="button-group">
			<button type="button" class="button button-primary" id="smartalt-bulk-run">
				<?php esc_html_e('▶ Run Bulk Update Now', 'smart-alt-tag-optimizer'); ?>
			</button>
			<button type="button" class="button button-secondary" id="smartalt-bulk-dry-run">
				<?php esc_html_e('👁 Dry Run (Preview)', 'smart-alt-tag-optimizer'); ?>
			</button>
		</div>

		<div id="smartalt-bulk-progress" style="display:none; margin-top: 20px;">
			<div class="smartalt-field">
				<div style="font-weight: bold;">Progress</div>
				<progress id="smartalt-progress-bar" value="0" max="100" style="width: 100%; height: 30px;"></progress>
				<div id="smartalt-progress-text" style="margin-top: 10px;"></div>
			</div>
		</div>

		<script>
			document.getElementById('smartalt-bulk-run')?.addEventListener('click', function() {
				if (confirm('<?php esc_attr_e('Start bulk update? This may take several minutes.', 'smart-alt-tag-optimizer'); ?>')) {
					smartaltBulkRun(false);
				}
			});

			document.getElementById('smartalt-bulk-dry-run')?.addEventListener('click', function() {
				if (confirm('<?php esc_attr_e('Preview first 10 items? No changes will be made.', 'smart-alt-tag-optimizer'); ?>')) {
					smartaltBulkRun(true);
				}
			});

			function smartaltBulkRun(dryRun) {
				const scope = document.getElementById('smartalt_bulk_scope').value;
				const forceUpdate = document.getElementById('smartalt_bulk_force_update').checked;
				const nonce = document.querySelector('input[name="_wpnonce"]')?.value;

				document.getElementById('smartalt-bulk-progress').style.display = 'block';
				document.getElementById('smartalt-progress-text').textContent = '<?php esc_attr_e('Starting...', 'smart-alt-tag-optimizer'); ?>';

				fetch(ajaxurl, {
						method: 'POST',
						headers: {
							'Content-Type': 'application/x-www-form-urlencoded'
						},
						body: new URLSearchParams({
							action: 'smartalt_bulk_run',
							dry_run: dryRun ? 'true' : 'false',
							scope: scope,
							force_update: forceUpdate ? 'true' : 'false',
							_wpnonce: nonce
						})
					})
					.then(r => r.json())
					.then(d => {
						if (d.success) {
							if (dryRun) {
								alert('<?php esc_attr_e('Dry run preview: ', 'smart-alt-tag-optimizer'); ?>' + d.data.message);
							} else {
								document.getElementById('smartalt-progress-text').textContent = 'Complete!';
								alert('<?php esc_attr_e('Bulk update completed successfully!', 'smart-alt-tag-optimizer'); ?>');
							}
						} else {
							alert('Error: ' + (d.data?.message || 'Unknown error'));
						}
						document.getElementById('smartalt-bulk-progress').style.display = 'none';
					})
					.catch(e => {
						alert('Error: ' + e.message);
						document.getElementById('smartalt-bulk-progress').style.display = 'none';
					});
			}
		</script>
	<?php
	}

	/**
	 * Render logs and statistics section.
	 *
	 * @return void
	 */
	private function render_logs_stats()
	{
		$stats = Logger::get_stats();
		$logs = Logger::get_logs(['limit' => 50]);

	?>
		<div class="smartalt-stats">
			<div class="smartalt-stat-card">
				<div class="label"><?php esc_html_e('Total Logged', 'smart-alt-tag-optimizer'); ?></div>
				<div class="value"><?php echo esc_html($stats['total_logged']); ?></div>
			</div>
			<div class="smartalt-stat-card">
				<div class="label"><?php esc_html_e('AI Generated', 'smart-alt-tag-optimizer'); ?></div>
				<div class="value"><?php echo esc_html($stats['ai_generated']); ?></div>
			</div>
			<div class="smartalt-stat-card">
				<div class="label"><?php esc_html_e('Errors', 'smart-alt-tag-optimizer'); ?></div>
				<div class="value" style="color: #dc3545;"><?php echo esc_html($stats['errors']); ?></div>
			</div>
			<div class="smartalt-stat-card">
				<div class="label"><?php esc_html_e('Last Run', 'smart-alt-tag-optimizer'); ?></div>
				<div class="value" style="font-size: 14px;">
					<?php echo $stats['last_run'] ? esc_html($stats['last_run']) : esc_html_e('Never', 'smart-alt-tag-optimizer'); ?>
				</div>
			</div>
		</div>

		<h3><?php esc_html_e('Recent Activity', 'smart-alt-tag-optimizer'); ?></h3>
		<table class="widefat striped" style="margin-top: 20px;">
			<thead>
				<tr>
					<th><?php esc_html_e('Time', 'smart-alt-tag-optimizer'); ?></th>
					<th><?php esc_html_e('Attachment', 'smart-alt-tag-optimizer'); ?></th>
					<th><?php esc_html_e('Source', 'smart-alt-tag-optimizer'); ?></th>
					<th><?php esc_html_e('Old Alt', 'smart-alt-tag-optimizer'); ?></th>
					<th><?php esc_html_e('New Alt', 'smart-alt-tag-optimizer'); ?></th>
					<th><?php esc_html_e('Status', 'smart-alt-tag-optimizer'); ?></th>
					<th><?php esc_html_e('Message', 'smart-alt-tag-optimizer'); ?></th>
					<th><?php esc_html_e('Action', 'smart-alt-tag-optimizer'); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if (! empty($logs)) : ?>
					<?php foreach ($logs as $log) : ?>
						<tr>
							<td><?php echo esc_html($log->time); ?></td>
							<td>
								<?php if ($log->attachment_id) : ?>
									<a href="<?php echo esc_url(get_edit_post_link($log->attachment_id)); ?>" target="_blank">
										<?php echo esc_html('#' . $log->attachment_id); ?>
									</a>
								<?php else : ?>
									—
								<?php endif; ?>
							</td>
							<td><?php echo esc_html($log->source); ?></td>
							<td><small><?php echo esc_html(substr((string) $log->old_alt, 0, 30)); ?><?php echo strlen((string) $log->old_alt) > 30 ? '...' : ''; ?></small></td>
							<td><small><?php echo esc_html(substr((string) $log->new_alt, 0, 30)); ?><?php echo strlen((string) $log->new_alt) > 30 ? '...' : ''; ?></small></td>
							<td>
								<span class="status-<?php echo esc_attr($log->status); ?>">
									<?php echo esc_html(ucfirst($log->status)); ?>
								</span>
							</td>
							<td><small><?php echo esc_html($log->message); ?></small></td>
							<td>
								<?php if ('success' === $log->status && $log->old_alt) : ?>
									<button class="button button-small smartalt-revert" data-log-id="<?php echo esc_attr($log->id); ?>" data-nonce="<?php echo esc_attr(wp_create_nonce('smartalt_revert_nonce')); ?>">
										<?php esc_html_e('Revert', 'smart-alt-tag-optimizer'); ?>
									</button>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				<?php else : ?>
					<tr>
						<td colspan="8" style="text-align: center; padding: 20px;">
							<?php esc_html_e('No logs yet. Create or update posts with images to generate alt text.', 'smart-alt-tag-optimizer'); ?>
						</td>
					</tr>
				<?php endif; ?>
			</tbody>
		</table>

		<script>
			document.querySelectorAll('.smartalt-revert').forEach(btn => {
				btn.addEventListener('click', function(e) {
					e.preventDefault();
					if (confirm('<?php esc_attr_e('Revert this change? This will restore the old alt text.', 'smart-alt-tag-optimizer'); ?>')) {
						const logId = this.getAttribute('data-log-id');
						const nonce = this.getAttribute('data-nonce');

						fetch(ajaxurl, {
								method: 'POST',
								headers: {
									'Content-Type': 'application/x-www-form-urlencoded'
								},
								body: new URLSearchParams({
									action: 'smartalt_revert_log',
									log_id: logId,
									_wpnonce: nonce
								})
							})
							.then(r => r.json())
							.then(d => {
								if (d.success) {
									alert('<?php esc_attr_e('Reverted successfully!', 'smart-alt-tag-optimizer'); ?>');
									location.reload();
								} else {
									alert('Error: ' + (d.data?.message || 'Unknown error'));
								}
							})
							.catch(e => alert('Error: ' + e.message));
					}
				});
			});
		</script>
	<?php
	}

	/**
	 * Add dashboard widget.
	 *
	 * @return void
	 */
	public function add_dashboard_widget()
	{
		if (! current_user_can('manage_options')) {
			return;
		}

		wp_add_dashboard_widget(
			'smartalt_dashboard_widget',
			__('Smart Alt Optimizer', 'smart-alt-tag-optimizer'),
			[$this, 'render_dashboard_widget']
		);
	}

	/**
	 * Render dashboard widget.
	 *
	 * @return void
	 */
	public function render_dashboard_widget()
	{
		global $wpdb;

		// Get attachment count with/without alt
		$total_attachments = $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_parent > 0"
		);

		$with_alt = $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->posts} p
		JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
		WHERE p.post_type = 'attachment' AND p.post_parent > 0
		AND pm.meta_key = '_wp_attachment_image_alt' AND pm.meta_value != ''"
		);

		$without_alt = $total_attachments - $with_alt;
		$coverage = $total_attachments > 0 ? round(($with_alt / $total_attachments) * 100, 1) : 0;

		$stats = Logger::get_stats();

	?>
		<div style="text-align: center; padding: 20px;">
			<div style="font-size: 48px; font-weight: bold; color: #0073aa;">
				<?php echo esc_html($coverage); ?>%
			</div>
			<div style="font-size: 14px; color: #666; margin-bottom: 20px;">
				<?php esc_html_e('Alt Coverage', 'smart-alt-tag-optimizer'); ?>
			</div>

			<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
				<div style="background: #e8f5e9; padding: 15px; border-radius: 3px;">
					<div style="font-weight: bold; color: #2e7d32;"><?php echo esc_html($with_alt); ?></div>
					<div style="font-size: 12px; color: #666;"><?php esc_html_e('With Alt', 'smart-alt-tag-optimizer'); ?></div>
				</div>
				<div style="background: #ffebee; padding: 15px; border-radius: 3px;">
					<div style="font-weight: bold; color: #c62828;"><?php echo esc_html($without_alt); ?></div>
					<div style="font-size: 12px; color: #666;"><?php esc_html_e('Missing Alt', 'smart-alt-tag-optimizer'); ?></div>
				</div>
			</div>

			<div style="margin-top: 15px; font-size: 12px; color: #999;">
				<?php echo sprintf(esc_html__('AI Generated: %d | Last: %s', 'smart-alt-tag-optimizer'), esc_html($stats['ai_generated']), esc_html($stats['last_run'] ? $stats['last_run'] : __('Never', 'smart-alt-tag-optimizer'))); ?>
			</div>

			<div style="margin-top: 15px;">
				<a href="<?php echo esc_url(admin_url('admin.php?page=smartalt-settings')); ?>" class="button button-primary">
					<?php esc_html_e('View Settings', 'smart-alt-tag-optimizer'); ?>
				</a>
			</div>
		</div>
<?php
	}
}
