<?php
// Enable error reporting
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Use absolute path to db.php
require_once __DIR__ . '/db.php';

try {
    // 1. Test database connection
    $db = new Database();
    $conn = $db->connect();
    echo "Database connection successful!<br>";

    // 2. Verify userfolders table exists
    $stmt = $conn->query("SHOW TABLES LIKE 'userfolders'");
    if ($stmt->rowCount() === 0) {
        throw new Exception("userfolders table doesn't exist");
    }
    echo "Table 'userfolders' exists!<br>";

    // 3. Check table structure
    $stmt = $conn->query("DESCRIBE userfolders");
    $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
    $required = ['Folder_Name', 'Category_Name', 'SpreadsheetLink', 'Created_By', 'Created_At'];
    
    foreach ($required as $col) {
        if (!in_array($col, $columns)) {
            throw new Exception("Missing column: $col");
        }
    }
    echo "Table structure is correct!<br>";

    // 4. Test insert (optional)
    $testInsert = $conn->prepare("INSERT INTO userfolders 
        (Folder_name, Category_name, SpreadsheetLink, Created_By, Created_At)
        VALUES (?, ?, ?, ?, ?)");
    $testInsert->execute([
        'test_folder', 
        'test_category', 
        'https://test.link', 
        'tester', 
        date('Y-m-d H:i:s')
    ]);
    echo "Test insert successful!<br>";

} catch (Exception $e) {
    die("Error: " . $e->getMessage());
}
?>