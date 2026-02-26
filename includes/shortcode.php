<?php

namespace WPScholar;

class Shortcode
{
  private $seo_handler;

  public function __construct()
  {
    add_shortcode('scholar_profile', array($this, 'render_profile'));
    $this->seo_handler = new SEO();
  }

  public function render_profile($atts)
  {
    // Enqueue assets only on pages with this shortcode
    wp_enqueue_style(
      'scholar-profile-styles',
      WP_SCHOLAR_PLUGIN_URL . 'assets/css/style.css',
      array(),
      WP_SCHOLAR_VERSION
    );
    wp_enqueue_script(
      'scholar-profile-sorting',
      WP_SCHOLAR_PLUGIN_URL . 'assets/js/scholar-sorting.js',
      array(),
      WP_SCHOLAR_VERSION,
      true
    );

    // Parse shortcode attributes
    $atts = shortcode_atts(array(
      'sort_by' => '',
      'sort_order' => 'desc',
      'per_page' => 20
    ), $atts, 'scholar_profile');

    $options = get_option('scholar_profile_settings');
    $data = get_option('scholar_profile_data');

    // Check data status and display appropriate messages
    $scheduler = new Scheduler();
    $data_status = $scheduler->get_data_status();
    $is_data_stale = $scheduler->is_data_stale();

    if (!$data) {
      // No data available at all - check for enhanced error details
      if ($data_status['status'] === 'error') {
        return $this->render_enhanced_error_message($data_status['message']);
      } else {
        return $this->render_no_data_message();
      }
    }

    // Validate that the data is complete
    if (!$this->validate_display_data($data)) {
      return $this->render_enhanced_error_message(__('Profile data appears to be incomplete or corrupted.', 'wp-google-scholar'));
    }

    // Add SEO enhancements
    $this->seo_handler->add_profile_seo($data, $options);

    // Check if data is stale and should show a warning
    $show_stale_warning = $is_data_stale && ($data_status['status'] === 'stale' || $data_status['status'] === 'error');

    // Apply sorting if specified
    if (!empty($atts['sort_by']) && !empty($data['publications'])) {
      $data['publications'] = $this->sort_publications($data['publications'], $atts['sort_by'], $atts['sort_order']);
    }

    // Get current page from URL parameter
    $current_page = isset($_GET['scholar_page']) ? max(1, intval(sanitize_text_field($_GET['scholar_page']))) : 1;
    $per_page = max(1, min(100, intval($atts['per_page']))); // Limit between 1-100

    // Calculate pagination
    $total_publications = count($data['publications']);
    $total_pages = ceil($total_publications / $per_page);
    $current_page = min($current_page, $total_pages); // Ensure current page doesn't exceed total

    // Slice publications for current page
    $offset = ($current_page - 1) * $per_page;
    $paged_publications = array_slice($data['publications'], $offset, $per_page);

    // Store pagination info for JavaScript
    $pagination_data = array(
      'current_page' => $current_page,
      'total_pages' => $total_pages,
      'per_page' => $per_page,
      'total_publications' => $total_publications
    );

    ob_start();

    // Show stale data warning if needed
    if ($show_stale_warning) {
      $this->render_stale_data_warning($data_status);
    }

    // Main wrapper with pagination data
    echo '<div class="scholar-profile" data-pagination=\'' . json_encode($pagination_data) . '\'>';

    // Main content section
    echo '<div class="scholar-main">';
    $this->render_header($data, $options);
    $this->render_publications($data, $options, $paged_publications, $current_page, $total_pages, $per_page);
    echo '</div>'; // Close main section

    // Sidebar
    echo '<div class="scholar-sidebar">';
    $this->render_metrics($data);
    $this->render_coauthors($data, $options);
    echo '</div>'; // Close sidebar

    echo '</div>'; // Close profile wrapper

    // Add structured data
    $this->seo_handler->add_structured_data($data, $paged_publications);

    return ob_get_clean();
  }

