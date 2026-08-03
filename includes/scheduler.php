<?php

namespace WPScholar;

if (!defined('ABSPATH')) {
  exit; // Exit if accessed directly
}

class Scheduler
{
  private $hook = 'scholar_profile_update';

  // Exponential backoff constants
  private const BASE_RETRY_DELAY = 3600; // 1 hour in seconds
  private const MAX_RETRY_DELAY = 86400; // 24 hours in seconds
  private const FAILURE_THRESHOLD_FOR_BACKOFF = 3; // Start backoff after 3 failures

  private static $hooks_registered = false;

  public function __construct()
  {
    if (!self::$hooks_registered) {
      add_filter('cron_schedules', array($this, 'add_schedules'));
      add_action($this->hook, array($this, 'update_profile'));
      self::$hooks_registered = true;
    }
  }

  /**
   * Activate the scheduler by setting up the cron job
   *
   * @return void
   */
  public function activate(): void
  {
    $options = get_option('scholar_profile_settings');
    $update_method = $options['update_method'] ?? 'server';

    if ($update_method === 'browser') {
      // Browser-assisted mode never scrapes automatically - make sure no
      // leftover schedule from a previous "server" mode keeps firing.
      if (wp_next_scheduled($this->hook)) {
        wp_clear_scheduled_hook($this->hook);
      }
    } elseif (!wp_next_scheduled($this->hook)) {
      $frequency = $options['update_frequency'] ?? 'weekly';
      $valid = ['daily', 'weekly', 'monthly', 'yearly'];
      if (!in_array($frequency, $valid, true)) {
        $frequency = 'weekly';
      }
      wp_schedule_event(time(), $frequency, $this->hook);
    }

    if (!wp_next_scheduled('scholar_profile_cleanup_errors')) {
      wp_schedule_event(time(), 'weekly', 'scholar_profile_cleanup_errors');
    }
  }

  /**
   * Deactivate the scheduler by removing the cron job
   *
   * @return void
   */
  public function deactivate(): void
  {
    wp_clear_scheduled_hook($this->hook);
  }

  public function add_schedules($schedules)
  {
    $options = get_option('scholar_profile_settings');
    $frequency = isset($options['update_frequency']) ? $options['update_frequency'] : 'weekly';

    $intervals = array(
      'daily' => array(
        'interval' => 86400,
        'display' => __('Daily', 'wp-google-scholar')
      ),
      'weekly' => array(
        'interval' => 604800,
        'display' => __('Weekly', 'wp-google-scholar')
      ),
      'monthly' => array(
        'interval' => 2592000,
        'display' => __('Monthly', 'wp-google-scholar')
      ),
      'yearly' => array(
        'interval' => 31536000,
        'display' => __('Yearly', 'wp-google-scholar')
      )
    );

    if (isset($intervals[$frequency]) && !isset($schedules[$frequency])) {
      $schedules[$frequency] = $intervals[$frequency];
    }

    return $schedules;
  }

