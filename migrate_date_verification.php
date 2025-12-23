<?php
/**
 * Script de migration temporaire pour ajouter la colonne date_verification
 * À exécuter UNE SEULE FOIS via l'URL : /wp-content/plugins/Smartschool-retards/migrate_date_verification.php
 */

// Charge WordPress
require_once('../../../wp-load.php');

if (!current_user_can('manage_options')) {
    die('Accès refusé');
}

global $wpdb;
$table = $wpdb->prefix . 'smartschool_retards_verif';

// Vérifie si la table existe
$table_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table));
if (!$table_exists) {
    die("❌ Erreur : La table $table n'existe pas.");
}

// Vérifie si la colonne existe déjà
$column_exists = $wpdb->get_results($wpdb->prepare(
    "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = 'date_verification'",
    DB_NAME,
    $table
));

if (!empty($column_exists)) {
    echo "✅ La colonne 'date_verification' existe déjà dans la table $table\n<br>";
    echo "Rien à faire !";
} else {
    // Ajoute la colonne
    $result = $wpdb->query("ALTER TABLE `$table` ADD COLUMN `date_verification` DATE NULL AFTER `date_retard`");

    if ($result !== false) {
        echo "✅ Colonne 'date_verification' ajoutée avec succès à la table $table\n<br>";
        echo "Vous pouvez maintenant supprimer ce fichier migrate_date_verification.php";
    } else {
        echo "❌ Erreur lors de l'ajout de la colonne :\n<br>";
        echo $wpdb->last_error;
    }
}

// Affiche la structure de la table
echo "\n<br><br><strong>Structure actuelle de la table :</strong>\n<br>";
$columns = $wpdb->get_results("SHOW COLUMNS FROM `$table`");
echo "<pre>";
foreach ($columns as $col) {
    echo $col->Field . " (" . $col->Type . ")\n";
}
echo "</pre>";
