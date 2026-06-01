<?php

namespace WPScholar;

if (!defined('ABSPATH')) {
  exit; // Exit if accessed directly
}

class Settings
{
  private $option_name = 'scholar_profile_settings';
  private $page_slug = 'scholar-profile-settings';

  // Constants for validation and rate limiting
  public const REFRESH_COOLDOWN_SECONDS = 300; // 5 minutes
private const MIN_PROFILE_ID_LENGTH = 8;
  private const MAX_PROFILE_ID_LENGTH = 20;
  public const DATA_STALE_AGE_DAYS = 90; // Public so Scheduler can access it

  public function __construct()
  {
    add_action('admin_menu', array($this, 'add_menu_page'));
    add_action('admin_init', array($this, 'register_settings'));
    add_action('admin_init', array($this, 'handle_form_submission'));
    add_action('admin_post_refresh_scholar_profile', array($this, 'handle_manual_refresh'));
    add_action('admin_post_clear_stale_data', array($this, 'handle_clear_stale_data'));
    add_filter(
      'plugin_action_links_' . plugin_basename(WP_SCHOLAR_PLUGIN_DIR . 'wp-google-scholar.php'),
      array($this, 'add_settings_link')
    );
  }

  public function add_menu_page()
  {
    add_options_page(
      __('Google Scholar Profile Settings', 'wp-google-scholar'),
      __('Scholar Profile', 'wp-google-scholar'),
      'manage_options',
      $this->page_slug,
      array($this, 'render_settings_page')
    );
  }

  public function register_settings()
  {
    // Since we're handling all form processing manually, we don't need WordPress 
    // to register or process the settings. This prevents the double processing
    // that was causing checkbox values to be overridden.

    // WordPress admin pages work fine without register_setting when using custom handlers
  }

  public function handle_form_submission()
  {
    // Check if this is our settings form submission
    if (!isset($_POST['scholar_profile_settings']) || !isset($_POST['scholar_settings_nonce'])) {
      return;
    }

    // Verify user permissions
    if (!current_user_can('manage_options')) {
      wp_die(__('You do not have sufficient permissions to access this page.', 'wp-google-scholar'));
    }

    // Verify nonce
    if (!wp_verify_nonce($_POST['scholar_settings_nonce'], 'scholar_profile_settings')) {
      wp_die(__('Security check failed.', 'wp-google-scholar'));
    }

    // Sanitize and validate settings
    $input = $_POST['scholar_profile_settings'];
    $validation_errors = array();

    // Validate profile ID format
    if (!empty($input['profile_id'])) {
      $profile_id = sanitize_text_field(trim($input['profile_id']));

      // Check length (Google Scholar IDs are typically 12 characters, but allow some variation)
      if (strlen($profile_id) < self::MIN_PROFILE_ID_LENGTH || strlen($profile_id) > self::MAX_PROFILE_ID_LENGTH) {
        // translators: %1$d is minimum length, %2$d is maximum length
        $validation_errors[] = sprintf(
          __('Profile ID should be between %1$d-%2$d characters long.', 'wp-google-scholar'),
          self::MIN_PROFILE_ID_LENGTH,
          self::MAX_PROFILE_ID_LENGTH
        );
      }
      // Check format - only allow letters, numbers, underscores, and hyphens
      elseif (!preg_match('/^[a-zA-Z0-9_-]+$/', $profile_id)) {
        $validation_errors[] = __('Profile ID can only contain letters, numbers, underscores, and hyphens.', 'wp-google-scholar');
      }
      // Check for common user mistakes
      elseif (strpos($profile_id, 'user=') !== false) {
        $validation_errors[] = __('Please enter only the Profile ID, not the full URL. Remove "user=" part.', 'wp-google-scholar');
      } elseif (strpos($profile_id, 'scholar.google.com') !== false) {
        $validation_errors[] = __('Please enter only the Profile ID, not the full URL.', 'wp-google-scholar');
      }
    }

    // If there are validation errors, redirect back with errors
    if (!empty($validation_errors)) {
      $error_message = implode(' ', $validation_errors);
      wp_safe_redirect(add_query_arg(
        array(
          'page' => $this->page_slug,
          'settings-error' => urlencode($error_message)
        ),
        admin_url('options-general.php')
      ));
      exit;
    }

    // Get current settings to preserve any values not in the form
    $current_settings = get_option($this->option_name, array());

    // Sanitize and save settings
    $sanitized = $this->sanitize_settings($input, $current_settings);
    update_option($this->option_name, $sanitized);

    // Check if scheduler needs to be rescheduled
    $scheduler = new Scheduler();
    $scheduler->reschedule();

    // Redirect back to settings page with success message
    wp_safe_redirect(add_query_arg(
      array('page' => $this->page_slug, 'settings-updated' => 'true'),
      admin_url('options-general.php')
    ));
    exit;
  }

