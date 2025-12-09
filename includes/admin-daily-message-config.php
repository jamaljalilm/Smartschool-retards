<?php
if (!defined('ABSPATH')) exit;

/**
 * includes/admin-daily-message-config.php
 * Configuration du message quotidien automatique
 *
 * Note: Le menu est enregistré dans admin.php
 */

/* ========================== PAGE CONFIGURATION MESSAGE QUOTIDIEN ========================== */
function ssr_admin_daily_message_config_render(){
    if (!current_user_can('manage_options')) {
        wp_die(__('Accès refusé.'));
    }

    $saved = false;

    // Traitement du formulaire
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ssr_daily_msg_save'])) {
        check_admin_referer('ssr_daily_msg_save', 'ssr_daily_msg_nonce');

        $title = isset($_POST['daily_message_title']) ? sanitize_text_field(wp_unslash($_POST['daily_message_title'])) : '';
        $body = isset($_POST['daily_message_body']) ? wp_kses_post(wp_unslash($_POST['daily_message_body'])) : '';

        update_option('ssr_daily_message_title', $title);
        update_option('ssr_daily_message_body', $body);

        $saved = true;
    }

    // Récupération des valeurs actuelles
    $current_title = get_option('ssr_daily_message_title', 'Retard - Interdiction de sortir');
    $current_body = get_option('ssr_daily_message_body',
        "Bonjour,\n\ntu étais en retard aujourd'hui.\n\nMerci de venir te présenter demain pendant l'heure du midi au péron.\n\nMonsieur Khali"
    );

    ?>
    <div class="wrap">
        <h1><?php _e('Configuration du message quotidien', 'smartschool-retards'); ?></h1>

        <?php if ($saved): ?>
            <div class="notice notice-success is-dismissible">
                <p><strong>✅ Configuration enregistrée avec succès !</strong></p>
            </div>
        <?php endif; ?>

        <div class="card" style="max-width: 900px; margin-top: 20px;">
            <h2>📧 Template du message quotidien</h2>
            <p style="color: #666;">
                Ce message sera envoyé automatiquement à tous les élèves en retard et leurs parents.
                <br>Vous pouvez utiliser l'éditeur pour formater le texte (gras, couleurs, listes, etc.).
            </p>

            <form method="post" action="">
                <?php wp_nonce_field('ssr_daily_msg_save', 'ssr_daily_msg_nonce'); ?>

                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="daily_message_title">Titre du message *</label>
                        </th>
                        <td>
                            <input type="text"
                                   name="daily_message_title"
                                   id="daily_message_title"
                                   class="regular-text"
                                   required
                                   value="<?php echo esc_attr($current_title); ?>">
                            <p class="description">Le titre qui apparaîtra dans la messagerie Smartschool</p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">
                            <label for="daily_message_body">Corps du message *</label>
                        </th>
                        <td>
                            <?php
                            wp_editor(
                                $current_body,
                                'daily_message_body',
                                array(
                                    'textarea_name' => 'daily_message_body',
                                    'textarea_rows' => 12,
                                    'media_buttons' => false,
                                    'teeny' => false,
                                    'tinymce' => array(
                                        'toolbar1' => 'formatselect,bold,italic,underline,forecolor,backcolor,bullist,numlist,alignleft,aligncenter,alignright,link,unlink,undo,redo',
                                        'toolbar2' => '',
                                    ),
                                    'quicktags' => true,
                                )
                            );
                            ?>
                            <p class="description">
                                Le contenu principal du message. Utilisez l'éditeur pour le formater.
                                <br><strong>Astuce :</strong> Vous pouvez utiliser du HTML pour un formatage avancé.
                                <br><strong>Note :</strong> Incluez la signature directement dans le corps du message.
                            </p>
                        </td>
                    </tr>
                </table>

                <p class="submit">
                    <button type="submit" name="ssr_daily_msg_save" class="button button-primary">
                        💾 Enregistrer la configuration
                    </button>
                </p>
            </form>
        </div>

        <div class="card" style="max-width: 900px; margin-top: 20px;">
            <h2>📋 Prévisualisation du message complet</h2>
            <div style="background: #f5f5f7; padding: 20px; border-radius: 8px; border-left: 4px solid #2271b1;">
                <h3 style="margin-top: 0; color: #2271b1;"><?php echo esc_html($current_title); ?></h3>
                <div style="line-height: 1.6;">
                    <?php echo wpautop($current_body); ?>
                </div>
            </div>
        </div>

        <div class="card" style="max-width: 900px; margin-top: 20px; background: #fff3cd; border-left: 4px solid #ffc107;">
            <h2>ℹ️ Informations importantes</h2>
            <ul style="line-height: 1.8;">
                <li><strong>Destinataires :</strong> Le message sera envoyé à l'élève (compte principal) + parents (coaccount 1 et 2)</li>
                <li><strong>Expéditeur :</strong> Tous les messages sont envoyés depuis le compte <code>R001</code></li>
                <li><strong>Planification :</strong> Envoi automatique selon l'heure configurée dans les réglages généraux</li>
                <li><strong>Formatage :</strong> Le HTML est supporté par Smartschool (gras, couleurs, listes, etc.)</li>
            </ul>
        </div>
    </div>
    <?php
}
