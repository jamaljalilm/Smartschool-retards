<?php
if (!defined('ABSPATH')) exit;

/**
 * Page d'administration pour la migration de la base de données
 */

// Ajouter la page de migration au menu admin
add_action('admin_menu', function() {
    add_submenu_page(
        'ssr-settings',
        'Migration Base de Données',
        '🔧 Migration BDD',
        'manage_options',
        'ssr-migration',
        'ssr_admin_migration_render'
    );
}, 100);

function ssr_admin_migration_render() {
    if (!current_user_can('manage_options')) {
        wp_die('Accès non autorisé');
    }

    global $wpdb;
    $ver = SSR_T_VERIF;

    echo '<div class="wrap">';
    echo '<h1>🔧 Migration Base de Données - Smartschool Retards</h1>';

    // Style inline
    echo '<style>
        .ssr-migration-box {
            background: white;
            border: 1px solid #ccd0d4;
            border-radius: 4px;
            padding: 20px;
            margin: 20px 0;
            box-shadow: 0 1px 1px rgba(0,0,0,.04);
        }
        .ssr-migration-box h2 {
            margin-top: 0;
            border-bottom: 2px solid #f57c00;
            padding-bottom: 10px;
        }
        .ssr-success {
            background: #e8f5e9;
            border-left: 4px solid #2e7d32;
            padding: 12px;
            margin: 10px 0;
        }
        .ssr-error {
            background: #ffebee;
            border-left: 4px solid #c62828;
            padding: 12px;
            margin: 10px 0;
        }
        .ssr-warning {
            background: #fff3e0;
            border-left: 4px solid #f57c00;
            padding: 12px;
            margin: 10px 0;
        }
        .ssr-info {
            background: #e3f2fd;
            border-left: 4px solid #1565c0;
            padding: 12px;
            margin: 10px 0;
        }
        .ssr-migration-table {
            width: 100%;
            border-collapse: collapse;
            margin: 15px 0;
        }
        .ssr-migration-table th,
        .ssr-migration-table td {
            padding: 10px;
            text-align: left;
            border-bottom: 1px solid #ddd;
        }
        .ssr-migration-table th {
            background: #f5f5f5;
            font-weight: 600;
        }
        .ssr-migration-table code {
            background: #f5f5f5;
            padding: 2px 6px;
            border-radius: 3px;
            font-family: monospace;
        }
    </style>';

    // Étape 1 : Diagnostic
    echo '<div class="ssr-migration-box">';
    echo '<h2>📊 Étape 1 : Diagnostic de la table</h2>';

    $table_exists = $wpdb->get_var($wpdb->prepare(
        "SHOW TABLES LIKE %s",
        $wpdb->esc_like($ver)
    )) === $ver;

    if (!$table_exists) {
        echo '<div class="ssr-error">❌ La table <code>' . esc_html($ver) . '</code> n\'existe pas !</div>';
        echo '<p>Veuillez activer le plugin Smartschool Retards d\'abord.</p>';
        echo '</div></div>';
        return;
    }

    echo '<div class="ssr-success">✅ La table <code>' . esc_html($ver) . '</code> existe</div>';

    // Récupérer la structure de la table
    $columns = $wpdb->get_results($wpdb->prepare(
        "SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = %s
        AND TABLE_NAME = %s
        ORDER BY ORDINAL_POSITION",
        DB_NAME,
        $ver
    ), ARRAY_A);

    echo '<h3>Structure actuelle de la table :</h3>';
    echo '<table class="ssr-migration-table">';
    echo '<thead><tr><th>Colonne</th><th>Type</th><th>Nullable</th><th>Défaut</th></tr></thead>';
    echo '<tbody>';
    foreach ($columns as $col) {
        echo '<tr>';
        echo '<td><code>' . esc_html($col['COLUMN_NAME']) . '</code></td>';
        echo '<td>' . esc_html($col['COLUMN_TYPE']) . '</td>';
        echo '<td>' . esc_html($col['IS_NULLABLE']) . '</td>';
        echo '<td>' . esc_html($col['COLUMN_DEFAULT'] ?: '—') . '</td>';
        echo '</tr>';
    }
    echo '</tbody></table>';

    $column_names = array_column($columns, 'COLUMN_NAME');

    // Vérifier quelles migrations sont nécessaires
    $migrations_needed = [];

    if (in_array('date_jour', $column_names) && !in_array('date_retard', $column_names)) {
        $migrations_needed[] = "Renommer <code>date_jour</code> → <code>date_retard</code>";
    }

    if (in_array('lastname', $column_names) && !in_array('last_name', $column_names)) {
        $migrations_needed[] = "Renommer <code>lastname</code> → <code>last_name</code>";
    }

    if (in_array('firstname', $column_names) && !in_array('first_name', $column_names)) {
        $migrations_needed[] = "Renommer <code>firstname</code> → <code>first_name</code>";
    }

    if (in_array('verified_by_id', $column_names) && !in_array('verified_by_code', $column_names)) {
        $migrations_needed[] = "Renommer <code>verified_by_id</code> → <code>verified_by_code</code>";
    }

    if (!in_array('verified_at', $column_names)) {
        $migrations_needed[] = "Ajouter la colonne <code>verified_at</code>";
    }

    if (!in_array('status_raw', $column_names)) {
        $migrations_needed[] = "Ajouter la colonne <code>status_raw</code>";
    }

    echo '</div>';

    // Étape 2 : Migrations
    echo '<div class="ssr-migration-box">';
    echo '<h2>🔄 Étape 2 : Migrations nécessaires</h2>';

    if (empty($migrations_needed)) {
        echo '<div class="ssr-success">✅ Aucune migration nécessaire ! La table est à jour.</div>';
    } else {
        echo '<div class="ssr-warning">⚠️ Migrations à effectuer :</div>';
        echo '<ul>';
        foreach ($migrations_needed as $migration) {
            echo '<li>' . $migration . '</li>';
        }
        echo '</ul>';

        // Formulaire pour exécuter la migration
        if (isset($_POST['execute_migration']) && check_admin_referer('ssr_migration', 'ssr_migration_nonce')) {
            echo '<div class="ssr-info">🚀 Exécution de la migration en cours...</div>';

            // Exécuter les migrations
            ssr_db_migrate_column_names();
            ssr_db_add_status_raw_column();

            echo '<div class="ssr-success">✅ Migration terminée avec succès !</div>';
            echo '<p><a href="' . admin_url('admin.php?page=ssr-migration') . '" class="button button-primary">🔄 Vérifier le résultat</a></p>';

            // Afficher les logs
            $logs = $wpdb->get_results(
                "SELECT * FROM {$wpdb->prefix}smartschool_retards_log
                WHERE context = 'migration'
                ORDER BY created_at DESC
                LIMIT 10",
                ARRAY_A
            );

            if (!empty($logs)) {
                echo '<h3>📋 Logs de migration :</h3>';
                echo '<table class="ssr-migration-table">';
                echo '<thead><tr><th>Date</th><th>Niveau</th><th>Message</th></tr></thead>';
                echo '<tbody>';
                foreach ($logs as $log) {
                    echo '<tr>';
                    echo '<td>' . esc_html(date_i18n('d/m/Y H:i:s', strtotime($log['created_at']))) . '</td>';
                    echo '<td>' . esc_html($log['level']) . '</td>';
                    echo '<td>' . esc_html($log['message']) . '</td>';
                    echo '</tr>';
                }
                echo '</tbody></table>';
            }
        } else {
            // Afficher le formulaire
            echo '<form method="post">';
            wp_nonce_field('ssr_migration', 'ssr_migration_nonce');
            echo '<p><button type="submit" name="execute_migration" class="button button-primary button-large" onclick="return confirm(\'Êtes-vous sûr de vouloir exécuter la migration ?\')">🚀 Exécuter la migration maintenant</button></p>';
            echo '</form>';
        }
    }

    echo '</div>';

    // Étape 3 : Statistiques
    echo '<div class="ssr-migration-box">';
    echo '<h2>📈 Étape 3 : Statistiques</h2>';

    $total_records = $wpdb->get_var("SELECT COUNT(*) FROM `{$ver}`");
    echo '<div class="ssr-info">ℹ️ Nombre total d\'enregistrements : <strong>' . number_format($total_records) . '</strong></div>';

    // Compter par date
    $by_date = $wpdb->get_results("
        SELECT DATE(created_at) as date, COUNT(*) as count
        FROM `{$ver}`
        GROUP BY DATE(created_at)
        ORDER BY date DESC
        LIMIT 10
    ", ARRAY_A);

    if (!empty($by_date)) {
        echo '<h3>Derniers enregistrements par date :</h3>';
        echo '<table class="ssr-migration-table">';
        echo '<thead><tr><th>Date</th><th>Nombre d\'enregistrements</th></tr></thead>';
        echo '<tbody>';
        foreach ($by_date as $row) {
            echo '<tr>';
            echo '<td>' . esc_html(date_i18n('d/m/Y', strtotime($row['date']))) . '</td>';
            echo '<td>' . esc_html(number_format($row['count'])) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
    }

    echo '</div>';

    echo '</div>'; // wrap
}
