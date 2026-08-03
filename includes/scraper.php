<?php

namespace WPScholar;

class Scraper
{
  // Configuration constants
  private const DEFAULT_MAX_PUBLICATIONS = 200;
  private const DEFAULT_PAGE_SIZE = 20;
  private const DEFAULT_REQUEST_DELAY = 1; // seconds
  private const MAX_IMAGE_SIZE_BYTES = 5242880; // 5MB
  private const MIN_IMAGE_SIZE_BYTES = 100;
  private const MAX_SCRAPING_PAGES = 25;
  private const HTTP_TIMEOUT_SECONDS = 30;
  private const IMAGE_CACHE_DURATION = 86400; // 24 hours

  private $max_publications = self::DEFAULT_MAX_PUBLICATIONS;
  private $page_size = self::DEFAULT_PAGE_SIZE;
  private $request_delay = self::DEFAULT_REQUEST_DELAY;
  private $last_error_details = null; // Store detailed error information

  /**
   * Get detailed error information from the last failed request
   *
   * @return array|null Error details array or null if no error
   */
  public function get_last_error_details(): ?array
  {
    return $this->last_error_details;
  }

  /**
   * Clear stored error details
   *
   * @return void
   */
  public function clear_error_details(): void
  {
    $this->last_error_details = null;
  }

  private function download_to_media_library($image_url, $profile_id, $title = '')
  {
    if (empty($image_url)) {
      wp_scholar_log("Avatar download skipped: Empty image URL for profile $profile_id");
      return '';
    }

    wp_scholar_log("Starting avatar download for profile $profile_id from: $image_url");

    // Generate a consistent filename based on URL hash for better caching
    $url_hash = hash('sha256', $image_url);
    $filename = 'scholar-' . $profile_id . '-' . $url_hash;

    // Determine file extension
    $extension = '.jpg'; // Default
    if (preg_match('/\.(jpg|jpeg|png|gif|webp)$/i', $image_url, $matches)) {
      $extension = strtolower($matches[0]);
    }
    $filename .= $extension;

    // Enhanced cache check - look for existing images by URL hash and profile ID
    $existing_attachment = get_posts(array(
      'post_type' => 'attachment',
      'meta_query' => array(
        'relation' => 'AND',
        array(
          'key' => '_scholar_profile_id',
          'value' => $profile_id
        ),
        array(
          'key' => '_scholar_image_hash',
          'value' => $url_hash
        )
      ),
      'posts_per_page' => 1
    ));

    if (!empty($existing_attachment)) {
      $attachment_url = wp_get_attachment_url($existing_attachment[0]->ID);
      if ($attachment_url && $this->verify_image_exists($attachment_url)) {
        wp_scholar_log("Using cached avatar for hash: $url_hash");
        return $attachment_url;
      } else {
        // Clean up broken attachment and its transient so the image is re-downloaded
        wp_delete_attachment($existing_attachment[0]->ID, true);
        delete_transient('scholar_image_download_' . $url_hash);
        wp_scholar_log("Removed broken cached avatar for hash: $url_hash");
      }
    }

    // Check if we've downloaded this exact URL recently (within last 24 hours)
    $recent_download = get_transient('scholar_image_download_' . $url_hash);
    if ($recent_download) {
      wp_scholar_log("Using recent cached avatar for hash: $url_hash");
      return $recent_download;
    }

    wp_scholar_log("Downloading new avatar for hash: $url_hash from: $image_url");

    // Handle different image URL formats
    $file_array = null;
    $cleanup_temp = false;

    try {
      if (strpos($image_url, 'data:image') === 0) {
        // Handle base64 encoded images
        wp_scholar_log("Processing base64 avatar image");
        $file_array = $this->process_base64_image($image_url, $filename);
        $cleanup_temp = true;
      } else {
        // Handle external image URLs
        wp_scholar_log("Processing external avatar URL");
        $file_array = $this->process_external_image($image_url, $filename);
        $cleanup_temp = true;
      }

      if (!$file_array) {
        wp_scholar_log("Failed to process avatar image for profile $profile_id");
        return '';
      }

      // Verify the file exists and is readable
      if (!file_exists($file_array['tmp_name']) || !is_readable($file_array['tmp_name'])) {
        wp_scholar_log("Avatar temp file is not readable: " . $file_array['tmp_name']);
        return '';
      }

      // Check file size (should be reasonable for an avatar)
      $file_size = filesize($file_array['tmp_name']);
      if ($file_size === false || $file_size < self::MIN_IMAGE_SIZE_BYTES) {
        wp_scholar_log("Avatar file too small or unreadable: {$file_size} bytes");
        if ($cleanup_temp && file_exists($file_array['tmp_name'])) {
          unlink($file_array['tmp_name']);
        }
        return '';
      }

      if ($file_size > self::MAX_IMAGE_SIZE_BYTES) {
        wp_scholar_log("Avatar file too large: {$file_size} bytes");
        if ($cleanup_temp && file_exists($file_array['tmp_name'])) {
          unlink($file_array['tmp_name']);
        }
        return '';
      }

      wp_scholar_log("Avatar file size OK: {$file_size} bytes");

      // Check file type more thoroughly
      $file_type = wp_check_filetype($filename);
      if (!$file_type['type'] || !in_array($file_type['type'], ['image/jpeg', 'image/png', 'image/gif', 'image/webp'])) {
        wp_scholar_log("Invalid avatar file type: " . ($file_type['type'] ?: 'unknown'));
        if ($cleanup_temp && file_exists($file_array['tmp_name'])) {
          unlink($file_array['tmp_name']);
        }
        return '';
      }

      // Additional image validation using getimagesize
      // Validate that the file exists before checking if it's an image
      if (!file_exists($file_array['tmp_name'])) {
        wp_scholar_log('Downloaded file does not exist', 'warning');
        return '';
      }

      // Check if it's a valid image (suppress warnings for invalid files)
      $image_info = @getimagesize($file_array['tmp_name']);
      if ($image_info === false) {
        wp_scholar_log("Avatar file is not a valid image", 'warning');
        if ($cleanup_temp && file_exists($file_array['tmp_name'])) {
          unlink($file_array['tmp_name']);
        }
        return '';
      }

      wp_scholar_log("Avatar validated: {$image_info[0]}x{$image_info[1]}, type: {$image_info['mime']}");

      // Prepare attachment data with better title
      $clean_title = !empty($title) ? $title : sprintf('Scholar Avatar - %s', $profile_id);
      $attachment = array(
        'post_mime_type' => $file_type['type'],
        'post_title' => sanitize_text_field($clean_title),
        'post_content' => '',
        'post_status' => 'inherit'
      );

      // Insert attachment into media library
      $attach_id = media_handle_sideload($file_array, 0, '', $attachment);

      if (is_wp_error($attach_id)) {
        if ($cleanup_temp && isset($file_array['tmp_name']) && file_exists($file_array['tmp_name'])) {
          unlink($file_array['tmp_name']);
        }
        wp_scholar_log('Failed to create avatar attachment: ' . $attach_id->get_error_message());
        return '';
      }

      // Generate optimized image sizes (WordPress will create thumbnails automatically)
      $file_path = get_attached_file($attach_id);
      if ($file_path) {
        require_once(ABSPATH . 'wp-admin/includes/image.php');
        $attachment_metadata = wp_generate_attachment_metadata($attach_id, $file_path);
        if (false === $attachment_metadata) {
          wp_scholar_log("Failed to generate attachment metadata for attachment ID: $attach_id", 'warning');
        } else {
          wp_update_attachment_metadata($attach_id, $attachment_metadata);
          wp_scholar_log("Generated optimized image thumbnails for attachment ID: $attach_id");
        }
      }

      // Add enhanced meta for better caching and tracking
      update_post_meta($attach_id, '_scholar_profile_id', $profile_id);
      update_post_meta($attach_id, '_scholar_image_url', $image_url);
      update_post_meta($attach_id, '_scholar_image_hash', $url_hash);
      update_post_meta($attach_id, '_scholar_download_time', time());
      update_post_meta($attach_id, '_scholar_image_type', 'avatar');

      // Get the attachment URL
      $attachment_url = wp_get_attachment_url($attach_id);

      if ($attachment_url) {
        // Cache the result
        set_transient('scholar_image_download_' . $url_hash, $attachment_url, self::IMAGE_CACHE_DURATION);
        wp_scholar_log("Successfully downloaded and cached avatar with hash: $url_hash");
        return $attachment_url;
      } else {
        wp_scholar_log("Failed to get attachment URL for avatar ID: $attach_id");
        return '';
      }
    } catch (\Exception $e) {
      wp_scholar_log("Exception during avatar download: " . $e->getMessage());
      if ($cleanup_temp && isset($file_array['tmp_name']) && file_exists($file_array['tmp_name'])) {
        unlink($file_array['tmp_name']);
      }
      return '';
    }
  }