  /**
   * Render enhanced error message with detailed information when available
   */
  protected function render_enhanced_error_message($message = '')
  {
    // Get detailed error information if available
    $error_details = get_option('scholar_profile_last_error_details');

    $default_message = __('Unable to display profile data. Please contact the site administrator.', 'wp-google-scholar');
    $display_message = !empty($message) ? $message : $default_message;

    // Check if we have enhanced error details for better user guidance
    if ($error_details && isset($error_details['type'])) {
      $enhanced_message = $this->format_frontend_error_message($error_details);
      if ($enhanced_message) {
        $display_message = $enhanced_message;
      }
    }

    return sprintf(
      '<div class="scholar-error-message">
        <p class="scholar-error">
          <span class="scholar-error-icon">⚠️</span>
          %s
        </p>
      </div>',
      wp_kses($display_message, array(
        'strong' => array(),
        'em' => array(),
        'br' => array()
      ))
    );
  }

  /**
   * Format error message for frontend display (less technical than admin)
   */
  private function format_frontend_error_message($error_details)
  {
    if (!isset($error_details['user_message'])) {
      return null;
    }

    $message = '<strong>' . esc_html($error_details['user_message']) . '</strong>';

    // Add user-friendly suggestions based on error type
    switch ($error_details['type']) {
      case 'blocked_access':
        $message .= '<br><br>' . __('This is usually temporary. The profile should be accessible again within a few hours.', 'wp-google-scholar');
        break;

      case 'profile_not_found':
        $message .= '<br><br>' . __('Please verify the profile ID is correct and the profile is publicly accessible.', 'wp-google-scholar');
        break;

      case 'profile_private':
        $message .= '<br><br>' . __('The profile owner needs to make their Google Scholar profile public for it to be displayed.', 'wp-google-scholar');
        break;

      case 'rate_limited':
        $message .= '<br><br>' . __('This is temporary - the profile should be available again soon.', 'wp-google-scholar');
        break;

      case 'service_unavailable':
        $message .= '<br><br>' . __('Google Scholar is temporarily unavailable. Please try refreshing the page in a few minutes.', 'wp-google-scholar');
        break;
    }

    return $message;
  }

  /**
   * Render message when no data is available
   */
  protected function render_no_data_message()
  {
    return '<div class="scholar-no-data-message">
      <p class="scholar-info">
        <span class="scholar-info-icon">📚</span>
        ' . __('Google Scholar profile data is not yet available. Please check back later.', 'wp-google-scholar') . '
      </p>
    </div>';
  }

  /**
   * Render warning for stale data
   */
  protected function render_stale_data_warning($data_status)
  {
    $last_update = get_option('scholar_profile_last_update', 0);
    $age_text = '';

    if ($last_update) {
      $age_days = ceil((time() - $last_update) / DAY_IN_SECONDS);
      if ($age_days == 1) {
        $age_text = __('1 day ago', 'wp-google-scholar');
      } elseif ($age_days < 30) {
        // translators: %d is the number of days
        $age_text = sprintf(__('%d days ago', 'wp-google-scholar'), $age_days);
      } elseif ($age_days < 365) {
        // translators: %d is the number of months
        $age_text = sprintf(__('%d months ago', 'wp-google-scholar'), ceil($age_days / 30));
      } else {
        // translators: %d is the number of years
        $age_text = sprintf(__('%d years ago', 'wp-google-scholar'), ceil($age_days / 365));
      }
    }

    echo '<div class="scholar-stale-warning">
      <p class="scholar-warning">
        <span class="scholar-warning-icon">⚠️</span>
        <strong>' . __('Data Update Notice:', 'wp-google-scholar') . '</strong> ';

    if ($data_status['status'] === 'stale') {
      echo __('This profile data may be outdated', 'wp-google-scholar');
      if ($age_text) {
        // translators: %s is when the data was last updated (e.g., "3 days ago")
        echo ' (' . sprintf(__('last updated %s', 'wp-google-scholar'), $age_text) . ')';
      }
      echo '. ' . __('Automatic updates are currently experiencing issues.', 'wp-google-scholar');
    } elseif ($data_status['status'] === 'error') {
      echo __('Unable to update this profile data.', 'wp-google-scholar');
      if ($age_text) {
        // translators: %s is when the data is from (e.g., "3 days ago")
        echo ' ' . sprintf(__('Showing data from %s.', 'wp-google-scholar'), $age_text);
      }
    }

    echo '</p></div>';
  }