  public function update_profile()
  {
    $options = get_option('scholar_profile_settings');

    // activate()/reschedule() remove the cron event in Browser mode, but an
    // event may already be executing when the setting changes. Never let
    // that race send a server-side request to Scholar.
    if (($options['update_method'] ?? 'server') === 'browser') {
      wp_scholar_log('Scheduled update skipped: Browser-assisted mode is enabled');
      return;
    }

    if (empty($options['profile_id'])) {
      wp_scholar_log('Scheduled update skipped: No profile ID configured');
      $this->update_data_status('error', 'No profile ID configured');
      return;
    }

    // Check if we're in exponential backoff period
    $next_retry = get_option('scholar_profile_next_retry', 0);
    if ($next_retry > time()) {
      $wait_time = $next_retry - time();
      wp_scholar_log(sprintf(
        'Scheduled update skipped: In exponential backoff period. Next retry in %d seconds (%.1f hours)',
        $wait_time,
        $wait_time / 3600
      ));
      return;
    }

    wp_scholar_log('Starting scheduled profile update for: ' . $options['profile_id']);
    $this->update_data_status('updating', 'Fetching data from Google Scholar...');

    $scraper = new Scraper();

    // Configure scraper limits based on settings
    $scraper_config = array(
      'max_publications' => isset($options['max_publications']) ? intval($options['max_publications']) : 200
    );
    $scraper->set_config($scraper_config);

    $data = $scraper->scrape($options['profile_id']);

    if ($data && Scraper::validate_scraped_data($data)) {
      // Store the new data
      update_option('scholar_profile_data', $data);
      update_option('scholar_profile_last_update', time());

      // Update status to success
      $this->update_data_status('success', sprintf(
        'Successfully updated at %s - Found %d publications',
        wp_date('Y-m-d H:i:s'),
        count($data['publications'])
      ));

      // Clear any previous error status, details, and backoff timer
      delete_option('scholar_profile_consecutive_failures');
      delete_option('scholar_profile_last_error_details');
      delete_option('scholar_profile_next_retry');

      wp_scholar_log(sprintf(
        'Google Scholar Profile updated for ID: %s at %s - Found %d publications',
        $options['profile_id'],
        wp_date('Y-m-d H:i:s'),
        count($data['publications'])
      ));
    } else {
      // Scraping failed - get detailed error information
      $error_details = $scraper->get_last_error_details();

      // Store detailed error information for admin reference
      if ($error_details) {
        update_option('scholar_profile_last_error_details', $error_details);
      }

      // Handle gracefully with enhanced error reporting
      $this->handle_scraping_failure($options['profile_id'], $error_details);
    }
  }

  /**
   * Calculate retry delay based on number of consecutive failures (exponential backoff)
   *
   * @param int $failure_count Number of consecutive failures
   * @return int Delay in seconds before next retry
   */
  private function calculate_retry_delay(int $failure_count): int
  {
    if ($failure_count < self::FAILURE_THRESHOLD_FOR_BACKOFF) {
      return 0; // No delay for first few failures
    }

    // Exponential backoff: 2^(failures - threshold) * base_delay
    $backoff_factor = $failure_count - self::FAILURE_THRESHOLD_FOR_BACKOFF + 1;
    $delay = self::BASE_RETRY_DELAY * pow(2, $backoff_factor - 1);

    // Cap at maximum delay
    return (int) min($delay, self::MAX_RETRY_DELAY);
  }

  /**
   * Handle scraping failures with enhanced error information and exponential backoff
   */
  private function handle_scraping_failure($profile_id, $error_details = null)
  {
    $consecutive_failures = get_option('scholar_profile_consecutive_failures', 0);
    $consecutive_failures++;
    update_option('scholar_profile_consecutive_failures', $consecutive_failures);

    // Calculate and apply exponential backoff delay
    $retry_delay = $this->calculate_retry_delay($consecutive_failures);
    if ($retry_delay > 0) {
      $next_retry = time() + $retry_delay;
      update_option('scholar_profile_next_retry', $next_retry);
      wp_scholar_log(sprintf(
        "Applying exponential backoff: next retry in %d seconds (%.1f hours) after %d failures",
        $retry_delay,
        $retry_delay / 3600,
        $consecutive_failures
      ));
    }

    $existing_data = get_option('scholar_profile_data');
    $has_existing_data = !empty($existing_data) && !empty($existing_data['name']);

    // Create enhanced error message based on error details
    $error_message = 'Failed to fetch data from Google Scholar';
    if ($error_details && isset($error_details['user_message'])) {
      $error_message = $error_details['user_message'];
      if (isset($error_details['status_code'])) {
        $error_message .= sprintf(' (HTTP %d)', $error_details['status_code']);
      }
    }

    if ($has_existing_data) {
      // Keep existing data but mark as stale
      $last_update = get_option('scholar_profile_last_update', 0);
      $age_days = $last_update ? ceil((time() - $last_update) / DAY_IN_SECONDS) : 'unknown';

      $status_message = sprintf(
        '%s (attempt %d). Keeping existing data from %s days ago.',
        $error_message,
        $consecutive_failures,
        $age_days
      );

      $this->update_data_status('stale', $status_message);

      wp_scholar_log("Scheduled update failed for profile: $profile_id - keeping existing data (failure #$consecutive_failures) - " . $error_message, 'warning');
    } else {
      // No existing data to fall back to
      $status_message = sprintf(
        '%s (attempt %d). No existing data available.',
        $error_message,
        $consecutive_failures
      );

      $this->update_data_status('error', $status_message);

      wp_scholar_log("Scheduled update failed for profile: $profile_id - no existing data available (failure #$consecutive_failures) - " . $error_message, 'error');
    }

    // If we've had too many consecutive failures, consider more drastic action
    if ($consecutive_failures >= WP_SCHOLAR_MAX_CONSECUTIVE_FAILURES) {
      $this->handle_persistent_failures($profile_id, $consecutive_failures, $error_details);
    }
  }

