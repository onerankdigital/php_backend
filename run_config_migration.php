<?php
/**
 * Run Form Configurations Migration
 */

require_once __DIR__ . '/config.php';

try {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET,
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
        ]
    );
    
    echo "Running migration: 018_create_form_configurations_table.sql\n\n";
    
    $sql = file_get_contents(__DIR__ . '/migrations/018_create_form_configurations_table.sql');
    $pdo->exec($sql);
    
    echo "✅ Migration completed successfully!\n\n";
    
    // Verify
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM form_configurations");
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    echo "Total configurations in database: " . $result['count'] . "\n";
    
} catch (PDOException $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    exit(1);
}

