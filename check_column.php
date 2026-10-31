<?php
require_once 'app/bootstrap.php';
try {
    $r = Database::fetch('SHOW COLUMNS FROM bills LIKE "file_name"');
    var_dump($r);
} catch (Exception $e) {
    echo 'Error: ' . $e->getMessage();
}