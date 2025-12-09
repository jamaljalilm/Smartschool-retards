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

        $title = isset($_POST['daily_message_title']) ? sanitize_text_field(stripslashes($_POST['daily_message_title'])) : '';
        $body = isset($_POST['daily_message_body']) ? wp_kses_post(stripslashes($_POST['daily_message_body'])) : '';

        // Destinataires
        $send_to_student = !empty($_POST['send_to_student']) ? '1' : '0';
        $send_to_parents = !empty($_POST['send_to_parents']) ? '1' : '0';

        // Heure d'envoi automatique
        $send_time = isset($_POST['daily_send_time']) ? sanitize_text_field($_POST['daily_send_time']) : '13:15';

        update_option('ssr_daily_message_title', $title);
        update_option('ssr_daily_message_body', $body);
        update_option('ssr_daily_send_to_student', $send_to_student);
        update_option('ssr_daily_send_to_parents', $send_to_parents);
        update_option('ssr_daily_hhmm', $send_time);

        // Replanifier le cron si l'heure a changé
        if (function_exists('ssr_cron_maybe_reschedule_daily')) {
            ssr_cron_maybe_reschedule_daily();
        }

        $saved = true;
    }

    // Récupération des valeurs actuelles
    $current_title = get_option('ssr_daily_message_title', 'Retard - Interdiction de sortir');
    $current_body = get_option('ssr_daily_message_body',
        "Bonjour,\n\ntu étais en retard aujourd'hui.\n\nMerci de venir te présenter demain pendant l'heure du midi au péron.\n\nMonsieur Khali"
    );
    $send_to_student = get_option('ssr_daily_send_to_student', '1');
    $send_to_parents = get_option('ssr_daily_send_to_parents', '1');
    $send_time = get_option('ssr_daily_hhmm', '13:15');

    ?>
    <style>
        /* Style personnalisé pour les checkboxes */
        .ssr-checkbox-wrapper {
            margin-bottom: 12px;
        }
        .ssr-checkbox-wrapper input[type="checkbox"] {
            width: 22px;
            height: 22px;
            margin: 0 8px 0 0;
            cursor: pointer;
            vertical-align: middle;
            position: relative;
            appearance: none;
            -webkit-appearance: none;
            border: 2px solid #8c8f94;
            border-radius: 4px;
            background-color: #fff;
            transition: all 0.2s ease;
            display: inline-block;
            flex-shrink: 0;
        }
        .ssr-checkbox-wrapper input[type="checkbox"]:hover {
            border-color: #2271b1;
        }
        .ssr-checkbox-wrapper input[type="checkbox"]:checked {
            background-color: #2271b1;
            border-color: #2271b1;
        }
        .ssr-checkbox-wrapper input[type="checkbox"]:checked::after {
            content: "✓";
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #fff;
            font-size: 16px;
            font-weight: bold;
            line-height: 1;
        }
        .ssr-checkbox-wrapper {
            display: flex;
            align-items: center;
        }
        .ssr-checkbox-wrapper label {
            cursor: pointer;
            margin: 0;
            user-select: none;
        }
        .ssr-checkbox-wrapper label:hover {
            color: #2271b1;
        }
    </style>
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

                    <tr>
                        <th scope="row">
                            Destinataires du message *
                        </th>
                        <td>
                            <fieldset style="border: none; padding: 0; margin: 0;">
                                <legend class="screen-reader-text"><span>Destinataires</span></legend>
                                <div class="ssr-checkbox-wrapper">
                                    <input type="checkbox"
                                           name="send_to_student"
                                           id="send_to_student"
                                           value="1"
                                           <?php checked('1', $send_to_student); ?>>
                                    <label for="send_to_student">
                                        <strong>Élève</strong> (compte principal)
                                    </label>
                                </div>
                                <div class="ssr-checkbox-wrapper">
                                    <input type="checkbox"
                                           name="send_to_parents"
                                           id="send_to_parents"
                                           value="1"
                                           <?php checked('1', $send_to_parents); ?>>
                                    <label for="send_to_parents">
                                        <strong>Parents</strong> (coaccount 1 et 2)
                                    </label>
                                </div>
                            </fieldset>
                            <p class="description">Cochez au moins un destinataire. Vous pouvez sélectionner les deux options.</p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">
                            <label for="daily_send_time">Heure d'envoi automatique *</label>
                        </th>
                        <td>
                            <input type="time"
                                   name="daily_send_time"
                                   id="daily_send_time"
                                   required
                                   value="<?php echo esc_attr($send_time); ?>"
                                   style="width: 150px;">
                            <p class="description">Heure à laquelle les messages seront envoyés automatiquement chaque jour (format 24h)</p>
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
                <li><strong>Destinataires :</strong> Vous pouvez choisir d'envoyer aux élèves et/ou aux parents selon vos préférences</li>
                <li><strong>Expéditeur :</strong> Tous les messages sont envoyés depuis le compte <code>R001</code></li>
                <li><strong>Planification :</strong> Envoi automatique à l'heure configurée ci-dessus (<?php echo esc_html($send_time); ?>)</li>
                <li><strong>Formatage :</strong> Le HTML est supporté par Smartschool (gras, couleurs, listes, etc.)</li>
                <li><strong>Destinataires actuels :</strong>
                    <?php
                    $recipients = array();
                    if ($send_to_student === '1') $recipients[] = 'Élèves';
                    if ($send_to_parents === '1') $recipients[] = 'Parents';
                    echo !empty($recipients) ? implode(' + ', $recipients) : '<span style="color: #d63638;">Aucun destinataire sélectionné !</span>';
                    ?>
                </li>
            </ul>
        </div>
    </div>
    <?php
}