  /**
   * Handle persistent scraping failures with enhanced reporting
   */
  private function handle_persistent_failures($profile_id, $failure_count, $error_details = null)
  {
    wp_scholar_log("$failure_count consecutive failures for profile: $profile_id", 'warning');

    // Optionally send email notification to admin with enhanced details
    if ($failure_count === WP_SCHOLAR_MAX_CONSECUTIVE_FAILURES) {
      $this->send_failure_notification($profile_id, $failure_count, $error_details);
    }

    // Consider clearing old data if it's very old and we can't update it
    $last_update = get_option('scholar_profile_last_update', 0);
    $data_age_days = $last_update ? (time() - $last_update) / DAY_IN_SECONDS : 999;

    // Use constant from Settings class for data age threshold
    if ($data_age_days > Settings::DATA_STALE_AGE_DAYS) {
      wp_scholar_log("Considering data too old ($data_age_days days) - marking for manual review");

      $error_summary = 'Data is outdated and cannot be updated';
      if ($error_details && isset($error_details['type'])) {
        switch ($error_details['type']) {
          case 'blocked_access':
            $error_summary .= ' - server IP appears to be blocked by Google Scholar';
            break;
          case 'profile_not_found':
            $error_summary .= ' - profile may no longer exist or be public';
            break;
          case 'rate_limited':
            $error_summary .= ' - persistent rate limiting from Google Scholar';
            break;
          default:
            $error_summary .= ' - ' . $error_details['message'];
            break;
        }
      }

      $this->update_data_status('error', sprintf(
        '%s after %d attempts. Manual review required.',
        $error_summary,
        $failure_count
      ));
    }
  }

  /**
   * Send enhanced email notification about persistent failures
   */
  private function send_failure_notification($profile_id, $failure_count, $error_details = null)
  {
    $admin_email = get_option('admin_email');
    $site_name = get_bloginfo('name');

    $subject = sprintf('[%s] Google Scholar Profile Update Failures', $site_name);

    // Build enhanced error message
    $error_summary = 'Unknown error';
    $recommendations = 'Check the plugin settings and try a manual refresh.';

    if ($error_details) {
      $error_summary = $error_details['message'] ?? 'Unknown error';

      if (isset($error_details['status_code'])) {
        $error_summary .= sprintf(' (HTTP %d)', $error_details['status_code']);
      }

      // Add specific recommendations based on error type
      if (isset($error_details['type'])) {
        switch ($error_details['type']) {
          case 'blocked_access':
            $recommendations = "Your server's IP address appears to be blocked by Google Scholar. Contact your hosting provider about IP reputation, or try again in 24-48 hours.";
            break;
          case 'profile_not_found':
            $recommendations = "The Google Scholar profile may have been deleted or made private. Verify the profile still exists and is publicly accessible.";
            break;
          case 'rate_limited':
            $recommendations = "Google Scholar is rate limiting your server. Reduce update frequency to monthly and avoid manual refreshes for a while.";
            break;
          case 'profile_private':
            $recommendations = "The Google Scholar profile appears to be set to private. Contact the profile owner to make it public.";
            break;
        }
      }
    }

    $message = sprintf(
      "The Google Scholar Profile plugin has failed to update data %d consecutive times.\n\n" .
        "Profile ID: %s\n" .
        "Error: %s\n" .
        "Last successful update: %s\n" .
        "Site: %s\n\n" .
        "Recommendations:\n%s\n\n" .
        "Admin URL: %s",
      $failure_count,
      $profile_id,
      $error_summary,
      get_option('scholar_profile_last_update') ?
        wp_date('Y-m-d H:i:s', get_option('scholar_profile_last_update')) : 'Never',
      home_url(),
      $recommendations,
      admin_url('options-general.php?page=scholar-profile-settings')
    );

    wp_mail($admin_email, $subject, $message);
    wp_scholar_log("Enhanced failure notification sent to admin: $admin_email - Error type: " . ($error_details['type'] ?? 'unknown'));
  }