  /**
   * Process base64 encoded images
   */
  private function process_base64_image($image_url, $filename)
  {
    wp_scholar_log("Processing base64 image data");

    // Validate base64 data format
    if (!preg_match('/^data:image\/([a-zA-Z]+);base64,(.+)$/', $image_url, $matches)) {
      wp_scholar_log("Invalid base64 image format");
      return null;
    }

    $image_type = $matches[1];
    $image_data_b64 = $matches[2];

    // Validate image type
    if (!in_array(strtolower($image_type), ['jpeg', 'jpg', 'png', 'gif', 'webp'])) {
      wp_scholar_log("Unsupported base64 image type: $image_type");
      return null;
    }

    $image_data = base64_decode($image_data_b64);
    if ($image_data === false) {
      wp_scholar_log("Failed to decode base64 image data");
      return null;
    }

    // Create temporary file with proper extension
    $temp_file = wp_tempnam();
    if (!$temp_file) {
      wp_scholar_log("Failed to create temporary file for base64 image");
      return null;
    }

    $bytes_written = file_put_contents($temp_file, $image_data);
    if ($bytes_written === false) {
      wp_scholar_log("Failed to write base64 image data to temporary file");
      unlink($temp_file);
      return null;
    }

    wp_scholar_log("Base64 image written to temp file: $bytes_written bytes");

    return array(
      'name' => $filename,
      'tmp_name' => $temp_file
    );
  }

  /**
   * Process external image URLs with better error handling
   */
  private function process_external_image($image_url, $filename)
  {
    wp_scholar_log("Processing external image URL: $image_url");

    // Validate URL format
    if (!filter_var($image_url, FILTER_VALIDATE_URL)) {
      wp_scholar_log("Invalid image URL format");
      return null;
    }

    // Handle Google Scholar specific avatar URLs
    $processed_url = $this->process_google_scholar_avatar_url($image_url);

    // Download with enhanced options
    $temp_file = download_url($processed_url, self::HTTP_TIMEOUT_SECONDS, false);

    if (is_wp_error($temp_file)) {
      $error_message = $temp_file->get_error_message();
      wp_scholar_log("Failed to download external image: $error_message");

      // Try alternative approaches for Google Scholar images
      if (
        strpos($image_url, 'googleusercontent.com') !== false ||
        strpos($image_url, 'scholar.google.com') !== false
      ) {
        wp_scholar_log("Attempting Google Scholar avatar workaround");
        return $this->try_google_scholar_avatar_workaround($image_url, $filename);
      }

      return null;
    }

    wp_scholar_log("External image downloaded to: $temp_file");

    return array(
      'name' => $filename,
      'tmp_name' => $temp_file
    );
  }