  public function handle_manual_refresh()
  {
    if (!current_user_can('manage_options')) {
      wp_die(__('You do not have sufficient permissions to access this page.'));
    }

    // Verify nonce before anything else
    if (!isset($_POST['scholar_refresh_nonce']) || !wp_verify_nonce($_POST['scholar_refresh_nonce'], 'refresh_scholar_profile')) {
      wp_die(__('Security check failed.'));
    }

    // Rate limiting: Prevent refreshes more than once every few minutes
    $last_manual_refresh = get_option('scholar_profile_last_manual_refresh', 0);
    $time_since_last = time() - $last_manual_refresh;

    if ($time_since_last < self::REFRESH_COOLDOWN_SECONDS) {
      $minutes_remaining = ceil((self::REFRESH_COOLDOWN_SECONDS - $time_since_last) / 60);
      wp_safe_redirect(add_query_arg(
        array(
          'page' => $this->page_slug,
          'refresh' => 'failed',
          'message' => 'rate_limited',
          'minutes' => $minutes_remaining
        ),
        admin_url('options-general.php')
      ));
      exit;
    }

    // Update the last manual refresh timestamp
    update_option('scholar_profile_last_manual_refresh', time());

    $options = get_option($this->option_name);
    if (empty($options['profile_id'])) {
      wp_safe_redirect(add_query_arg(
        array('page' => $this->page_slug, 'refresh' => 'failed', 'message' => 'no_profile_id'),
        admin_url('options-general.php')
      ));
      exit;
    }

    // Update status to indicate we're starting a manual refresh
    $scheduler = new Scheduler();
    $scheduler->update_data_status('updating', 'Manual refresh in progress...');

    wp_scholar_log("Starting manual refresh for profile: " . $options['profile_id']);

    $scraper = new Scraper();

    // Configure scraper limits based on settings
    $scraper_config = array(
      'max_publications' => isset($options['max_publications']) ? intval($options['max_publications']) : 200
    );
    $scraper->set_config($scraper_config);

    $data = $scraper->scrape($options['profile_id']);

    if ($data && Scraper::validate_scraped_data($data)) {
      update_option('scholar_profile_data', $data);
      update_option('scholar_profile_last_update', time());

      // Reset consecutive failures counter
      delete_option('scholar_profile_consecutive_failures');

      // Update status to success
      $scheduler->update_data_status('success', sprintf(
        'Manual refresh successful at %s - Found %d publications',
        wp_date('Y-m-d H:i:s'),
        count($data['publications'])
      ));

      wp_scholar_log("Manual refresh successful for profile: " . $options['profile_id']);

      wp_safe_redirect(add_query_arg(
        array('page' => $this->page_slug, 'refresh' => 'success'),
        admin_url('options-general.php')
      ));
    } else {
      // Manual refresh failed - get detailed error information
      $error_details = $scraper->get_last_error_details();

      $consecutive_failures = get_option('scholar_profile_consecutive_failures', 0) + 1;
      update_option('scholar_profile_consecutive_failures', $consecutive_failures);

      $existing_data = get_option('scholar_profile_data');
      $has_existing_data = !empty($existing_data) && !empty($existing_data['name']);

      if ($has_existing_data) {
        $last_update = get_option('scholar_profile_last_update', 0);
        $age_days = $last_update ? ceil((time() - $last_update) / DAY_IN_SECONDS) : 'unknown';

        $scheduler->update_data_status('stale', sprintf(
          'Manual refresh failed. Keeping existing data from %s days ago.',
          $age_days
        ));
      } else {
        $scheduler->update_data_status('error', 'Manual refresh failed and no existing data available.');
      }

      wp_scholar_log("Manual refresh failed for profile: " . $options['profile_id'], 'error');

      // Store detailed error information for display
      if ($error_details) {
        update_option('scholar_profile_last_error_details', $error_details);
      }

      // Redirect with specific error information
      $redirect_args = array(
        'page' => $this->page_slug,
        'refresh' => 'failed',
        'message' => 'scrape_failed'
      );

      // Add error type for more specific handling
      if ($error_details && isset($error_details['type'])) {
        $redirect_args['error_type'] = $error_details['type'];
      }

      wp_safe_redirect(add_query_arg($redirect_args, admin_url('options-general.php')));
    }
    exit;
  }

