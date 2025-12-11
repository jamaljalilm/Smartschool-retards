<?php
if (!defined('ABSPATH')) exit;

/**
 * includes/admin-view-logs.php
 * Page pour visualiser les logs du plugin
 */

function ssr_admin_view_logs_render(){
    if (!current_user_can('manage_options')) {
        wp_die(__('Accès refusé.'));
    }

    global $wpdb;
    $table_log = SSR_T_LOG;

    // Filtres
    $level = isset($_GET['level']) ? sanitize_text_field($_GET['level']) : '';
    $context = isset($_GET['context']) ? sanitize_text_field($_GET['context']) : '';

    // Construction de la requête
    $where = [];
    $params = [];

    if ($level) {
        $where[] = "level = %s";
        $params[] = $level;
    }

    if ($context) {
        $where[] = "context = %s";
        $params[] = $context;
    }

    $where_clause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

    // Récupérer les logs
    if (!empty($params)) {
        $query = $wpdb->prepare(
            "SELECT * FROM $table_log $where_clause ORDER BY created_at DESC LIMIT 100",
            ...$params
        );
    } else {
        $query = "SELECT * FROM $table_log ORDER BY created_at DESC LIMIT 100";
    }

    $logs = $wpdb->get_results($query);

    // Récupérer les contextes uniques pour le filtre
    $contexts = $wpdb->get_col("SELECT DISTINCT context FROM $table_log WHERE context IS NOT NULL ORDER BY context");

    ?>
    <div class="wrap">
        <h1>📋 Logs du plugin</h1>

        <div class="card" style="max-width: 100%; margin-top: 20px;">
            <h2>Filtres</h2>
            <form method="get" action="">
                <input type="hidden" name="page" value="ssr-view-logs">

                <label for="level">Niveau : </label>
                <select name="level" id="level">
                    <option value="">Tous les niveaux</option>
                    <option value="info" <?php selected($level, 'info'); ?>>Info</option>
                    <option value="warning" <?php selected($level, 'warning'); ?>>Warning</option>
                    <option value="error" <?php selected($level, 'error'); ?>>Error</option>
                </select>

                <label for="context" style="margin-left: 15px;">Contexte : </label>
                <select name="context" id="context">
                    <option value="">Tous les contextes</option>
                    <?php foreach ($contexts as $ctx): ?>
                        <option value="<?php echo esc_attr($ctx); ?>" <?php selected($context, $ctx); ?>>
                            <?php echo esc_html($ctx); ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <button type="submit" class="button" style="margin-left: 10px;">Filtrer</button>
                <a href="<?php echo admin_url('admin.php?page=ssr-view-logs'); ?>" class="button" style="margin-left: 5px;">Réinitialiser</a>
            </form>
        </div>

        <div class="card" style="max-width: 100%; margin-top: 20px;">
            <h2>Derniers logs (100 max)</h2>

            <?php if (empty($logs)): ?>
                <p style="color: #666; font-style: italic;">Aucun log trouvé.</p>
            <?php else: ?>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th style="width: 180px;">Date/Heure</th>
                            <th style="width: 80px;">Niveau</th>
                            <th style="width: 120px;">Contexte</th>
                            <th>Message</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($logs as $log): ?>
                            <?php
                            // Couleur selon le niveau
                            $badge_color = '#646970'; // gris par défaut
                            if ($log->level === 'error') $badge_color = '#d63638';
                            if ($log->level === 'warning') $badge_color = '#dba617';
                            if ($log->level === 'info') $badge_color = '#2271b1';
                            ?>
                            <tr>
                                <td><?php echo esc_html($log->created_at); ?></td>
                                <td>
                                    <span style="
                                        background: <?php echo $badge_color; ?>;
                                        color: white;
                                        padding: 2px 8px;
                                        border-radius: 3px;
                                        font-size: 11px;
                                        font-weight: bold;
                                        text-transform: uppercase;
                                    ">
                                        <?php echo esc_html($log->level); ?>
                                    </span>
                                </td>
                                <td><?php echo esc_html($log->context ? $log->context : '-'); ?></td>
                                <td><?php echo esc_html($log->message); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

        <div class="card" style="max-width: 100%; margin-top: 20px; background: #fff3cd; border-left: 4px solid #ffc107;">
            <h2>💡 Astuce</h2>
            <p>Pour voir les logs du cron, filtrez par contexte <strong>"cron"</strong> ou <strong>"daily-messages"</strong>.</p>
            <p>Pour tester le cron manuellement, visitez : <code><?php echo admin_url('?ssr_cron_run_now=1'); ?></code></p>
        </div>
    </div>
    <?php
}
