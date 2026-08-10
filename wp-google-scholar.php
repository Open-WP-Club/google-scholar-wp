<?php

/**
 * Plugin Name: Google Scholar Profile Display
 * Plugin URI: https://openwpclub.com/
 * Description: Displays Google Scholar profile information using shortcode [scholar_profile]
 * Version: 1.6.0
 * Author: OpenWPClub.com
 * Author URI: https://openwpclub.com/
 * License: GPL v2 or later
 * Text Domain: wp-google-scholar
 * Domain Path: /languages
 * Requires at least: 5.0
 * Requires PHP: 7.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
  exit;
}

// Define plugin constants
define('WP_SCHOLAR_VERSION', '1.6.0');
define('WP_SCHOLAR_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('WP_SCHOLAR_PLUGIN_URL', plugin_dir_url(__FILE__));
define('WP_SCHOLAR_MAX_CONSECUTIVE_FAILURES', 5);

// Autoload classes
spl_autoload_register('wp_scholar_autoload');

function wp_scholar_autoload($class)
{
  $prefix = 'WPScholar\\';
  $base_dir = WP_SCHOLAR_PLUGIN_DIR . 'includes/';

  $len = strlen($prefix);
  if (strncmp($prefix, $class, $len) !== 0) {
    return;
  }

  $relative_class = substr($class, $len);
  $file = $base_dir . str_replace('\\', '/', strtolower($relative_class)) . '.php';

  if (file_exists($file)) {
    require $file;
  }
}

// Initialize plugin
add_action('plugins_loaded', 'wp_scholar_init');

function wp_scholar_init()
{
  // Load text domain
  load_plugin_textdomain('wp-google-scholar', false, dirname(plugin_basename(__FILE__)) . '/languages');

  // Initialize classes
  $settings = new WPScholar\Settings();
  new WPScholar\Shortcode();
  new WPScholar\Scheduler();
  new WPScholar\RestApi($settings);
}

// Enqueue admin styles
add_action('admin_enqueue_scripts', 'wp_scholar_enqueue_admin_styles');

function wp_scholar_enqueue_admin_styles($hook)
{
  // Only load on our settings page
  if ('settings_page_scholar-profile-settings' !== $hook) {
    return;
  }

  wp_enqueue_style(
    'scholar-profile-admin-styles',
    WP_SCHOLAR_PLUGIN_URL . 'assets/css/admin-style.css',
    array(),
    WP_SCHOLAR_VERSION
  );
}

// Activation hook
register_activation_hook(__FILE__, 'wp_scholar_activate');

function wp_scholar_activate()
{
  // Log activation for debugging
  wp_scholar_log("Google Scholar Profile plugin activated - Version: " . WP_SCHOLAR_VERSION);

  // Activate scheduler
  $scheduler = new WPScholar\Scheduler();
  $scheduler->activate();

  // Set default options if they don't exist
  if (!get_option('scholar_profile_settings')) {
    update_option('scholar_profile_settings', array(
      'profile_id' => '',
      'show_avatar' => '1',
      'show_info' => '1',
      'show_publications' => '1',
      'show_coauthors' => '1',
      'update_frequency' => 'weekly',
      'max_publications' => '200',
      'update_method' => 'server',
      'expand_authors' => '0'
    ));
    wp_scholar_log("Default plugin settings created");
  } else {
    // Add new setting to existing options if it doesn't exist
    $options = get_option('scholar_profile_settings');
    $updated = false;

    if (!isset($options['max_publications'])) {
      $options['max_publications'] = '200';
      $updated = true;
    }

    if (!isset($options['update_method'])) {
      $options['update_method'] = 'server';
      $updated = true;
    }

    if (!isset($options['expand_authors'])) {
      $options['expand_authors'] = '0';
      $updated = true;
    }

    if ($updated) {
      update_option('scholar_profile_settings', $options);
      wp_scholar_log("Plugin settings updated with new options");
    }
  }

  // Clear any stale error details on activation
  delete_option('scholar_profile_last_error_details');
  wp_scholar_log("Cleared any existing error details on activation");
}

// Deactivation hook
register_deactivation_hook(__FILE__, 'wp_scholar_deactivate');

function wp_scholar_deactivate()
{
  wp_scholar_log("Google Scholar Profile plugin deactivated");

  $scheduler = new WPScholar\Scheduler();
  $scheduler->deactivate();

  // Clear any scheduled error notifications
  wp_clear_scheduled_hook('scholar_profile_cleanup_errors');
}

// Uninstall hook (clean up on uninstall)
register_uninstall_hook(__FILE__, 'wp_scholar_uninstall');

function wp_scholar_uninstall()
{
  // Remove all plugin options
  delete_option('scholar_profile_settings');
  delete_option('scholar_profile_data');
  delete_option('scholar_profile_last_update');
  delete_option('scholar_profile_last_manual_refresh');
  delete_option('scholar_profile_data_status');
  delete_option('scholar_profile_consecutive_failures');
  delete_option('scholar_profile_last_error_details');

  // Remove all cached images from media library
  $attachments = get_posts(array(
    'post_type' => 'attachment',
    'meta_key' => '_scholar_profile_id',
    'posts_per_page' => -1,
    'fields' => 'ids'
  ));

  foreach ($attachments as $attachment_id) {
    wp_delete_attachment($attachment_id, true);
  }

  // Clean up any scheduled events
  wp_clear_scheduled_hook('scholar_profile_update');
  wp_clear_scheduled_hook('scholar_profile_cleanup_errors');

  // Log uninstall for debugging purposes
  if (defined('WP_DEBUG') && WP_DEBUG) {
    error_log('[Google Scholar Profile] Plugin uninstalled and all data removed');
  }
}

/**
 * Enhanced logging function with configurable logging levels
 *
 * @param string|array $message Message to log (string or array/object)
 * @param string $level Log level: 'debug', 'info', 'warning', 'error'
 * @return void
 */
