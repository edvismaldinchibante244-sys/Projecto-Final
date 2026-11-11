<?php
require __DIR__ . '/../src/public/app_base.php';
require APP_BASE . '/src/config/database.php';

// Simple DB connection test using the project's Database class.
try {
    $db = (new Database())->getConnection();
    echo "DB OK: connected\n";
} catch (Throwable $e) {
    echo "DB EXCEPTION: " . $e->getMessage() . "\n";
}
