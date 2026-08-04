<?php
if (!defined('ABSPATH')) {
  exit;
}

// Ensure $options is available
if (!isset($options)) {
  $options = get_option('scholar_profile_settings', array(
    'profile_id' => '',
    'show_avatar' => '1',
    'show_info' => '1',
    'show_publications' => '1',
    'show_coauthors' => '1',
    'update_frequency' => 'weekly',
    'max_publications' => '200'
  ));
}

$update_method = $options['update_method'] ?? 'server';

// Get profile data and last update info
$profile_data = get_option('scholar_profile_data');
$last_update = get_option('scholar_profile_last_update');
$last_manual_refresh = get_option('scholar_profile_last_manual_refresh', 0);
$has_profile_data = !empty($profile_data) && !empty($profile_data['name']);

// Calculate next automatic update
$scheduler = new WPScholar\Scheduler();
$next_scheduled = $scheduler->get_next_scheduled();

// Calculate refresh cooldown
$cooldown_period = \WPScholar\Settings::REFRESH_COOLDOWN_SECONDS;
$time_since_refresh = time() - $last_manual_refresh;
$can_refresh = $time_since_refresh >= $cooldown_period;
$cooldown_remaining = $can_refresh ? 0 : ceil(($cooldown_period - $time_since_refresh) / 60);
?>