  /**
   * Process Google Scholar specific avatar URLs
   */
  private function process_google_scholar_avatar_url($url)
  {
    // Handle different Google Scholar avatar URL formats
    if (strpos($url, 'googleusercontent.com') !== false) {
      // Ensure we request a reasonable size
      if (strpos($url, '=s') !== false) {
        $url = preg_replace('/=s\d+/', '=s120', $url);
      } else {
        $url .= '=s120';
      }
      wp_scholar_log("Processed Google avatar URL: $url");
    }

    return $url;
  }

  /**
   * Workaround for Google Scholar avatar download issues
   */
  private function try_google_scholar_avatar_workaround($original_url, $filename)
  {
    wp_scholar_log("Trying Google Scholar avatar workaround");

    // Try to use WordPress HTTP API with custom headers
    $response = wp_remote_get($original_url, array(
      'timeout' => self::HTTP_TIMEOUT_SECONDS,
      'headers' => array(
        'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36',
        'Accept' => 'image/webp,image/apng,image/*,*/*;q=0.8',
        'Accept-Language' => 'en-US,en;q=0.9',
        'Cache-Control' => 'no-cache',
        'Referer' => 'https://scholar.google.com/'
      )
    ));

    if (is_wp_error($response)) {
      wp_scholar_log("Workaround failed: " . $response->get_error_message());
      return null;
    }

    $image_data = wp_remote_retrieve_body($response);
    $content_type = wp_remote_retrieve_header($response, 'content-type');

    if (empty($image_data)) {
      wp_scholar_log("Workaround got empty response body");
      return null;
    }

    // Verify content type
    if ($content_type && strpos($content_type, 'image/') !== 0) {
      wp_scholar_log("Workaround got non-image content type: $content_type");
      return null;
    }

    // Create temporary file
    $temp_file = wp_tempnam();
    if (!$temp_file) {
      wp_scholar_log("Failed to create temp file for workaround");
      return null;
    }

    $bytes_written = file_put_contents($temp_file, $image_data);
    if ($bytes_written === false) {
      wp_scholar_log("Failed to write workaround image data");
      unlink($temp_file);
      return null;
    }

    wp_scholar_log("Workaround successful: $bytes_written bytes written");

    return array(
      'name' => $filename,
      'tmp_name' => $temp_file
    );
  }

  /**
   * Verify that a cached image still exists and is accessible
   */
  private function verify_image_exists($url)
  {
    if (empty($url)) return false;

    // For local URLs, check if file exists
    if (strpos($url, home_url()) === 0) {
      $upload_dir = wp_upload_dir();
      $file_path = str_replace($upload_dir['baseurl'], $upload_dir['basedir'], $url);
      $exists = file_exists($file_path);
      wp_scholar_log("Checking cached image existence: " . ($exists ? 'EXISTS' : 'MISSING') . " - $file_path");
      return $exists;
    }

    // For external URLs, we'll assume they exist to avoid extra HTTP requests
    return true;
  }

  /**
   * Scrape Google Scholar profile data
   *
   * @param string $profile_id The Google Scholar profile ID
   * @return array|false Profile data array on success, false on failure
   */
  public function scrape(string $profile_id)
  {
    if (empty($profile_id)) {
      wp_scholar_log("Scrape failed: Empty profile ID");
      $this->last_error_details = array(
        'type' => 'invalid_input',
        'message' => 'Empty profile ID provided'
      );
      return false;
    }

    wp_scholar_log("Starting to scrape profile: $profile_id");
    $this->clear_error_details(); // Clear any previous errors

    try {
      // First, get the main profile page to extract basic info
      $main_data = $this->scrape_main_profile($profile_id);
      if (!$main_data) {
        wp_scholar_log("Failed to scrape main profile data for: $profile_id", 'error');
        return false;
      }

      // Then get all publications across multiple pages
      $all_publications = $this->scrape_all_publications($profile_id);
      $main_data['publications'] = $all_publications;

      wp_scholar_log(sprintf("Successfully scraped profile %s with %d publications", $profile_id, count($all_publications)));

      return $main_data;
    } catch (\Exception $e) {
      wp_scholar_log("Exception during scraping: " . $e->getMessage());
      $this->last_error_details = array(
        'type' => 'exception',
        'message' => $e->getMessage()
      );
      return false;
    }
  }

  private function scrape_main_profile($profile_id)
  {
    $url = "https://scholar.google.com/citations?user=" . urlencode($profile_id) . "&hl=en";

    wp_scholar_log("Fetching main profile from: $url");

    $response = wp_remote_get($url, array(
      'timeout' => self::HTTP_TIMEOUT_SECONDS,
      'headers' => array(
        'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36'
      )
    ));

    if (is_wp_error($response)) {
      $error_message = $response->get_error_message();
      wp_scholar_log('Error fetching main profile - ' . $error_message, 'error');

      // Store detailed error information
      $this->last_error_details = array(
        'type' => 'network_error',
        'message' => $error_message,
        'url' => $url
      );

      return false;
    }

    $status_code = wp_remote_retrieve_response_code($response);
    wp_scholar_log("Main profile returned HTTP $status_code");

    // Enhanced error handling for different HTTP status codes
    if ($status_code !== 200) {
      $this->handle_http_error($status_code, $url, $profile_id);
      return false;
    }

    $html = wp_remote_retrieve_body($response);

    if (empty($html)) {
      wp_scholar_log('Empty response from main profile');
      $this->last_error_details = array(
        'type' => 'empty_response',
        'message' => 'Received empty response from Google Scholar'
      );
      return false;
    }

    // Enhanced error detection with better specificity
    if (!$this->is_valid_scholar_profile($html)) {
      return false;
    }

    return $this->parse_main_profile_html($html, $profile_id);
  }

