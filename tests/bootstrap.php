<?php

/**
 * PHPUnit Bootstrap for Google Scholar Profile Display Plugin
 */

// Load Composer autoloader
$autoloader = dirname(__DIR__) . '/vendor/autoload.php';
if (!file_exists($autoloader)) {
    echo 'Please run `composer install` before running tests.' . PHP_EOL;
    exit(1);
}
require_once $autoloader;

// Define WordPress constants if not already defined
if (!defined('ABSPATH')) {
    define('ABSPATH', '/tmp/wordpress/');
}

// WordPress time constants
if (!defined('MINUTE_IN_SECONDS')) {
    define('MINUTE_IN_SECONDS', 60);
}
if (!defined('HOUR_IN_SECONDS')) {
    define('HOUR_IN_SECONDS', 3600);
}
if (!defined('DAY_IN_SECONDS')) {
    define('DAY_IN_SECONDS', 86400);
}
if (!defined('WEEK_IN_SECONDS')) {
    define('WEEK_IN_SECONDS', 604800);
}
if (!defined('MONTH_IN_SECONDS')) {
    define('MONTH_IN_SECONDS', 2592000);
}
if (!defined('YEAR_IN_SECONDS')) {
    define('YEAR_IN_SECONDS', 31536000);
}

// WordPress debug constants
if (!defined('WP_DEBUG')) {
    define('WP_DEBUG', true);
}
if (!defined('WP_DEBUG_LOG')) {
    define('WP_DEBUG_LOG', true);
}

// Plugin constants
if (!defined('WP_SCHOLAR_VERSION')) {
    define('WP_SCHOLAR_VERSION', '1.4.0');
}
if (!defined('WP_SCHOLAR_PLUGIN_DIR')) {
    define('WP_SCHOLAR_PLUGIN_DIR', dirname(__DIR__) . '/');
}
if (!defined('WP_SCHOLAR_PLUGIN_URL')) {
    define('WP_SCHOLAR_PLUGIN_URL', 'https://example.com/wp-content/plugins/google-scholar-wp/');
}
if (!defined('WP_SCHOLAR_MAX_CONSECUTIVE_FAILURES')) {
    define('WP_SCHOLAR_MAX_CONSECUTIVE_FAILURES', 5);
}

/**
 * Global logging function stub (no-op for tests)
 */
if (!function_exists('wp_scholar_log')) {
    function wp_scholar_log($message, $level = 'info')
    {
        // No-op during tests
    }
}

/**
 * Minimal WP_Error stub for tests
 */
if (!class_exists('WP_Error')) {
    class WP_Error
    {
        protected $code;
        protected $message;
        protected $data;

        public function __construct($code = '', $message = '', $data = '')
        {
            $this->code = $code;
            $this->message = $message;
            $this->data = $data;
        }

        public function get_error_code()
        {
            return $this->code;
        }

        public function get_error_message()
        {
            return $this->message;
        }

        public function get_error_data()
        {
            return $this->data;
        }

        public function get_error_messages()
        {
            return array($this->message);
        }
    }
}

/**
 * is_wp_error function stub
 */
if (!function_exists('is_wp_error')) {
    function is_wp_error($thing)
    {
        return $thing instanceof WP_Error;
    }
}

/**
 * Minimal WP_REST_Request stub for tests - a read-only param bag standing
 * in for WordPress core's REST request object.
 */
if (!class_exists('WP_REST_Request')) {
    class WP_REST_Request
    {
        private $params;

        public function __construct(array $params = array())
        {
            $this->params = $params;
        }

        public function get_param($key)
        {
            return $this->params[$key] ?? null;
        }
    }
}

/**
 * Minimal WP_Application_Passwords stub for tests - an in-memory store
 * standing in for WordPress core's Application Passwords API (5.6+).
 * Tests reset WP_Application_Passwords::$store in setUp().
 */
if (!class_exists('WP_Application_Passwords')) {
    class WP_Application_Passwords
    {
        public static $store = array();
        private static $next_id = 1;

        public static function create_new_application_password($user_id, $args = array())
        {
            $uuid = 'test-uuid-' . self::$next_id++;
            $item = array(
                'uuid' => $uuid,
                'name' => $args['name'] ?? '',
                'created' => time(),
            );
            self::$store[$user_id][$uuid] = $item;
            return array('plaintext-password-' . $uuid, $item);
        }

        public static function get_user_application_passwords($user_id)
        {
            return array_values(self::$store[$user_id] ?? array());
        }

        public static function delete_application_password($user_id, $uuid)
        {
            if (!isset(self::$store[$user_id][$uuid])) {
                return false;
            }
            unset(self::$store[$user_id][$uuid]);
            return true;
        }
    }
}
