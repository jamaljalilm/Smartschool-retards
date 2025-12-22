<?php
if (!defined('ABSPATH')) exit;

/**
 * Ajoute le préfixe INDL. à tous les user_identifier qui ne l'ont pas
 */
function ssr_add_indl_prefix_to_all_identifiers() {
    global $wpdb;

    $tables_to_update = [
        'verif' => SSR_T_VERIF,
        'sanctions' => SSR_T_SANCTIONS,
        'messages' => $wpdb->prefix . 'smartschool_daily_messages',
    ];

    $results = [];

    foreach ($tables_to_update as $table_name => $table) {
        // Vérifier que la table existe
        $table_exists = $wpdb->get_var($wpdb->prepare(
            "SHOW TABLES LIKE %s",
            $wpdb->esc_like($table)
        )) === $table;

        if (!$table_exists) {
            $results[$table_name] = [
                'exists' => false,
                'updated' => 0,
                'deleted' => 0,
                'message' => "Table n'existe pas"
            ];
            ssr_log("Table {$table} n'existe pas", 'warning', 'migration');
            continue;
        }

        // Compter combien d'enregistrements n'ont pas le préfixe
        $count_without_prefix = $wpdb->get_var("
            SELECT COUNT(*)
            FROM `{$table}`
            WHERE user_identifier NOT LIKE 'INDL.%'
            AND user_identifier IS NOT NULL
            AND user_identifier != ''
        ");

        ssr_log("Table {$table}: {$count_without_prefix} enregistrements sans préfixe INDL.", 'info', 'migration');

        if ($count_without_prefix > 0) {
            $deleted = 0;

            // Pour la table verif, gérer les doublons potentiels AVANT l'UPDATE
            if ($table_name === 'verif') {
                // Étape 1: Identifier et supprimer les enregistrements qui créeraient des doublons
                // On garde ceux qui ont déjà INDL., on supprime ceux qui ne l'ont pas
                $deleted = $wpdb->query("
                    DELETE v1 FROM `{$table}` v1
                    INNER JOIN `{$table}` v2
                    ON CONCAT('INDL.', v1.user_identifier) = v2.user_identifier
                    AND v1.date_retard = v2.date_retard
                    WHERE v1.user_identifier NOT LIKE 'INDL.%'
                ");

                $deleted = $deleted !== false ? intval($deleted) : 0;

                if ($deleted > 0) {
                    ssr_log("Supprimé {$deleted} doublons potentiels dans {$table}", 'info', 'migration');
                }

                // Étape 2: Vérifier si l'index unique existe
                $index_exists = $wpdb->get_var("
                    SELECT COUNT(*)
                    FROM INFORMATION_SCHEMA.STATISTICS
                    WHERE TABLE_SCHEMA = DATABASE()
                    AND TABLE_NAME = '{$table}'
                    AND INDEX_NAME = 'uniq_user_day'
                ");

                if ($index_exists) {
                    ssr_log("Index uniq_user_day existe, suppression temporaire...", 'info', 'migration');
                    $drop_result = $wpdb->query("ALTER TABLE `{$table}` DROP INDEX `uniq_user_day`");

                    if ($drop_result === false) {
                        ssr_log("ERREUR lors de la suppression de l'index: " . $wpdb->last_error, 'error', 'migration');
                    } else {
                        ssr_log("Index supprimé avec succès", 'info', 'migration');
                    }
                }

                // Étape 3: Mettre à jour tous les user_identifier sans préfixe
                $updated = $wpdb->query("
                    UPDATE `{$table}`
                    SET user_identifier = CONCAT('INDL.', user_identifier)
                    WHERE user_identifier NOT LIKE 'INDL.%'
                    AND user_identifier IS NOT NULL
                    AND user_identifier != ''
                ");

                if ($updated === false) {
                    ssr_log("ERREUR lors de l'UPDATE: " . $wpdb->last_error, 'error', 'migration');
                } else {
                    ssr_log("UPDATE réussi: {$updated} lignes affectées", 'info', 'migration');
                }

                // Étape 4: Supprimer tout doublon restant (au cas où)
                $wpdb->query("
                    DELETE v1 FROM `{$table}` v1
                    INNER JOIN `{$table}` v2
                    WHERE v1.user_identifier = v2.user_identifier
                    AND v1.date_retard = v2.date_retard
                    AND v1.id < v2.id
                ");

                // Étape 5: Recréer la contrainte d'unicité si elle a été supprimée
                if ($index_exists) {
                    $add_result = $wpdb->query("
                        ALTER TABLE `{$table}`
                        ADD UNIQUE KEY `uniq_user_day` (`user_identifier`, `date_retard`)
                    ");

                    if ($add_result === false) {
                        ssr_log("ERREUR lors de la recréation de l'index: " . $wpdb->last_error, 'error', 'migration');
                    } else {
                        ssr_log("Index recréé avec succès", 'info', 'migration');
                    }
                }
            } else {
                // Pour les autres tables, UPDATE simple
                $updated = $wpdb->query("
                    UPDATE `{$table}`
                    SET user_identifier = CONCAT('INDL.', user_identifier)
                    WHERE user_identifier NOT LIKE 'INDL.%'
                    AND user_identifier IS NOT NULL
                    AND user_identifier != ''
                ");

                if ($updated === false) {
                    ssr_log("ERREUR lors de l'UPDATE sur {$table}: " . $wpdb->last_error, 'error', 'migration');
                }
            }

            $actual_updated = $updated !== false ? intval($updated) : 0;

            $results[$table_name] = [
                'exists' => true,
                'updated' => $actual_updated,
                'deleted' => $deleted,
                'message' => "Mis à jour " . $actual_updated . " enregistrement(s)" .
                           ($deleted > 0 ? ", supprimé {$deleted} doublon(s)" : "")
            ];

            ssr_log(
                "Préfixe INDL. ajouté à " . $actual_updated . " enregistrements dans la table {$table}" .
                ($deleted > 0 ? " ({$deleted} doublons supprimés)" : ""),
                'info',
                'migration'
            );
        } else {
            $results[$table_name] = [
                'exists' => true,
                'updated' => 0,
                'deleted' => 0,
                'message' => "Tous les identifiants ont déjà le préfixe INDL."
            ];
        }
    }

    return $results;
}

// Ajouter la page d'administration pour la migration INDL
add_action('admin_menu', function() {
    add_submenu_page(
        'ssr-settings',
        'Migration INDL',
        '🔄 Migration INDL',
        'manage_options',
        'ssr-migration-indl',
        'ssr_admin_migration_indl_render'
    );
}, 102);

function ssr_admin_migration_indl_render() {
    if (!current_user_can('manage_options')) {
        wp_die('Accès non autorisé');
    }

    global $wpdb;

    echo '<div class="wrap">';
    echo '<h1>🔄 Migration des identifiants vers le format INDL.XXXX</h1>';

    echo '<style>
        .ssr-indl-box {
            background: white;
            border: 1px solid #ccd0d4;
            border-radius: 4px;
            padding: 20px;
            margin: 20px 0;
        }
        .ssr-indl-box h2 {
            margin-top: 0;
            border-bottom: 2px solid #f57c00;
            padding-bottom: 10px;
        }
        .ssr-indl-table {
            width: 100%;
            border-collapse: collapse;
            margin: 15px 0;
        }
        .ssr-indl-table th,
        .ssr-indl-table td {
            padding: 10px;
            text-align: left;
            border-bottom: 1px solid #ddd;
        }
        .ssr-indl-table th {
            background: #f5f5f5;
            font-weight: 600;
        }
        .ssr-success {
            background: #e8f5e9;
            border-left: 4px solid #2e7d32;
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
    </style>';

    // Informations importantes
    echo '<div class="ssr-indl-box">';
    echo '<h2>ℹ️ À propos de cette migration</h2>';
    echo '<div class="ssr-info">';
    echo '<p><strong>Pourquoi cette migration ?</strong></p>';
    echo '<p>Pour que l\'envoi de messages via Smartschool fonctionne correctement, tous les identifiants d\'élèves doivent être au format <code>INDL.XXXX</code> (ex: <code>INDL.6033</code>).</p>';
    echo '<p>Cette migration va automatiquement ajouter le préfixe <code>INDL.</code> à tous les identifiants qui ne l\'ont pas encore.</p>';
    echo '<p><strong>Tables concernées :</strong></p>';
    echo '<ul>';
    echo '<li><code>wp_smartschool_retards_verif</code> (vérifications)</li>';
    echo '<li><code>wp_smartschool_retenues_sanctions</code> (sanctions)</li>';
    echo '<li><code>wp_smartschool_daily_messages</code> (messages quotidiens)</li>';
    echo '</ul>';
    echo '</div>';
    echo '</div>';

    // Diagnostic avant migration
    echo '<div class="ssr-indl-box">';
    echo '<h2>📊 Étape 1 : Diagnostic</h2>';

    $tables_info = [
        'Vérifications' => SSR_T_VERIF,
        'Sanctions' => SSR_T_SANCTIONS,
        'Messages' => $wpdb->prefix . 'smartschool_daily_messages',
    ];

    echo '<table class="ssr-indl-table">';
    echo '<thead><tr><th>Table</th><th>Total</th><th>Sans préfixe INDL.</th><th>Avec préfixe INDL.</th></tr></thead>';
    echo '<tbody>';

    $total_without_prefix = 0;

    foreach ($tables_info as $label => $table) {
        $table_exists = $wpdb->get_var($wpdb->prepare(
            "SHOW TABLES LIKE %s",
            $wpdb->esc_like($table)
        )) === $table;

        if (!$table_exists) {
            echo '<tr>';
            echo '<td>' . esc_html($label) . '</td>';
            echo '<td colspan="3" style="color: #999;">Table n\'existe pas</td>';
            echo '</tr>';
            continue;
        }

        $total = $wpdb->get_var("SELECT COUNT(*) FROM `{$table}` WHERE user_identifier IS NOT NULL AND user_identifier != ''");
        $without_prefix = $wpdb->get_var("SELECT COUNT(*) FROM `{$table}` WHERE user_identifier NOT LIKE 'INDL.%' AND user_identifier IS NOT NULL AND user_identifier != ''");
        $with_prefix = $total - $without_prefix;

        $total_without_prefix += $without_prefix;

        echo '<tr>';
        echo '<td><strong>' . esc_html($label) . '</strong></td>';
        echo '<td>' . number_format($total) . '</td>';
        echo '<td style="' . ($without_prefix > 0 ? 'color: #f57c00; font-weight: 600;' : '') . '">' . number_format($without_prefix) . '</td>';
        echo '<td style="color: #2e7d32;">' . number_format($with_prefix) . '</td>';
        echo '</tr>';
    }

    echo '</tbody></table>';

    if ($total_without_prefix > 0) {
        echo '<div class="ssr-warning">';
        echo '<p><strong>⚠️ ' . number_format($total_without_prefix) . ' identifiant(s) nécessitent le préfixe INDL.</strong></p>';
        echo '</div>';
    } else {
        echo '<div class="ssr-success">';
        echo '<p><strong>✅ Tous les identifiants ont déjà le préfixe INDL. !</strong></p>';
        echo '</div>';
    }

    echo '</div>';

    // Migration
    echo '<div class="ssr-indl-box">';
    echo '<h2>🚀 Étape 2 : Migration</h2>';

    if (isset($_POST['execute_indl_migration']) && check_admin_referer('ssr_indl_migration', 'ssr_indl_nonce')) {
        echo '<div class="ssr-info">🚀 Exécution de la migration en cours...</div>';

        $results = ssr_add_indl_prefix_to_all_identifiers();

        echo '<h3>Résultats de la migration :</h3>';
        echo '<table class="ssr-indl-table">';
        echo '<thead><tr><th>Table</th><th>Statut</th><th>Détails</th></tr></thead>';
        echo '<tbody>';

        foreach ($results as $table_name => $result) {
            echo '<tr>';
            echo '<td><strong>' . esc_html(ucfirst($table_name)) . '</strong></td>';

            if (!$result['exists']) {
                echo '<td>❌</td>';
                echo '<td>' . esc_html($result['message']) . '</td>';
            } elseif ($result['updated'] > 0 || $result['deleted'] > 0) {
                echo '<td>✅</td>';
                echo '<td style="color: #2e7d32; font-weight: 600;">' . esc_html($result['message']) . '</td>';
            } else {
                echo '<td>ℹ️</td>';
                echo '<td>' . esc_html($result['message']) . '</td>';
            }

            echo '</tr>';
        }

        echo '</tbody></table>';

        echo '<div class="ssr-success">';
        echo '<p><strong>✅ Migration terminée avec succès !</strong></p>';
        echo '<p>Tous les identifiants sont maintenant au format INDL.XXXX</p>';
        echo '</div>';

        echo '<p><a href="' . admin_url('admin.php?page=ssr-migration-indl') . '" class="button button-primary">🔄 Vérifier le résultat</a></p>';

        // Afficher les logs
        $logs = $wpdb->get_results(
            "SELECT * FROM {$wpdb->prefix}smartschool_retards_log
            WHERE context = 'migration'
            AND message LIKE '%INDL%'
            ORDER BY created_at DESC
            LIMIT 10",
            ARRAY_A
        );

        if (!empty($logs)) {
            echo '<h3>📋 Logs de migration :</h3>';
            echo '<table class="ssr-indl-table">';
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
        if ($total_without_prefix > 0) {
            echo '<p>Cliquez sur le bouton ci-dessous pour ajouter automatiquement le préfixe <code>INDL.</code> à tous les identifiants.</p>';
            echo '<form method="post">';
            wp_nonce_field('ssr_indl_migration', 'ssr_indl_nonce');
            echo '<button type="submit" name="execute_indl_migration" class="button button-primary button-large" onclick="return confirm(\'Êtes-vous sûr de vouloir ajouter le préfixe INDL. à tous les identifiants ?\')">🚀 Exécuter la migration maintenant</button>';
            echo '</form>';
        } else {
            echo '<div class="ssr-success">';
            echo '<p>✅ Aucune migration nécessaire. Tous les identifiants sont déjà corrects.</p>';
            echo '</div>';
        }
    }

    echo '</div>';

    echo '</div>'; // wrap
}