  /**
   * Handle different HTTP error codes with specific error details
   */
  private function handle_http_error($status_code, $url, $profile_id)
  {
    switch ($status_code) {
      case 403:
        wp_scholar_log("HTTP 403 Forbidden - Likely IP blocked or rate limited");
        $this->last_error_details = array(
          'type' => 'blocked_access',
          'status_code' => $status_code,
          'message' => 'Access forbidden by Google Scholar',
          'user_message' => 'Google Scholar is currently blocking requests from your server. This usually happens due to rate limiting or IP restrictions.',
          'suggestions' => array(
            'Your hosting provider IP may be temporarily blocked by Google Scholar',
            'Try again in a few hours - blocks are often temporary',
            'Contact your hosting provider about IP reputation',
            'Consider using a different hosting provider if this persists'
          )
        );
        break;

      case 404:
        wp_scholar_log("HTTP 404 Not Found - Invalid profile ID or profile doesn't exist");
        $this->last_error_details = array(
          'type' => 'profile_not_found',
          'status_code' => $status_code,
          'message' => 'Profile not found',
          'user_message' => 'The Google Scholar profile could not be found.',
          'suggestions' => array(
            'Double-check your Profile ID - it should be the part after "user=" in your Scholar URL',
            'Make sure your Google Scholar profile is set to public',
            'Verify the profile exists by visiting it directly in your browser'
          )
        );
        break;

      case 429:
        wp_scholar_log("HTTP 429 Too Many Requests - Rate limited");
        $this->last_error_details = array(
          'type' => 'rate_limited',
          'status_code' => $status_code,
          'message' => 'Rate limited by Google Scholar',
          'user_message' => 'Google Scholar is rate limiting requests from your server.',
          'suggestions' => array(
            'Wait at least 1 hour before trying again',
            'Reduce the maximum number of publications in settings',
            'Increase the update frequency to weekly or monthly',
            'This is usually temporary - try again later'
          )
        );
        break;

      case 503:
        wp_scholar_log("HTTP 503 Service Unavailable - Google Scholar may be down");
        $this->last_error_details = array(
          'type' => 'service_unavailable',
          'status_code' => $status_code,
          'message' => 'Google Scholar service unavailable',
          'user_message' => 'Google Scholar is currently unavailable.',
          'suggestions' => array(
            'This is usually temporary - try again in a few minutes',
            'Check if Google Scholar is accessible in your browser',
            'Google Scholar may be experiencing maintenance or outages'
          )
        );
        break;

      case 502:
      case 504:
        wp_scholar_log("HTTP $status_code Gateway Error - Connection issues");
        $this->last_error_details = array(
          'type' => 'gateway_error',
          'status_code' => $status_code,
          'message' => 'Gateway error accessing Google Scholar',
          'user_message' => 'There was a connection problem reaching Google Scholar.',
          'suggestions' => array(
            'This is usually temporary - try again in a few minutes',
            'Your hosting provider may be experiencing network issues',
            'Check if other websites are working properly from your server'
          )
        );
        break;

      default:
        wp_scholar_log("HTTP $status_code Unexpected Error");
        $this->last_error_details = array(
          'type' => 'http_error',
          'status_code' => $status_code,
          'message' => "Unexpected HTTP error: $status_code",
          'user_message' => "Google Scholar returned an unexpected error (HTTP $status_code).",
          'suggestions' => array(
            'Try again in a few minutes',
            'Check if your Profile ID is correct',
            'Contact support if this problem persists'
          )
        );
        break;
    }
  }

  private function scrape_all_publications($profile_id)
  {
    $all_publications = array();
    $current_start = 0;
    $page_number = 1;

    while (count($all_publications) < $this->max_publications) {
      wp_scholar_log("Fetching publications page $page_number (start: $current_start)");

      $publications = $this->scrape_publications_page($profile_id, $current_start);

      if (empty($publications)) {
        wp_scholar_log("No more publications found on page $page_number");
        break;
      }

      $all_publications = array_merge($all_publications, $publications);

      // If we got fewer publications than the page size, we've reached the end
      if (count($publications) < $this->page_size) {
        wp_scholar_log("Reached end of publications (got " . count($publications) . " on page $page_number)");
        break;
      }

      $current_start += $this->page_size;
      $page_number++;

      // Safety check to prevent infinite loops
      if ($page_number > self::MAX_SCRAPING_PAGES) {
        wp_scholar_log(sprintf("Reached maximum page limit (%d pages)", self::MAX_SCRAPING_PAGES));
        break;
      }

      // Add delay between requests to be respectful to Google Scholar
      sleep($this->request_delay);
    }

    wp_scholar_log("Total publications collected: " . count($all_publications));
    return $all_publications;
  }

