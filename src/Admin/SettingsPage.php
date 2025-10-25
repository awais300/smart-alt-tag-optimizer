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
class SettingsPage {

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
	public static function instance() {
		if ( null === self::$instance ) {
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
	public function register_menu() {
		add_menu_page(
			__( 'Smart Alt Optimizer', SMARTALT_TEXT_DOMAIN ),
			__( 'Smart Alt', SMARTALT_TEXT_DOMAIN ),
			'manage_options',
			'smartalt-settings',
			[ $this, 'render_page' ],
			'dashicons-image-alt',
			76
		);

		// Add dashboard widget
		add_action( 'wp_dashboard_setup', [ $this, 'add_dashboard_widget' ] );
	}

	/**
	 * Register settings fields.
	 *
	 * @return void
	 */
	public function register_settings() {
		// Register all settings with autoload=false to avoid bloating autoloaded options
		$this->register_setting( 'smartalt_enabled', 1 );
		$this->register_setting( 'smartalt_alt_source', 'post_title' );
		$this->register_setting( 'smartalt_injection_method', 'server_buffer' );
		$this->register_setting( 'smartalt_max_alt_length', 125 );
		$this->register_setting( 'smartalt_cache_ai_results', 1 );
		$this->register_setting( 'smartalt_ai_cache_ttl_days', 90 );
		$this->register_setting( 'smartalt_batch_size', 100 );
		$this->register_setting( 'smartalt_bulk_schedule', 'none' );
		$this->register_setting( 'smartalt_bulk_scope', 'attached_only' );
		$this->register_setting( 'smartalt_bulk_force_update', 0 );
		$this->register_setting( 'smartalt_logging_enabled', 1 );
		$this->register_setting( 'smartalt_log_level', 'info' );
		$this->register_setting( 'smartalt_log_retention_days', 30 );
		$this->register_setting( 'smartalt_ai_endpoint', '' );
		$this->register_setting( 'smartalt_ai_method', 'POST' );
		$this->register_setting( 'smartalt_ai_headers', '' );
		$this->register_setting( 'smartalt_ai_key', '' );
		$this->register_setting( 'smartalt_ai_request_template', '' );
		$this->register_setting( 'smartalt_ai_response_path', 'text' );
		$this->register_setting( 'smartalt_ai_model_name', 'generic_http' );
	}

	/**
	 * Register a single setting with autoload=false.
	 *
	 * @param string $option Option name.
	 * @param mixed  $default Default value.
	 *
	 * @return void
	 */
	private function register_setting( $option, $default ) {
		if ( ! get_option( $option ) ) {
			add_option( $option, $default, '', 'no' ); // autoload = 'no'
		}
	}

	/**
	 * Render the settings page.
	 *
	 * @return void
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized', SMARTALT_TEXT_DOMAIN ) );
		}

		?>
		<div class="wrap smartalt-settings">
			<h1><?php esc_html_e( 'Smart Alt Tag Optimizer', SMARTALT_TEXT_DOMAIN ); ?></h1>

			<div class="smartalt-tabs">
				<nav class="nav-tab-wrapper">
					<a href="#general" class="nav-tab nav-tab-active"><?php esc_html_e( 'General', SMARTALT_TEXT_DOMAIN ); ?></a>
					<a href="#ai" class="nav-tab"><?php esc_html_e( 'AI Configuration', SMARTALT_TEXT_DOMAIN ); ?></a>
					<a href="#bulk" class="nav-tab"><?php esc_html_e( 'Bulk Update', SMARTALT_TEXT_DOMAIN ); ?></a>
					<a href="#logs" class="nav-tab"><?php esc_html_e( 'Logs & Stats', SMARTALT_TEXT_DOMAIN ); ?></a>
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
		</div>

		<style>
			.smartalt-settings { max-width: 1000px; margin: 20px 0; }
			.nav-tab-wrapper { border-bottom: 1px solid #ccc; }
			.smartalt-tab-content { background: #fff; padding: 20px; border: 1px solid #ccc; border-top: none; }
			.smartalt-field { margin: 20px 0; padding: 15px; background: #f9f9f9; border-left: 4px solid #0073aa; }
			.smartalt-field label { display: block; font-weight: bold; margin-bottom: 8px; }
			.smartalt-field input[type="text"],
			.smartalt-field input[type="number"],
			.smartalt-field select,
			.smartalt-field textarea { width: 100%; max-width: 500px; padding: 8px; border: 1px solid #ddd; border-radius: 3px; font-family: monospace; }
			.smartalt-field textarea { height: 150px; }
			.smartalt-help { font-size: 12px; color: #666; margin-top: 5px; font-style: italic; }
			.smartalt-stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin: 20px 0; }
			.smartalt-stat-card { background: #f0f0f0; padding: 20px; border-radius: 5px; text-align: center; }
			.smartalt-stat-card .label { font-size: 12px; color: #666; text-transform: uppercase; }
			.smartalt-stat-card .value { font-size: 32px; font-weight: bold; color: #0073aa; }
			.button-group { margin: 20px 0; }
			.button-group .button { margin-right: 10px; }
		</style>

		<script>
			document.querySelectorAll('.nav-tab').forEach(tab => {
				tab.addEventListener('click', function(e) {
					e.preventDefault();
					document.querySelectorAll('.nav-tab').forEach(t => t.classList.remove('nav-tab-active'));
					document.querySelectorAll('.smartalt-tab-content').forEach(c => c.style.display = 'none');
					this.classList.add('nav-tab-active');
					document.querySelector(this.getAttribute('href')).style.display = 'block';
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
	private function render_general_settings() {
		$enabled = (bool) get_option( 'smartalt_enabled' );
		$alt_source = get_option( 'smartalt_alt_source', 'post_title' );
		$injection_method = get_option( 'smartalt_injection_method', 'server_buffer' );
		$max_length = (int) get_option( 'smartalt_max_alt_length', 125 );

		?>
		<form method="post" action="options.php">
			<?php settings_fields( self::OPTION_GROUP ); ?>

			<div class="smartalt-field">
				<label for="smartalt_enabled">
					<input type="checkbox" id="smartalt_enabled" name="smartalt_enabled" value="1" <?php checked( $enabled ); ?> />
					<?php esc_html_e( 'Enable Smart Alt Optimizer', SMARTALT_TEXT_DOMAIN ); ?>
				</label>
				<div class="smartalt-help"><?php esc_html_e( 'Disable to pause all auto-alt functionality.', SMARTALT_TEXT_DOMAIN ); ?></div>
			</div>

			<div class="smartalt-field">
				<label for="smartalt_alt_source"><?php esc_html_e( 'Alt Text Source', SMARTALT_TEXT_DOMAIN ); ?></label>
				<select id="smartalt_alt_source" name="smartalt_alt_source">
					<option value="post_title" <?php selected( $alt_source, 'post_title' ); ?>>
						<?php esc_html_e( 'Post Title (Fast, SEO-friendly)', SMARTALT_TEXT_DOMAIN ); ?>
					</option>
					<option value="ai" <?php selected( $alt_source, 'ai' ); ?>>
						<?php esc_html_e( 'AI Generated (Requires API)', SMARTALT_TEXT_DOMAIN ); ?>
					</option>
				</select>
				<div class="smartalt-help">
					<?php esc_html_e( 'Choose the source for generating alt text. AI provides more descriptive alts but requires an API key.', SMARTALT_TEXT_DOMAIN ); ?>
				</div>
			</div>

			<div class="smartalt-field">
				<label for="smartalt_injection_method"><?php esc_html_e( 'Frontend Injection Method', SMARTALT_TEXT_DOMAIN ); ?></label>
				<select id="smartalt_injection_method" name="smartalt_injection_method">
					<option value="server_buffer" <?php selected( $injection_method, 'server_buffer' ); ?>>
						<?php esc_html_e( 'Server-side Buffer (Recommended)', SMARTALT_TEXT_DOMAIN ); ?>
					</option>
					<option value="js_injection" <?php selected( $injection_method, 'js_injection' ); ?>>
						<?php esc_html_e( 'JavaScript (Fallback only)', SMARTALT_TEXT_DOMAIN ); ?>
					</option>
				</select>
				<div class="smartalt-help">
					<?php esc_html_e( 'Server-side is best for SEO and performance. JS is slower and doesn\'t help crawlers.', SMARTALT_TEXT_DOMAIN ); ?>
				</div>
			</div>

			<div class="smartalt-field">
				<label for="smartalt_max_alt_length"><?php esc_html_e( 'Max Alt Length', SMARTALT_TEXT_DOMAIN ); ?></label>
				<input type="number" id="smartalt_max_alt_length" name="smartalt_max_alt_length" value="<?php echo esc_attr( $max_length ); ?>" min="50" max="500" />
				<div class="smartalt-help">
					<?php esc_html_e( 'Maximum characters for alt text (50-500). Default 125 is SEO best practice.', SMARTALT_TEXT_DOMAIN ); ?>
				</div>
			</div>

			<?php submit_button(); ?>
		</form>
		<?php
	}

	/**
	 * Render AI settings section.
	 *
	 * @return void
	 */
	private function render_ai_settings() {
		$endpoint = get_option( 'smartalt_ai_endpoint', '' );
		$method = get_option( 'smartalt_ai_method', 'POST' );
		$headers = get_option( 'smartalt_ai_headers', '' );
		$request_template = get_option( 'smartalt_ai_request_template', '' );
		$response_path = get_option( 'smartalt_ai_response_path', 'text' );
		$model_name = get_option( 'smartalt_ai_model_name', 'generic_http' );
		$cache_results = (bool) get_option( 'smartalt_cache_ai_results', 1 );
		$cache_ttl = (int) get_option( 'smartalt_ai_cache_ttl_days', 90 );

		$api_key_set = ! empty( get_option( 'smartalt_ai_key' ) ) || defined( 'SMARTALT_AI_KEY' );

		?>
		<form method="post" action="options.php">
			<?php settings_fields( self::OPTION_GROUP ); ?>

			<div class="smartalt-field">
				<label for="smartalt_ai_endpoint"><?php esc_html_e( 'API Endpoint URL', SMARTALT_TEXT_DOMAIN ); ?></label>
				<input type="text" id="smartalt_ai_endpoint" name="smartalt_ai_endpoint" value="<?php echo esc_attr( $endpoint ); ?>" placeholder="https://api.example.com/generate-alt" />
				<div class="smartalt-help">
					<?php esc_html_e( 'Full HTTP(S) URL to your AI service endpoint.', SMARTALT_TEXT_DOMAIN ); ?>
				</div>
			</div>

			<div class="smartalt-field">
				<label for="smartalt_ai_method"><?php esc_html_e( 'HTTP Method', SMARTALT_TEXT_DOMAIN ); ?></label>
				<select id="smartalt_ai_method" name="smartalt_ai_method">
					<option value="POST" <?php selected( $method, 'POST' ); ?>>POST</option>
					<option value="GET" <?php selected( $method, 'GET' ); ?>>GET</option>
				</select>
			</div>

			<div class="smartalt-field">
				<label for="smartalt_ai_headers"><?php esc_html_e( 'HTTP Headers (JSON)', SMARTALT_TEXT_DOMAIN ); ?></label>
				<textarea id="smartalt_ai_headers" name="smartalt_ai_headers" placeholder='{"Content-Type": "application/json"}'><?php echo esc_textarea( $headers ); ?></textarea>
				<div class="smartalt-help">
					<?php esc_html_e( 'Custom headers as JSON object. Will be merged with default headers.', SMARTALT_TEXT_DOMAIN ); ?>
				</div>
			</div>

			<div class="smartalt-field">
				<label for="smartalt_ai_request_template"><?php esc_html_e( 'Request Body Template', SMARTALT_TEXT_DOMAIN ); ?></label>
				<textarea id="smartalt_ai_request_template" name="smartalt_ai_request_template" placeholder='{"image_url": "{image_url}", "prompt": "Describe this image"}'><?php echo esc_textarea( $request_template ); ?></textarea>
				<div class="smartalt-help">
					<?php esc_html_e( 'JSON template with placeholders: {image_url}, {post_title}, {post_excerpt}, {post_content}, {image_filename}, {max_length}', SMARTALT_TEXT_DOMAIN ); ?>
				</div>
			</div>

			<div class="smartalt-field">
				<label for="smartalt_ai_response_path"><?php esc_html_e( 'Response JSON Path', SMARTALT_TEXT_DOMAIN ); ?></label>
				<input type="text" id="smartalt_ai_response_path" name="smartalt_ai_response_path" value="<?php echo esc_attr( $response_path ); ?>" placeholder="data.alt_text" />
				<div class="smartalt-help">
					<?php esc_html_e( 'Dot notation path to alt text in JSON response (e.g., "data.result.text").', SMARTALT_TEXT_DOMAIN ); ?>
				</div>
			</div>

			<div class="smartalt-field">
				<label for="smartalt_ai_model_name"><?php esc_html_e( 'Model Name/ID', SMARTALT_TEXT_DOMAIN ); ?></label>
				<input type="text" id="smartalt_ai_model_name" name="smartalt_ai_model_name" value="<?php echo esc_attr( $model_name ); ?>" placeholder="gpt-4-vision" />
				<div class="smartalt-help">
					<?php esc_html_e( 'Identifier for the AI model (for logging purposes).', SMARTALT_TEXT_DOMAIN ); ?>
				</div>
			</div>

			<div class="smartalt-field">
				<label for="smartalt_ai_key"><?php esc_html_e( 'API Key', SMARTALT_TEXT_DOMAIN ); ?></label>
				<?php if ( defined( 'SMARTALT_AI_KEY' ) ) : ?>
					<div class="smartalt-help" style="color: #28a745; font-weight: bold;">
						<?php esc_html_e( '✓ API key set via environment variable (SMARTALT_AI_KEY)', SMARTALT_TEXT_DOMAIN ); ?>
					</div>
				<?php else : ?>
					<input type="password" id="smartalt_ai_key" name="smartalt_ai_key" value="" placeholder="<?php echo $api_key_set ? '••••••••' : ''; ?>" />
					<div class="smartalt-help">
						<?php esc_html_e( 'Or set SMARTALT_AI_KEY in wp-config.php for better security (recommended).', SMARTALT_TEXT_DOMAIN ); ?>
					</div>
				<?php endif; ?>
			</div>

			<div class="smartalt-field">
				<label for="smartalt_cache_ai_results">
					<input type="checkbox" id="smartalt_cache_ai_results" name="smartalt_cache_ai_results" value="1" <?php checked( $cache_results ); ?> />
					<?php esc_html_e( 'Cache AI Results', SMARTALT_TEXT_DOMAIN ); ?>
				</label>
				<div class="smartalt-help">
					<?php esc_html_e( 'Store AI-generated alts for 90 days to avoid redundant API calls.', SMARTALT_TEXT_DOMAIN ); ?>
				</div>
			</div>

			<div class="smartalt-field">
				<label for="smartalt_ai_cache_ttl_days"><?php esc_html_e( 'AI Cache TTL (Days)', SMARTALT_TEXT_DOMAIN ); ?></label>
				<input type="number" id="smartalt_ai_cache_ttl_days" name="smartalt_ai_cache_ttl_days" value="<?php echo esc_attr( $cache_ttl ); ?>" min="1" max="365" />
				<div class="smartalt-help">
					<?php esc_html_e( 'Re-query AI after this many days for potentially improved results.', SMARTALT_TEXT_DOMAIN ); ?>
				</div>
			</div>

			<div class="button-group">
				<button type="button" class="button button-secondary" id="smartalt-test-connection">
					<?php esc_html_e( 'Test Connection', SMARTALT_TEXT_DOMAIN ); ?>
				</button>
				<button type="button" class="button button-secondary" id="smartalt-clear-ai-cache">
					<?php esc_html_e( 'Clear AI Cache', SMARTALT_TEXT_DOMAIN ); ?>
				</button>
			</div>

			<?php submit_button(); ?>
		</form>

		<script>
			document.getElementById('smartalt-test-connection')?.addEventListener('click', function() {
				alert('Test connection functionality coming in next phase...');
			});

			document.getElementById('smartalt-clear-ai-cache')?.addEventListener('click', function() {
				if (confirm('<?php esc_attr_e( 'Clear all cached AI results? This cannot be undone.', SMARTALT_TEXT_DOMAIN ); ?>')) {
					// AJAX call to clear cache
					fetch(ajaxurl, {
						method: 'POST',
						headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
						body: 'action=smartalt_clear_ai_cache&_wpnonce=' + document.getElementById('_wpnonce')?.value
					}).then(r => r.json()).then(d => {
						alert(d.message || 'Done!');
					}).catch(e => alert('Error: ' + e.message));
				}
			});
		</script>
		<?php
	}

	/**
	 * Render bulk update settings section.
	 *
	 * @return void
	 */
	private function render_bulk_settings() {
		$schedule = get_option( 'smartalt_bulk_schedule', 'none' );
		$scope = get_option( 'smartalt_bulk_scope', 'attached_only' );
		$batch_size = (int) get_option( 'smartalt_batch_size', 100 );
		$force_update = (bool) get_option( 'smartalt_bulk_force_update' );

		?>
		<form method="post" action="options.php">
			<?php settings_fields( self::OPTION_GROUP ); ?>

			<div class="smartalt-field">
				<label for="smartalt_bulk_schedule"><?php esc_html_e( 'Auto-Schedule Bulk Updates', SMARTALT_TEXT_DOMAIN ); ?></label>
				<select id="smartalt_bulk_schedule" name="smartalt_bulk_schedule">
					<option value="none" <?php selected( $schedule, 'none' ); ?>>
						<?php esc_html_e( 'Disabled (Manual only)', SMARTALT_TEXT_DOMAIN ); ?>
					</option>
					<option value="daily" <?php selected( $schedule, 'daily' ); ?>>
						<?php esc_html_e( 'Daily', SMARTALT_TEXT_DOMAIN ); ?>
					</option>
					<option value="weekly" <?php selected( $schedule, 'weekly' ); ?>>
						<?php esc_html_e( 'Weekly', SMARTALT_TEXT_DOMAIN ); ?>
					</option>
				</select>
				<div class="smartalt-help">
					<?php esc_html_e( 'Automatic bulk updates via WP-Cron. Requires valid wp-cron setup.', SMARTALT_TEXT_DOMAIN ); ?>
				</div>
			</div>

			<div class="smartalt-field">
				<label for="smartalt_bulk_scope"><?php esc_html_e( 'Bulk Scope', SMARTALT_TEXT_DOMAIN ); ?></label>
				<select id="smartalt_bulk_scope" name="smartalt_bulk_scope">
					<option value="all_media" <?php selected( $scope, 'all_media' ); ?>>
						<?php esc_html_e( 'All Media Library', SMARTALT_TEXT_DOMAIN ); ?>
					</option>
					<option value="attached_only" <?php selected( $scope, 'attached_only' ); ?>>
						<?php esc_html_e( 'Attached to Posts (Recommended)', SMARTALT_TEXT_DOMAIN ); ?>
					</option>
					<option value="attached_products" <?php selected( $scope, 'attached_products' ); ?>>
						<?php esc_html_e( 'WooCommerce Products Only', SMARTALT_TEXT_DOMAIN ); ?>
					</option>
				</select>
			</div>

			<div class="smartalt-field">
				<label for="smartalt_batch_size"><?php esc_html_e( 'Batch Size', SMARTALT_TEXT_DOMAIN ); ?></label>
				<input type="number" id="smartalt_batch_size" name="smartalt_batch_size" value="<?php echo esc_attr( $batch_size ); ?>" min="10" max="500" />
				<div class="smartalt-help">
					<?php esc_html_e( 'Number of images to process per batch (10-500). Lower = less server load, higher = faster.', SMARTALT_TEXT_DOMAIN ); ?>
				</div>
			</div>

			<div class="smartalt-field">
				<label for="smartalt_bulk_force_update">
					<input type="checkbox" id="smartalt_bulk_force_update" name="smartalt_bulk_force_update" value="1" <?php checked( $force_update ); ?> />
					<?php esc_html_e( 'Force Update Existing Alt Text', SMARTALT_TEXT_DOMAIN ); ?>
				</label>
				<div class="smartalt-help">
					<?php esc_html_e( 'If checked, bulk updates will overwrite existing alt text. Otherwise only empty alts are updated.', SMARTALT_TEXT_DOMAIN ); ?>
				</div>
			</div>

			<div class="button-group">
				<button type="button" class="button button-primary" id="smartalt-bulk-run">
					<?php esc_html_e( '▶ Run Bulk Update Now', SMARTALT_TEXT_DOMAIN ); ?>
				</button>
				<button type="button" class="button button-secondary" id="smartalt-bulk-dry-run">
					<?php esc_html_e( '👁 Dry Run (Preview)', SMARTALT_TEXT_DOMAIN ); ?>
				</button>
			</div>

			<div id="smartalt-bulk-progress" style="display:none; margin-top: 20px;">
				<div class="smartalt-field">
					<div style="font-weight: bold;">Progress</div>
					<progress id="smartalt-progress-bar" value="0" max="100" style="width: 100%; height: 30px;"></progress>
					<div id="smartalt-progress-text" style="margin-top: 10px;"></div>
				</div>
			</div>

			<?php submit_button(); ?>
		</form>

		<script>
			document.getElementById('smartalt-bulk-run')?.addEventListener('click', function() {
				if (confirm('<?php esc_attr_e( 'Start bulk update? This may take several minutes.', SMARTALT_TEXT_DOMAIN ); ?>')) {
					smartaltBulkRun();
				}
			});

			document.getElementById('smartalt-bulk-dry-run')?.addEventListener('click', function() {
				alert('Dry run coming in next phase...');
			});

			function smartaltBulkRun() {
				document.getElementById('smartalt-bulk-progress').style.display = 'block';
				// AJAX implementation in next phase
			}
		</script>
		<?php
	}

	/**
	 * Render logs and statistics section.
	 *
	 * @return void
	 */
	private function render_logs_stats() {
		$stats = Logger::get_stats();
		$logs = Logger::get_logs( [ 'limit' => 20 ] );

		?>
		<div class="smartalt-stats">
			<div class="smartalt-stat-card">
				<div class="label"><?php esc_html_e( 'Total Logged', SMARTALT_TEXT_DOMAIN ); ?></div>
				<div class="value"><?php echo esc_html( $stats['total_logged'] ); ?></div>
			</div>
			<div class="smartalt-stat-card">
				<div class="label"><?php esc_html_e( 'AI Generated', SMARTALT_TEXT_DOMAIN ); ?></div>
				<div class="value"><?php echo esc_html( $stats['ai_generated'] ); ?></div>
			</div>
			<div class="smartalt-stat-card">
				<div class="label"><?php esc_html_e( 'Errors', SMARTALT_TEXT_DOMAIN ); ?></div>
				<div class="value" style="color: #dc3545;"><?php echo esc_html( $stats['errors'] ); ?></div>
			</div>
			<div class="smartalt-stat-card">
				<div class="label"><?php esc_html_e( 'Last Run', SMARTALT_TEXT_DOMAIN ); ?></div>
				<div class="value" style="font-size: 14px;"><?php echo $stats['last_run'] ? esc_html( $stats['last_run'] ) : esc_html_e( 'Never', SMARTALT_TEXT_DOMAIN ); ?></div>
			</div>
		</div>

		<table class="widefat striped" style="margin-top: 20px;">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Time', SMARTALT_TEXT_DOMAIN ); ?></th>
					<th><?php esc_html_e( 'Attachment', SMARTALT_TEXT_DOMAIN ); ?></th>
					<th><?php esc_html_e( 'Source', SMARTALT_TEXT_DOMAIN ); ?></th>
					<th><?php esc_html_e( 'Old Alt', SMARTALT_TEXT_DOMAIN ); ?></th>
					<th><?php esc_html_e( 'New Alt', SMARTALT_TEXT_DOMAIN ); ?></th>
					<th><?php esc_html_e( 'Status', SMARTALT_TEXT_DOMAIN ); ?></th>
					<th><?php esc_html_e( 'Action', SMARTALT_TEXT_DOMAIN ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $logs as $log ) : ?>
					<tr>
						<td><?php echo esc_html( $log->time ); ?></td>
						<td>
							<?php if ( $log->attachment_id ) : ?>
								<a href="<?php echo esc_url( get_edit_post_link( $log->attachment_id ) ); ?>" target="_blank">
									<?php echo esc_html( '#' . $log->attachment_id ); ?>
								</a>
							<?php else : ?>
								—
							<?php endif; ?>
						</td>
						<td><?php echo esc_html( $log->source ); ?></td>
						<td><small><?php echo esc_html( substr( $log->old_alt, 0, 50 ) ); ?></small></td>
						<td><small><?php echo esc_html( substr( $log->new_alt, 0, 50 ) ); ?></small></td>
						<td>
							<span class="status-<?php echo esc_attr( $log->status ); ?>">
								<?php echo esc_html( ucfirst( $log->status ) ); ?>
							</span>
						</td>
						<td>
							<?php if ( 'success' === $log->status && $log->old_alt ) : ?>
								<button class="button button-small smartalt-revert" data-log-id="<?php echo esc_attr( $log->id ); ?>">
									<?php esc_html_e( 'Revert', SMARTALT_TEXT_DOMAIN ); ?>
								</button>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>

		<style>
			.status-success { color: #28a745; font-weight: bold; }
			.status-error { color: #dc3545; font-weight: bold; }
			.status-skipped { color: #ffc107; font-weight: bold; }
		</style>
		<?php
	}

	/**
	 * Add dashboard widget.
	 *
	 * @return void
	 */
	public function add_dashboard_widget() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		wp_add_dashboard_widget(
			'smartalt_dashboard_widget',
			__( 'Smart Alt Optimizer', SMARTALT_TEXT_DOMAIN ),
			[ $this, 'render_dashboard_widget' ]
		);
	}

	/**
	 * Render dashboard widget.
	 *
	 * @return void
	 */
	public function render_dashboard_widget() {
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
		$coverage = $total_attachments > 0 ? round( ( $with_alt / $total_attachments ) * 100, 1 ) : 0;

		$stats = Logger::get_stats();

		?>
		<div style="text-align: center; padding: 20px;">
			<div style="font-size: 48px; font-weight: bold; color: #0073aa;">
				<?php echo esc_html( $coverage ); ?>%
			</div>
			<div style="font-size: 14px; color: #666; margin-bottom: 20px;">
				<?php esc_html_e( 'Alt Coverage', SMARTALT_TEXT_DOMAIN ); ?>
			</div>

			<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
				<div style="background: #e8f5e9; padding: 15px; border-radius: 3px;">
					<div style="font-weight: bold; color: #2e7d32;"><?php echo esc_html( $with_alt ); ?></div>
					<div style="font-size: 12px; color: #666;"><?php esc_html_e( 'With Alt', SMARTALT_TEXT_DOMAIN ); ?></div>
				</div>
				<div style="background: #ffebee; padding: 15px; border-radius: 3px;">
					<div style="font-weight: bold; color: #c62828;"><?php echo esc_html( $without_alt ); ?></div>
					<div style="font-size: 12px; color: #666;"><?php esc_html_e( 'Missing Alt', SMARTALT_TEXT_DOMAIN ); ?></div>
				</div>
			</div>

			<div style="margin-top: 15px; font-size: 12px; color: #999;">
				<?php echo sprintf( esc_html__( 'AI Generated: %d | Last: %s', SMARTALT_TEXT_DOMAIN ), esc_html( $stats['ai_generated'] ), esc_html( $stats['last_run'] ? $stats['last_run'] : __( 'Never', SMARTALT_TEXT_DOMAIN ) ) ); ?>
			</div>

			<div style="margin-top: 15px;">
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=smartalt-settings' ) ); ?>" class="button button-primary">
					<?php esc_html_e( 'View Settings', SMARTALT_TEXT_DOMAIN ); ?>
				</a>
			</div>
		</div>
		<?php
	}
}