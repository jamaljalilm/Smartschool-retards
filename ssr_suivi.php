<?php
if (!defined('ABSPATH')) exit;

add_shortcode('ssr_suivi', function(){
	if(!ssr_is_logged_in_pin())
        return ssr_locked_message('/connexion-verificateur');
    global $wpdb;
    $ver = $wpdb->prefix . "smartschool_retards_verif";

    $period = $_POST['period'] ?? '7';
    $since = ssr_today_be();
    if ($period==='7') $since->modify('-7 days');
    elseif ($period==='30') $since->modify('-30 days');
    else $since->modify('-365 days');

    $rows = $wpdb->get_results(
        $wpdb->prepare("SELECT * FROM $ver WHERE date_retard >= %s ORDER BY verified_at DESC", $since->format('Y-m-d')),
        ARRAY_A
    );
    ob_start(); ?>
    <form method="post" style="display:flex;gap:10px;align-items:center;margin:8px 0;">
        <label>Afficher :</label>
        <select name="period">
            <option value="7" <?php selected($period,'7'); ?>>7 jours</option>
            <option value="30" <?php selected($period,'30'); ?>>30 jours</option>
            <option value="year" <?php selected($period,'year'); ?>>Année</option>
        </select>
        <button type="submit" class="button">Filtrer</button>
        <a class="button button-secondary" href="<?php echo esc_url( admin_url('admin-post.php?action=smartschool_export_suivi') ); ?>">Exporter CSV</a>
    </form>
    <?php if(!$rows){ echo '<p>Aucune vérification.</p>'; return ob_get_clean(); } ?>
    <table class="ssr-table">
      <thead><tr>
        <th>Date vérif</th><th>Vérificateur</th><th>PIN</th><th>Classe</th><th>Élève</th><th>Date retard</th><th>Statut</th>
      </tr></thead><tbody>
      <?php foreach($rows as $r): ?>
      <tr>
        <td><?php echo esc_html($r['verified_at']); ?></td>
        <td><?php echo esc_html($r['verified_by_name']); ?></td>
        <td><?php echo esc_html($r['verified_by_code']); ?></td>
        <td><?php echo esc_html($r['class_code']); ?></td>
        <td><?php echo esc_html($r['last_name'].' '.$r['first_name']); ?></td>
        <td><?php echo esc_html($r['date_retard']); ?></td>
        <td><?php echo $r['status']==='present' ? '✅' : '❌'; ?></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php return ob_get_clean();
});;
