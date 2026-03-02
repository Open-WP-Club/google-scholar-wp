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