function wp_scholar_log($message, $level = 'info')
{
  // Only log if WP_DEBUG_LOG is enabled
  if (!defined('WP_DEBUG_LOG') || WP_DEBUG_LOG !== true) {
    return;
  }

  // Define logging level priorities
  $levels = array(
    'debug' => 0,
    'info' => 1,
    'warning' => 2,
    'error' => 3
  );

  // Cache minimum logging level to avoid DB query on every call
  static $cached_min_level = null;
  if ($cached_min_level === null) {
    $cached_min_level = get_option('scholar_profile_log_level', 'info');
  }
  $min_level = $cached_min_level;

  // If WP_DEBUG is true, log everything (debug level)
  if (defined('WP_DEBUG') && WP_DEBUG === true) {
    $min_level = 'debug';
  }

  // Validate levels
  if (!isset($levels[$level]) || !isset($levels[$min_level])) {
    $level = 'info';
    $min_level = 'info';
  }

  // Only log if message level >= minimum level
  if ($levels[$level] >= $levels[$min_level]) {
    $timestamp = current_time('Y-m-d H:i:s');
    $formatted_message = sprintf(
      '[%s] [Google Scholar Profile] [%s] %s',
      $timestamp,
      strtoupper($level),
      is_string($message) ? $message : print_r($message, true)
    );

    error_log($formatted_message);
  }
}

// Add admin notice for persistent errors (shown to admins only)
add_action('admin_notices', 'wp_scholar_admin_notices');

function wp_scholar_admin_notices()
{
  // Only show to users who can manage options
  if (!current_user_can('manage_options')) {
    return;
  }

  // Check for persistent error conditions
  $error_details = get_option('scholar_profile_last_error_details');
  $consecutive_failures = get_option('scholar_profile_consecutive_failures', 0);
  $options = get_option('scholar_profile_settings');

  // Show notice for persistent failures
  if ($consecutive_failures >= WP_SCHOLAR_MAX_CONSECUTIVE_FAILURES && !empty($options['profile_id'])) {
    $current_screen = get_current_screen();

    // Don't show on the plugin's own settings page (to avoid duplicate notices)
    if ($current_screen && $current_screen->id !== 'settings_page_scholar-profile-settings') {
      $error_type = isset($error_details['type']) ? $error_details['type'] : 'unknown';
      $settings_url = admin_url('options-general.php?page=scholar-profile-settings');

      // translators: %d is the number of consecutive failures
      $notice_message = sprintf(
        __('Google Scholar Profile: %d consecutive update failures detected. ', 'wp-google-scholar'),
        $consecutive_failures
      );

      // Add specific guidance based on error type
      switch ($error_type) {
        case 'blocked_access':
          $notice_message .= __('Your server IP appears to be blocked by Google Scholar.', 'wp-google-scholar');
          break;
        case 'profile_not_found':
          $notice_message .= __('The configured profile could not be found.', 'wp-google-scholar');
          break;
        default:
          $notice_message .= __('Please check your configuration.', 'wp-google-scholar');
          break;
      }

      $notice_message .= sprintf(
        ' <a href="%s">%s</a>',
        esc_url($settings_url),
        __('View Settings', 'wp-google-scholar')
      );

      echo '<div class="notice notice-warning"><p>' . wp_kses($notice_message, array(
        'a' => array('href' => array())
      )) . '</p></div>';
    }
  }
}

// Clean up old error details periodically
add_action('scholar_profile_cleanup_errors', 'wp_scholar_cleanup_errors');

function wp_scholar_cleanup_errors()
{
  $error_details = get_option('scholar_profile_last_error_details');
  $consecutive_failures = get_option('scholar_profile_consecutive_failures', 0);

  // If no recent failures and we have old error details, clean them up
  if ($consecutive_failures === 0 && $error_details) {
    delete_option('scholar_profile_last_error_details');
    wp_scholar_log("Cleaned up old error details - no recent failures");
  }
}

// Add helpful links to plugin page
add_filter('plugin_row_meta', 'wp_scholar_plugin_row_meta', 10, 2);

function wp_scholar_plugin_row_meta($links, $file)
{
  if (plugin_basename(__FILE__) === $file) {
    $row_meta = array(
      'docs' => '<a href="https://github.com/Open-WP-Club/wp-google-scholar" target="_blank">' . __('Documentation', 'wp-google-scholar') . '</a>',
      'support' => '<a href="https://github.com/Open-WP-Club/wp-google-scholar/issues" target="_blank">' . __('Support', 'wp-google-scholar') . '</a>',
    );
    return array_merge($links, $row_meta);
  }
  return $links;
}