<div class="wrap">
  <h1><?php _e('Google Scholar Profile', 'wp-google-scholar'); ?></h1>

  <?php if (!empty($messages)): ?>
    <?php foreach ($messages as $message): ?>
      <div class="notice notice-<?php echo esc_attr($message['type']); ?> is-dismissible">
        <p>
          <?php
          // Check if message contains HTML and should not be escaped
          if (isset($message['is_html']) && $message['is_html']) {
            echo wp_kses($message['message'], array(
              'strong' => array(),
              'em' => array(),
              'br' => array(),
              'ul' => array(),
              'li' => array(),
              'div' => array(),
              'span' => array()
            ));
          } else {
            echo esc_html($message['message']);
          }
          ?>
        </p>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>

  <div class="scholar-admin-container">

    <!-- Main Layout: Side by Side -->
    <div class="scholar-main-layout">

      <!-- LEFT: Settings (steps 1-2) + Actions (step 3) - 70% -->
      <div class="scholar-main-content">
        <div class="scholar-settings-card">
          <form method="post" action="">
            <?php wp_nonce_field('scholar_profile_settings', 'scholar_settings_nonce'); ?>

            <!-- STEP 1: Profile -->
            <div class="scholar-step">
              <div class="scholar-step-header">
                <span class="scholar-step-badge">1</span>
                <h2><?php _e('Profile', 'wp-google-scholar'); ?></h2>
              </div>

              <table class="form-table" role="presentation">
                <tr>
                  <th scope="row">
                    <label for="profile_id"><?php _e('Profile ID', 'wp-google-scholar'); ?></label>
                  </th>
                  <td>
                    <input type="text"
                      id="profile_id"
                      name="scholar_profile_settings[profile_id]"
                      value="<?php echo esc_attr($options['profile_id']); ?>"
                      class="regular-text"
                      placeholder="e.g., XXXXXXXXXX">
                    <p class="description">
                      <?php _e('Your Google Scholar profile ID from the URL: https://scholar.google.com/citations?user=<strong>PROFILE_ID</strong>', 'wp-google-scholar'); ?>
                      <br><em><?php _e('💡 Tip: Copy only the ID part after "user=" - not the full URL', 'wp-google-scholar'); ?></em>
                    </p>
                  </td>
                </tr>

                <tr>
                  <th scope="row"><?php _e('Display Options', 'wp-google-scholar'); ?></th>
                  <td>
                    <fieldset>
                      <legend class="screen-reader-text"><?php _e('Display Options', 'wp-google-scholar'); ?></legend>

                      <label class="scholar-checkbox-label">
                        <input type="checkbox"
                          name="scholar_profile_settings[show_avatar]"
                          value="1" <?php checked('1', $options['show_avatar']); ?>>
                        <span class="scholar-checkbox-text"><?php _e('Show profile avatar', 'wp-google-scholar'); ?></span>
                      </label>

                      <label class="scholar-checkbox-label">
                        <input type="checkbox"
                          name="scholar_profile_settings[show_info]"
                          value="1" <?php checked('1', $options['show_info']); ?>>
                        <span class="scholar-checkbox-text"><?php _e('Show profile information', 'wp-google-scholar'); ?></span>
                      </label>

                      <label class="scholar-checkbox-label">
                        <input type="checkbox"
                          name="scholar_profile_settings[show_publications]"
                          value="1" <?php checked('1', $options['show_publications']); ?>>
                        <span class="scholar-checkbox-text"><?php _e('Show publications list', 'wp-google-scholar'); ?></span>
                      </label>

                      <label class="scholar-checkbox-label">
                        <input type="checkbox"
                          name="scholar_profile_settings[show_coauthors]"
                          value="1" <?php checked('1', $options['show_coauthors']); ?>>
                        <span class="scholar-checkbox-text"><?php _e('Show co-authors', 'wp-google-scholar'); ?></span>
                      </label>
                    </fieldset>
                  </td>
                </tr>
              </table>
            </div>

            <!-- STEP 2: How updates happen -->
            <div class="scholar-step">
              <div class="scholar-step-header">
                <span class="scholar-step-badge">2</span>
                <h2><?php _e('How updates happen', 'wp-google-scholar'); ?></h2>
              </div>
              <p class="scholar-step-intro">
                <?php _e('Choose where publication data comes from. This only decides the method - it does not fetch anything by itself.', 'wp-google-scholar'); ?>
              </p>

              <table class="form-table" role="presentation">
                <tr>
                  <th scope="row"><?php _e('Update Method', 'wp-google-scholar'); ?></th>
                  <td>
                    <fieldset>
                      <legend class="screen-reader-text"><?php _e('Update Method', 'wp-google-scholar'); ?></legend>

                      <label class="scholar-checkbox-label">
                        <input type="radio"
                          name="scholar_profile_settings[update_method]"
                          value="server" <?php checked('server', $update_method); ?>>
                        <span class="scholar-checkbox-text"><?php _e('Server (automatic)', 'wp-google-scholar'); ?></span>
                      </label>
                      <label class="scholar-checkbox-label">
                        <input type="radio"
                          name="scholar_profile_settings[update_method]"
                          value="browser" <?php checked('browser', $update_method); ?>>
                        <span class="scholar-checkbox-text"><?php _e('Browser (manual, for hosts that block scraping)', 'wp-google-scholar'); ?></span>
                      </label>
                    </fieldset>
                    <p class="description">
                      <?php _e('Server mode fetches data automatically from your server on the schedule below. If your host blocks outbound requests to Google Scholar (HTTP 403/429 errors), switch to Browser mode to fetch data through your own browser instead - not automatic, but works where server scraping does not.', 'wp-google-scholar'); ?>
                    </p>
                  </td>
                </tr>

                <?php if ($update_method === 'server'): ?>
                  <tr>
                    <th scope="row">
                      <label for="update_frequency"><?php _e('Update Frequency', 'wp-google-scholar'); ?></label>
                    </th>
                    <td>
                      <select id="update_frequency" name="scholar_profile_settings[update_frequency]">
                        <option value="daily" <?php selected($options['update_frequency'], 'daily'); ?>>
                          <?php _e('Daily', 'wp-google-scholar'); ?>
                        </option>
                        <option value="weekly" <?php selected($options['update_frequency'], 'weekly'); ?>>
                          <?php _e('Weekly', 'wp-google-scholar'); ?>
                        </option>
                        <option value="monthly" <?php selected($options['update_frequency'], 'monthly'); ?>>
                          <?php _e('Monthly (Recommended)', 'wp-google-scholar'); ?>
                        </option>
                        <option value="yearly" <?php selected($options['update_frequency'], 'yearly'); ?>>
                          <?php _e('Yearly', 'wp-google-scholar'); ?>
                        </option>
                      </select>
                      <p class="description">
                        <?php _e('How often to automatically refresh profile data from Google Scholar.', 'wp-google-scholar'); ?>
                        <?php if ($next_scheduled): ?>
                          <br><strong><?php _e('Next automatic update:', 'wp-google-scholar'); ?></strong>
                          <?php echo date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $next_scheduled); ?>
                        <?php endif; ?>
                      </p>
                    </td>
                  </tr>
                <?php else: ?>
                  <tr>
                    <th scope="row"><?php _e('Update Frequency', 'wp-google-scholar'); ?></th>
                    <td>
                      <p class="description">
                        <?php _e('Not used in Browser mode. Automatic updates are off - you fetch data yourself in step 3 below.', 'wp-google-scholar'); ?>
                      </p>
                    </td>
                  </tr>
                <?php endif; ?>

                <tr>
                  <th scope="row">
                    <label for="max_publications"><?php _e('Max Publications', 'wp-google-scholar'); ?></label>
                  </th>
                  <td>
                    <select id="max_publications" name="scholar_profile_settings[max_publications]">
                      <option value="50" <?php selected($options['max_publications'] ?? '200', '50'); ?>>
                        <?php _e('50 publications', 'wp-google-scholar'); ?>
                      </option>
                      <option value="100" <?php selected($options['max_publications'] ?? '200', '100'); ?>>
                        <?php _e('100 publications', 'wp-google-scholar'); ?>
                      </option>
                      <option value="200" <?php selected($options['max_publications'] ?? '200', '200'); ?>>
                        <?php _e('200 publications (recommended)', 'wp-google-scholar'); ?>
                      </option>
                      <option value="500" <?php selected($options['max_publications'] ?? '200', '500'); ?>>
                        <?php _e('500 publications', 'wp-google-scholar'); ?>
                      </option>
                    </select>
                    <p class="description">
                      <?php _e('Maximum number of publications to fetch from Google Scholar. Applies to both update methods above. Higher numbers take longer to process.', 'wp-google-scholar'); ?>
                      <br><strong class="scholar-warning-text"><?php _e('⚠️ Warning:', 'wp-google-scholar'); ?></strong>
                      <?php _e('Fetching large numbers of publications (500+) may temporarily trigger IP rate limiting from Google Scholar. Use higher limits sparingly and consider longer update intervals.', 'wp-google-scholar'); ?>
                    </p>
                  </td>
                </tr>
              </table>
            </div>

            <div class="scholar-form-actions">
              <?php submit_button(__('Save Settings', 'wp-google-scholar'), 'primary', 'submit', false); ?>
            </div>
          </form>
        </div>

        <!-- STEP 3: Get your data now (deliberately separate from the settings form/card above) -->
        <div class="scholar-settings-card scholar-step-action-card">
          <div class="scholar-step">
            <div class="scholar-step-header">
              <span class="scholar-step-badge scholar-step-badge-action">3</span>
              <h2><?php _e('Get your data', 'wp-google-scholar'); ?></h2>
            </div>
            <p class="scholar-step-intro">
              <?php _e('This is separate from "Save Settings" above - saving your settings never fetches data by itself. Use the action below whenever you actually want to pull data from Google Scholar.', 'wp-google-scholar'); ?>
            </p>

            <?php if ($update_method === 'browser'): ?>
              <!-- Browser-Assisted Import Panel -->
              <div class="scholar-refresh-section">
                <h3><?php _e('Browser-Assisted Import', 'wp-google-scholar'); ?></h3>

                <?php if (empty($options['profile_id'])): ?>
                  <p class="description"><?php _e('Enter and save a Profile ID above first.', 'wp-google-scholar'); ?></p>
                <?php else: ?>
                  <p class="description">
                    <?php _e('Bookmarklet (recommended): drag the button below to your bookmarks bar. Open your Scholar profile, click it, then come back here and paste.', 'wp-google-scholar'); ?>
                  </p>

                  <p>
                    <a href="<?php echo esc_url($bookmarklet_href, array('javascript')); ?>"
                      class="button button-secondary"
                      onclick="alert('Drag this button to your bookmarks bar instead of clicking it.'); return false;">
                      📥 <?php _e('Import Scholar Data', 'wp-google-scholar'); ?>
                    </a>
                    <a href="https://scholar.google.com/citations?user=<?php echo esc_attr(rawurlencode($options['profile_id'])); ?>&hl=en"
                      target="_blank" rel="noopener noreferrer" class="button">
                      <?php _e('Open my profile', 'wp-google-scholar'); ?>
                    </a>
                  </p>

                  <p class="description">
                    <?php _e("No bookmarklet? Open your profile page above, select all (Ctrl/Cmd+A), copy (Ctrl/Cmd+C), and paste below. The Import box keeps the copied HTML when your browser provides it; if it cannot, open View Source and copy that page instead. First use ‘Replace profile data’. For later pages (&cstart=20, &cstart=40, ...), use ‘Add publications from another page’ so the existing list is kept.", 'wp-google-scholar'); ?>
                  </p>

                  <form method="post" action="<?php echo admin_url('admin-post.php'); ?>" id="scholar-import-form">
                    <input type="hidden" name="action" value="import_scholar_profile">
                    <?php wp_nonce_field('import_scholar_profile', 'scholar_import_nonce'); ?>

                    <textarea name="scholar_import_content" id="scholar_import_content" rows="8"
                      class="large-text code"
                      placeholder="<?php esc_attr_e('Paste bookmarklet JSON or Scholar page HTML here...', 'wp-google-scholar'); ?>"></textarea>
                    <p id="scholar-import-paste-status" class="description" aria-live="polite"></p>

                    <div class="scholar-form-actions">
                      <button type="submit" name="scholar_import_mode" value="replace" class="button button-primary">
                        <?php _e('Replace profile data', 'wp-google-scholar'); ?>
                      </button>
                      <button type="submit" name="scholar_import_mode" value="append" class="button button-secondary">
                        <?php _e('Add publications from another page', 'wp-google-scholar'); ?>
                      </button>
                    </div>
                  </form>
                <?php endif; ?>
              </div>
            <?php else: ?>
              <!-- Separate Refresh Form with Loading Indicators -->
              <div class="scholar-refresh-section">
                <h3><?php _e('Manual Refresh', 'wp-google-scholar'); ?></h3>

                <div class="scholar-loading-message" id="scholar-loading-message">
                  <strong>🔄 <?php _e('Refreshing Profile Data...', 'wp-google-scholar'); ?></strong>
                  <div class="scholar-progress-steps" id="scholar-progress-steps">
                    <div class="scholar-progress-step" id="step-1">📡 <?php _e('Connecting to Google Scholar...', 'wp-google-scholar'); ?></div>
                    <div class="scholar-progress-step" id="step-2">📄 <?php _e('Fetching profile information...', 'wp-google-scholar'); ?></div>
                    <div class="scholar-progress-step" id="step-3">📚 <?php _e('Loading publications...', 'wp-google-scholar'); ?></div>
                    <div class="scholar-progress-step" id="step-4">👥 <?php _e('Processing co-authors...', 'wp-google-scholar'); ?></div>
                    <div class="scholar-progress-step" id="step-5">💾 <?php _e('Saving data...', 'wp-google-scholar'); ?></div>
                  </div>
                  <p><em><?php _e('This may take 30-60 seconds for large profiles. Please do not close this page.', 'wp-google-scholar'); ?></em></p>
                </div>

                <form method="post" action="<?php echo admin_url('admin-post.php'); ?>" id="scholar-refresh-form">
                  <input type="hidden" name="action" value="refresh_scholar_profile">
                  <?php wp_nonce_field('refresh_scholar_profile', 'scholar_refresh_nonce'); ?>

                  <div class="scholar-refresh-controls" id="scholar-refresh-controls">
                    <input type="submit"
                      name="refresh_profile"
                      class="button button-secondary"
                      id="scholar-refresh-btn"
                      value="<?php esc_attr_e('Refresh Profile Data', 'wp-google-scholar'); ?>"
                      <?php echo !$can_refresh ? 'disabled' : ''; ?>>

                    <?php if (!$can_refresh): ?>
                      <span class="scholar-cooldown-notice">
                        <?php
                        // translators: %d is the number of minutes remaining
                        printf(
                          __('Please wait %d more minute(s) before refreshing again.', 'wp-google-scholar'),
                          $cooldown_remaining
                        ); ?>
                      </span>
                    <?php endif; ?>
                  </div>

                  <p class="description">
                    <?php _e('Manually refresh data from Google Scholar. Large profiles may take several minutes to process.', 'wp-google-scholar'); ?>
                    <?php if ($can_refresh): ?>
                      <br><em><?php _e('💡 Tip: This is useful after adding new publications to your Google Scholar profile.', 'wp-google-scholar'); ?></em>
                    <?php endif; ?>
                  </p>
                </form>
              </div>
            <?php endif; ?>
          </div>
        </div>
      </div>

      <!-- RIGHT: Profile Info + Usage (30%) -->
      <div class="scholar-sidebar-content">

        <!-- Enhanced Profile Status Card -->
        <?php if ($has_profile_data): ?>
          <div class="scholar-status-card scholar-status-active">
            <div class="scholar-status-header">
              <div class="scholar-status-info">
                <h3><?php echo esc_html($profile_data['name']); ?></h3>
                <p><?php echo esc_html($profile_data['affiliation']); ?></p>

                <!-- Research Interests Preview -->
                <?php if (!empty($profile_data['interests']) && is_array($profile_data['interests'])): ?>
                  <div class="scholar-interests-preview">
                    <?php
                    $preview_interests = array_slice($profile_data['interests'], 0, 3);
                    foreach ($preview_interests as $interest):
                      if (is_array($interest)): ?>
                        <span class="scholar-interest-tag"><?php echo esc_html($interest['text']); ?></span>
                      <?php else: ?>
                        <span class="scholar-interest-tag"><?php echo esc_html($interest); ?></span>
                      <?php endif;
                    endforeach;

                    if (count($profile_data['interests']) > 3): ?>
                      <span class="scholar-interest-more">+<?php echo count($profile_data['interests']) - 3; ?> more</span>
                    <?php endif; ?>
                  </div>
                <?php endif; ?>
              </div>

              <?php if (!empty($profile_data['avatar'])): ?>
                <img src="<?php echo esc_url($profile_data['avatar']); ?>"
                  alt="<?php echo esc_attr($profile_data['name']); ?>"
                  class="scholar-status-avatar">
              <?php endif; ?>
            </div>

            <div class="scholar-status-stats">
              <div class="scholar-stat">
                <span class="scholar-stat-number"><?php echo number_format($profile_data['citations']['total']); ?></span>
                <span class="scholar-stat-label"><?php _e('Citations', 'wp-google-scholar'); ?></span>
              </div>
              <div class="scholar-stat">
                <span class="scholar-stat-number"><?php echo count($profile_data['publications']); ?></span>
                <span class="scholar-stat-label"><?php _e('Publications', 'wp-google-scholar'); ?></span>
              </div>
              <div class="scholar-stat">
                <span class="scholar-stat-number"><?php echo esc_html($profile_data['citations']['h_index']); ?></span>
                <span class="scholar-stat-label">h-index</span>
              </div>
              <div class="scholar-stat">
                <span class="scholar-stat-number"><?php echo count($profile_data['coauthors']); ?></span>
                <span class="scholar-stat-label"><?php _e('Co-authors', 'wp-google-scholar'); ?></span>
              </div>
            </div>

            <!-- Enhanced Footer with More Info -->
            <div class="scholar-status-footer">
              <div class="scholar-update-info">
                <?php if ($last_update): ?>
                  <span class="scholar-last-update">
                    <strong><?php _e('Last updated:', 'wp-google-scholar'); ?></strong>
                    <?php echo date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $last_update); ?>
                    <small>(<?php echo human_time_diff($last_update, current_time('timestamp')); ?> ago)</small>
                  </span>
                <?php endif; ?>

                <?php if ($next_scheduled): ?>
                  <span class="scholar-next-update">
                    <strong><?php _e('Next update:', 'wp-google-scholar'); ?></strong>
                    <?php echo human_time_diff($next_scheduled, current_time('timestamp')); ?>
                  </span>
                <?php endif; ?>
              </div>
            </div>
          </div>
        <?php else: ?>
          <div class="scholar-status-card scholar-status-empty">
            <div class="scholar-status-empty-content">
              <span class="dashicons dashicons-admin-users"></span>
              <h3><?php _e('No profile data', 'wp-google-scholar'); ?></h3>
              <p><?php _e('Configure your profile ID above, then use step 3 to fetch your data.', 'wp-google-scholar'); ?></p>

              <?php if (!empty($options['profile_id'])): ?>
                <div class="scholar-empty-actions">
                  <p><strong><?php _e('Profile ID set:', 'wp-google-scholar'); ?></strong> <?php echo esc_html($options['profile_id']); ?></p>
                  <p><em><?php echo $update_method === 'browser'
                      ? esc_html__('Use the Browser-Assisted Import panel in step 3 to load your data.', 'wp-google-scholar')
                      : esc_html__('Click "Refresh Profile Data" in step 3 to load your data.', 'wp-google-scholar'); ?></em></p>
                </div>
              <?php else: ?>
                <div class="scholar-empty-actions">
                  <p><em><?php _e('Start by entering your Google Scholar Profile ID above.', 'wp-google-scholar'); ?></em></p>
                </div>
              <?php endif; ?>
            </div>
          </div>
        <?php endif; ?>

        <!-- Enhanced Usage Instructions -->
        <div class="scholar-usage-card">
          <h3><?php _e('Usage Guide', 'wp-google-scholar'); ?></h3>

          <!-- Basic Shortcode -->
          <div class="scholar-usage-section">
            <h4><?php _e('📝 Basic Usage', 'wp-google-scholar'); ?></h4>
            <p><?php _e('Add to any post or page:', 'wp-google-scholar'); ?></p>
            <div class="scholar-shortcode">
              <code>[scholar_profile]</code>
              <button type="button" class="scholar-copy-btn" onclick="navigator.clipboard.writeText('[scholar_profile]')" title="<?php esc_attr_e('Copy shortcode', 'wp-google-scholar'); ?>">
                <span class="dashicons dashicons-admin-page"></span>
              </button>
            </div>
          </div>

          <!-- Sorting Options -->
          <div class="scholar-usage-section">
            <h4><?php _e('📊 Sorting Options', 'wp-google-scholar'); ?></h4>
            <p><?php _e('Sort publications by year, citations, or title:', 'wp-google-scholar'); ?></p>
            <div class="scholar-code-examples">
              <code>[scholar_profile sort_by="year" sort_order="desc"]</code>
              <code>[scholar_profile sort_by="citations" sort_order="desc"]</code>
              <code>[scholar_profile sort_by="title" sort_order="asc"]</code>
            </div>
            <p class="scholar-usage-tip">
              <?php _e('💡 <strong>Interactive Sorting:</strong> Readers can also click column headers to sort the table dynamically.', 'wp-google-scholar'); ?>
            </p>
          </div>

          <!-- Pagination -->
          <div class="scholar-usage-section">
            <h4><?php _e('📄 Pagination', 'wp-google-scholar'); ?></h4>
            <p><?php _e('Control publications per page:', 'wp-google-scholar'); ?></p>
            <div class="scholar-code-examples">
              <code>[scholar_profile per_page="10"]</code>
              <code>[scholar_profile per_page="25"]</code>
            </div>
          </div>
        </div>

        <!-- Enhanced Rate Limiting Notice -->
        <div class="scholar-notice-card">
          <h3>
            <span class="dashicons dashicons-warning"></span>
            <?php _e('Common Issues & Solutions', 'wp-google-scholar'); ?>
          </h3>

          <?php if ($update_method === 'server'): ?>
            <!-- HTTP 403 Blocked Access -->
            <div class="scholar-troubleshooting-section">
              <h4>🔒 <?php _e('Server Access Blocked (HTTP 403)', 'wp-google-scholar'); ?></h4>
              <p><?php _e('Most common issue. Google Scholar temporarily blocks server IPs.', 'wp-google-scholar'); ?></p>
              <ul class="scholar-notice-list">
                <li><?php _e('Wait 1-2 hours and try again', 'wp-google-scholar'); ?></li>
                <li><?php _e('Contact your hosting provider if it persists', 'wp-google-scholar'); ?></li>
                <li><?php _e('Use monthly updates instead of daily/weekly', 'wp-google-scholar'); ?></li>
                <li><?php _e('Still blocked? Switch to Browser mode in step 2 - it fetches through your own browser instead of the server.', 'wp-google-scholar'); ?></li>
              </ul>
            </div>
          <?php else: ?>
            <!-- Paste Not Recognized -->
            <div class="scholar-troubleshooting-section">
              <h4>📋 <?php _e('Pasted Content Not Recognized', 'wp-google-scholar'); ?></h4>
              <p><?php _e('Most common issue in Browser mode. The paste box did not receive a full Scholar profile page.', 'wp-google-scholar'); ?></p>
              <ul class="scholar-notice-list">
                <li><?php _e('Use the bookmarklet - it captures the page more reliably than copy/paste', 'wp-google-scholar'); ?></li>
                <li><?php _e('If pasting manually, use View Source and copy that instead of the rendered page', 'wp-google-scholar'); ?></li>
                <li><?php _e('For a later page (&cstart=20, ...), use "Add publications from another page", not "Replace profile data"', 'wp-google-scholar'); ?></li>
                <li><?php _e('"Add publications" requires the main profile to be imported first with "Replace profile data"', 'wp-google-scholar'); ?></li>
              </ul>
            </div>
          <?php endif; ?>

          <!-- Profile Issues -->
          <div class="scholar-troubleshooting-section">
            <h4>👤 <?php _e('Profile Not Found (HTTP 404)', 'wp-google-scholar'); ?></h4>
            <ul class="scholar-notice-list">
              <li><?php _e('Double-check your Profile ID format', 'wp-google-scholar'); ?></li>
              <li><?php _e('Make sure your profile is set to public', 'wp-google-scholar'); ?></li>
              <li><?php _e('Test the profile URL in your browser first', 'wp-google-scholar'); ?></li>
            </ul>
          </div>

          <p class="scholar-notice-recommendation">
            <strong><?php _e('💡 Best Practice:', 'wp-google-scholar'); ?></strong>
            <?php echo $update_method === 'server'
              ? esc_html__('Set up automatic monthly updates and avoid frequent manual refreshes to prevent IP blocks.', 'wp-google-scholar')
              : esc_html__('Import the main profile page first, then add later pages one at a time using "Add publications from another page".', 'wp-google-scholar'); ?>
          </p>
        </div>

      </div>
    </div>

  </div>
