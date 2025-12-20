<?php
/**
 * Script de migration et diagnostic de la base de données
 * À exécuter UNE SEULE FOIS après avoir uploadé les nouveaux fichiers
 *
 * URL: http://votresite.com/wp-content/plugins/Smartschool-retards/migrate-database.php
 */

// Charger WordPress
$wp_load = dirname(dirname(dirname(dirname(__FILE__)))) . '/wp-load.php';
if (!file_exists($wp_load)) {
    die("❌ Erreur : Impossible de trouver wp-load.php");
}
require_once $wp_load;

// Vérifier les permissions
if (!current_user_can('manage_options')) {
    die("❌ Erreur : Vous devez être administrateur pour exécuter ce script");
}

echo "<!DOCTYPE html>
<html>
<head>
    <meta charset='UTF-8'>
    <title>Migration Base de Données - Smartschool Retards</title>
    <style>
        body { font-family: system-ui, sans-serif; max-width: 1000px; margin: 50px auto; padding: 20px; background: #f5f5f5; }
        .box { background: white; padding: 20px; margin: 20px 0; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); }
        h1 { color: #f57c00; }
        h2 { color: #333; border-bottom: 2px solid #f57c00; padding-bottom: 10px; }
        .success { color: #2e7d32; background: #e8f5e9; padding: 10px; border-radius: 4px; margin: 10px 0; }
        .error { color: #c62828; background: #ffebee; padding: 10px; border-radius: 4px; margin: 10px 0; }
        .info { color: #1565c0; background: #e3f2fd; padding: 10px; border-radius: 4px; margin: 10px 0; }
        .warning { color: #f57c00; background: #fff3e0; padding: 10px; border-radius: 4px; margin: 10px 0; }
        table { width: 100%; border-collapse: collapse; margin: 10px 0; }
        th, td { padding: 10px; text-align: left; border-bottom: 1px solid #ddd; }
        th { background: #f5f5f5; font-weight: 600; }
        code { background: #f5f5f5; padding: 2px 6px; border-radius: 3px; font-family: monospace; }
        .btn { display: inline-block; padding: 10px 20px; background: #f57c00; color: white; text-decoration: none; border-radius: 4px; margin: 10px 5px 10px 0; }
        .btn:hover { background: #e56b00; }
    </style>
</head>
<body>
    <h1>🔧 Migration Base de Données - Smartschool Retards</h1>
";

global $wpdb;
$ver = $wpdb->prefix . 'smartschool_retards_verif';

echo "<div class='box'>";
echo "<h2>📊 Étape 1 : Diagnostic de la table</h2>";

// Vérifier que la table existe
$table_exists = $wpdb->get_var($wpdb->prepare(
    "SHOW TABLES LIKE %s",
    $wpdb->esc_like($ver)
)) === $ver;

if (!$table_exists) {
    echo "<div class='error'>❌ La table <code>{$ver}</code> n'existe pas !</div>";
    echo "<p>Veuillez activer le plugin Smartschool Retards d'abord.</p>";
    echo "</body></html>";
    exit;
}

echo "<div class='success'>✅ La table <code>{$ver}</code> existe</div>";

// Récupérer la structure de la table
$columns = $wpdb->get_results($wpdb->prepare(
    "SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = %s
    AND TABLE_NAME = %s
    ORDER BY ORDINAL_POSITION",
    DB_NAME,
    $ver
), ARRAY_A);

echo "<h3>Structure actuelle :</h3>";
echo "<table>";
echo "<tr><th>Colonne</th><th>Type</th><th>Nullable</th><th>Défaut</th></tr>";
foreach ($columns as $col) {
    echo "<tr>";
    echo "<td><code>{$col['COLUMN_NAME']}</code></td>";
    echo "<td>{$col['COLUMN_TYPE']}</td>";
    echo "<td>{$col['IS_NULLABLE']}</td>";
    echo "<td>" . ($col['COLUMN_DEFAULT'] ?: '—') . "</td>";
    echo "</tr>";
}
echo "</table>";

$column_names = array_column($columns, 'COLUMN_NAME');

// Vérifier quelles migrations sont nécessaires
$migrations_needed = [];

if (in_array('date_jour', $column_names) && !in_array('date_retard', $column_names)) {
    $migrations_needed[] = "Renommer <code>date_jour</code> → <code>date_retard</code>";
}

if (in_array('lastname', $column_names) && !in_array('last_name', $column_names)) {
    $migrations_needed[] = "Renommer <code>lastname</code> → <code>last_name</code>";
}

if (in_array('firstname', $column_names) && !in_array('first_name', $column_names)) {
    $migrations_needed[] = "Renommer <code>firstname</code> → <code>first_name</code>";
}

if (in_array('verified_by_id', $column_names) && !in_array('verified_by_code', $column_names)) {
    $migrations_needed[] = "Renommer <code>verified_by_id</code> → <code>verified_by_code</code>";
}

if (!in_array('verified_at', $column_names)) {
    $migrations_needed[] = "Ajouter la colonne <code>verified_at</code>";
}

if (!in_array('status_raw', $column_names)) {
    $migrations_needed[] = "Ajouter la colonne <code>status_raw</code>";
}

echo "</div>";

// Étape 2 : Migrations nécessaires
echo "<div class='box'>";
echo "<h2>🔄 Étape 2 : Migrations nécessaires</h2>";

if (empty($migrations_needed)) {
    echo "<div class='success'>✅ Aucune migration nécessaire ! La table est à jour.</div>";
} else {
    echo "<div class='warning'>⚠️ Migrations à effectuer :</div>";
    echo "<ul>";
    foreach ($migrations_needed as $migration) {
        echo "<li>{$migration}</li>";
    }
    echo "</ul>";

    // Bouton pour exécuter la migration
    if (isset($_GET['execute']) && $_GET['execute'] === 'yes') {
        echo "<div class='info'>🚀 Exécution de la migration...</div>";

        // Charger les fonctions nécessaires
        require_once plugin_dir_path(__FILE__) . 'includes/constants.php';
        require_once plugin_dir_path(__FILE__) . 'includes/db.php';

        // Exécuter les migrations
        ssr_db_migrate_column_names();
        ssr_db_add_status_raw_column();

        echo "<div class='success'>✅ Migration terminée !</div>";
        echo "<p><a href='" . remove_query_arg('execute') . "' class='btn'>🔄 Vérifier le résultat</a></p>";

        // Afficher les logs
        $logs = $wpdb->get_results(
            "SELECT * FROM {$wpdb->prefix}smartschool_retards_log
            WHERE context = 'migration'
            ORDER BY created_at DESC
            LIMIT 10",
            ARRAY_A
        );

        if (!empty($logs)) {
            echo "<h3>📋 Logs de migration :</h3>";
            echo "<table>";
            echo "<tr><th>Date</th><th>Niveau</th><th>Message</th></tr>";
            foreach ($logs as $log) {
                echo "<tr>";
                echo "<td>" . date('d/m/Y H:i:s', strtotime($log['created_at'])) . "</td>";
                echo "<td>{$log['level']}</td>";
                echo "<td>{$log['message']}</td>";
                echo "</tr>";
            }
            echo "</table>";
        }
    } else {
        echo "<p><a href='?execute=yes' class='btn' onclick='return confirm(\"Êtes-vous sûr de vouloir exécuter la migration ?\")'>🚀 Exécuter la migration</a></p>";
    }
}

echo "</div>";

// Étape 3 : Vérification des données
echo "<div class='box'>";
echo "<h2>📈 Étape 3 : Vérification des données</h2>";

$total_records = $wpdb->get_var("SELECT COUNT(*) FROM `{$ver}`");
echo "<div class='info'>ℹ️ Nombre total d'enregistrements : <strong>{$total_records}</strong></div>";

// Compter par date
$by_date = $wpdb->get_results("
    SELECT DATE(created_at) as date, COUNT(*) as count
    FROM `{$ver}`
    GROUP BY DATE(created_at)
    ORDER BY date DESC
    LIMIT 10
", ARRAY_A);

if (!empty($by_date)) {
    echo "<h3>Derniers enregistrements par date :</h3>";
    echo "<table>";
    echo "<tr><th>Date</th><th>Nombre</th></tr>";
    foreach ($by_date as $row) {
        echo "<tr>";
        echo "<td>" . date('d/m/Y', strtotime($row['date'])) . "</td>";
        echo "<td>{$row['count']}</td>";
        echo "</tr>";
    }
    echo "</table>";
}

echo "</div>";

echo "<div class='box'>";
echo "<h2>✅ Terminé</h2>";
echo "<p>Une fois la migration effectuée avec succès, vous pouvez :</p>";
echo "<ul>";
echo "<li>Retourner à l'<a href='" . admin_url() . "'>administration WordPress</a></li>";
echo "<li>Tester le <a href='" . home_url('/recap-retards') . "'>récap retards</a></li>";
echo "<li><strong>IMPORTANT :</strong> Supprimer ce fichier <code>migrate-database.php</code> pour des raisons de sécurité</li>";
echo "</ul>";
echo "</div>";

echo "</body></html>";
