<?php
/**
 * Run Permissions Migration
 * This script applies the permission system database schema and migrations
 */

require_once __DIR__ . '/config/database.php';

function runMigration() {
    try {
        $db = Database::getInstance();
        $conn = $db->getConnection();
        
        if (!$conn) {
            throw new Exception("Database connection failed");
        }
        
        echo "Starting permissions migration...\n\n";
        
        // Read and execute permissions_schema.sql
        echo "1. Creating tables...\n";
        $schemaFile = __DIR__ . '/config/permissions_schema.sql';
        if (!file_exists($schemaFile)) {
            throw new Exception("Schema file not found: $schemaFile");
        }
        $schemaSQL = file_get_contents($schemaFile);
        $conn->exec($schemaSQL);
        echo "✓ Tables created successfully\n\n";
        
        // Read and execute permissions_seed.sql
        echo "2. Seeding initial data...\n";
        $seedFile = __DIR__ . '/config/permissions_seed.sql';
        if (!file_exists($seedFile)) {
            throw new Exception("Seed file not found: $seedFile");
        }
        $seedSQL = file_get_contents($seedFile);
        
        // Execute seed SQL line by line to handle errors
        $statements = explode(';', $seedSQL);
        foreach ($statements as $statement) {
            $statement = trim($statement);
            if (!empty($statement)) {
                try {
                    $conn->exec($statement);
                } catch (PDOException $e) {
                    // Ignore errors about duplicates
                    $errorMsg = $e->getMessage();
                    if (strpos($errorMsg, 'Duplicate column name') !== false ||
                        strpos($errorMsg, 'Duplicate key') !== false ||
                        strpos($errorMsg, 'already exists') !== false ||
                        strpos($errorMsg, 'Integrity constraint') !== false) {
                        echo "Note: " . $e->getMessage() . "\n";
                        continue;
                    }
                    throw $e;
                }
            }
        }
        echo "✓ Initial data seeded successfully\n\n";
        
        // Read and execute permissions_migration.sql
        echo "3. Migrating users table...\n";
        $migrationFile = __DIR__ . '/config/permissions_migration.sql';
        if (!file_exists($migrationFile)) {
            throw new Exception("Migration file not found: $migrationFile");
        }
        $migrationSQL = file_get_contents($migrationFile);
        
        // Execute migration line by line to handle errors
        $statements = explode(';', $migrationSQL);
        foreach ($statements as $statement) {
            $statement = trim($statement);
            if (!empty($statement)) {
                try {
                    $conn->exec($statement);
                } catch (PDOException $e) {
                    // Ignore errors about columns/constraints already existing
                    $errorMsg = $e->getMessage();
                    if (strpos($errorMsg, 'Duplicate column name') !== false ||
                        strpos($errorMsg, 'Duplicate key') !== false ||
                        strpos($errorMsg, 'already exists') !== false) {
                        echo "Note: " . $e->getMessage() . "\n";
                        continue;
                    }
                    throw $e;
                }
            }
        }
        echo "✓ Users table migrated successfully\n\n";
        
        echo "Migration completed successfully!\n";
        echo "All permission system tables and data are now in place.\n";
        
    } catch (Exception $e) {
        echo "Error: " . $e->getMessage() . "\n";
        exit(1);
    }
}

// Run migration
runMigration();