  /**
   * Handle clearing stale data
   */
  public function handle_clear_stale_data()
  {
    if (!current_user_can('manage_options')) {
      wp_die(__('You do not have sufficient permissions to access this page.'));
    }

    // Verify nonce
    if (!isset($_POST['scholar_clear_nonce']) || !wp_verify_nonce($_POST['scholar_clear_nonce'], 'clear_stale_data')) {
      wp_die(__('Security check failed.'));
    }

    $scheduler = new Scheduler();
    $scheduler->clear_stale_data();

    wp_safe_redirect(add_query_arg(
      array('page' => $this->page_slug, 'clear' => 'success'),
      admin_url('options-general.php')
    ));
    exit;
  }

  public function render_settings_page()
  {
    if (!current_user_can('manage_options')) {
      return;
    }

    $options = get_option($this->option_name);
    $scheduler = new Scheduler();
    $data_status = $scheduler->get_data_status();
    $is_data_stale = $scheduler->is_data_stale();
    $messages = array();

    // Debug: Log only known page-related parameters
    if (defined('WP_DEBUG') && WP_DEBUG) {
      $safe_params = array_intersect_key(
        $_GET,
        array_flip(['page', 'refresh', 'message', 'clear', 'settings-updated'])
      );
      wp_scholar_log('Settings page URL parameters: ' . print_r($safe_params, true));
    }

    // Handle settings validation errors
    if (isset($_GET['settings-error'])) {
      $messages[] = array(
        'type' => 'error',
        'message' => '⚠ ' . sanitize_text_field(urldecode($_GET['settings-error']))
      );
    }

    // Handle clear stale data status
    if (isset($_GET['clear'])) {
      if ($_GET['clear'] === 'success') {
        $messages[] = array(
          'type' => 'updated',
          'message' => __('✓ Stale data cleared successfully!', 'wp-google-scholar')
        );
      }
    }
    // Handle refresh status messages (check refresh first, before settings-updated)
    elseif (isset($_GET['refresh'])) {
      if ($_GET['refresh'] === 'success') {
        $messages[] = array(
          'type' => 'updated',
          'message' => __('✓ Profile data refreshed successfully!', 'wp-google-scholar')
        );
      } elseif ($_GET['refresh'] === 'failed') {
        // Get enhanced error message based on error type
        $error_message = $this->get_enhanced_error_message($_GET);

        $messages[] = array(
          'type' => 'error',
          'message' => '⚠ ' . $error_message,
          'is_html' => true // Allow HTML in enhanced error messages
        );
      }
    }
    // Only check for settings-updated if refresh is NOT set
    elseif (isset($_GET['settings-updated']) && $_GET['settings-updated'] === 'true') {
      $messages[] = array(
        'type' => 'updated',
        'message' => __('✓ Settings saved successfully!', 'wp-google-scholar')
      );
    }

    // Add data status warning if needed
    if ($is_data_stale && !empty(get_option('scholar_profile_data'))) {
      $status_message = $data_status['message'] ?: 'Data may be outdated';
      $messages[] = array(
        'type' => 'warning',
        'message' => '⚠ ' . __('Data Status Warning: ', 'wp-google-scholar') . $status_message
      );
    }

    include WP_SCHOLAR_PLUGIN_DIR . 'views/settings-page.php';
  }

