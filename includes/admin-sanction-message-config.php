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

    // Traiter la soumission du formulaire
    if (isset($_POST['ssr_save_sanction_config']) && check_admin_referer('ssr_sanction_config_nonce')) {
        // Sauvegarder le titre
        $title = wp_kses_post($_POST['ssr_sanction_message_title'] ?? '');
        update_option('ssr_sanction_message_title', $title);

        // Sauvegarder le corps du message
        $body = wp_kses_post($_POST['ssr_sanction_message_body'] ?? '');
        update_option('ssr_sanction_message_body', $body);

        // Sauvegarder l'envoi automatique
        $auto_send = isset($_POST['ssr_sanction_auto_send']) ? '1' : '0';
        update_option('ssr_sanction_auto_send', $auto_send);

        // Sauvegarder les destinataires
        $send_to_student = isset($_POST['ssr_sanction_send_to_student']) ? '1' : '0';
        update_option('ssr_sanction_send_to_student', $send_to_student);

        $send_to_parents = isset($_POST['ssr_sanction_send_to_parents']) ? '1' : '0';
        update_option('ssr_sanction_send_to_parents', $send_to_parents);

        echo '<div class="notice notice-success is-dismissible"><p>✅ Configuration des messages de sanction sauvegardée avec succès.</p></div>';
    }

    // Récupérer les valeurs actuelles
    $default_title = 'Notification de sanction';
    $title = get_option('ssr_sanction_message_title', $default_title);

    $default_body = '<div style="font-family: Arial, sans-serif; color: #333;">
<p style="margin-bottom: 15px;">Bonjour {prenom},</p>
<p style="margin-bottom: 15px;">Suite à tes <strong style="color: #d32f2f;">{nb_absences} non-présentations</strong> après des retards, tu as atteint le seuil pour une <strong>{sanction_label}</strong>.</p>
<p style="margin-bottom: 15px;">📋 <strong>Détails de ta sanction :</strong></p>
<ul style="margin-bottom: 15px; padding-left: 20px;">
    <li><strong>Nombre de non-présentations :</strong> {nb_absences}</li>
    <li><strong>Type de sanction :</strong> {sanction_label}</li>
</ul>
<p style="margin-bottom: 15px;">⚠️ <strong>Important :</strong> Un message te sera envoyé prochainement avec la <strong>date exacte</strong> à laquelle tu devras te présenter pour effectuer ta sanction.</p>
<p style="margin-bottom: 15px;">Pour rappel, voici les seuils de sanction :</p>
<ul style="margin-bottom: 15px; padding-left: 20px;">
    <li>5-9 absences : Retenue 1</li>
    <li>10-14 absences : Retenue 2</li>
    <li>15-19 absences : Demi-jour de renvoi</li>
    <li>20+ absences : Jour de renvoi</li>