  /**
   * Validate that display data is reasonable
   */
  protected function validate_display_data($data)
  {
    if (!is_array($data)) {
      return false;
    }

    // Check for essential fields
    if (empty($data['name'])) {
      return false;
    }

    // Publications should be an array (even if empty)
    if (!isset($data['publications']) || !is_array($data['publications'])) {
      return false;
    }

    // Citations should be an array
    if (!isset($data['citations']) || !is_array($data['citations'])) {
      return false;
    }

    return true;
  }

  protected function render_header($data, $options)
  {
    if (!$options['show_info']) {
      return;
    }

    echo '<div class="scholar-card scholar-profile-info">
            <div class="scholar-card-content">
                <div class="scholar-profile-header">';

    // Avatar with fallback handling
    if ($options['show_avatar'] && !empty($data['avatar'])) {
      // Verify avatar URL is still valid
      $avatar_url = $data['avatar'];
      echo '<div class="scholar-avatar">
                    <img src="' . esc_url($avatar_url) . '" 
                         alt="' . esc_attr($data['name']) . ' profile photo"
                         onerror="this.style.display=\'none\'">
                  </div>';
    }

    // Basic info with name moved here
    echo '<div class="scholar-profile-details">
                <h1>' . esc_html($data['name']) . '</h1>';

    if (!empty($data['affiliation'])) {
      echo '<div class="scholar-affiliation">' . esc_html($data['affiliation']) . '</div>';
    }

    // Research interests with links
    if (!empty($data['interests'])) {
      echo '<div class="scholar-profile-interests">
                  <h3>' . __('Research Interests', 'wp-google-scholar') . '</h3>
                  <div class="scholar-fields">';
      foreach ($data['interests'] as $interest) {
        if (is_array($interest) && !empty($interest['url'])) {
          echo '<a href="' . esc_url($interest['url']) . '" class="scholar-field" target="_blank" rel="noopener noreferrer">' . esc_html($interest['text']) . '</a>';
        } else {
          // Fallback for old data format
          echo '<span class="scholar-field">' . esc_html(is_array($interest) ? $interest['text'] : $interest) . '</span>';
        }
      }
      echo '</div></div>';
    }

    echo '</div></div></div></div>';
  }