  private function scrape_publications_page($profile_id, $start = 0)
  {
    $url = "https://scholar.google.com/citations?user=" . urlencode($profile_id) . "&hl=en&cstart=" . $start . "&pagesize=" . $this->page_size;

    $response = wp_remote_get($url, array(
      'timeout' => self::HTTP_TIMEOUT_SECONDS,
      'headers' => array(
        'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/137.0.0.0 Safari/537.36'
      )
    ));

    if (is_wp_error($response)) {
      wp_scholar_log('Error fetching publications page - ' . $response->get_error_message(), 'error');
      return array();
    }

    $status_code = wp_remote_retrieve_response_code($response);
    if ($status_code !== 200) {
      wp_scholar_log("Publications page returned HTTP $status_code");
      return array();
    }

    $html = wp_remote_retrieve_body($response);

    if (empty($html)) {
      wp_scholar_log('Empty response from publications page');
      return array();
    }

    return $this->extract_publications_from_html($html);
  }

  /**
   * More sophisticated method to detect if we got a valid Scholar profile
   */
  private function is_valid_scholar_profile($html)
  {
    // Log response length for debugging
    wp_scholar_log("Received HTML response length: " . strlen($html) . " bytes");

    // Check for specific Scholar profile indicators
    $required_elements = [
      'gsc_prf', // Profile container
      'citations', // Citations table
      'gsc_a_tr' // Publication rows (even if empty)
    ];

    $found_elements = [];
    foreach ($required_elements as $element) {
      if (strpos($html, $element) !== false) {
        $found_elements[] = $element;
      }
    }

    wp_scholar_log("Found Scholar elements: " . implode(', ', $found_elements) . " out of " . count($required_elements));

    // Must have at least the profile container
    if (!in_array('gsc_prf', $found_elements)) {
      wp_scholar_log("Missing profile container - not a valid Scholar profile");

      // Check for specific error patterns more carefully
      if ($this->detect_scholar_errors($html)) {
        return false;
      }

      // If no clear error but no profile container, likely an issue
      wp_scholar_log("No profile container found but no clear error detected");
      $this->last_error_details = array(
        'type' => 'invalid_page_structure',
        'message' => 'Page does not appear to be a valid Google Scholar profile',
        'user_message' => 'The page returned by Google Scholar does not contain expected profile data.',
        'suggestions' => array(
          'Verify your Profile ID is correct',
          'Make sure your Google Scholar profile is public',
          'Try accessing your profile directly in a browser to confirm it works'
        )
      );
      return false;
    }

    return true;
  }

  /**
   * Detect specific Scholar error patterns without false positives
   */
  private function detect_scholar_errors($html)
  {
    // More specific error patterns
    $error_patterns = [
      'This profile is not available' => array(
        'type' => 'profile_unavailable',
        'user_message' => 'This Google Scholar profile is not publicly available.',
        'suggestions' => array(
          'Make sure your Google Scholar profile is set to public',
          'Check your Profile ID is correct',
          'The profile owner may have restricted access'
        )
      ),
      'User profiles are not publicly viewable' => array(
        'type' => 'profile_private',
        'user_message' => 'This Google Scholar profile is set to private.',
        'suggestions' => array(
          'The profile owner needs to make their profile public',
          'Verify you have the correct Profile ID',
          'Contact the profile owner to make their profile public'
        )
      ),
      'The profile you are looking for could not be found' => array(
        'type' => 'profile_not_found',
        'user_message' => 'The Google Scholar profile could not be found.',
        'suggestions' => array(
          'Double-check your Profile ID',
          'Make sure the profile still exists',
          'Verify the profile is publicly accessible'
        )
      ),
      'Citations to this profile are not available' => array(
        'type' => 'citations_unavailable',
        'user_message' => 'Citations for this profile are not available.',
        'suggestions' => array(
          'The profile may be too new or have privacy restrictions',
          'Try again later',
          'Contact the profile owner about citation visibility'
        )
      )
    ];

    foreach ($error_patterns as $pattern => $error_info) {
      if (stripos($html, $pattern) !== false) {
        wp_scholar_log("Detected specific error pattern: $pattern");

        // Log a snippet around the error for context
        $pos = stripos($html, $pattern);
        $start = max(0, $pos - 100);
        $snippet = substr($html, $start, 200);
        wp_scholar_log("Error context: " . htmlspecialchars($snippet));

        $this->last_error_details = array_merge(array(
          'message' => $pattern
        ), $error_info);

        return true;
      }
    }

    // Check for redirect to login or blocked page
    if (strpos($html, 'accounts.google.com') !== false && strpos($html, 'signin') !== false) {
      wp_scholar_log("Detected redirect to Google login - profile may be private or blocked");
      $this->last_error_details = array(
        'type' => 'login_required',
        'message' => 'Redirected to Google login page',
        'user_message' => 'Google Scholar is requiring login to access this profile.',
        'suggestions' => array(
          'The profile may be set to private or restricted',
          'Your server IP may be flagged for suspicious activity',
          'Try again later as this may be temporary'
        )
      );
      return true;
    }

    return false;
  }

  /**
   * Import profile data from HTML captured in the admin's own browser
   * (browser-assisted update mode), instead of fetching it server-side.
   *
   * @param string $html HTML of the user's own Scholar profile page
   * @param string $profile_id The Google Scholar profile ID
   * @param bool $skip_avatar_download Skip downloading avatar/coauthor images
   * @return array|false Profile data array on success, false on failure
   */
  public function import_main_profile_html(string $html, string $profile_id, bool $skip_avatar_download = false)
  {
    if (empty($html)) {
      $this->last_error_details = array(
        'type' => 'empty_input',
        'message' => 'No HTML content provided'
      );
      return false;
    }

    if (!$this->is_valid_scholar_profile($html)) {
      return false;
    }

    // Coauthor avatars are never fetched for browser-assisted imports -
    // only the main profile avatar is ever worth the extra request(s).
    // The pasted main page contains the first publications page as well, so
    // extract it from the same DOMDocument instead of parsing the HTML twice.
    $data = $this->parse_main_profile_html($html, $profile_id, $skip_avatar_download, true, true);

    if ($data === false && $this->last_error_details === null) {
      $this->last_error_details = array(
        'type' => 'missing_profile_name',
        'message' => 'Could not find a profile name in the pasted content.'
      );
    }

    return $data;
  }

