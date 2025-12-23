<?php
/**
 * Script pour vider le cache OPcache
 * Accédez à : /wp-content/plugins/Smartschool-retards/clear-cache.php
 */

// Sécurité : vérifier que l'utilisateur est admin ou localhost
$is_localhost = in_array($_SERVER['REMOTE_ADDR'], ['127.0.0.1', '::1']);
$is_logged_in = false;

if (file_exists('../../../wp-load.php')) {
    require_once('../../../wp-load.php');
    $is_logged_in = current_user_can('manage_options');
}

if (!$is_localhost && !$is_logged_in) {
    die('❌ Accès refusé. Vous devez être administrateur.');
}

echo '<h1>Vidage du cache PHP</h1>';

// Vider OPcache
if (function_exists('opcache_reset')) {
    $result = opcache_reset();
    if ($result) {
        echo '<p style="color:green;">✅ <strong>OPcache vidé avec succès !</strong></p>';
    } else {
        echo '<p style="color:red;">❌ Échec du vidage d\'OPcache</p>';
    }
} else {
    echo '<p style="color:orange;">⚠️ OPcache n\'est pas activé</p>';
}

// Afficher l'état d'OPcache
if (function_exists('opcache_get_status')) {
    $status = opcache_get_status(false);
    echo '<h2>État d\'OPcache</h2>';
    echo '<ul>';
    echo '<li>Activé : ' . ($status['opcache_enabled'] ? 'OUI' : 'NON') . '</li>';
    echo '<li>Utilisation mémoire : ' . round($status['memory_usage']['used_memory'] / 1024 / 1024, 2) . ' MB</li>';
    echo '<li>Fichiers en cache : ' . $status['opcache_statistics']['num_cached_scripts'] . '</li>';
    echo '</ul>';
}

echo '<hr>';
echo '<p><a href="javascript:history.back()">← Retour</a></p>';
echo '<p><strong>Maintenant, retournez sur le calendrier et rafraîchissez la page (Ctrl+F5)</strong></p>';