  protected function render_publications($data, $options, $paged_publications, $current_page, $total_pages, $per_page)
  {
    if (!$options['show_publications']) {
      return;
    }

    // Handle case where publications might be empty
    if (empty($data['publications'])) {
      echo '<div class="scholar-card scholar-publications">
              <div class="scholar-card-header">
                  <h2 class="scholar-card-title">' . __('Publications', 'wp-google-scholar') . '</h2>
              </div>
              <div class="scholar-card-content">
                  <p class="scholar-no-publications">' . __('No publications found.', 'wp-google-scholar') . '</p>
              </div>
            </div>';
      return;
    }

    $total_publications = count($data['publications']);
    $start_index = ($current_page - 1) * $per_page + 1;
    $end_index = min($current_page * $per_page, $total_publications);

    echo '<div class="scholar-card scholar-publications">
            <div class="scholar-card-header">
                <h2 class="scholar-card-title">' . __('Publications', 'wp-google-scholar') . '</h2>';

    // Show publication count and pagination info
    if ($total_pages > 1) {
      // translators: %1$d is start index, %2$d is end index, %3$d is total publications
      echo '<div class="scholar-card-info">' .
        sprintf(
          __('Showing %1$d-%2$d of %3$d publications', 'wp-google-scholar'),
          $start_index,
          $end_index,
          $total_publications
        ) . '</div>';
    } else {
      // translators: %d is the number of publications
      echo '<div class="scholar-card-info">' .
        sprintf(
          _n('%d publication', '%d publications', $total_publications, 'wp-google-scholar'),
          $total_publications
        ) . '</div>';
    }

    echo '</div>
            <div class="scholar-card-content">';

    echo '<table class="scholar-publications-table" data-sortable="true">
                <thead>
                    <tr>
                        <th class="publication-title sortable" data-sort="title" tabindex="0" role="button" aria-label="' . __('Sort by title', 'wp-google-scholar') . '">
                            <span class="sort-label">' . __('Title', 'wp-google-scholar') . '</span>
                            <span class="sort-arrow" aria-hidden="true"></span>
                        </th>
                        <th class="publication-year sortable" data-sort="year" tabindex="0" role="button" aria-label="' . __('Sort by year', 'wp-google-scholar') . '">
                            <span class="sort-label">' . __('Year', 'wp-google-scholar') . '</span>
                            <span class="sort-arrow" aria-hidden="true"></span>
                        </th>
                        <th class="publication-citations sortable" data-sort="citations" tabindex="0" role="button" aria-label="' . __('Sort by citations', 'wp-google-scholar') . '">
                            <span class="sort-label">' . __('Cited by', 'wp-google-scholar') . '</span>
                            <span class="sort-arrow" aria-hidden="true"></span>
                        </th>
                    </tr>
                </thead>
                <tbody class="publications-tbody">';

    foreach ($paged_publications as $pub) {
      echo '<tr>
                <td class="publication-info">
                    <a href="' . esc_url($pub['google_scholar_url']) . '" 
                       class="scholar-publication-title" 
                       target="_blank" rel="noopener noreferrer">'
        . esc_html($pub['title']) . '</a>';

      if (!empty($pub['authors'])) {
        echo '<div class="scholar-publication-authors">' . esc_html($pub['authors']) . '</div>';
      }

      if (!empty($pub['venue'])) {
        echo '<div class="scholar-publication-venue">' . esc_html($pub['venue']) . '</div>';
      }

      echo '</td>
                <td class="publication-year" data-year="' . esc_attr($pub['year']) . '">'
        . esc_html($pub['year']) . '</td>
                <td class="publication-citations" data-citations="' . esc_attr($pub['citations']) . '">';

      if ($pub['citations'] > 0 && !empty($pub['citations_url'])) {
        echo '<a href="' . esc_url($pub['citations_url']) . '" 
                     target="_blank" rel="noopener noreferrer">'
          . number_format($pub['citations']) . '</a>';
      } else {
        echo intval($pub['citations']);
      }

      echo '</td></tr>';
    }

    echo '</tbody></table>';

    // Render pagination if needed
    if ($total_pages > 1) {
      $this->render_pagination($current_page, $total_pages);
    }

    echo '</div></div>';
  }

