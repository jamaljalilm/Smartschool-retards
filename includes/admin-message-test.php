<?php
if (!defined('ABSPATH')) exit;

/**
 * includes/admin-message-test.php
 * Outil de test pour envoyer des messages Smartschool manuellement
 *
 * Note: Le menu est enregistré dans admin.php
 */

/* ========================== PAGE TEST MESSAGES ========================== */
function ssr_admin_test_messages_render(){
    if (!current_user_can('manage_options')) {
        wp_die(__('Accès refusé.'));
    }

    $result_msg = '';
    $result_type = '';

    /* --------- POST HANDLER --------- */
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ssr_test_send'])) {
        check_admin_referer('ssr_test_send_message', 'ssr_test_nonce');

        $user_id     = sanitize_text_field($_POST['user_identifier'] ?? '');
        $title       = sanitize_text_field($_POST['message_title'] ?? '');
        $body        = wp_kses_post($_POST['message_body'] ?? '');
        $sender      = sanitize_text_field($_POST['sender_identifier'] ?? 'Null');
        $coaccount   = isset($_POST['coaccount']) ? intval($_POST['coaccount']) : null;
        $copy_to_lvs = !empty($_POST['copy_to_lvs']);

        // Validation
        if (empty($user_id)) {
            $result_msg = 'Erreur : l\'identifiant utilisateur est requis.';
            $result_type = 'error';
        } elseif (empty($title)) {
            $result_msg = 'Erreur : le titre est requis.';
            $result_type = 'error';
        } elseif (empty($body)) {
            $result_msg = 'Erreur : le corps du message est requis.';
            $result_type = 'error';
        } else {
            // Envoi du message
            if (function_exists('ssr_api_send_message')) {
                $result = ssr_api_send_message(
                    $user_id,
                    $title,
                    $body,
                    $sender,
                    null,        // attachments
                    $coaccount,  // coaccount
                    $copy_to_lvs // copyToLVS
                );

                if (is_wp_error($result)) {
                    $result_msg = 'Erreur lors de l\'envoi : ' . esc_html($result->get_error_message());
                    $result_type = 'error';
                } else {
                    $coaccount_label = ($coaccount === null) ? 'compte principal' : 'coaccount ' . $coaccount;
                    $result_msg = 'Message envoyé avec succès à l\'utilisateur ' . esc_html($user_id) . ' (' . $coaccount_label . ')';
                    $result_type = 'success';

                    // Log
                    if (function_exists('ssr_log')) {
                        ssr_log('Test message sent to ' . $user_id . ' (' . $coaccount_label . ')', 'info', 'admin-test');
                    }
                }
            } else {
                $result_msg = 'Erreur : la fonction ssr_api_send_message n\'est pas disponible.';
                $result_type = 'error';
            }
        }
    }

    // Récupération du sender par défaut
    $default_sender = 'R001'; // Compte expéditeur Smartschool

    ?>
    <div class="wrap">
        <h1><?php _e('Test d\'envoi de messages Smartschool', 'smartschool-retards'); ?></h1>

        <?php if ($result_msg): ?>
            <div class="notice notice-<?php echo esc_attr($result_type); ?> is-dismissible">
                <p><?php echo $result_msg; ?></p>
            </div>
        <?php endif; ?>

        <div class="card" style="max-width: 800px; margin-top: 20px;">
            <h2>Envoyer un message de test</h2>
            <p style="color: #666;">
                Utilisez cet outil pour tester l'envoi de messages via l'API Smartschool sendMsg.
                <br><strong>Attention :</strong> Les messages seront réellement envoyés !
            </p>

            <form method="post" action="">
                <?php wp_nonce_field('ssr_test_send_message', 'ssr_test_nonce'); ?>

                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="user_identifier">Identifiant utilisateur *</label>
                        </th>
                        <td>
                            <input type="text"
                                   name="user_identifier"
                                   id="user_identifier"
                                   class="regular-text"
                                   required
                                   placeholder="Ex: INDL.9999"
                                   value="<?php echo isset($_POST['user_identifier']) ? esc_attr($_POST['user_identifier']) : 'INDL.9999'; ?>">
                            <p class="description">Numéro interne Smartschool ou identifiant unique de l'utilisateur</p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">
                            <label for="message_title">Titre du message *</label>
                        </th>
                        <td>
                            <input type="text"
                                   name="message_title"
                                   id="message_title"
                                   class="regular-text"
                                   required
                                   placeholder="Ex: Retard - Interdiction de sortir"
                                   value="<?php echo isset($_POST['message_title']) ? esc_attr($_POST['message_title']) : 'Retard - Interdiction de sortir'; ?>">
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">
                            <label for="message_body">Corps du message *</label>
                        </th>
                        <td>
                            <?php
                            $default_message = "Bonjour,\n\ntu étais en retard aujourd'hui.\n\nMerci de venir te présenter demain pendant l'heure du midi au péron.\n\nMonsieur Khali";
                            $message_content = isset($_POST['message_body']) ? wp_kses_post($_POST['message_body']) : $default_message;

                            wp_editor(
                                $message_content,
                                'message_body',
                                array(
                                    'textarea_name' => 'message_body',
                                    'textarea_rows' => 10,
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
                            <p class="description">Utilisez l'éditeur pour formater votre message (gras, couleurs, listes, etc.)</p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">
                            <label for="sender_identifier">Expéditeur</label>
                        </th>
                        <td>
                            <input type="text"
                                   name="sender_identifier"
                                   id="sender_identifier"
                                   class="regular-text"
                                   placeholder="'Null' pour aucun expéditeur"
                                   value="<?php echo isset($_POST['sender_identifier']) ? esc_attr($_POST['sender_identifier']) : esc_attr($default_sender); ?>">
                            <p class="description">
                                Identifiant Smartschool de l'expéditeur. Utilisez 'Null' pour ne pas définir d'expéditeur.
                                <?php if ($default_sender): ?>
                                    <br><strong>Valeur par défaut :</strong> <?php echo esc_html($default_sender); ?>
                                <?php endif; ?>
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">
                            <label for="coaccount">Type de compte</label>
                        </th>
                        <td>
                            <select name="coaccount" id="coaccount">
                                <option value="">Compte principal (élève)</option>
                                <option value="1" <?php selected(isset($_POST['coaccount']) && $_POST['coaccount'] == '1'); ?>>Coaccount 1 (Parent 1)</option>
                                <option value="2" <?php selected(isset($_POST['coaccount']) && $_POST['coaccount'] == '2'); ?>>Coaccount 2 (Parent 2)</option>
                            </select>
                            <p class="description">Choisissez à quel compte envoyer le message</p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">
                            <label for="copy_to_lvs">Copier dans LVS</label>
                        </th>
                        <td>
                            <label>
                                <input type="checkbox"
                                       name="copy_to_lvs"
                                       id="copy_to_lvs"
                                       value="1"
                                       <?php checked(!empty($_POST['copy_to_lvs'])); ?>>
                                Ajouter ce message dans le Suivi des élèves (LVS)
                            </label>
                        </td>
                    </tr>
                </table>

                <p class="submit">
                    <button type="submit" name="ssr_test_send" class="button button-primary">
                        📤 Envoyer le message de test
                    </button>
                </p>
            </form>
        </div>

        <div class="card" style="max-width: 800px; margin-top: 20px;">
            <h2>📋 Exemples de tests rapides</h2>
            <p>Utilisez ces exemples pour tester différents scénarios :</p>
            <ul style="line-height: 1.8;">
                <li><strong>Message simple à un élève :</strong> Compte principal, sans expéditeur</li>
                <li><strong>Message aux parents :</strong> Sélectionner Coaccount 1 ou 2</li>
                <li><strong>Message avec suivi :</strong> Cocher "Copier dans LVS"</li>
            </ul>
        </div>

        <div class="card" style="max-width: 800px; margin-top: 20px; background: #fff3cd; border-left: 4px solid #ffc107;">
            <h2>⚠️ Important</h2>
            <ul style="line-height: 1.8;">
                <li>Les messages envoyés via cet outil sont <strong>réels</strong> et seront reçus par les utilisateurs.</li>
                <li>Vérifiez toujours l'identifiant utilisateur avant d'envoyer.</li>
                <li>Testez d'abord sur votre propre compte ou un compte de test.</li>
                <li>Les logs sont enregistrés dans la table <code><?php echo esc_html(SSR_T_LOG); ?></code></li>
            </ul>
        </div>
    </div>
    <?php
}
