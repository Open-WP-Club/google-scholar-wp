<?php

namespace WPScholar;

if (!defined('ABSPATH')) {
  exit;
}

/**
 * Stores additional Scholar profiles without changing the legacy default
 * profile options used by existing installations.
 *
 * Each additional profile gets its own option (data/status/meta bundled
 * together) instead of sharing one big option, so a cron scrape of profile
 * A and an admin import of profile B never read-modify-write the same row.
 * ponytail: writes to the SAME profile from two overlapping requests can
 * still race (last write wins) - add per-profile locking if that's ever
 * hit in practice.
 */
class ProfileStore
{
  private const INDEX_OPTION_NAME = 'scholar_profile_profiles_index';
  private const PROFILE_OPTION_PREFIX = 'scholar_profile_profile_';

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

  private static function option_name_for(string $profile_id): string
  {
    return self::PROFILE_OPTION_PREFIX . md5($profile_id);
  }

  private static function get_record(string $profile_id): array
  {
    $record = get_option(self::option_name_for($profile_id), array());
    return is_array($record) ? $record : array();
  }

  private static function update_record(string $profile_id, array $record): void
  {
    update_option(self::option_name_for($profile_id), $record);
  }

  public static function get_ids($default_id = ''): array
  {
    $ids = array();
    $default_id = self::normalize_id($default_id);
    if ($default_id !== '' && self::is_valid_id($default_id)) {
      $ids[] = $default_id;
    }

    $index = get_option(self::INDEX_OPTION_NAME, array());
    foreach ((is_array($index) ? $index : array()) as $profile_id) {
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

    // Drop stored data for profiles that are no longer registered.
    $previous_index = get_option(self::INDEX_OPTION_NAME, array());
    foreach (array_diff(is_array($previous_index) ? $previous_index : array(), $ids) as $removed_id) {
      delete_option(self::option_name_for($removed_id));
    }

    update_option(self::INDEX_OPTION_NAME, $ids);
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

    return self::get_record($profile_id)['data'] ?? null;
  }

  public static function set_data($profile_id, array $data, $default_id = ''): void
  {
    $profile_id = self::normalize_id($profile_id);
    if ($profile_id === '' || $profile_id === self::normalize_id($default_id)) {
      update_option('scholar_profile_data', $data);
      return;
    }

    $record = self::get_record($profile_id);
    $record['data'] = $data;
    self::update_record($profile_id, $record);
  }

  public static function get_status($profile_id, $default_id = ''): array
  {
    $profile_id = self::normalize_id($profile_id);
    if ($profile_id === '' || $profile_id === self::normalize_id($default_id)) {
      return get_option('scholar_profile_data_status', self::default_status());
    }

    $status = self::get_record($profile_id)['status'] ?? null;
    return is_array($status) ? $status : self::default_status();
  }

  public static function set_status($profile_id, array $status, $default_id = ''): void
  {
    $profile_id = self::normalize_id($profile_id);
    if ($profile_id === '' || $profile_id === self::normalize_id($default_id)) {
      update_option('scholar_profile_data_status', $status);
      return;
    }

    $record = self::get_record($profile_id);
    $record['status'] = $status;
    self::update_record($profile_id, $record);
  }

  public static function get_meta($profile_id, string $key, $default = 0, $default_id = '')
  {
    $profile_id = self::normalize_id($profile_id);
    if ($profile_id === '' || $profile_id === self::normalize_id($default_id)) {
      return get_option('scholar_profile_' . $key, $default);
    }

    return self::get_record($profile_id)[$key] ?? $default;
  }

  public static function set_meta($profile_id, string $key, $value, $default_id = ''): void
  {
    $profile_id = self::normalize_id($profile_id);
    if ($profile_id === '' || $profile_id === self::normalize_id($default_id)) {
      update_option('scholar_profile_' . $key, $value);
      return;
    }

    $record = self::get_record($profile_id);
    $record[$key] = $value;
    self::update_record($profile_id, $record);
  }

  public static function delete_meta($profile_id, string $key, $default_id = ''): void
  {
    $profile_id = self::normalize_id($profile_id);
    if ($profile_id === '' || $profile_id === self::normalize_id($default_id)) {
      delete_option('scholar_profile_' . $key);
      return;
    }

    $record = self::get_record($profile_id);
    if (isset($record[$key])) {
      unset($record[$key]);
      self::update_record($profile_id, $record);
    }
  }

  /**
   * Wipe everything stored for a profile (data, status, and all meta).
   */
  public static function delete_all($profile_id, $default_id = ''): void
  {
    $profile_id = self::normalize_id($profile_id);
    if ($profile_id === '' || $profile_id === self::normalize_id($default_id)) {
      delete_option('scholar_profile_data');
      delete_option('scholar_profile_last_update');
      delete_option('scholar_profile_data_status');
      delete_option('scholar_profile_consecutive_failures');
      return;
    }

    delete_option(self::option_name_for($profile_id));
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