  protected function render_pagination($current_page, $total_pages)
  {
    $base_url = esc_url_raw(strtok($_SERVER['REQUEST_URI'], '?'));
    $query_params = isset($_GET) ? map_deep(wp_unslash($_GET), 'sanitize_text_field') : array();

    echo '<nav class="scholar-pagination" role="navigation" aria-label="' . __('Publications pagination', 'wp-google-scholar') . '">
            <div class="scholar-pagination-wrapper">';

    // Previous button
    if ($current_page > 1) {
      $query_params['scholar_page'] = $current_page - 1;
      $prev_url = $base_url . '?' . http_build_query($query_params);
      echo '<a href="' . esc_url($prev_url) . '" class="scholar-pagination-btn scholar-pagination-prev" aria-label="' . __('Previous page', 'wp-google-scholar') . '">
                <span aria-hidden="true">‹</span>
                <span class="scholar-pagination-text">' . __('Previous', 'wp-google-scholar') . '</span>
            </a>';
    } else {
      echo '<span class="scholar-pagination-btn scholar-pagination-prev disabled" aria-hidden="true">
                <span aria-hidden="true">‹</span>
                <span class="scholar-pagination-text">' . __('Previous', 'wp-google-scholar') . '</span>
            </span>';
    }

    // Page numbers
    echo '<div class="scholar-pagination-numbers">';

    $start_page = max(1, $current_page - 2);
    $end_page = min($total_pages, $current_page + 2);

    // First page + ellipsis if needed
    if ($start_page > 1) {
      $query_params['scholar_page'] = 1;
      $first_url = $base_url . '?' . http_build_query($query_params);
      echo '<a href="' . esc_url($first_url) . '" class="scholar-pagination-number" aria-label="' . __('Go to page 1', 'wp-google-scholar') . '">1</a>';

      if ($start_page > 2) {
        echo '<span class="scholar-pagination-ellipsis" aria-hidden="true">…</span>';
      }
    }

    // Page numbers around current page
    for ($page = $start_page; $page <= $end_page; $page++) {
      if ($page == $current_page) {
        // translators: %d is the page number
        echo '<span class="scholar-pagination-number current" aria-current="page" aria-label="' . sprintf(__('Page %d, current page', 'wp-google-scholar'), $page) . '">' . $page . '</span>';
      } else {
        $query_params['scholar_page'] = $page;
        $page_url = $base_url . '?' . http_build_query($query_params);
        // translators: %d is the page number
        echo '<a href="' . esc_url($page_url) . '" class="scholar-pagination-number" aria-label="' . sprintf(__('Go to page %d', 'wp-google-scholar'), $page) . '">' . $page . '</a>';
      }
    }

    // Last page + ellipsis if needed
    if ($end_page < $total_pages) {
      if ($end_page < $total_pages - 1) {
        echo '<span class="scholar-pagination-ellipsis" aria-hidden="true">…</span>';
      }

      $query_params['scholar_page'] = $total_pages;
      $last_url = $base_url . '?' . http_build_query($query_params);
      // translators: %d is the last page number
      echo '<a href="' . esc_url($last_url) . '" class="scholar-pagination-number" aria-label="' . sprintf(__('Go to page %d', 'wp-google-scholar'), $total_pages) . '">' . $total_pages . '</a>';
    }

    echo '</div>';

    // Next button
    if ($current_page < $total_pages) {
      $query_params['scholar_page'] = $current_page + 1;
      $next_url = $base_url . '?' . http_build_query($query_params);
      echo '<a href="' . esc_url($next_url) . '" class="scholar-pagination-btn scholar-pagination-next" aria-label="' . __('Next page', 'wp-google-scholar') . '">
                <span class="scholar-pagination-text">' . __('Next', 'wp-google-scholar') . '</span>
                <span aria-hidden="true">›</span>
            </a>';
    } else {
      echo '<span class="scholar-pagination-btn scholar-pagination-next disabled" aria-hidden="true">
                <span class="scholar-pagination-text">' . __('Next', 'wp-google-scholar') . '</span>
                <span aria-hidden="true">›</span>
            </span>';
    }

    echo '</div></nav>';
  }

  protected function render_metrics($data)
  {
    // Check if citations data exists and has meaningful values
    if (
      empty($data['citations']) ||
      ($data['citations']['total'] === 0 &&
        $data['citations']['h_index'] === 0 &&
        $data['citations']['i10_index'] === 0 &&
        $data['citations']['since_2019'] === 0 &&
        $data['citations']['h_index_2019'] === 0 &&
        $data['citations']['i10_index_2019'] === 0)
    ) {
      return;
    }

    echo '<div class="scholar-card scholar-metrics">
            <div class="scholar-card-header">
                <h2 class="scholar-card-title">' . __('Citations', 'wp-google-scholar') . '</h2>
            </div>
            <div class="scholar-card-content">
                <table class="scholar-metrics-table">
                    <thead>
                        <tr>
                            <th></th>
                            <th>' . __('All', 'wp-google-scholar') . '</th>
                            <th>' . __('Since 2019', 'wp-google-scholar') . '</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td>' . __('Citations', 'wp-google-scholar') . '</td>
                            <td>' . number_format($data['citations']['total']) . '</td>
                            <td>' . number_format($data['citations']['since_2019']) . '</td>
                        </tr>
                        <tr>
                            <td>h-index</td>
                            <td>' . esc_html($data['citations']['h_index']) . '</td>
                            <td>' . esc_html($data['citations']['h_index_2019']) . '</td>
                        </tr>
                        <tr>
                            <td>i10-index</td>
                            <td>' . esc_html($data['citations']['i10_index']) . '</td>
                            <td>' . esc_html($data['citations']['i10_index_2019']) . '</td>
                        </tr>
                    </tbody>
                </table>
            </div>
          </div>';
  }

