<?php
if (!defined('ABSPATH')) exit;

/**
 * Page de diagnostic pour le récap retards
 */

add_action('admin_menu', function() {
    add_submenu_page(
        'ssr-settings',
        'Diagnostic Récap',
        '🔍 Diagnostic Récap',
        'manage_options',
        'ssr-diagnostic',
        'ssr_admin_diagnostic_render'
    );
}, 101);

function ssr_admin_diagnostic_render() {
    if (!current_user_can('manage_options')) {
        wp_die('Accès non autorisé');
    }

    global $wpdb;
    $ver = SSR_T_VERIF;

    echo '<div class="wrap">';
    echo '<h1>🔍 Diagnostic - Récap Retards</h1>';

    echo '<style>
        .ssr-diag-box {
            background: white;
            border: 1px solid #ccd0d4;
            border-radius: 4px;
            padding: 20px;
            margin: 20px 0;
        }
        .ssr-diag-box h2 {
            margin-top: 0;
            border-bottom: 2px solid #2271b1;
            padding-bottom: 10px;
        }
        .ssr-diag-table {
            width: 100%;
            border-collapse: collapse;
            margin: 15px 0;
        }
        .ssr-diag-table th,
        .ssr-diag-table td {
            padding: 8px;
            text-align: left;
            border-bottom: 1px solid #ddd;
        }
        .ssr-diag-table th {
            background: #f5f5f5;
            font-weight: 600;
        }
        .ssr-highlight {
            background: #fff3cd;
            padding: 2px 6px;
            border-radius: 3px;
        }
        .ssr-search-form {
            margin: 20px 0;
            padding: 15px;
            background: #f9f9f9;
            border-radius: 4px;
        }
    </style>';

    // Formulaire de recherche
    echo '<div class="ssr-diag-box">';
    echo '<h2>🔎 Rechercher un élève</h2>';
    echo '<div class="ssr-search-form">';
    echo '<form method="get">';
    echo '<input type="hidden" name="page" value="ssr-diagnostic">';
    echo '<label for="user_id">ID Élève (user_identifier) :</label><br>';
    echo '<input type="text" id="user_id" name="user_id" value="' . esc_attr($_GET['user_id'] ?? '') . '" placeholder="Ex: 12345" style="width: 300px; padding: 8px; margin: 10px 0;">';
    echo ' <button type="submit" class="button button-primary">🔍 Rechercher</button>';
    echo '</form>';
    echo '</div>';

    if (!empty($_GET['user_id'])) {
        $user_id = sanitize_text_field($_GET['user_id']);

        echo '<h3>Résultats pour l\'élève : <span class="ssr-highlight">' . esc_html($user_id) . '</span></h3>';

        // Récupérer TOUS les enregistrements pour cet élève
        $records = $wpdb->get_results($wpdb->prepare("
            SELECT
                id,
                user_identifier,
                class_code,
                last_name,
                first_name,
                date_retard,
                status,
                status_raw,
                verified_at,
                verified_by_code,
                verified_by_name,
                created_at
            FROM {$ver}
            WHERE user_identifier = %s
            ORDER BY date_retard DESC, verified_at DESC
        ", $user_id), ARRAY_A);

        $total = count($records);

        if ($total === 0) {
            echo '<p style="color: #d63638; font-weight: 600;">❌ Aucun enregistrement trouvé pour cet élève.</p>';
            echo '<p>Vérifiez que l\'ID est correct.</p>';
        } else {
            echo '<p style="color: #00a32a; font-weight: 600;">✅ ' . $total . ' enregistrement(s) trouvé(s)</p>';

            // Afficher TOUS les enregistrements
            echo '<h4>📋 Tous les enregistrements (ordre chronologique inverse) :</h4>';
            echo '<table class="ssr-diag-table">';
            echo '<thead><tr>';
            echo '<th>ID</th>';
            echo '<th>Date retard</th>';
            echo '<th>Status</th>';
            echo '<th>Status Raw</th>';
            echo '<th>Vérifié le</th>';
            echo '<th>Vérifié par</th>';
            echo '<th>Créé le</th>';
            echo '</tr></thead>';
            echo '<tbody>';

            foreach ($records as $rec) {
                echo '<tr>';
                echo '<td>' . esc_html($rec['id']) . '</td>';
                echo '<td><strong>' . esc_html(date('d/m/Y', strtotime($rec['date_retard']))) . '</strong></td>';
                echo '<td>' . esc_html($rec['status']) . '</td>';
                echo '<td>' . esc_html($rec['status_raw'] ?: '—') . '</td>';
                echo '<td>' . esc_html($rec['verified_at'] ? date('d/m/Y H:i', strtotime($rec['verified_at'])) : '—') . '</td>';
                echo '<td>' . esc_html($rec['verified_by_name'] ?: '—') . '</td>';
                echo '<td>' . esc_html($rec['created_at'] ? date('d/m/Y H:i', strtotime($rec['created_at'])) : '—') . '</td>';
                echo '</tr>';
            }

            echo '</tbody></table>';

            // Simulation de la déduplication (comme dans recap_retards.php)
            echo '<h4>🔬 Simulation du traitement dans Récap Retards :</h4>';
            echo '<p>La fonction <code>ssr_fetch_fiche_eleve_cb()</code> déduplique par date (garde la plus récente par date).</p>';

            $byDate = [];
            foreach ($records as $r) {
                $d = $r['date_retard'];
                if ($d && !isset($byDate[$d])) {
                    $byDate[$d] = $r;
                }
            }
            $deduplicated = array_values($byDate);

            echo '<p><strong>Après déduplication : ' . count($deduplicated) . ' enregistrement(s)</strong></p>';

            echo '<table class="ssr-diag-table">';
            echo '<thead><tr>';
            echo '<th>Date retard</th>';
            echo '<th>Status</th>';
            echo '<th>Status Raw</th>';
            echo '<th>Vérifié par</th>';
            echo '<th>Vérifié le</th>';
            echo '</tr></thead>';
            echo '<tbody>';

            foreach ($deduplicated as $rec) {
                echo '<tr>';
                echo '<td><strong>' . esc_html(date('d/m/Y', strtotime($rec['date_retard']))) . '</strong></td>';
                echo '<td>' . esc_html($rec['status']) . '</td>';
                echo '<td>' . esc_html($rec['status_raw'] ?: '—') . '</td>';
                echo '<td>' . esc_html($rec['verified_by_name'] ?: '—') . '</td>';
                echo '<td>' . esc_html($rec['verified_at'] ? date('d/m/Y H:i', strtotime($rec['verified_at'])) : '—') . '</td>';
                echo '</tr>';
            }

            echo '</tbody></table>';

            echo '<div style="background: #e7f3ff; padding: 15px; border-left: 4px solid #2271b1; margin: 20px 0;">';
            echo '<h4 style="margin-top: 0;">💡 Explication</h4>';
            echo '<p><strong>Nombre total d\'enregistrements :</strong> ' . $total . '</p>';
            echo '<p><strong>Nombre après déduplication :</strong> ' . count($deduplicated) . '</p>';
            echo '<p>Si vous voyez moins de lignes après déduplication, c\'est normal : la fiche élève affiche <strong>1 seule vérification par date</strong> (la plus récente).</p>';
            echo '<p>Si vous pensez qu\'il manque des dates entières, vérifiez ci-dessus que ces dates existent bien dans la base de données.</p>';
            echo '</div>';
        }
    } else {
        echo '<p>👆 Entrez un ID d\'élève ci-dessus pour voir tous ses enregistrements.</p>';
    }

    echo '</div>';

    // Statistiques globales
    echo '<div class="ssr-diag-box">';
    echo '<h2>📊 Statistiques globales</h2>';

    $total_records = $wpdb->get_var("SELECT COUNT(*) FROM {$ver}");
    $total_students = $wpdb->get_var("SELECT COUNT(DISTINCT user_identifier) FROM {$ver}");
    $total_dates = $wpdb->get_var("SELECT COUNT(DISTINCT date_retard) FROM {$ver}");

    echo '<table class="ssr-diag-table" style="width: auto;">';
    echo '<tr><th>Total enregistrements</th><td>' . number_format($total_records) . '</td></tr>';
    echo '<tr><th>Élèves distincts</th><td>' . number_format($total_students) . '</td></tr>';
    echo '<tr><th>Dates distinctes</th><td>' . number_format($total_dates) . '</td></tr>';
    echo '</table>';

    // Top 10 élèves avec le plus de vérifications
    echo '<h3>🏆 Top 10 des élèves avec le plus de vérifications</h3>';
    $top_students = $wpdb->get_results("
        SELECT
            user_identifier,
            MAX(last_name) as last_name,
            MAX(first_name) as first_name,
            COUNT(*) as total,
            COUNT(DISTINCT date_retard) as distinct_dates
        FROM {$ver}
        GROUP BY user_identifier
        ORDER BY total DESC
        LIMIT 10
    ", ARRAY_A);

    echo '<table class="ssr-diag-table">';
    echo '<thead><tr><th>ID</th><th>Nom</th><th>Prénom</th><th>Total vérif.</th><th>Dates distinctes</th><th>Action</th></tr></thead>';
    echo '<tbody>';
    foreach ($top_students as $student) {
        echo '<tr>';
        echo '<td>' . esc_html($student['user_identifier']) . '</td>';
        echo '<td>' . esc_html($student['last_name']) . '</td>';
        echo '<td>' . esc_html($student['first_name']) . '</td>';
        echo '<td>' . esc_html($student['total']) . '</td>';
        echo '<td>' . esc_html($student['distinct_dates']) . '</td>';
        echo '<td><a href="?page=ssr-diagnostic&user_id=' . esc_attr($student['user_identifier']) . '" class="button button-small">Voir détails</a></td>';
        echo '</tr>';
    }
    echo '</tbody></table>';

    echo '</div>';

    echo '</div>'; // wrap
}
