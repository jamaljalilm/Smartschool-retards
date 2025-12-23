<?php
// Script de vérification des retards
require_once('../../../wp-load.php');

if (!current_user_can('manage_options')) {
    die('Accès refusé');
}

global $wpdb;
$table = $wpdb->prefix . 'smartschool_retards_verif';

echo "<h2>Vérification des retards dans la base de données</h2>";

// Retards des 7 derniers jours
$dates = [];
for ($i = 0; $i < 7; $i++) {
    $dates[] = date('Y-m-d', strtotime("-$i days"));
}

echo "<table border='1' cellpadding='5'>";
echo "<tr><th>Date</th><th>Nombre de retards</th><th>Exemple</th></tr>";

foreach ($dates as $date) {
    $count = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM $table WHERE date_retard = %s",
        $date
    ));
    
    $sample = $wpdb->get_row($wpdb->prepare(
        "SELECT first_name, last_name, status FROM $table WHERE date_retard = %s LIMIT 1",
        $date
    ), ARRAY_A);
    
    echo "<tr>";
    echo "<td>$date</td>";
    echo "<td><strong>$count</strong></td>";
    echo "<td>" . ($sample ? $sample['first_name'] . ' ' . $sample['last_name'] . ' (' . $sample['status'] . ')' : '-') . "</td>";
    echo "</tr>";
}

echo "</table>";