  protected function render_coauthors($data, $options)
  {
    if (!$options['show_coauthors'] || empty($data['coauthors'])) {
      return;
    }

    $coauthor_count = count($data['coauthors']);

    // translators: %d is the number of co-authors
    $coauthor_text = sprintf(_n('%d co-author', '%d co-authors', $coauthor_count, 'wp-google-scholar'), $coauthor_count);

    echo '<div class="scholar-card scholar-coauthors">
            <div class="scholar-card-header">
                <h2 class="scholar-card-title">' . __('Co-authors', 'wp-google-scholar') . '</h2>
                <div class="scholar-card-info">' . $coauthor_text . '</div>
            </div>
            <div class="scholar-card-content">
                <div class="scholar-coauthor-list">';

    foreach ($data['coauthors'] as $coauthor) {
      echo '<div class="scholar-coauthor">
                    <div class="scholar-coauthor-header">';

      // Avatar with fallback handling
      if (!empty($coauthor['avatar'])) {
        echo '<img src="' . esc_url($coauthor['avatar']) . '" 
                       alt="' . esc_attr($coauthor['name']) . '" 
                       class="scholar-coauthor-avatar"
                       onerror="this.style.display=\'none\'">';
      }

      echo '<div class="scholar-coauthor-main">
                        <a href="' . esc_url($coauthor['profile_url']) . '" 
                           class="scholar-coauthor-name"
                           target="_blank" rel="noopener noreferrer">'
        . esc_html($coauthor['name']) . '</a>';

      if (!empty($coauthor['title'])) {
        echo '<div class="scholar-coauthor-affiliation">'
          . esc_html($coauthor['title']) . '</div>';
      }

      echo '</div></div></div>';
    }

    echo '</div></div></div>';
  }

  /**
   * Sort publications by specified field and order
   * Matches Google Scholar behavior with secondary sorting:
   * - Sort by year → secondary sort by citations
   * - Sort by citations → secondary sort by year
   */
  protected function sort_publications($publications, $sort_by, $sort_order = 'desc')
  {
    if (empty($publications) || !is_array($publications)) {
      return $publications;
    }

    $valid_sorts = array('title', 'year', 'citations');
    if (!in_array($sort_by, $valid_sorts)) {
      return $publications;
    }

    $sort_order = strtolower($sort_order) === 'asc' ? 'asc' : 'desc';

    usort($publications, function ($a, $b) use ($sort_by, $sort_order) {
      // Primary sort field
      $value_a = $a[$sort_by] ?? '';
      $value_b = $b[$sort_by] ?? '';

      switch ($sort_by) {
        case 'year':
        case 'citations':
          $value_a = intval($value_a);
          $value_b = intval($value_b);
          break;
        case 'title':
          $value_a = strtolower(trim($value_a));
          $value_b = strtolower(trim($value_b));
          break;
      }

      // Compare primary field
      $comparison = 0;
      if ($value_a != $value_b) {
        if ($sort_order === 'asc') {
          $comparison = ($value_a < $value_b) ? -1 : 1;
        } else {
          $comparison = ($value_a > $value_b) ? -1 : 1;
        }
      }

      // If primary fields are equal, apply secondary sort (matching Google Scholar)
      if ($comparison === 0) {
        if ($sort_by === 'year') {
          // When sorting by year, secondary sort by citations (descending)
          $citations_a = intval($a['citations'] ?? 0);
          $citations_b = intval($b['citations'] ?? 0);
          $comparison = $citations_b - $citations_a; // Always descending for citations
        } elseif ($sort_by === 'citations') {
          // When sorting by citations, secondary sort by year (descending)
          $year_a = intval($a['year'] ?? 0);
          $year_b = intval($b['year'] ?? 0);
          $comparison = $year_b - $year_a; // Always descending for year
        }
      }

      return $comparison;
    });

    return $publications;
  }
}
