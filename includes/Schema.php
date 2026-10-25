<?php
namespace MyAduan;

if (!defined('ABSPATH')) {
    exit;
}

/** Table definitions for dbDelta. Follow dbDelta formatting rules when editing. */
final class Schema
{
    public static function table(string $name): string
    {
        global $wpdb;
        return $wpdb->prefix . 'myaduan_' . $name;
    }

    /** @return list<string> */
    public static function sql(): array
    {
        global $wpdb;
        $c = $wpdb->get_charset_collate();
        $t = [self::class, 'table'];

        return [
            "CREATE TABLE {$t('cases')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  reference varchar(24) NOT NULL,
  version int(10) unsigned NOT NULL DEFAULT 1,
  status varchar(24) NOT NULL DEFAULT 'DITERIMA',
  priority varchar(12) NOT NULL DEFAULT 'BIASA',
  title varchar(150) NOT NULL DEFAULT '',
  details text NOT NULL,
  location_text varchar(500) NOT NULL DEFAULT '',
  lat decimal(9,6) DEFAULT NULL,
  lng decimal(9,6) DEFAULT NULL,
  category_code varchar(3) DEFAULT NULL,
  dun_slug varchar(40) DEFAULT NULL,
  area_id bigint(20) unsigned DEFAULT NULL,
  name_enc text NOT NULL,
  ic_enc text NOT NULL,
  ic_index char(64) NOT NULL,
  phone_enc text NOT NULL,
  email_enc text DEFAULT NULL,
  owner_id bigint(20) unsigned NOT NULL DEFAULT 0,
  track varchar(12) DEFAULT NULL,
  next_action varchar(500) DEFAULT NULL,
  resolution text DEFAULT NULL,
  closure_reason varchar(24) DEFAULT NULL,
  public_draft text DEFAULT NULL,
  public_message text DEFAULT NULL,
  reopen_count int(10) unsigned NOT NULL DEFAULT 0,
  source varchar(20) NOT NULL DEFAULT 'web',
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  assigned_at datetime DEFAULT NULL,
  accepted_at datetime DEFAULT NULL,
  first_action_at datetime DEFAULT NULL,
  last_action_at datetime DEFAULT NULL,
  followup_at datetime DEFAULT NULL,
  closed_at datetime DEFAULT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY reference (reference),
  KEY status_owner (status,owner_id),
  KEY category_code (category_code),
  KEY area (dun_slug,area_id),
  KEY created_at (created_at),
  KEY ic_index (ic_index),
  KEY followup_at (followup_at)
) $c;",
            "CREATE TABLE {$t('events')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  case_id bigint(20) unsigned NOT NULL,
  actor_id bigint(20) unsigned NOT NULL DEFAULT 0,
  event varchar(32) NOT NULL,
  status_from varchar(24) DEFAULT NULL,
  status_to varchar(24) DEFAULT NULL,
  note text DEFAULT NULL,
  changes longtext DEFAULT NULL,
  request_id char(64) DEFAULT NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY request_id (request_id),
  KEY case_id (case_id,id),
  KEY actor_id (actor_id)
) $c;",
            "CREATE TABLE {$t('attachments')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  case_id bigint(20) unsigned NOT NULL,
  kind varchar(10) NOT NULL DEFAULT 'public',
  path varchar(255) NOT NULL,
  original_name varchar(190) NOT NULL DEFAULT '',
  mime varchar(60) NOT NULL,
  size int(10) unsigned NOT NULL,
  sha256 char(64) NOT NULL,
  uploaded_by bigint(20) unsigned NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY case_id (case_id)
) $c;",
            "CREATE TABLE {$t('agency_list')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  name varchar(150) NOT NULL,
  officer varchar(200) NOT NULL DEFAULT '',
  email varchar(190) NOT NULL DEFAULT '',
  cc varchar(1000) NOT NULL DEFAULT '',
  sort_order smallint(5) unsigned NOT NULL DEFAULT 0,
  active tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY  (id),
  UNIQUE KEY name (name)
) $c;",
            "CREATE TABLE {$t('agencies')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  case_id bigint(20) unsigned NOT NULL,
  agency_id bigint(20) unsigned NOT NULL,
  status varchar(24) NOT NULL DEFAULT 'Dirujuk',
  reference_no varchar(100) DEFAULT NULL,
  note text DEFAULT NULL,
  actor_id bigint(20) unsigned NOT NULL DEFAULT 0,
  followup_at datetime DEFAULT NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY case_id (case_id),
  KEY agency_status (agency_id,status)
) $c;",
            "CREATE TABLE {$t('categories')} (
  code varchar(3) NOT NULL,
  name varchar(120) NOT NULL,
  group_name varchar(120) NOT NULL,
  group_order smallint(5) unsigned NOT NULL DEFAULT 0,
  active tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY  (code)
) $c;",
            "CREATE TABLE {$t('duns')} (
  slug varchar(40) NOT NULL,
  name varchar(120) NOT NULL,
  sort_order smallint(5) unsigned NOT NULL DEFAULT 0,
  active tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY  (slug)
) $c;",
            "CREATE TABLE {$t('localities')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  dun_slug varchar(40) NOT NULL,
  name varchar(150) NOT NULL,
  active tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY  (id),
  UNIQUE KEY dun_name (dun_slug,name)
) $c;",
            "CREATE TABLE {$t('submissions')} (
  client_key char(64) NOT NULL,
  payload_hash char(64) NOT NULL,
  case_id bigint(20) unsigned NOT NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (client_key)
) $c;",
            "CREATE TABLE {$t('mail_queue')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  dedupe_key varchar(120) NOT NULL,
  case_id bigint(20) unsigned DEFAULT NULL,
  recipient varchar(190) NOT NULL,
  subject varchar(255) NOT NULL,
  body text NOT NULL,
  headers text DEFAULT NULL,
  attachments text DEFAULT NULL,
  state varchar(12) NOT NULL DEFAULT 'pending',
  attempts tinyint(3) unsigned NOT NULL DEFAULT 0,
  last_error varchar(255) DEFAULT NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY dedupe_key (dedupe_key),
  KEY state (state,id)
) $c;",
            "CREATE TABLE {$t('notifications')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  user_id bigint(20) unsigned NOT NULL,
  case_id bigint(20) unsigned DEFAULT NULL,
  kind varchar(20) NOT NULL,
  title varchar(200) NOT NULL,
  body varchar(500) NOT NULL DEFAULT '',
  push_state varchar(10) NOT NULL DEFAULT 'none',
  read_at datetime DEFAULT NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY user_read (user_id,read_at,id),
  KEY push_state (push_state,id)
) $c;",
            "CREATE TABLE {$t('push_subscriptions')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  user_id bigint(20) unsigned NOT NULL,
  endpoint_hash char(64) NOT NULL,
  endpoint text NOT NULL,
  p256dh varchar(120) NOT NULL,
  auth varchar(40) NOT NULL,
  device varchar(190) NOT NULL DEFAULT '',
  created_at datetime NOT NULL,
  last_ok_at datetime DEFAULT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY endpoint_hash (endpoint_hash),
  KEY user_id (user_id)
) $c;",
            "CREATE TABLE {$t('access_log')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  case_id bigint(20) unsigned NOT NULL,
  actor_id bigint(20) unsigned NOT NULL,
  what varchar(32) NOT NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY case_id (case_id),
  KEY actor_id (actor_id,created_at)
) $c;",
        ];
    }
}
