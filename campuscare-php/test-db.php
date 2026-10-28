
<?php

require_once __DIR__ . '/config/db.php';

echo 'CampusCare database connection successful!';
echo '<br>Connected database: ' .
    htmlspecialchars($pdo->query('SELECT DATABASE()')->fetchColumn());