  /**
   * Update data status for tracking
   *
   * @param string $status Status type: 'success', 'stale', 'error', 'updating'
   * @param string $message Optional status message
   * @return void
   */
  public function update_data_status(string $status, string $message = ''): void
  {
    $status_data = array(
      'status' => $status, // 'success', 'stale', 'error', 'updating'
      'message' => $message,
      'timestamp' => time(),
      'consecutive_failures' => get_option('scholar_profile_consecutive_failures', 0)
    );

    update_option('scholar_profile_data_status', $status_data);
    wp_scholar_log("Data status updated: $status - $message");
  }

  /**
   * Get current data status
   *
   * @return array Status data with keys: status, message, timestamp, consecutive_failures
   */
  public function get_data_status(): array
  {
    return get_option('scholar_profile_data_status', array(
      'status' => 'unknown',
      'message' => 'No status information available',
      'timestamp' => 0,
      'consecutive_failures' => 0
    ));
  }

  /**
   * Check if data is stale based on age and status
   *
   * @return bool True if data is stale, false otherwise
   */
  public function is_data_stale(): bool
  {
    $status = $this->get_data_status();
    $last_update = get_option('scholar_profile_last_update', 0);

    // If status is explicitly stale or error
    if (in_array($status['status'], ['stale', 'error'])) {
      return true;
    }

    // If data is older than expected update frequency
    if ($last_update) {
      $options = get_option('scholar_profile_settings');
      $frequency = $options['update_frequency'] ?? 'weekly';

      $max_age = array(
        'daily' => DAY_IN_SECONDS * 2,     // 2 days tolerance
        'weekly' => WEEK_IN_SECONDS * 2,   // 2 weeks tolerance
        'monthly' => MONTH_IN_SECONDS * 2, // 2 months tolerance
        'yearly' => YEAR_IN_SECONDS * 1.5  // 1.5 years tolerance
      );

      $tolerance = $max_age[$frequency] ?? WEEK_IN_SECONDS * 2;
      $age = time() - $last_update;

      return $age > $tolerance;
    }

    return false;
  }

  /**
   * Clear stale data manually
   *
   * @return bool Always returns true
   */
  public function clear_stale_data(): bool
  {
    delete_option('scholar_profile_data');
    delete_option('scholar_profile_last_update');
    delete_option('scholar_profile_data_status');
    delete_option('scholar_profile_consecutive_failures');

    wp_scholar_log("Stale data cleared manually");

    return true;
  }

  /**
   * Reschedule the cron job (deactivate and reactivate)
   *
   * @return void
   */
  public function reschedule(): void
  {
    $this->deactivate();
    $this->activate();
  }

  /**
   * Get the timestamp of the next scheduled update
   *
   * @return int|false Timestamp of next scheduled event or false if not scheduled
   */
  public function get_next_scheduled()
  {
    return wp_next_scheduled($this->hook);
  }

  /**
   * Force an immediate profile update
   *
   * Note: This method does not return a value. Check scholar_profile_data_status
   * option after calling to determine success/failure.
   *
   * @since 1.4.0 Changed to void return type (previously had no documented return value)
   * @return void
   */
  public function force_update(): void
  {
    $this->update_profile();
  }
}
