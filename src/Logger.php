<?php
/**
 * Logger - Structured logging for alt text changes.
 *
 * @package SmartAlt
 */

namespace SmartAlt;

use SmartAlt\Utils\Sanitize;

/**
 * Logger singleton for structured logging.
 */
class Logger {

	/**
	 * Singleton instance.
	 *
	 * @var Logger
	 */
	private static $instance = null;

	/**
	 * Database table name.
	 *
	 * @var string
	 */
	private $table_name;

	/**
	 * Get singleton instance.
	 *
	 * @return Logger
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
	private function __construct() {
		global $wpdb;
		$this->table_name = $wpdb->prefix . 'smartalt_logs';
	}

	/**
	 * Create the logs table.
	 *
	 * @return void
	 */
	public static function create_table() {
		global $wpdb;
		$table_name = $wpdb->prefix . 'smartalt_logs';

		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE IF NOT EXISTS {$table_name} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
			site_id INT(11) NOT NULL DEFAULT 1,
			time DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			attachment_id BIGINT(20) UNSIGNED NULL,
			post_id BIGINT(20) UNSIGNED NULL,
			old_alt LONGTEXT NULL,
			new_alt LONGTEXT NULL,
			source VARCHAR(50) NOT NULL DEFAULT 'manual',
			model VARCHAR(100) NULL,
			user_id BIGINT(20) UNSIGNED NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'success',
			log_level VARCHAR(20) NOT NULL DEFAULT 'info',
			message LONGTEXT NULL,
			INDEX idx_site_time (site_id, time),
			INDEX idx_attachment (attachment_id),
			INDEX idx_post (post_id),
			INDEX idx_status (status)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Log a change to alt text.
	 *
	 * @param string       $old_alt      Old alt text.
	 * @param string       $new_alt      New alt text.
	 * @param int|null     $attachment_id Attachment ID.
	 * @param int|null     $post_id      Post ID.
	 * @param string       $source       Source: 'post_title', 'ai', 'manual', 'system'.
	 * @param string|null  $model        AI model used (if applicable).
	 * @param string       $status       'success', 'error', 'skipped'.
	 * @param string|null  $message      Optional message/error description.
	 * @param string       $log_level    'info', 'error', 'debug'.
	 *
	 * @return int|bool Insert ID or false on failure.
	 */
	public static function log(
		$old_alt = null,
		$new_alt = null,
		$attachment_id = null,
		$post_id = null,
		$source = 'manual',
		$model = null,
		$status = 'success',
		$message = null,
		$log_level = 'info'
	) {
		if ( ! (bool) get_option( 'smartalt_logging_enabled' ) ) {
			return false;
		}

		// Check log level setting
		$min_log_level = get_option( 'smartalt_log_level', 'info' );
		if ( ! self::should_log( $log_level, $min_log_level ) ) {
			return false;
		}

		global $wpdb;
		$logger = self::instance();
		$current_user = wp_get_current_user();

		$data = [
			'site_id'       => get_current_blog_id(),
			'time'          => current_time( 'mysql' ),
			'attachment_id' => $attachment_id ? (int) $attachment_id : null,
			'post_id'       => $post_id ? (int) $post_id : null,
			'old_alt'       => $old_alt ? Sanitize::alt_text( $old_alt ) : null,
			'new_alt'       => $new_alt ? Sanitize::alt_text( $new_alt ) : null,
			'source'        => sanitize_text_field( $source ),
			'model'         => $model ? sanitize_text_field( $model ) : null,
			'user_id'       => $current_user->ID ? $current_user->ID : null,
			'status'        => sanitize_text_field( $status ),
			'log_level'     => sanitize_text_field( $log_level ),
			'message'       => $message ? sanitize_text_field( $message ) : null,
		];

		$result = $wpdb->insert( $logger->table_name, $data );

		return $result ? $wpdb->insert_id : false;
	}

	/**
	 * Determine if a log message should be logged based on level.
	 *
	 * @param string $message_level Level of the message.
	 * @param string $min_level     Minimum level to log.
	 *
	 * @return bool
	 */
	private static function should_log( $message_level, $min_level ) {
		$levels = [ 'debug' => 0, 'info' => 1, 'error' => 2 ];
		$message_priority = $levels[ $message_level ] ?? 1;
		$min_priority = $levels[ $min_level ] ?? 1;
		return $message_priority >= $min_priority;
	}

	/**
	 * Get logs with optional filtering.
	 *
	 * @param array $args Query arguments.
	 *
	 * @return array Array of log entries.
	 */
	public static function get_logs( $args = [] ) {
		global $wpdb;
		$logger = self::instance();

		$defaults = [
			'limit'       => 50,
			'offset'      => 0,
			'attachment_id' => null,
			'post_id'     => null,
			'status'      => null,
			'source'      => null,
			'order'       => 'DESC',
		];

		$args = wp_parse_args( $args, $defaults );

		$query = "SELECT * FROM {$logger->table_name} WHERE 1=1";

		if ( $args['attachment_id'] ) {
			$query .= $wpdb->prepare( ' AND attachment_id = %d', $args['attachment_id'] );
		}

		if ( $args['post_id'] ) {
			$query .= $wpdb->prepare( ' AND post_id = %d', $args['post_id'] );
		}

		if ( $args['status'] ) {
			$query .= $wpdb->prepare( ' AND status = %s', $args['status'] );
		}

		if ( $args['source'] ) {
			$query .= $wpdb->prepare( ' AND source = %s', $args['source'] );
		}

		$query .= " ORDER BY time {$args['order']}";
		$query .= $wpdb->prepare( ' LIMIT %d OFFSET %d', $args['limit'], $args['offset'] );

		return $wpdb->get_results( $query );
	}

	/**
	 * Prune logs older than retention days.
	 *
	 * @return int Number of rows deleted.
	 */
	public static function prune_logs() {
		global $wpdb;
		$logger = self::instance();

		$retention_days = (int) get_option( 'smartalt_log_retention_days', 30 );
		$cutoff_date = gmdate( 'Y-m-d H:i:s', strtotime( "-{$retention_days} days" ) );

		$result = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$logger->table_name} WHERE time < %s",
				$cutoff_date
			)
		);

		return $result ? $result : 0;
	}

	/**
	 * Get log statistics.
	 *
	 * @return array Statistics.
	 */
	public static function get_stats() {
		global $wpdb;
		$logger = self::instance();

		$total = $wpdb->get_var( "SELECT COUNT(*) FROM {$logger->table_name}" );
		$errors = $wpdb->get_var( "SELECT COUNT(*) FROM {$logger->table_name} WHERE status = 'error'" );
		$ai_generated = $wpdb->get_var( "SELECT COUNT(*) FROM {$logger->table_name} WHERE source = 'ai'" );
		$last_run = $wpdb->get_var( "SELECT MAX(time) FROM {$logger->table_name}" );

		return [
			'total_logged'   => (int) $total,
			'errors'         => (int) $errors,
			'ai_generated'   => (int) $ai_generated,
			'last_run'       => $last_run,
		];
	}
}