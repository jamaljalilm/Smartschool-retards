<?php
if (!defined('ABSPATH')) exit;

function ssr_db_maybe_create_tables(){
    global $wpdb;
    $charset = $wpdb->get_charset_collate();

    $log = SSR_T_LOG;
    $ver = SSR_T_VERIF;
    $messages = $wpdb->prefix . 'smartschool_daily_messages';

    $sql = [];
    $sql[] = "CREATE TABLE IF NOT EXISTS $log (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        level VARCHAR(20) NOT NULL DEFAULT 'info',
        context VARCHAR(100) NULL,
        message TEXT,
        PRIMARY KEY(id),
        KEY level (level),
        KEY context (context)
    ) $charset;";

    $sql[] = "CREATE TABLE IF NOT EXISTS $ver (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        user_identifier VARCHAR(64) NOT NULL,
        class_code VARCHAR(64) NULL,
        date_jour DATE NOT NULL,
        status ENUM('present','absent','late') NOT NULL DEFAULT 'present',
        lastname VARCHAR(191) NULL,
        firstname VARCHAR(191) NULL,
        verified_by_id VARCHAR(64) NULL,
        verified_by_name VARCHAR(191) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NULL,
        UNIQUE KEY uniq_user_day (user_identifier, date_jour),
        KEY idx_class_day (class_code, date_jour),
        PRIMARY KEY(id)
    ) $charset;";

    $sql[] = "CREATE TABLE IF NOT EXISTS $messages (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        user_identifier VARCHAR(64) NOT NULL,
        class_code VARCHAR(64) NULL,
        last_name VARCHAR(191) NULL,
        first_name VARCHAR(191) NULL,
        date_retard DATE NOT NULL,
        message_title VARCHAR(500) NULL,
        message_content TEXT NULL,
        sent_to_student TINYINT(1) DEFAULT 0,
        sent_to_parent1 TINYINT(1) DEFAULT 0,
        sent_to_parent2 TINYINT(1) DEFAULT 0,
        sent_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        status VARCHAR(20) DEFAULT 'success',
        error_message TEXT NULL,
        PRIMARY KEY(id),
        KEY idx_date (date_retard),
        KEY idx_student (user_identifier),
        KEY idx_sent_at (sent_at)
    ) $charset;";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    foreach($sql as $q){
        dbDelta($q);
    }
}

function ssr_log($message, $level='info', $context=null){
    global $wpdb;
    $wpdb->insert(SSR_T_LOG, [
        'level' => sanitize_text_field($level),
        'context' => $context ? sanitize_text_field($context) : null,
        'message' => wp_strip_all_tags((string)$message),
    ], ['%s','%s','%s']);
}