  /**
   * Import a page of publications from HTML captured in the admin's own
   * browser (e.g. a Scholar `&cstart=N` pagination page).
   *
   * @param string $html HTML fragment/page containing publication rows
   * @return array Publications found in the HTML (empty array if none)
   */
  public function import_publications_fragment_html(string $html): array
  {
    if (empty($html)) {
      return array();
    }

    return $this->extract_publications_from_html($html);
  }

  /**
   * Import profile data produced by the browser-assisted bookmarklet,
   * which extracts data directly from the DOM and ships it as JSON
   * (no image URLs — avatars are not captured client-side).
   *
   * @param string $json JSON string produced by the bookmarklet
   * @param string $profile_id The Google Scholar profile ID (used to cache the avatar, if any)
   * @return array|false Profile data array on success, false on failure
   */
  public function import_from_bookmarklet_json(string $json, string $profile_id = '')
  {
    $decoded = json_decode($json, true);

    if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
      $this->last_error_details = array(
        'type' => 'invalid_json',
        'message' => 'Could not parse pasted content as JSON: ' . json_last_error_msg()
      );
      return false;
    }

    if (empty($decoded['name']) || !isset($decoded['publications']) || !is_array($decoded['publications'])) {
      $this->last_error_details = array(
        'type' => 'invalid_bookmarklet_data',
        'message' => 'Pasted JSON is missing required "name" or "publications" fields'
      );
      return false;
    }

    if ($profile_id !== '' && (!isset($decoded['profile_id']) || $decoded['profile_id'] !== $profile_id)) {
      $this->last_error_details = array(
        'type' => 'wrong_import_profile',
        'message' => 'Bookmarklet data belongs to a different Google Scholar profile. Recreate the bookmarklet from this site’s settings page and try again.'
      );
      return false;
    }

    foreach ($decoded['publications'] as $index => $publication) {
      if (!is_array($publication) ||
        empty($publication['title']) ||
        empty($publication['google_scholar_url']) ||
        !array_key_exists('year', $publication) ||
        !array_key_exists('citations', $publication) ||
        !array_key_exists('citations_url', $publication)) {
        $this->last_error_details = array(
          'type' => 'invalid_bookmarklet_publication',
          'message' => sprintf('Publication %d is missing one or more required fields.', $index + 1)
        );
        return false;
      }

      if (!is_string($publication['title']) ||
        !is_string($publication['google_scholar_url']) ||
        !is_scalar($publication['year']) ||
        !is_numeric($publication['citations']) ||
        !is_string($publication['citations_url'])) {
        $this->last_error_details = array(
          'type' => 'invalid_bookmarklet_publication',
          'message' => sprintf('Publication %d has invalid field values.', $index + 1)
        );
        return false;
      }
    }

    $citations = is_array($decoded['citations'] ?? null) ? $decoded['citations'] : array();

    // The bookmarklet only ever ships the avatar's URL (not its bytes -
    // fetching image bytes cross-origin from the browser risks CORS). The
    // actual download happens here, same as for HTML-pasted imports.
    $avatar = '';
    if (!empty($decoded['avatar_url']) && is_string($decoded['avatar_url'])) {
      $avatar = $this->download_to_media_library(
        $decoded['avatar_url'],
        $profile_id !== '' ? $profile_id : (string) $decoded['name'],
        sprintf('Scholar Profile Avatar - %s', $decoded['name'])
      );
    }