</ul>
<p style="margin-bottom: 15px;">N\'oublie pas de te présenter lors de la prochaine pause de midi à <strong>13h05 à l\'accueil</strong> pour éviter d\'accumuler davantage d\'absences.</p>
<p style="margin-bottom: 15px;">Cordialement,</p>
</div>
<div style="font-family: Arial, sans-serif; font-size: 14px; color: #000;">
<table style="border-collapse: collapse;" cellspacing="0" cellpadding="0">
<tbody>
<tr>
<td style="padding-right: 15px; vertical-align: top;">
<p style="margin: 0px; font-weight: bold; font-size: 16px; text-align: right;">Robot IA - INDL Retards</p>
<p style="margin: 2px 0px; font-style: italic; color: #333333; text-align: right;">Rue Edmond Tollenaere, 32<br/>1020 Bruxelles</p>
</td>
<td style="vertical-align: top;"><a href="https://indl.be/" target="_blank" rel="noopener"> <img style="float: left;" src="https://indl.be/wp-content/uploads/2023/04/185-low.gif" alt="Logo Institut Notre-Dame de Lourdes" width="180" height="50" border="0" /> </a></td>
</tr>
</tbody>
</table>
</div>';

    $body = get_option('ssr_sanction_message_body', $default_body);
    $auto_send = get_option('ssr_sanction_auto_send', '1');
    $send_to_student = get_option('ssr_sanction_send_to_student', '1');
    $send_to_parents = get_option('ssr_sanction_send_to_parents', '1');

    ?>
    <div class="wrap">
        <h1>⚖️ Configuration des messages de sanction automatiques</h1>

        <div class="notice notice-info">
            <p>
                <strong>💡 Comment ça fonctionne ?</strong><br>
                Lorsqu'un élève atteint un seuil de sanction (5, 10, 15 ou 20 non-présentations),
                un message lui sera automatiquement envoyé pour l'informer de sa sanction.
            </p>
        </div>

        <form method="post" action="">
            <?php wp_nonce_field('ssr_sanction_config_nonce'); ?>

            <table class="form-table" role="presentation">
                <!-- Envoi automatique -->
                <tr>
                    <th scope="row">
                        <label for="ssr_sanction_auto_send">Envoi automatique</label>
                    </th>
                    <td>
                        <label>
                            <input type="checkbox"
                                   name="ssr_sanction_auto_send"
                                   id="ssr_sanction_auto_send"
                                   value="1"
                                   <?php checked($auto_send, '1'); ?>>
                            Activer l'envoi automatique des notifications de sanction
                        </label>
                        <p class="description">
                            Si activé, un message sera automatiquement envoyé dès qu'un élève atteint un nouveau seuil de sanction.
                        </p>
                    </td>
                </tr>

                <!-- Destinataires -->
                <tr>
                    <th scope="row">Destinataires</th>
                    <td>
                        <fieldset>
                            <label>
                                <input type="checkbox"
                                       name="ssr_sanction_send_to_student"
                                       value="1"
                                       <?php checked($send_to_student, '1'); ?>>
                                Envoyer à l'élève
                            </label>
                            <br>
                            <label>
                                <input type="checkbox"
                                       name="ssr_sanction_send_to_parents"
                                       value="1"
                                       <?php checked($send_to_parents, '1'); ?>>
                                Envoyer aux parents (co-comptes Smartschool)
                            </label>
                        </fieldset>
                        <p class="description">
                            Sélectionnez qui doit recevoir les notifications de sanction.
                        </p>
                    </td>
                </tr>

                <!-- Titre du message -->
                <tr>
                    <th scope="row">
                        <label for="ssr_sanction_message_title">Titre du message</label>
                    </th>
                    <td>
                        <input type="text"
                               name="ssr_sanction_message_title"
                               id="ssr_sanction_message_title"
                               value="<?php echo esc_attr($title); ?>"
                               class="large-text">
                        <p class="description">
                            Variables disponibles :
                            <code>{prenom}</code>,
                            <code>{nom}</code>,
                            <code>{nb_absences}</code>,
                            <code>{sanction_label}</code>
                        </p>
                    </td>
                </tr>

                <!-- Corps du message -->
                <tr>
                    <th scope="row">
                        <label for="ssr_sanction_message_body">Corps du message</label>
                    </th>
                    <td>
                        <?php
                        wp_editor($body, 'ssr_sanction_message_body', [
                            'textarea_rows' => 20,
                            'media_buttons' => false,
                            'teeny' => false,
                            'tinymce' => [
                                'toolbar1' => 'bold,italic,underline,strikethrough,|,bullist,numlist,|,link,unlink,|,forecolor,backcolor,|,alignleft,aligncenter,alignright,|,undo,redo',
                                'toolbar2' => '',
                            ],
                        ]);
                        ?>
                        <p class="description">
                            <strong>Variables disponibles :</strong><br>
                            <code>{prenom}</code> - Prénom de l'élève<br>
                            <code>{nom}</code> - Nom de l'élève<br>
                            <code>{nb_absences}</code> - Nombre de non-présentations<br>
                            <code>{sanction_type}</code> - Type de sanction (retenue_1, retenue_2, demi_jour, jour_renvoi)<br>
                            <code>{sanction_label}</code> - Libellé de la sanction (Retenue 1, Retenue 2, Demi-jour de renvoi, Jour de renvoi)
                        </p>
                    </td>
                </tr>
            </table>

            <p class="submit">
                <input type="submit"
                       name="ssr_save_sanction_config"
                       class="button button-primary"
                       value="💾 Sauvegarder la configuration">
            </p>
        </form>

        <hr>

        <h2>📊 Seuils de sanction</h2>
        <table class="widefat striped">
            <thead>
                <tr>
                    <th>Nombre d'absences</th>
                    <th>Type de sanction</th>
                    <th>Variable sanction_type</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>5-9 absences</td>
                    <td><strong style="color: #856404;">Retenue 1</strong></td>
                    <td><code>retenue_1</code></td>
                </tr>
                <tr>
                    <td>10-14 absences</td>
                    <td><strong style="color: #c2410c;">Retenue 2</strong></td>
                    <td><code>retenue_2</code></td>
                </tr>
                <tr>
                    <td>15-19 absences</td>
                    <td><strong style="color: #991b1b;">Demi-jour de renvoi</strong></td>
                    <td><code>demi_jour</code></td>
                </tr>
                <tr>
                    <td>20+ absences</td>
                    <td><strong style="color: #7f1d1d;">Jour de renvoi</strong></td>
                    <td><code>jour_renvoi</code></td>
                </tr>
            </tbody>
        </table>

        <hr>

        <h2>📝 Exemple de message</h2>
        <div style="background: #f9f9f9; border: 1px solid #ddd; padding: 20px; border-radius: 4px;">
            <p><strong>Titre :</strong> <?php echo esc_html($title); ?></p>
            <p><strong>Corps :</strong></p>
            <div style="background: #fff; border: 1px solid #ccc; padding: 15px;">
                <?php
                // Remplacer les variables pour l'aperçu
                $preview_replacements = [
                    '{prenom}' => 'Jean',
                    '{nom}' => 'Dupont',
                    '{nb_absences}' => '10',
                    '{sanction_type}' => 'retenue_2',
                    '{sanction_label}' => 'Retenue 2',
                ];
                $preview_body = str_replace(array_keys($preview_replacements), array_values($preview_replacements), $body);
                echo $preview_body;
                ?>
            </div>
        </div>
    </div>

    <style>
        .wrap h2 {
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid #ddd;
        }

        .wrap h2:first-of-type {
            margin-top: 0;
            padding-top: 0;
            border-top: none;
        }

        code {
            background: #f0f0f1;
            padding: 2px 6px;
            border-radius: 3px;
            font-family: monospace;
        }
    </style>
    <?php
}