</div>

<script>
  document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('scholar-refresh-form');
    const controls = document.getElementById('scholar-refresh-controls');
    const message = document.getElementById('scholar-loading-message');
    const steps = document.querySelectorAll('.scholar-progress-step');

    if (form && controls && message) {
      form.addEventListener('submit', function() {
        // Show loading state
        controls.classList.add('scholar-refresh-loading');
        message.classList.add('show');

        // Animate progress steps
        let currentStep = 0;
        const progressInterval = setInterval(function() {
          if (currentStep < steps.length) {
            if (currentStep > 0) {
              steps[currentStep - 1].classList.remove('current');
              steps[currentStep - 1].classList.add('completed');
            }
            steps[currentStep].classList.add('current');
            currentStep++;
          } else {
            clearInterval(progressInterval);
          }
        }, 8000); // 8 seconds per step = ~40 seconds total

        // Fallback timeout
        setTimeout(function() {
          clearInterval(progressInterval);
        }, 120000); // 2 minutes max
      });
    }

    const importTextarea = document.getElementById('scholar_import_content');
    const importPasteStatus = document.getElementById('scholar-import-paste-status');

    if (importTextarea) {
      importTextarea.addEventListener('paste', function(event) {
        const html = event.clipboardData && event.clipboardData.getData('text/html');

        // A textarea normally receives text/plain, even when the clipboard
        // also contains the selected page's HTML. Preserve the latter so the
        // server can parse Scholar's gsc_* elements reliably.
        if (html && html.indexOf('gsc_') !== -1) {
          event.preventDefault();
          importTextarea.value = html;
          if (importPasteStatus) {
            importPasteStatus.textContent = '<?php echo esc_js(__('Copied page HTML detected and ready to import.', 'wp-google-scholar')); ?>';
          }
        } else if (importPasteStatus) {
          importPasteStatus.textContent = '<?php echo esc_js(__('Pasted text did not contain page HTML. Use the bookmarklet or copy from View Source.', 'wp-google-scholar')); ?>';
        }
      });
    }
  });
</script>
