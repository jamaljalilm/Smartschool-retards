<?php
if (!defined('ABSPATH')) exit;

function ssr_db_maybe_create_tables(){
    global $wpdb;
    $charset = $wpdb->get_charset_collate();

    $log = SSR_T_LOG;
    $ver = SSR_T_VERIF;
    $messages = $wpdb->prefix . 'smartschool_daily_messages';
    $sanctions = SSR_T_SANCTIONS;

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
        date_retard DATE NOT NULL,
        status ENUM('present','absent','late') NOT NULL DEFAULT 'present',
        status_raw VARCHAR(10) NULL COMMENT 'AM, PM ou AM+PM',
        last_name VARCHAR(191) NULL,
        first_name VARCHAR(191) NULL,
        verified_at DATETIME NULL,
        verified_by_code VARCHAR(64) NULL,
        verified_by_name VARCHAR(191) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NULL,
        UNIQUE KEY uniq_user_day (user_identifier, date_retard),
        KEY idx_class_day (class_code, date_retard),
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

    $sql[] = "CREATE TABLE IF NOT EXISTS $sanctions (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        user_identifier VARCHAR(64) NOT NULL,
        class_code VARCHAR(64) NULL,
        last_name VARCHAR(191) NULL,
        first_name VARCHAR(191) NULL,
        nb_absences INT NOT NULL,
        sanction_type VARCHAR(50) NOT NULL,
        date_sanction DATE NULL,
        assigned_by_id VARCHAR(64) NULL,
        assigned_by_name VARCHAR(191) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NULL,
        UNIQUE KEY uniq_user_sanction (user_identifier),
        KEY idx_date (date_sanction),
        KEY idx_assigned (assigned_by_id),
        PRIMARY KEY(id)
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

/**
 * Ajoute le champ status_raw à la table de vérification si nécessaire
 */
function ssr_db_add_status_raw_column(){
    global $wpdb;
    $ver = SSR_T_VERIF;

    // Vérifier si la colonne existe déjà
    $column_exists = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = %s
        AND TABLE_NAME = %s
        AND COLUMN_NAME = 'status_raw'",
        DB_NAME,
        $ver
    ));

    if (empty($column_exists)) {
        // Ajouter la colonne
        $wpdb->query("ALTER TABLE `{$ver}` ADD COLUMN `status_raw` VARCHAR(10) NULL COMMENT 'AM, PM ou AM+PM' AFTER `status`");
        ssr_log("Colonne status_raw ajoutée à la table {$ver}", 'info', 'migration');
    }
}

/**
 * Migration des anciens noms de colonnes vers les nouveaux
 */
function ssr_db_migrate_column_names(){
    global $wpdb;
    $ver = SSR_T_VERIF;

    // Vérifier que la table existe
    $table_exists = $wpdb->get_var($wpdb->prepare(
        "SHOW TABLES LIKE %s",
        $wpdb->esc_like($ver)
    )) === $ver;

    if (!$table_exists) {
        return; // Table n'existe pas encore, pas besoin de migration
    }

    // Récupérer toutes les colonnes de la table
    $columns = $wpdb->get_results($wpdb->prepare(
        "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = %s
        AND TABLE_NAME = %s",
        DB_NAME,
        $ver
    ), ARRAY_A);

    $column_names = array_column($columns, 'COLUMN_NAME');

    // Migration: date_jour -> date_retard
    if (in_array('date_jour', $column_names) && !in_array('date_retard', $column_names)) {
        $wpdb->query("ALTER TABLE `{$ver}` CHANGE `date_jour` `date_retard` DATE NOT NULL");
        ssr_log("Colonne date_jour renommée en date_retard", 'info', 'migration');
    }

    // Migration: lastname -> last_name
    if (in_array('lastname', $column_names) && !in_array('last_name', $column_names)) {
        $wpdb->query("ALTER TABLE `{$ver}` CHANGE `lastname` `last_name` VARCHAR(191) NULL");
        ssr_log("Colonne lastname renommée en last_name", 'info', 'migration');
    }

    // Migration: firstname -> first_name
    if (in_array('firstname', $column_names) && !in_array('first_name', $column_names)) {
        $wpdb->query("ALTER TABLE `{$ver}` CHANGE `firstname` `first_name` VARCHAR(191) NULL");
        ssr_log("Colonne firstname renommée en first_name", 'info', 'migration');
    }

    // Migration: verified_by_id -> verified_by_code
    if (in_array('verified_by_id', $column_names) && !in_array('verified_by_code', $column_names)) {
        $wpdb->query("ALTER TABLE `{$ver}` CHANGE `verified_by_id` `verified_by_code` VARCHAR(64) NULL");
        ssr_log("Colonne verified_by_id renommée en verified_by_code", 'info', 'migration');
    }

    // Ajouter verified_at si elle n'existe pas
    if (!in_array('verified_at', $column_names)) {
        $wpdb->query("ALTER TABLE `{$ver}` ADD COLUMN `verified_at` DATETIME NULL AFTER `first_name`");
        ssr_log("Colonne verified_at ajoutée à la table {$ver}", 'info', 'migration');
    }
}
