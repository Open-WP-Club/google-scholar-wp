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
  private const IMPORT_REPLACE_COOLDOWN_SECONDS = 15;
  private const SYNC_CREDENTIAL_PREFIX = 'Scholar Auto-Sync';
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
    add_action('admin_post_import_scholar_profile', array($this, 'handle_import_scholar_profile'));
    add_action('admin_post_download_scholar_sync_script', array($this, 'handle_download_sync_script'));
    add_action('admin_post_revoke_scholar_sync_credential', array($this, 'handle_revoke_sync_credential'));
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

    // A stale tab can still submit a valid nonce after Browser mode is saved,
    // so enforce the mode here as well as in the settings-page UI.
    $options = get_option($this->option_name, array());
    if (($options['update_method'] ?? 'server') === 'browser') {
      wp_safe_redirect(add_query_arg(
        array('page' => $this->page_slug, 'refresh' => 'failed', 'message' => 'browser_mode'),
        admin_url('options-general.php')
      ));
      exit;
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

  /**
   * Generate and stream a ready-to-run sync script for the automated
   * browser-assisted sync flow: fetches Scholar pages from the admin's own
   * machine (via cron) and POSTs them to RestApi::handle_import(), using a
   * dedicated Application Password issued just for this purpose.
   */
  public function handle_download_sync_script()
  {
    if (!current_user_can('manage_options')) {
      wp_die(__('You do not have sufficient permissions to access this page.'));
    }

    if (!isset($_POST['scholar_sync_download_nonce']) || !wp_verify_nonce($_POST['scholar_sync_download_nonce'], 'download_scholar_sync_script')) {
      wp_die(__('Security check failed.'));
    }

    $options = get_option($this->option_name, array());
    if (empty($options['profile_id'])) {
      wp_safe_redirect(add_query_arg(
        array('page' => $this->page_slug, 'sync_download' => 'failed'),
        admin_url('options-general.php')
      ));
      exit;
    }

    $user_id = get_current_user_id();
    $this->revoke_sync_credentials($user_id);

    $password_result = \WP_Application_Passwords::create_new_application_password($user_id, array(
      'name' => self::SYNC_CREDENTIAL_PREFIX . ' (' . wp_date('Y-m-d H:i') . ')'
    ));

    if (is_wp_error($password_result)) {
      wp_die(esc_html($password_result->get_error_message()));
    }

    list($app_password, ) = $password_result;

    $template_path = WP_SCHOLAR_PLUGIN_DIR . 'assets/tools/scholar-sync.sh.tpl';
    $template = file_exists($template_path) ? file_get_contents($template_path) : false;

    if ($template === false) {
      wp_die(__('Sync script template is missing from this plugin install.', 'wp-google-scholar'));
    }

    $current_user = wp_get_current_user();
    $script = str_replace(
      array('__SITE_URL__', '__WP_USER__', '__APP_PASSWORD__', '__PROFILE_ID__', '__MAX_PUBLICATIONS__', '__IMPORT_URL__'),
      array(
        home_url(),
        $current_user->user_login,
        $app_password,
        $options['profile_id'],
        (string) intval($options['max_publications'] ?? 200),
        rest_url('wp-google-scholar/v1/import')
      ),
      $template
    );

    nocache_headers();
    header('Content-Type: text/x-sh; charset=utf-8');
    header('Content-Disposition: attachment; filename="scholar-sync.sh"');
    header('Content-Length: ' . strlen($script));
    echo $script;
    exit;
  }

  /**
   * Revoke a single automated-sync Application Password from the settings
   * page, without sending the admin to Users > Profile.
   */
  public function handle_revoke_sync_credential()
  {
    if (!current_user_can('manage_options')) {
      wp_die(__('You do not have sufficient permissions to access this page.'));
    }

    if (!isset($_POST['scholar_sync_revoke_nonce']) || !wp_verify_nonce($_POST['scholar_sync_revoke_nonce'], 'revoke_scholar_sync_credential')) {
      wp_die(__('Security check failed.'));
    }

    $uuid = isset($_POST['uuid']) ? sanitize_text_field($_POST['uuid']) : '';
    if ($uuid !== '') {
      $user_id = get_current_user_id();
      foreach ($this->get_sync_credentials($user_id) as $item) {
        if ($item['uuid'] === $uuid) {
          \WP_Application_Passwords::delete_application_password($user_id, $uuid);
          break;
        }
      }
    }

    wp_safe_redirect(add_query_arg(
      array('page' => $this->page_slug, 'sync_revoke' => 'success'),
      admin_url('options-general.php')
    ));
    exit;
  }

  /**
   * Revoke every existing automated-sync Application Password for a user,
   * so downloading a new script always leaves exactly one active credential.
   */
  private function revoke_sync_credentials(int $user_id): void
  {
    foreach ($this->get_sync_credentials($user_id) as $item) {
      \WP_Application_Passwords::delete_application_password($user_id, $item['uuid']);
    }
  }

  /**
   * Active automated-sync Application Passwords for the current admin, for
   * display (with per-credential Revoke buttons) in the Browser mode panel.
   */
  public function get_active_sync_credentials(): array
  {
    return $this->get_sync_credentials(get_current_user_id());
  }

  /**
   * Application Passwords belonging to a user whose name carries the
   * sync-credential prefix - the single source of truth for what counts as
   * "in scope" for automated-sync revoke/list operations, so a revoke
   * request can never reach outside this plugin's own credentials.
   */
  private function get_sync_credentials(int $user_id): array
  {
    $existing = \WP_Application_Passwords::get_user_application_passwords($user_id);
    return array_values(array_filter($existing, function ($item) {
      return isset($item['name']) && strpos($item['name'], self::SYNC_CREDENTIAL_PREFIX) === 0;
    }));
  }

  /**
   * Handle browser-assisted import: data pasted from the bookmarklet (JSON)
   * or copied directly from a Scholar profile page (HTML), captured in the
   * admin's own browser instead of fetched server-side.
   */
  public function handle_import_scholar_profile()
  {
    if (!current_user_can('manage_options')) {
      wp_die(__('You do not have sufficient permissions to access this page.'));
    }

    if (!isset($_POST['scholar_import_nonce']) || !wp_verify_nonce($_POST['scholar_import_nonce'], 'import_scholar_profile')) {
      wp_die(__('Security check failed.'));
    }

    $content = isset($_POST['scholar_import_content']) ? wp_unslash($_POST['scholar_import_content']) : '';
    $import_mode = isset($_POST['scholar_import_mode']) && $_POST['scholar_import_mode'] === 'append'
      ? 'append'
      : 'replace';

    $result = $this->process_import($content, $import_mode);

    if (isset($result['error'])) {
      wp_safe_redirect(add_query_arg(
        array(
          'page' => $this->page_slug,
          'import' => 'failed',
          'error_type' => $result['error']['type'] ?? 'unknown'
        ),
        admin_url('options-general.php')
      ));
      exit;
    }

    wp_safe_redirect(add_query_arg(
      array('page' => $this->page_slug, 'import' => 'success'),
      admin_url('options-general.php')
    ));
    exit;
  }

  /**
   * Core browser-assisted import processing, shared by the admin-post form
   * handler above and the REST API sync endpoint (WPScholar\RestApi). No
   * nonce, capability, or redirect side effects - callers own their own
   * auth and response handling.
   *
   * @param string $content Raw pasted/POSTed content (JSON from the bookmarklet, or HTML)
   * @param string $import_mode 'replace' or 'append'
   * @param string $source 'browser' (manual paste) or 'sync' (automated REST API)
   * @return array Either ['data' => array] on success or ['error' => array] on failure
   */
  public function process_import(string $content, string $import_mode, string $source = 'browser'): array
  {
    $options = get_option($this->option_name, array());
    if (($options['update_method'] ?? 'server') !== 'browser') {
      return array('error' => array(
        'type' => 'browser_mode_required',
        'message' => 'Browser mode is not enabled for this site.'
      ));
    }

    // A full replacement may download the profile avatar. Briefly lock that
    // action against double-clicks/back-button resubmits or overlapping
    // sync runs, while deliberately leaving append imports unrestricted for
    // the expected cstart=N flow.
    if ($import_mode === 'replace') {
      $lock_name = 'scholar_profile_import_replace_lock';
      if (get_transient($lock_name)) {
        return array('error' => array(
          'type' => 'import_rate_limited',
          'message' => 'Please wait a few seconds before replacing profile data again.'
        ));
      }
      set_transient($lock_name, 1, self::IMPORT_REPLACE_COOLDOWN_SECONDS);
    }

    $existing_data = get_option('scholar_profile_data', array());
    $result = $this->build_import_data(
      $content,
      is_array($existing_data) ? $existing_data : array(),
      $options['profile_id'] ?? '',
      $import_mode,
      intval($options['max_publications'] ?? 200)
    );

    if (isset($result['error'])) {
      wp_scholar_log('Browser import failed: ' . ($result['error']['message'] ?? 'Unknown error'), 'error');
      update_option('scholar_profile_last_error_details', $result['error']);
      $scheduler = new Scheduler();
      $scheduler->update_data_status('error', $result['error']['message'] ?? 'Browser import failed.');
      return $result;
    }

    $data = $result['data'];

    if (!Scraper::validate_scraped_data($data)) {
      $error = array(
        'type' => 'validation_failed',
        'message' => 'The imported data did not look complete enough to save. Please make sure you copied the full profile page.'
      );
      update_option('scholar_profile_last_error_details', $error);
      $scheduler = new Scheduler();
      $scheduler->update_data_status('error', $error['message']);
      return array('error' => $error);
    }

    update_option('scholar_profile_data', $data);
    update_option('scholar_profile_last_update', time());
    delete_option('scholar_profile_consecutive_failures');
    delete_option('scholar_profile_last_error_details');

    $scheduler = new Scheduler();
    $status_message = $source === 'sync'
      ? 'Imported via automated sync at %s - Found %d publications'
      : 'Imported via browser at %s - Found %d publications';
    $scheduler->update_data_status('success', sprintf(
      $status_message,
      wp_date('Y-m-d H:i:s'),
      count($data['publications'])
    ));

    wp_scholar_log('Browser import successful for profile: ' . ($options['profile_id'] ?? ''));

    return array('data' => $data);
  }

  /**
   * Work out what to do with browser-assisted import content, without any
   * WordPress redirect/die side effects - kept separate so it's unit
   * testable.
   *
   * @param string $content Raw pasted content (JSON from the bookmarklet, or HTML)
   * @param array $existing_data Currently stored scholar_profile_data (for appending extra pages)
   * @param string $profile_id The configured Google Scholar profile ID
   * @param string $import_mode Whether this replaces profile data or appends a later page
   * @param int $max_publications Maximum number of publications to retain
   * @return array Either ['data' => array] on success or ['error' => array] on failure
   */
  public function build_import_data(string $content, array $existing_data, string $profile_id, string $import_mode = 'replace', int $max_publications = 200): array
  {
    $content = trim($content);

    if ($content === '') {
      return array('error' => array(
        'type' => 'empty_content',
        'message' => 'No content was pasted.'
      ));
    }

    if (!in_array($import_mode, array('replace', 'append'), true)) {
      return array('error' => array(
        'type' => 'invalid_import_mode',
        'message' => 'Choose whether to replace the profile data or add a later publications page.'
      ));
    }

    $max_publications = max(1, $max_publications);

    $scraper = new Scraper();

    // Bookmarklet output is a JSON object.
    if ($content[0] === '{') {
      if ($import_mode === 'append') {
        return array('error' => array(
          'type' => 'bookmarklet_cannot_append',
          'message' => 'Bookmarklet data already contains the full profile. Use “Replace profile data” to import it.'
        ));
      }
      $data = $scraper->import_from_bookmarklet_json($content, $profile_id);
      if ($data === false) {
        return array('error' => $scraper->get_last_error_details());
      }
      $data['publications'] = array_slice($data['publications'], 0, $max_publications);
      return array('data' => $data);
    }

    // Subsequent Scholar pages also contain gsc_prf. The explicit append
    // action prevents a cstart=N page from replacing stored publications.
    if ($import_mode === 'append' && strpos($content, 'gsc_a_tr') !== false) {
      return $this->append_imported_publications($scraper, $content, $existing_data, $profile_id, $max_publications);
    }

    // A full profile page replaces profile information and publications.
    // The main avatar is downloaded (a single request); coauthor avatars
    // are always skipped.
    if ($import_mode === 'replace' && strpos($content, 'gsc_prf') !== false) {
      $data = $scraper->import_main_profile_html($content, $profile_id, false);
      if ($data === false) {
        return array('error' => $scraper->get_last_error_details());
      }
      $data['publications'] = array_slice($data['publications'], 0, $max_publications);
      return array('data' => $data);
    }

    if ($import_mode === 'replace' && strpos($content, 'gsc_a_tr') !== false) {
      return array('error' => array(
        'type' => 'profile_page_required',
        'message' => 'Use “Add publications from another page” for a later Scholar page, or paste the main profile page to replace profile data.'
      ));
    }

    return array('error' => array(
      'type' => 'unrecognized_content',
      'message' => "This doesn't look like a Google Scholar profile page."
    ));
  }

  /**
   * Add publications from a later Scholar page without changing stored
   * profile details. Complete pages and table-only fragments are both valid.
   */
  private function append_imported_publications(Scraper $scraper, string $content, array $existing_data, string $profile_id, int $max_publications): array
  {
    $new_publications = $scraper->import_publications_fragment_html($content);

    if (empty($new_publications)) {
      return array('error' => array(
        'type' => 'no_publications_found',
        'message' => 'No publications were found in the pasted content.'
      ));
    }

    if (empty($existing_data) || empty($existing_data['name'])) {
      return array('error' => array(
        'type' => 'no_base_profile',
        'message' => 'Please import the main profile page first before adding extra pages.'
      ));
    }

    $imported_profile_id = $this->extract_profile_id_from_html($content);
    if ($imported_profile_id === null) {
      return array('error' => array(
        'type' => 'missing_import_profile_id',
        'message' => 'Could not verify which Scholar profile this publications page belongs to.'
      ));
    }

    if ($profile_id !== '' && $imported_profile_id !== $profile_id) {
      return array('error' => array(
        'type' => 'wrong_import_profile',
        'message' => 'The pasted publications page belongs to a different Google Scholar profile.'
      ));
    }

    $data = $existing_data;
    $data['publications'] = array_slice(
      $this->merge_publications($existing_data['publications'] ?? array(), $new_publications),
      0,
      $max_publications
    );
    return array('data' => $data);
  }

  /**
   * Extract the Scholar user ID embedded in either a full later page or its
   * publication links. cstart=N pages do not reliably expose a canonical URL.
   */
  private function extract_profile_id_from_html(string $html): ?string
  {
    $html = html_entity_decode($html, ENT_QUOTES, 'UTF-8');

    // Publication links identify the profile directly. Prefer them over a
    // generic user= URL, which can point to a coauthor in the sidebar.
    if (preg_match('/citation_for_view=([A-Za-z0-9_-]+)(?::|%3A)/i', $html, $matches)) {
      return $matches[1];
    }

    if (preg_match('/[?&]user=([A-Za-z0-9_-]+)/', $html, $matches)) {
      return $matches[1];
    }

    return null;
  }

  /**
   * Append new publications onto existing ones, skipping any that already
   * exist (matched by Scholar URL, falling back to title).
   */
  private function merge_publications(array $existing, array $new): array
  {
    $seen = array();
    foreach ($existing as $pub) {
      $key = $pub['google_scholar_url'] ?? $pub['title'] ?? '';
      $seen[$key] = true;
    }

    foreach ($new as $pub) {
      $key = $pub['google_scholar_url'] ?? $pub['title'] ?? '';
      if (!isset($seen[$key])) {
        $existing[] = $pub;
        $seen[$key] = true;
      }
    }

    return $existing;
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
    // Handle browser-assisted import status messages
    elseif (isset($_GET['import'])) {
      if ($_GET['import'] === 'success') {
        $messages[] = array(
          'type' => 'updated',
          'message' => __('✓ Profile data imported successfully!', 'wp-google-scholar')
        );
      } elseif ($_GET['import'] === 'failed') {
        $messages[] = array(
          'type' => 'error',
          'message' => '⚠ ' . $this->get_import_error_message($_GET),
          'is_html' => true
        );
      }
    }
    // Handle automated sync script download / credential revoke messages
    elseif (isset($_GET['sync_download']) && $_GET['sync_download'] === 'failed') {
      $messages[] = array(
        'type' => 'error',
        'message' => '⚠ ' . __('Enter and save a Profile ID before downloading the sync script.', 'wp-google-scholar')
      );
    } elseif (isset($_GET['sync_revoke']) && $_GET['sync_revoke'] === 'success') {
      $messages[] = array(
        'type' => 'updated',
        'message' => __('✓ Sync credential revoked.', 'wp-google-scholar')
      );
    }
    // Only check for settings-updated if refresh/import are NOT set
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

    $update_method = $options['update_method'] ?? 'server';
    $bookmarklet_href = $update_method === 'browser'
      ? $this->build_bookmarklet_href($options['max_publications'] ?? 200)
      : '';
    $sync_credentials = $update_method === 'browser'
      ? $this->get_active_sync_credentials()
      : array();

    include WP_SCHOLAR_PLUGIN_DIR . 'views/settings-page.php';
  }

  /**
   * Get an error message for a failed browser-assisted import
   */
  private function get_import_error_message($get_params)
  {
    // This failure is local to the current request. Prefer it to an old
    // server-side error retained for troubleshooting.
    if (isset($get_params['error_type']) && $get_params['error_type'] === 'validation_failed') {
      return __('The imported data did not look complete enough to save. Please make sure you copied the full profile page.', 'wp-google-scholar');
    }

    if (isset($get_params['error_type']) && $get_params['error_type'] === 'import_rate_limited') {
      return __('Please wait a few seconds before replacing profile data again.', 'wp-google-scholar');
    }

    if (isset($get_params['error_type']) && $get_params['error_type'] === 'browser_mode_required') {
      return __('Select Browser mode before importing profile data.', 'wp-google-scholar');
    }

    $error_details = get_option('scholar_profile_last_error_details');

    if ($error_details) {
      $message = $this->format_detailed_error_message($error_details);
      if ($message) {
        return $message;
      }
      if (isset($error_details['message'])) {
        return esc_html($error_details['message']);
      }
    }

    return __('Could not import the pasted content. Please check it and try again.', 'wp-google-scholar');
  }

  /**
   * Build the "javascript:" bookmarklet link from the bundled JS asset,
   * with the configured max publications baked in.
   */
  private function build_bookmarklet_href($max_publications): string
  {
    // Bookmark managers may truncate long javascript: URLs. Use the bundled
    // minified asset so the encoded bookmarklet remains safely below that
    // limit, while retaining the readable source as the canonical file.
    $js_path = WP_SCHOLAR_PLUGIN_DIR . 'assets/js/scholar-bookmarklet.min.js';
    if (!file_exists($js_path)) {
      $js_path = WP_SCHOLAR_PLUGIN_DIR . 'assets/js/scholar-bookmarklet.js';
    }
    $js = file_exists($js_path) ? file_get_contents($js_path) : false;

    if ($js === false) {
      return '';
    }

    // Strip the leading doc comment and collapse blank lines to keep the
    // generated bookmarklet link reasonably short.
    $js = preg_replace('#^/\*\*.*?\*/\s*#s', '', $js, 1);
    $js = preg_replace('/\n\s*\n/', "\n", $js);
    $js = str_replace('__MAX_PUBLICATIONS__', (string) intval($max_publications), $js);

    return 'javascript:' . rawurlencode($js);
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

        case 'browser_mode':
          return __('Server-side refresh is disabled while Browser mode is selected. Use the Browser-Assisted Import panel instead.', 'wp-google-scholar');
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

    // Update Method: server (automatic cron scraping) or browser (manual import)
    $sanitized['update_method'] = sanitize_text_field($input['update_method'] ?? 'server');
    if (!in_array($sanitized['update_method'], array('server', 'browser'))) {
      $sanitized['update_method'] = 'server';
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
