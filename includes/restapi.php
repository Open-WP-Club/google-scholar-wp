<?php

namespace WPScholar;

if (!defined('ABSPATH')) {
  exit; // Exit if accessed directly
}

/**
 * REST API endpoint for the automated browser-assisted sync flow: a script
 * running on the admin's own machine (via cron/launchd, generated and
 * downloaded from the Browser mode settings panel) POSTs raw Scholar HTML
 * here instead of the admin pasting it into wp-admin by hand. Delegates
 * all parsing/validation/persistence to Settings::process_import() - the
 * same code path the manual browser-paste form uses.
 */
class RestApi
{
  private const NAMESPACE = 'wp-google-scholar/v1';

  /** Error type => HTTP status for process_import() failures not covered by the default. */
  private const ERROR_STATUS_MAP = array(
    'browser_mode_required' => 403,
    'import_rate_limited' => 429,
  );

  private $settings;

  public function __construct(Settings $settings)
  {
    $this->settings = $settings;
    add_action('rest_api_init', array($this, 'register_routes'));
  }

  public function register_routes(): void
  {
    register_rest_route(self::NAMESPACE, '/import', array(
      'methods' => 'POST',
      'callback' => array($this, 'handle_import'),
      'permission_callback' => array($this, 'check_permission'),
      'args' => array(
        'content' => array('required' => true, 'type' => 'string'),
        'import_mode' => array('required' => true, 'type' => 'string', 'enum' => array('replace', 'append')),
      ),
    ));
  }

  /**
   * @return bool|\WP_Error
   */
  public function check_permission()
  {
    if (!is_ssl()) {
      return new \WP_Error('scholar_https_required', 'This endpoint requires HTTPS.', array('status' => 403));
    }

    if (!current_user_can('manage_options')) {
      return new \WP_Error('scholar_forbidden', 'You do not have permission to perform this action.', array('status' => 403));
    }

    return true;
  }

  /**
   * @return array|\WP_Error
   */
  public function handle_import(\WP_REST_Request $request)
  {
    $content = (string) $request->get_param('content');
    $import_mode = $request->get_param('import_mode') === 'append' ? 'append' : 'replace';

    $result = $this->settings->process_import($content, $import_mode, 'sync');

    if (isset($result['error'])) {
      $type = $result['error']['type'] ?? 'unknown';
      $status = self::ERROR_STATUS_MAP[$type] ?? 422;

      return new \WP_Error(
        'scholar_import_' . $type,
        $result['error']['message'] ?? 'Import failed.',
        array('status' => $status)
      );
    }

    return array(
      'success' => true,
      'publications' => count($result['data']['publications'] ?? array()),
    );
  }
}
