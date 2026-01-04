<?php
/**
 * Run the file_link migration
 */

require_once __DIR__ . '/config.php';

// Database connection
try {
    $dsn = sprintf('mysql:host=%s;dbname=%s;charset=%s', DB_HOST, DB_NAME, DB_CHARSET);
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    
    echo "Connected to database successfully.\n\n";
    
    // Read the migration file
    $migrationFile = __DIR__ . '/migrations/017_add_file_link_to_enquiry_form.sql';
    $sql = file_get_contents($migrationFile);
    
    echo "Running migration: 017_add_file_link_to_enquiry_form.sql\n";
    echo "SQL: $sql\n\n";
    
    // Execute the migration
    $pdo->exec($sql);
    
    echo "✅ Migration completed successfully!\n";
    echo "The 'file_link' column has been added to the 'enquiry_form' table.\n";
    
} catch (PDOException $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    exit(1);
}

