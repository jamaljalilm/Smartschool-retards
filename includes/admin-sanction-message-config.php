<?php
if (!defined('ABSPATH')) exit;

/**
 * includes/admin-sanction-message-config.php
 * Configuration des messages automatiques de sanction
 *
 * Note: Le menu est enregistré dans admin.php
 */

function ssr_render_admin_sanction_message_config() {
    // Vérifier les permissions
    if (!current_user_can('manage_options')) {
        wp_die('Accès refusé');
    }

    ?>
    <div class="wrap">
        <h1>⚖️ Configuration des messages de sanction automatiques</h1>
        <p>Cette page est en cours de développement.</p>
    </div>
    <?php
}