    return array(
      'avatar' => $avatar,
      'name' => (string) $decoded['name'],
      'affiliation' => isset($decoded['affiliation']) ? (string) $decoded['affiliation'] : '',
      'interests' => is_array($decoded['interests'] ?? null) ? $decoded['interests'] : array(),
      'publications' => $decoded['publications'],
      'citations' => array(
        'total' => intval($citations['total'] ?? 0),
        'h_index' => intval($citations['h_index'] ?? 0),
        'i10_index' => intval($citations['i10_index'] ?? 0),
        'since_2019' => intval($citations['since_2019'] ?? 0),
        'h_index_2019' => intval($citations['h_index_2019'] ?? 0),
        'i10_index_2019' => intval($citations['i10_index_2019'] ?? 0)
      ),
      'coauthors' => is_array($decoded['coauthors'] ?? null) ? $decoded['coauthors'] : array()
    );
  }

  private function parse_main_profile_html($html, $profile_id, $skip_avatar_download = false, $skip_coauthor_avatars = false, $include_publications = false)
  {
    $doc = new \DOMDocument();
    libxml_use_internal_errors(true);
    $doc->loadHTML($html);
    libxml_clear_errors();
    $xpath = new \DOMXPath($doc);

    $data = array(
      'avatar' => '',
      'name' => '',
      'affiliation' => '',
      'interests' => array(),
      'publications' => array(), // Will be populated separately
      'citations' => array(
        'total' => 0,
        'h_index' => 0,
        'i10_index' => 0,
        'since_2019' => 0,
        'h_index_2019' => 0,
        'i10_index_2019' => 0
      ),
      'coauthors' => array()
    );

    $this->extract_profile_info($xpath, $data, $profile_id, $skip_avatar_download);
    $this->extract_citations($xpath, $data);
    $this->extract_coauthors($xpath, $data, $profile_id, $skip_coauthor_avatars);
    if ($include_publications) {
      $data['publications'] = $this->extract_publications_from_xpath($xpath);
    }

    // Validate that we extracted meaningful data
    if (empty($data['name'])) {
      wp_scholar_log("Failed to extract profile name - page may have changed structure", 'warning');

      // Log more info about the page structure for debugging
      $name_elements = $xpath->query("//div[@id='gsc_prf_in']");
      wp_scholar_log("Found " . $name_elements->length . " name elements");

      if ($name_elements->length > 0) {
        wp_scholar_log("First name element content: " . trim($name_elements->item(0)->textContent));
      }

      return false;
    }

    wp_scholar_log("Successfully parsed profile for: " . $data['name']);
    return $data;
  }

  private function extract_publications_from_html($html)
  {
    $doc = new \DOMDocument();
    libxml_use_internal_errors(true);
    $doc->loadHTML($html);
    libxml_clear_errors();
    $xpath = new \DOMXPath($doc);

    return $this->extract_publications_from_xpath($xpath);
  }

  /**
   * Extract publication rows from an already parsed Scholar page.
   *
   * @param \DOMXPath $xpath Page XPath instance
   * @return array
   */
  private function extract_publications_from_xpath(\DOMXPath $xpath): array
  {
    $publications = array();
    $publication_rows = $xpath->query("//tr[@class='gsc_a_tr']");

    foreach ($publication_rows as $pub) {
      $title_node = $xpath->query(".//a[@class='gsc_a_at']", $pub)->item(0);
      $authors_node = $xpath->query(".//div[@class='gs_gray'][1]", $pub)->item(0);
      $venue_node = $xpath->query(".//div[@class='gs_gray'][2]", $pub)->item(0);
      $year_node = $xpath->query(".//span[@class='gsc_a_h gsc_a_hc gs_ibl']", $pub)->item(0);
      $citations_node = $xpath->query(".//a[@class='gsc_a_ac gs_ibl']", $pub)->item(0);

      if ($title_node) {
        $publication = array(
          'title' => trim($title_node->textContent),
          'link' => 'https://scholar.google.com' . $title_node->getAttribute('href'),
          'google_scholar_url' => 'https://scholar.google.com' . $title_node->getAttribute('href'),
          'authors' => $authors_node ? trim($authors_node->textContent) : '',
          'venue' => $venue_node ? trim($venue_node->textContent) : '',
          'year' => $year_node ? trim($year_node->textContent) : '',
          'citations' => $citations_node ? intval($citations_node->textContent) : 0,
          'citations_url' => $citations_node ? 'https://scholar.google.com' . $citations_node->getAttribute('href') : '',
          'citations_by_year_url' => $citations_node ? 'https://scholar.google.com' . $citations_node->getAttribute('href') . '&view_op=view_citation_years' : ''
        );

        $publications[] = $publication;
      }
    }

    return $publications;
  }

  protected function extract_profile_info($xpath, &$data, $profile_id, $skip_avatar_download = false)
  {
    // Get the name first as we need it for the avatar title
    $name_node = $xpath->query("//div[@id='gsc_prf_in']")->item(0);
    if ($name_node) {
      $data['name'] = trim($name_node->textContent);
      wp_scholar_log("Extracted name: " . $data['name']);
    } else {
      wp_scholar_log("Warning: Could not extract profile name");
    }

    if ($skip_avatar_download) {
      wp_scholar_log("Avatar download skipped (browser import mode)");
    } else {
      // Now get the avatar with enhanced error handling
      $avatar_node = $xpath->query("//img[@id='gsc_prf_pup-img']")->item(0);
      if ($avatar_node) {
        $avatar_url = $avatar_node->getAttribute('src');
        wp_scholar_log("Found avatar URL: $avatar_url");

        if (!empty($avatar_url)) {
          $data['avatar'] = $this->download_to_media_library(
            $avatar_url,
            $profile_id,
            sprintf('Scholar Profile Avatar - %s', $data['name'] ?: $profile_id)
          );

          if ($data['avatar']) {
            wp_scholar_log("Avatar successfully downloaded: " . $data['avatar']);
          } else {
            wp_scholar_log("Avatar download failed for URL: $avatar_url");
          }
        }
      } else {
        wp_scholar_log("No avatar found in profile");
      }
    }

    $affiliation_node = $xpath->query("//div[@class='gsc_prf_il']")->item(0);
    if ($affiliation_node) {
      $data['affiliation'] = trim($affiliation_node->textContent);
      wp_scholar_log("Extracted affiliation: " . $data['affiliation']);
    }

    $interests_nodes = $xpath->query("//div[@id='gsc_prf_int']//a");
    foreach ($interests_nodes as $interest) {
      $data['interests'][] = array(
        'text' => trim($interest->textContent),
        'url' => 'https://scholar.google.com' . $interest->getAttribute('href')
      );
    }
    wp_scholar_log("Extracted " . count($data['interests']) . " research interests");
  }

  protected function extract_citations($xpath, &$data)
  {
    // Get all citation table cells
    $table_cells = $xpath->query("//table[@id='gsc_rsb_st']//td[@class='gsc_rsb_std']");

    if ($table_cells->length >= 6) {
      // All time citations
      $data['citations']['total'] = intval(trim($table_cells->item(0)->textContent));
      $data['citations']['h_index'] = intval(trim($table_cells->item(2)->textContent));
      $data['citations']['i10_index'] = intval(trim($table_cells->item(4)->textContent));

      // Since 2019 citations
      $data['citations']['since_2019'] = intval(trim($table_cells->item(1)->textContent));
      $data['citations']['h_index_2019'] = intval(trim($table_cells->item(3)->textContent));
      $data['citations']['i10_index_2019'] = intval(trim($table_cells->item(5)->textContent));

      wp_scholar_log("Extracted citations - Total: " . $data['citations']['total'] . ", h-index: " . $data['citations']['h_index']);
    } else {
      wp_scholar_log("Warning: Could not extract citation metrics");
    }
  }

  protected function extract_coauthors($xpath, &$data, $profile_id, $skip_avatar_download = false)
  {
    $coauthors = $xpath->query("//div[contains(@class, 'gsc_rsb_aa')]");

    foreach ($coauthors as $coauthor) {
      $link = $xpath->query(".//a", $coauthor)->item(0);
      $affiliation = $xpath->query(".//div[contains(@class, 'gsc_rsb_a_ext')]", $coauthor)->item(0);
      $img = $xpath->query(".//img", $coauthor)->item(0);

      if ($link) {
        $name = trim($link->textContent);
        $avatar_url = $img ? $img->getAttribute('src') : '';
        $local_avatar = '';

        // Use the coauthor's own Scholar ID as the cache key, falling back to a
        // hash of their name so coauthor images don't share the main profile's
        // _scholar_profile_id and aren't incorrectly deleted during cleanup.
        $href = $link->getAttribute('href');
        preg_match('/user=([^&]+)/', $href, $user_matches);
        $coauthor_cache_id = !empty($user_matches[1]) ? $user_matches[1] : 'coauthor_' . md5($name);

        if ($avatar_url && !$skip_avatar_download) {
          wp_scholar_log("Processing coauthor avatar for: $name");
          $local_avatar = $this->download_to_media_library(
            $avatar_url,
            $coauthor_cache_id,
            sprintf('Scholar Coauthor Avatar - %s', $name)
          );

          if ($local_avatar) {
            wp_scholar_log("Coauthor avatar successfully downloaded: $local_avatar");
          } else {
            wp_scholar_log("Coauthor avatar download failed for: $name");
          }
        }

        $coauthor_data = array(
          'name' => $name,
          'profile_url' => 'https://scholar.google.com' . $link->getAttribute('href'),
          'title' => $affiliation ? trim($affiliation->textContent) : '',
          'avatar' => $local_avatar
        );

        $data['coauthors'][] = $coauthor_data;
      }
    }

    wp_scholar_log("Extracted " . count($data['coauthors']) . " coauthors");
  }

  /**
   * Get current scraper configuration
   *
   * @return array Configuration array with max_publications, page_size, and request_delay
   */
  public function get_config(): array
  {
    return array(
      'max_publications' => $this->max_publications,
      'page_size' => $this->page_size,
      'request_delay' => $this->request_delay
    );
  }

  /**
   * Set scraper configuration
   *
   * @param array $config Configuration array with optional keys: max_publications, page_size, request_delay
   * @return void
   */
  public function set_config(array $config): void
  {
    if (isset($config['max_publications'])) {
      $this->max_publications = max(20, min(500, intval($config['max_publications'])));
      wp_scholar_log("Max publications set to: " . $this->max_publications);
    }
    if (isset($config['page_size'])) {
      $this->page_size = max(10, min(100, intval($config['page_size'])));
    }
    if (isset($config['request_delay'])) {
      $this->request_delay = max(0.5, min(5, floatval($config['request_delay'])));
    }
  }

  /**
   * Validate scraped data to ensure it's complete and reasonable
   */
  public static function validate_scraped_data($data)
  {
    if (!is_array($data)) {
      wp_scholar_log("Data validation failed: not an array");
      return false;
    }

    // A name must be present, while publications must be present as an array.
    // An empty array is legitimate for a newly created Scholar profile.
    if (empty($data['name'])) {
      wp_scholar_log("Data validation failed: missing required field 'name'");
      return false;
    }

    if (!array_key_exists('publications', $data)) {
      wp_scholar_log("Data validation failed: missing required field 'publications'");
      return false;
    }

    // Check if publications array is reasonable
    if (!is_array($data['publications'])) {
      wp_scholar_log("Data validation failed: publications is not an array");
      return false;
    }

    // Basic sanity check - if someone has 0 publications, that might be suspicious
    // unless it's a new profile
    if (count($data['publications']) === 0) {
      $existing_data = get_option('scholar_profile_data');
      if ($existing_data && isset($existing_data['publications']) && count($existing_data['publications']) > 0) {
        wp_scholar_log("Data validation warning: new data has 0 publications but existing data has publications");
        return false;
      }
    }

    wp_scholar_log("Data validation passed: " . count($data['publications']) . " publications found");
    return true;
  }

  /**
   * Clean up old cached images for a profile (optional method for maintenance)
   *
   * @param string $profile_id The Google Scholar profile ID
   * @param int $days_old Number of days old for cleanup threshold (default: 30)
   * @return int Number of images successfully deleted (attachments for which deletion fails are not counted)
   */
  public function cleanup_old_images(string $profile_id, int $days_old = 30): int
  {
    $old_attachments = get_posts(array(
      'post_type' => 'attachment',
      'meta_query' => array(
        array(
          'key' => '_scholar_profile_id',
          'value' => $profile_id
        ),
        array(
          'key' => '_scholar_download_time',
          'value' => time() - ($days_old * DAY_IN_SECONDS),
          'compare' => '<'
        )
      ),
      'posts_per_page' => -1
    ));

    $deleted_count = 0;
    foreach ($old_attachments as $attachment) {
      if (wp_delete_attachment($attachment->ID, true)) {
        $deleted_count++;
      }
    }

    if ($deleted_count > 0) {
      wp_scholar_log("Cleaned up $deleted_count old images for profile: $profile_id");
    }

    return $deleted_count;
  }
}
