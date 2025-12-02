<?php
if (!defined('ABSPATH')) exit;

add_shortcode('fiche_eleve', function($atts){
    if(!ssr_is_logged_in_pin()) return ssr_locked_message('/connexion-verificateur');

    $a = shortcode_atts([
        'id' => '',           // fallback si pas de query ?id
        'convocation' => '13:15', // heure affichée
        'back' => '/eleves',  // lien retour (facultatif)
    ], $atts, 'fiche_eleve');

    $uid = isset($_GET['id']) ? sanitize_text_field($_GET['id']) : sanitize_text_field($a['id']);
    if (!$uid) {
        return '<p>⚠️ Aucun élève sélectionné.</p>';
    }

    // Infos utilisateur (nom, classe)
    $user = ssr_api("getUserDetailsByNumber", ["internalNumber" => $uid]);
    $name = (($user['naam'] ?? $user['last_name'] ?? '') . ' ' . ($user['voornaam'] ?? $user['first_name'] ?? ''));
    $cls  = ssr_extract_official_class_from_user($user) ?: ($user['class'] ?? '');

    global $wpdb;
    $ver = $wpdb->prefix . "smartschool_retards_verif";

    // Toutes les vérifs connues pour cet élève
    $rows = $wpdb->get_results(
        $wpdb->prepare("
            SELECT date_retard, status, verified_at, verified_by_name
            FROM {$ver}
            WHERE user_identifier = %s
            ORDER BY date_retard DESC, verified_at DESC
        ", $uid),
        ARRAY_A
    );

    // Dédupliquer par date_retard (garder la plus récente vérif pour la date)
    $byDate = [];
    foreach($rows as $r){
        $d = $r['date_retard'];
        if (!isset($byDate[$d])) $byDate[$d] = $r; // la plus récente arrive d'abord
    }
    $rows = array_values($byDate);

    ob_start(); ?>
    <div class="ssr-eleve-fiche" style="max-width:980px;margin:0 auto;">
        <a href="<?php echo esc_url( home_url($a['back']) ); ?>" 
           style="display:inline-block;margin:10px 0 6px;text-decoration:none;">← Retour</a>

        <h2 class="ssr-title" style="font-size:26px;font-weight:800;margin:6px 0 2px;">
            <?php echo esc_html($name ?: "Élève $uid"); ?>
        </h2>
        <p style="margin:0 0 16px;color:#666;">
            Classe : <strong><?php echo esc_html($cls ?: '—'); ?></strong> • ID : <strong><?php echo esc_html($uid); ?></strong>
        </p>

        <table class="ssr-table" style="width:100%;margin:10px 0;">
            <thead>
                <tr>
                    <th>Date du retard</th>
                    <th>Convocation</th>
                    <th>Présent ?</th>
                    <th>Vérifié par</th>
                    <th>Vérifié le</th>
                </tr>
            </thead>
            <tbody>
                <?php if(!$rows): ?>
                    <tr><td colspan="5" style="text-align:center;">Aucun retard vérifié pour cet élève.</td></tr>
                <?php else: ?>
                    <?php foreach($rows as $r): ?>
                        <tr>
                            <td><?php echo esc_html( date('d/m/Y', strtotime($r['date_retard'])) ); ?></td>
                            <td><?php echo esc_html($a['convocation']); ?></td>
                            <td><?php echo $r['status']==='present' ? '✅' : '❌'; ?></td>
                            <td><?php echo esc_html($r['verified_by_name'] ?: '—'); ?></td>
                            <td><?php echo $r['verified_at'] ? esc_html( date('d/m/Y H:i', strtotime($r['verified_at'])) ) : '—'; ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php
    return ob_get_clean();
});;
