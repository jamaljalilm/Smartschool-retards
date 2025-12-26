<?php
if (!defined('ABSPATH')) exit;

add_shortcode('ssr_suivi', function(){
	if(!ssr_is_logged_in_pin())
        return ssr_locked_message('/connexion-verificateur');

    // Vérifier les permissions d'accès
    if (function_exists('ssr_can_access_suivi') && !ssr_can_access_suivi()) {
        return '<div class="ssr-notice ssr-error" style="padding:20px;border:1px solid #fca5a5;background:#fff1f2;border-radius:8px;color:#991b1b;margin:20px 0;">
            <p style="margin:0;"><strong>Accès refusé</strong></p>
            <p style="margin:10px 0 0;">Vous n\'avez pas la permission d\'accéder à cette page. Contactez un administrateur si vous pensez qu\'il s\'agit d\'une erreur.</p>
        </div>';
    }

    global $wpdb;
    $ver = $wpdb->prefix . "smartschool_retards_verif";
    $log = SSR_T_LOG;

    $period = $_POST['period'] ?? '7';
    $view = $_POST['view'] ?? 'verifications'; // 'verifications' ou 'sanctions'

    $since = ssr_today_be();
    if ($period==='7') $since->modify('-7 days');
    elseif ($period==='30') $since->modify('-30 days');
    else $since->modify('-365 days');

    // Récupérer les vérifications
    $rows = $wpdb->get_results(
        $wpdb->prepare("SELECT * FROM $ver WHERE date_retard >= %s ORDER BY verified_at DESC", $since->format('Y-m-d')),
        ARRAY_A
    );

    // Récupérer les logs de sanctions
    $sanction_logs = $wpdb->get_results(
        $wpdb->prepare("SELECT * FROM $log WHERE context = 'sanctions' AND created_at >= %s ORDER BY created_at DESC", $since->format('Y-m-d H:i:s')),
        ARRAY_A
    );

    ob_start(); ?>
    <style>
        .ssr-suivi-tabs {
            display: flex;
            gap: 10px;
            margin-bottom: 15px;
            border-bottom: 2px solid #e6eaef;
        }
        .ssr-suivi-tab {
            padding: 10px 20px;
            background: transparent;
            border: none;
            cursor: pointer;
            font-size: 15px;
            font-weight: 600;
            color: #666;
            border-bottom: 3px solid transparent;
            transition: all 0.2s;
        }
        .ssr-suivi-tab:hover {
            color: #f57c00;
        }
        .ssr-suivi-tab.active {
            color: #f57c00;
            border-bottom-color: #f57c00;
        }
        .ssr-suivi-content {
            display: none;
        }
        .ssr-suivi-content.active {
            display: block;
        }
    </style>

    <form method="post" style="display:flex;gap:10px;align-items:center;margin:8px 0;">
        <input type="hidden" name="view" id="view-input" value="<?php echo esc_attr($view); ?>">
        <label>Afficher :</label>
        <select name="period">
            <option value="7" <?php selected($period,'7'); ?>>7 jours</option>
            <option value="30" <?php selected($period,'30'); ?>>30 jours</option>
            <option value="year" <?php selected($period,'year'); ?>>Année</option>
        </select>
        <button type="submit" class="button">Filtrer</button>
        <a class="button button-secondary" href="<?php echo esc_url( admin_url('admin-post.php?action=smartschool_export_suivi') ); ?>">Exporter CSV</a>
    </form>

    <div class="ssr-suivi-tabs">
        <button type="button" class="ssr-suivi-tab <?php echo $view === 'verifications' ? 'active' : ''; ?>" data-tab="verifications">
            Vérifications (<?php echo count($rows); ?>)
        </button>
        <button type="button" class="ssr-suivi-tab <?php echo $view === 'sanctions' ? 'active' : ''; ?>" data-tab="sanctions">
            Dates de sanctions (<?php echo count($sanction_logs); ?>)
        </button>
    </div>

    <!-- Onglet Vérifications -->
    <div class="ssr-suivi-content <?php echo $view === 'verifications' ? 'active' : ''; ?>" data-content="verifications">
        <?php if(!$rows): ?>
            <p>Aucune vérification.</p>
        <?php else: ?>
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
        <?php endif; ?>
    </div>

    <!-- Onglet Sanctions -->
    <div class="ssr-suivi-content <?php echo $view === 'sanctions' ? 'active' : ''; ?>" data-content="sanctions">
        <?php if(!$sanction_logs): ?>
            <p>Aucune date de sanction encodée.</p>
        <?php else: ?>
            <table class="ssr-table">
              <thead><tr>
                <th>Date/Heure</th><th>Action</th>
              </tr></thead><tbody>
              <?php foreach($sanction_logs as $log): ?>
              <tr>
                <td style="white-space: nowrap;"><?php echo esc_html(date_i18n('d/m/Y H:i', strtotime($log['created_at']))); ?></td>
                <td><?php echo esc_html($log['message']); ?></td>
              </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
        <?php endif; ?>
    </div>

    <script>
    (function() {
        const tabs = document.querySelectorAll('.ssr-suivi-tab');
        const contents = document.querySelectorAll('.ssr-suivi-content');
        const viewInput = document.getElementById('view-input');

        tabs.forEach(tab => {
            tab.addEventListener('click', function() {
                const targetTab = this.getAttribute('data-tab');

                // Mettre à jour le champ hidden pour la soumission du formulaire
                if (viewInput) {
                    viewInput.value = targetTab;
                }

                // Retirer la classe active de tous les onglets et contenus
                tabs.forEach(t => t.classList.remove('active'));
                contents.forEach(c => c.classList.remove('active'));

                // Ajouter la classe active à l'onglet et au contenu cliqués
                this.classList.add('active');
                const targetContent = document.querySelector('[data-content="' + targetTab + '"]');
                if (targetContent) {
                    targetContent.classList.add('active');
                }
            });
        });
    })();
    </script>

    <?php return ob_get_clean();
});
