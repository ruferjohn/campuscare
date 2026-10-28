<?php
require_once __DIR__ . '/config/db.php';

try {
    $stmt = $pdo->query("DESCRIBE users");

    echo "<h2>CampusCare Users Table</h2>";
    echo "<table border='1' cellpadding='8'>";
    echo "<tr><th>Column</th><th>Type</th><th>Key</th></tr>";

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        echo "<tr>";
        echo "<td>" . htmlspecialchars($row['Field']) . "</td>";
        echo "<td>" . htmlspecialchars($row['Type']) . "</td>";
        echo "<td>" . htmlspecialchars($row['Key']) . "</td>";
        echo "</tr>";
    }

    echo "</table>";
} catch (PDOException $e) {
    http_response_code(500);
    echo "Could not inspect the users table.";
}


