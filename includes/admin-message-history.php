<?php
if (!defined('ABSPATH')) exit;

/**
 * includes/admin-message-history.php
 * Historique des messages quotidiens envoyés
 */

/* ========================== PAGE HISTORIQUE MESSAGES ========================== */
function ssr_admin_message_history_render(){
    if (!current_user_can('manage_options')) {
        wp_die(__('Accès refusé.'));
    }

    global $wpdb;
    $table = $wpdb->prefix . 'smartschool_daily_messages';

    // Pagination
    $per_page = 50;
    $current_page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
    $offset = ($current_page - 1) * $per_page;

    // Filtres
    $where = ['1=1'];
    $params = [];

    if (!empty($_GET['search_name'])) {
        $search = '%' . $wpdb->esc_like(sanitize_text_field($_GET['search_name'])) . '%';
        $where[] = '(first_name LIKE %s OR last_name LIKE %s)';
        $params[] = $search;
        $params[] = $search;
    }

    if (!empty($_GET['filter_date'])) {
        $date = sanitize_text_field($_GET['filter_date']);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $where[] = 'date_retard = %s';
            $params[] = $date;
        }
    }

    if (!empty($_GET['filter_class'])) {
        $where[] = 'class_code = %s';
        $params[] = sanitize_text_field($_GET['filter_class']);
    }

    $where_clause = implode(' AND ', $where);

    // Requête avec préparation
    if (!empty($params)) {
        $total_query = "SELECT COUNT(*) FROM $table WHERE $where_clause";
        $total = $wpdb->get_var($wpdb->prepare($total_query, $params));

        $query = "SELECT * FROM $table WHERE $where_clause ORDER BY sent_at DESC LIMIT %d OFFSET %d";
        $params[] = $per_page;
        $params[] = $offset;
        $messages = $wpdb->get_results($wpdb->prepare($query, $params));
    } else {
        $total = $wpdb->get_var("SELECT COUNT(*) FROM $table WHERE $where_clause");
        $messages = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table WHERE $where_clause ORDER BY sent_at DESC LIMIT %d OFFSET %d",
            $per_page,
            $offset
        ));
    }

    $total_pages = ceil($total / $per_page);

    // Récupérer les classes uniques pour le filtre
    $classes = $wpdb->get_col("SELECT DISTINCT class_code FROM $table WHERE class_code IS NOT NULL ORDER BY class_code");

    ?>
    <style>
        .ssr-history-filters {
            background: #f6f7f7;
            padding: 15px;
            border-radius: 6px;
            margin-bottom: 20px;
            border: 1px solid #d0d7de;
        }
        .ssr-history-filters input, .ssr-history-filters select {
            margin-right: 10px;
        }
        .ssr-badge {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 3px;
            font-size: 12px;
            font-weight: 600;
            margin-right: 3px;
        }
        .ssr-badge-success { background: #00a32a; color: #fff; }
        .ssr-badge-partial { background: #dba617; color: #fff; }
        .ssr-badge-failed { background: #d63638; color: #fff; }
        .ssr-message-preview {
            max-width: 300px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        .ssr-message-full {
            display: none;
            background: #fff;
            border: 1px solid #d0d7de;
            padding: 15px;
            margin-top: 10px;
            border-radius: 6px;
            max-width: 600px;
        }
    </style>

    <div class="wrap">
        <h1>📬 Historique des messages quotidiens</h1>
        <p>Consultez tous les messages de retard envoyés automatiquement aux élèves et parents.</p>

        <!-- Filtres -->
        <div class="ssr-history-filters">
            <form method="get" action="">
                <input type="hidden" name="page" value="ssr-message-history">

                <input type="text"
                       name="search_name"
                       placeholder="Rechercher par nom..."
                       value="<?php echo esc_attr(isset($_GET['search_name']) ? $_GET['search_name'] : ''); ?>"
                       style="width: 200px;">

                <input type="date"
                       name="filter_date"
                       value="<?php echo esc_attr(isset($_GET['filter_date']) ? $_GET['filter_date'] : ''); ?>">

                <select name="filter_class">
                    <option value="">Toutes les classes</option>
                    <?php foreach ($classes as $class): ?>
                        <option value="<?php echo esc_attr($class); ?>" <?php selected(isset($_GET['filter_class']) ? $_GET['filter_class'] : '', $class); ?>>
                            <?php echo esc_html($class); ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <button type="submit" class="button">Filtrer</button>
                <a href="?page=ssr-message-history" class="button">Réinitialiser</a>
            </form>
        </div>

        <!-- Statistiques rapides -->
        <div style="background: #fff; padding: 15px; margin-bottom: 20px; border-radius: 6px; border: 1px solid #d0d7de;">
            <?php
            $stats_total = $wpdb->get_var("SELECT COUNT(*) FROM $table");
            $stats_today = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM $table WHERE DATE(sent_at) = %s",
                current_time('Y-m-d')
            ));
            $stats_week = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM $table WHERE sent_at >= %s",
                date('Y-m-d', strtotime('-7 days'))
            ));
            ?>
            <strong>Statistiques :</strong>
            <?php echo intval($stats_total); ?> messages au total |
            <?php echo intval($stats_today); ?> aujourd'hui |
            <?php echo intval($stats_week); ?> cette semaine
        </div>

        <!-- Tableau des résultats -->
        <?php if ($messages): ?>
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th>Date retard</th>
                        <th>Élève</th>
                        <th>Classe</th>
                        <th>Titre</th>
                        <th>Destinataires</th>
                        <th>Statut</th>
                        <th>Envoyé le</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($messages as $msg): ?>
                        <tr>
                            <td><?php echo esc_html($msg->date_retard); ?></td>
                            <td><strong><?php echo esc_html($msg->first_name . ' ' . $msg->last_name); ?></strong></td>
                            <td><?php echo esc_html($msg->class_code); ?></td>
                            <td><?php echo esc_html($msg->message_title); ?></td>
                            <td>
                                <?php if ($msg->sent_to_student): ?>
                                    <span class="ssr-badge ssr-badge-success">Élève</span>
                                <?php endif; ?>
                                <?php if ($msg->sent_to_parent1): ?>
                                    <span class="ssr-badge ssr-badge-success">Parent 1</span>
                                <?php endif; ?>
                                <?php if ($msg->sent_to_parent2): ?>
                                    <span class="ssr-badge ssr-badge-success">Parent 2</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php
                                $status_class = 'success';
                                $status_text = 'Envoyé';
                                if ($msg->status === 'partial') {
                                    $status_class = 'partial';
                                    $status_text = 'Partiel';
                                } elseif ($msg->status === 'failed') {
                                    $status_class = 'failed';
                                    $status_text = 'Échec';
                                }
                                ?>
                                <span class="ssr-badge ssr-badge-<?php echo $status_class; ?>">
                                    <?php echo $status_text; ?>
                                </span>
                            </td>
                            <td><?php echo esc_html(date('d/m/Y H:i', strtotime($msg->sent_at))); ?></td>
                            <td>
                                <button type="button" class="button button-small" onclick="toggleMessage(<?php echo $msg->id; ?>)">
                                    Voir message
                                </button>
                                <div id="message-<?php echo $msg->id; ?>" class="ssr-message-full">
                                    <h3 style="margin-top: 0;"><?php echo esc_html($msg->message_title); ?></h3>
                                    <div><?php echo wp_kses_post($msg->message_content); ?></div>
                                    <?php if ($msg->error_message): ?>
                                        <p style="color: #d63638; margin-top: 15px; padding: 10px; background: #ffebee; border-radius: 4px;">
                                            <strong>Erreur :</strong> <?php echo esc_html($msg->error_message); ?>
                                        </p>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <!-- Pagination -->
            <?php if ($total_pages > 1): ?>
                <div class="tablenav" style="margin-top: 20px;">
                    <div class="tablenav-pages">
                        <?php
                        $base_url = remove_query_arg('paged');
                        for ($i = 1; $i <= $total_pages; $i++):
                            $class = ($i == $current_page) ? 'button button-primary' : 'button';
                            $url = add_query_arg('paged', $i, $base_url);
                        ?>
                            <a href="<?php echo esc_url($url); ?>" class="<?php echo $class; ?>"><?php echo $i; ?></a>
                        <?php endfor; ?>
                    </div>
                </div>
            <?php endif; ?>

        <?php else: ?>
            <div class="notice notice-info">
                <p>Aucun message trouvé avec ces critères.</p>
            </div>
        <?php endif; ?>
    </div>

    <script>
    function toggleMessage(id) {
        var element = document.getElementById('message-' + id);
        if (element.style.display === 'block') {
            element.style.display = 'none';
        } else {
            element.style.display = 'block';
        }
    }
    </script>
    <?php
}
