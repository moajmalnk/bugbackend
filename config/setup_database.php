<?php
try {
    // Connect to MySQL without selecting a database
    $pdo = new PDO("mysql:host=localhost", "root", "");
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Create database if it doesn't exist
    $sql = "CREATE DATABASE IF NOT EXISTS u262074081_bugfixer_db";
    $pdo->exec($sql);
    echo "Database created successfully\n";

    // Select the database
    $pdo->exec("USE u262074081_bugfixer_db");

    // Read and execute the SQL file
    $sql = file_get_contents(__DIR__ . '/database.sql');
    $pdo->exec($sql);
    echo "Tables created successfully\n";

    echo "Database setup completed successfully!\n";

} catch(PDOException $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?> 