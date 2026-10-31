<?php
// Run migration against the correct database
$host = 'localhost';
$db = 'u814177917_radharani_bill';
$user = 'u814177917_radharani_bill';
$pass = 'radharani@R20262#Dev';

$pdo = new PDO("mysql:host=$host;dbname=$db", $user, $pass);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Run the migration
$pdo->exec("ALTER TABLE bills ADD COLUMN file_name VARCHAR(255) NULL AFTER original_filename;");
$pdo->exec("UPDATE bills SET file_name = original_filename WHERE file_name IS NULL;");

echo "Migration applied successfully!\n";

// Verify
$stmt = $pdo->query("SHOW COLUMNS FROM bills LIKE 'file_name'");
$col = $stmt->fetch();
echo "Column exists: " . ($col ? 'YES' : 'NO') . "\n";