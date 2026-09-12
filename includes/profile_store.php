<?php

namespace WPScholar;

if (!defined('ABSPATH')) {
  exit;
}

/**
 * Stores additional Scholar profiles without changing the legacy default
 * profile options used by existing installations.
 */
class ProfileStore
{
  private const OPTION_NAME = 'scholar_profile_profiles';

  public static function normalize_id($profile_id): string
  {
    return sanitize_text_field(trim((string) $profile_id));
  }

  public static function is_valid_id($profile_id): bool
  {
    $profile_id = self::normalize_id($profile_id);
    return strlen($profile_id) >= 8
      && strlen($profile_id) <= 20
      && preg_match('/^[a-zA-Z0-9_-]+$/', $profile_id) === 1;
  }

  public static function get_registry(): array
  {
    $profiles = get_option(self::OPTION_NAME, array());
    return is_array($profiles) ? $profiles : array();
  }

  public static function get_ids($default_id = ''): array
  {
    $ids = array();
    $default_id = self::normalize_id($default_id);
    if ($default_id !== '' && self::is_valid_id($default_id)) {
      $ids[] = $default_id;
    }

    foreach (array_keys(self::get_registry()) as $profile_id) {
      if (self::is_valid_id($profile_id) && !in_array($profile_id, $ids, true)) {
        $ids[] = $profile_id;
      }
    }

    return $ids;
  }

  public static function set_registered_ids(array $profile_ids, $default_id = ''): array
  {
    $default_id = self::normalize_id($default_id);
    $ids = array();
    foreach ($profile_ids as $profile_id) {
      $profile_id = self::normalize_id($profile_id);
      if (!self::is_valid_id($profile_id) || $profile_id === $default_id || in_array($profile_id, $ids, true)) {
        continue;
      }
      $ids[] = $profile_id;
    }

    $existing = self::get_registry();
    $registry = array();
    foreach ($ids as $profile_id) {
      $registry[$profile_id] = is_array($existing[$profile_id] ?? null)
        ? $existing[$profile_id]
        : array();
    }
    update_option(self::OPTION_NAME, $registry);
    return $ids;
  }

  public static function is_registered($profile_id, $default_id = ''): bool
  {
    $profile_id = self::normalize_id($profile_id);
    return $profile_id !== '' && in_array($profile_id, self::get_ids($default_id), true);
  }

  public static function get_data($profile_id, $default_id = '')
  {
    $profile_id = self::normalize_id($profile_id);
    if ($profile_id === '' || $profile_id === self::normalize_id($default_id)) {
      return get_option('scholar_profile_data');
    }

    $record = self::get_registry()[$profile_id] ?? array();
    return $record['data'] ?? null;
  }

  public static function set_data($profile_id, array $data, $default_id = ''): void
  {
    $profile_id = self::normalize_id($profile_id);
    if ($profile_id === '' || $profile_id === self::normalize_id($default_id)) {
      update_option('scholar_profile_data', $data);
      return;
    }

    $registry = self::get_registry();
    $registry[$profile_id]['data'] = $data;
    update_option(self::OPTION_NAME, $registry);
  }

  public static function get_status($profile_id, $default_id = ''): array
  {
    $profile_id = self::normalize_id($profile_id);
    if ($profile_id === '' || $profile_id === self::normalize_id($default_id)) {
      return get_option('scholar_profile_data_status', self::default_status());
    }

    $record = self::get_registry()[$profile_id] ?? array();
    return is_array($record['status'] ?? null) ? $record['status'] : self::default_status();
  }

  public static function set_status($profile_id, array $status, $default_id = ''): void
  {
    $profile_id = self::normalize_id($profile_id);
    if ($profile_id === '' || $profile_id === self::normalize_id($default_id)) {
      update_option('scholar_profile_data_status', $status);
      return;
    }

    $registry = self::get_registry();
    $registry[$profile_id]['status'] = $status;
    update_option(self::OPTION_NAME, $registry);
  }

  public static function get_meta($profile_id, string $key, $default = 0, $default_id = '')
  {
    $profile_id = self::normalize_id($profile_id);
    if ($profile_id === '' || $profile_id === self::normalize_id($default_id)) {
      return get_option('scholar_profile_' . $key, $default);
    }

    $record = self::get_registry()[$profile_id] ?? array();
    return $record[$key] ?? $default;
  }

  public static function set_meta($profile_id, string $key, $value, $default_id = ''): void
  {
    $profile_id = self::normalize_id($profile_id);
    if ($profile_id === '' || $profile_id === self::normalize_id($default_id)) {
      update_option('scholar_profile_' . $key, $value);
      return;
    }

    $registry = self::get_registry();
    $registry[$profile_id][$key] = $value;
    update_option(self::OPTION_NAME, $registry);
  }

  public static function delete_meta($profile_id, string $key, $default_id = ''): void
  {
    $profile_id = self::normalize_id($profile_id);
    if ($profile_id === '' || $profile_id === self::normalize_id($default_id)) {
      delete_option('scholar_profile_' . $key);
      return;
    }

    $registry = self::get_registry();
    if (isset($registry[$profile_id])) {
      unset($registry[$profile_id][$key]);
      update_option(self::OPTION_NAME, $registry);
    }
  }

  private static function default_status(): array
  {
    return array(
      'status' => 'unknown',
      'message' => 'No status information available',
      'timestamp' => 0,
      'consecutive_failures' => 0
    );
  }
}