  /**
   * Get enhanced error message based on error type and details
   */
  private function get_enhanced_error_message($get_params)
  {
    $error_details = get_option('scholar_profile_last_error_details');

    // Handle specific URL parameter messages first
    if (isset($get_params['message'])) {
      switch ($get_params['message']) {
        case 'no_profile_id':
          return __('Please enter a Profile ID before refreshing.', 'wp-google-scholar');

        case 'rate_limited':
          $minutes = isset($get_params['minutes']) ? intval($get_params['minutes']) : 5;
          // translators: %d is the number of minutes to wait
          return sprintf(
            __('Please wait %d more minute(s) before refreshing again. This prevents rate limiting from Google Scholar.', 'wp-google-scholar'),
            $minutes
          );
      }
    }

    // Use enhanced error details if available
    if ($error_details && isset($error_details['type'])) {
      $message = $this->format_detailed_error_message($error_details);
      if ($message) {
        return $message;
      }
    }

    // Fallback to generic messages
    $existing_data = get_option('scholar_profile_data');
    if (!empty($existing_data)) {
      return __('Could not retrieve new data from Google Scholar, but existing data is preserved. Please check the details below and try again later.', 'wp-google-scholar');
    } else {
      return __('Could not retrieve data from Google Scholar. Please check the details below and try again.', 'wp-google-scholar');
    }
  }

  /**
   * Format detailed error message with helpful suggestions
   */
  private function format_detailed_error_message($error_details)
  {
    if (!isset($error_details['user_message'])) {
      return null;
    }

    $message = '<strong>' . esc_html($error_details['user_message']) . '</strong>';

    if (isset($error_details['status_code'])) {
      $message .= sprintf(' <em>(HTTP %d)</em>', $error_details['status_code']);
    }

    if (!empty($error_details['suggestions']) && is_array($error_details['suggestions'])) {
      $message .= '<br><br><strong>' . __('What you can try:', 'wp-google-scholar') . '</strong>';
      $message .= '<ul class="scholar-error-suggestions">';
      foreach ($error_details['suggestions'] as $suggestion) {
        $message .= '<li>' . esc_html($suggestion) . '</li>';
      }
      $message .= '</ul>';
    }

    // Add specific guidance for blocked access (403 errors)
    if ($error_details['type'] === 'blocked_access') {
      $message .= '<br><div class="scholar-error-blocked-notice">';
      $message .= '<strong>🔒 ' . __('Server Access Blocked', 'wp-google-scholar') . '</strong><br>';
      $message .= __('This is the most common issue and is usually temporary. Google Scholar blocks server IPs that make too many requests.', 'wp-google-scholar');
      $message .= '<br><strong>' . __('Recommended action:', 'wp-google-scholar') . '</strong> ';
      $message .= __('Wait 1-2 hours and try again. If the problem persists, contact your hosting provider.', 'wp-google-scholar');
      $message .= '</div>';
    }

    return $message;
  }

  public function sanitize_settings($input, $current_settings = array())
  {
    $sanitized = array();

    // Profile ID
    $sanitized['profile_id'] = sanitize_text_field(trim($input['profile_id'] ?? ''));

    // Display Options - Handle checkboxes properly
    // If checkbox is checked, it will be in $input. If unchecked, it won't be present.
    $sanitized['show_avatar'] = isset($input['show_avatar']) ? '1' : '0';
    $sanitized['show_info'] = isset($input['show_info']) ? '1' : '0';
    $sanitized['show_publications'] = isset($input['show_publications']) ? '1' : '0';
    $sanitized['show_coauthors'] = isset($input['show_coauthors']) ? '1' : '0';

    // Update Frequency
    $sanitized['update_frequency'] = sanitize_text_field($input['update_frequency'] ?? 'weekly');

    // Max Publications
    $sanitized['max_publications'] = isset($input['max_publications']) ? intval($input['max_publications']) : 200;

    // Validate update frequency
    $valid_frequencies = array('daily', 'weekly', 'monthly', 'yearly');
    if (!in_array($sanitized['update_frequency'], $valid_frequencies)) {
      $sanitized['update_frequency'] = 'weekly';
    }

    // Validate max publications
    $valid_max_pubs = array(50, 100, 200, 500);
    if (!in_array($sanitized['max_publications'], $valid_max_pubs)) {
      $sanitized['max_publications'] = 200;
    }

    return $sanitized;
  }

  public function add_settings_link($links)
  {
    $settings_link = sprintf(
      '<a href="%s">%s</a>',
      admin_url('options-general.php?page=' . $this->page_slug),
      __('Settings', 'wp-google-scholar')
    );
    array_unshift($links, $settings_link);
    return $links;
  }
}
