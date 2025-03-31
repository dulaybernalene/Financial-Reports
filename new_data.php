<?php
// Add these at the VERY TOP (before any output)
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/php_errors.log');
error_reporting(E_ALL);


require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/config.php'; // Contains your Google API credentials

use Google\Client as Google_Client;
use Google\Service\Sheets as Google_Service_Sheets;
use Google\Service\Drive as Google_Service_Drive;
use Google\Service\Drive\DriveFile as Google_Service_Drive_DriveFile;
use Google\Service\Drive\Permission as Google_Service_Drive_Permission;
use Google\Service\Sheets\ValueRange as Google_Service_Sheets_ValueRange;
use Google\Service\Sheets\BatchUpdateValuesRequest as Google_Service_Sheets_BatchUpdateValuesRequest;
use Google\Service\Sheets\BatchUpdateSpreadsheetRequest as Google_Service_Sheets_BatchUpdateSpreadsheetRequest;



// Helper function to get Google API client
function getGoogleClient() {
    $client = new Google_Client();
    $client->setApplicationName('Your Application Name');
    $client->setScopes([
        Google_Service_Drive::DRIVE,
        Google_Service_Sheets::SPREADSHEETS
    ]);
    
    // Verify credentials path exists
    $credPath = __DIR__ . '/credentials.json';
    if (!file_exists($credPath)) {
        error_log("Credentials file not found at: " . $credPath);
        throw new Exception("Server configuration error");
    }
    
    $client->setAuthConfig($credPath);
    $client->setAccessType('offline');
    
    // Verify token path
    $tokenPath = __DIR__ . '/token.json';
    if (file_exists($tokenPath)) {
        $accessToken = json_decode(file_get_contents($tokenPath), true);
        $client->setAccessToken($accessToken);
        
        // Refresh token if expired
        if ($client->isAccessTokenExpired()) {
            $client->fetchAccessTokenWithRefreshToken($client->getRefreshToken());
            file_put_contents($tokenPath, json_encode($client->getAccessToken()));
        }
    } else {
        error_log("Token file not found at: " . $tokenPath);
        throw new Exception("Authentication required");
    }
    
    return $client;
}

function getSheetsService() {
    $client = new Google_Client();
    $client->setAuthConfig(__DIR__ . '/credentials.json');
    $client->addScope(Google_Service_Sheets::SPREADSHEETS);
    return new Google_Service_Sheets($client);
}

// Get Google Drive service
function getDriveService() {
    $client = new Google_Client();
    $client->setAuthConfig(__DIR__ . '/credentials.json');
    $client->addScope(Google_Service_Drive::DRIVE);
    return new Google_Service_Drive($client);
}

// Configuration
const SHEET_ID = "1WUBrNdwwnx-LdgIvXYKzdKfCN1bRfhucEt3Z9M6hHcA";
const USER_SHEET = 'UserData';
const REGISTER_SHEET = 'RegisteredUserData';
const CATEGORIES_SHEET = 'Categories';
const REQUEST_CATEGORY_SHEET = 'Request Category';
const USER_FOLDER_DATA_SHEET = 'User Folder Data';


// Initialize session
session_start();

// Handle CORS if needed
header('Content-Type: application/json');
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['action']) && $_GET['action'] === 'createFolder') {
    try {
        $input = json_decode(file_get_contents('php://input'), true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception("Invalid JSON input");
        }
        
        $result = createFolder(
            $input['folderName'],
            $input['categoryName'],
            $input['spreadsheetLink'] ?? ''
        );
        
        echo json_encode([
            'success' => true,
            'folderId' => $result
        ]);
        
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => $e->getMessage()
        ]);
        error_log("API Error: " . $e->getMessage());
    }
    exit;
}

function createFolder($folderName, $categoryName, $spreadsheetLink) {
    // Enable error logging
    ini_set('display_errors', 0);
    ini_set('log_errors', 1);
    ini_set('error_log', __DIR__ . '/php_errors.log');
    error_reporting(E_ALL);

    if (!file_exists('db.php')) {
        throw new Exception("Database configuration missing");
    }
    require_once __DIR__ . '/db.php'; // Include your DB helper
    try {
        session_start();
        // Validate session
        if (!isset($_SESSION['user_id'])) {
            throw new Exception("User is not logged in");
        }

        // Validate spreadsheet link
        if (!filter_var($spreadsheetLink, FILTER_VALIDATE_URL)) {
            throw new Exception("Invalid spreadsheet link format");
        }
        
        // Extract spreadsheet ID if you want to store it separately
        $spreadsheetId = '';
        if (preg_match('/\/d\/([a-zA-Z0-9-_]+)/', $spreadsheetLink, $matches)) {
            $spreadsheetId = $matches[1];
        }

        // Validate inputs
        if (empty($folderName) || !is_string($folderName)) {
            throw new Exception("Invalid folder name");
        }
        if (empty($categoryName) || !is_string($categoryName)) {
            throw new Exception("Invalid category name");
        }

        // Initialize services
        $client = getGoogleClient();
        $driveService = new Google_Service_Drive($client);
        $sheetsService = new Google_Service_Sheets($client);

        $sessionUserId = $_SESSION['user_id'];
        $spreadsheetId = "1WUBrNdwwnx-LdgIvXYKzdKfCN1bRfhucEt3Z9M6hHcA"; 
        $registeredSheet = "RegisteredUserData";

        // Retrieve user details with error handling
        try {
            $response = $sheetsService->spreadsheets_values->get($spreadsheetId, "$registeredSheet!A2:Z");
            $userData = $response->getValues();
        } catch (Exception $e) {
            throw new Exception("Failed to retrieve user data: " . $e->getMessage());
        }

        $userEmail = "";
        $firstName = "";
        $lastName = "";

        foreach ($userData as $row) {
            if (!empty($row[0]) && $row[0] == $sessionUserId) {
                $userEmail = $row[4] ?? '';
                $firstName = $row[2] ?? '';
                $lastName = $row[3] ?? '';
                break;
            }
        }

        if (empty($userEmail)) {
            throw new Exception("User email not found in spreadsheet");
        }

        $profileName = trim("$firstName $lastName");

        // Step 1: Handle Category Folder
        try {
            $query = sprintf("name='%s' and mimeType='application/vnd.google-apps.folder' and trashed=false", 
                addslashes($categoryName));
            $existingFolders = $driveService->files->listFiles(['q' => $query])->getFiles();

            if (!empty($existingFolders)) {
                $categoryFolder = $existingFolders[0];
            } else {
                $fileMetadata = new Google_Service_Drive_DriveFile([
                    'name' => $categoryName,
                    'mimeType' => 'application/vnd.google-apps.folder'
                ]);
                $categoryFolder = $driveService->files->create($fileMetadata, ['fields' => 'id']);
            }

            // Share category folder
            $driveService->permissions->create($categoryFolder->id, new Google_Service_Drive_Permission([
                'type' => 'user',
                'role' => 'reader',
                'emailAddress' => $userEmail
            ]));
        } catch (Exception $e) {
            throw new Exception("Failed to create category folder: " . $e->getMessage());
        }

        // Step 2: Create User Folder
        try {
            $userFolderName = $profileName;
            $query = sprintf("name='%s' and '%s' in parents and mimeType='application/vnd.google-apps.folder' and trashed=false", 
                addslashes($userFolderName), 
                $categoryFolder->id);
            
            $userFolders = $driveService->files->listFiles(['q' => $query])->getFiles();

            if (!empty($userFolders)) {
                $userFolder = $userFolders[0];
            } else {
                $fileMetadata = new Google_Service_Drive_DriveFile([
                    'name' => $userFolderName,
                    'mimeType' => 'application/vnd.google-apps.folder',
                    'parents' => [$categoryFolder->id]
                ]);
                $userFolder = $driveService->files->create($fileMetadata, ['fields' => 'id']);
            }

            // Share user folder
            $driveService->permissions->create($userFolder->id, new Google_Service_Drive_Permission([
                'type' => 'user',
                'role' => 'writer',
                'emailAddress' => $userEmail
            ]));
        } catch (Exception $e) {
            throw new Exception("Failed to create user folder: " . $e->getMessage());
        }

        // Step 3: Create Year Subfolder
        try {
            $fileMetadata = new Google_Service_Drive_DriveFile([
                'name' => $folderName,
                'mimeType' => 'application/vnd.google-apps.folder',
                'parents' => [$userFolder->id]
            ]);
            $subFolder = $driveService->files->create($fileMetadata, ['fields' => 'id']);

            // Share subfolder
            $driveService->permissions->create($subFolder->id, new Google_Service_Drive_Permission([
                'type' => 'user',
                'role' => 'writer',
                'emailAddress' => $userEmail
            ]));
        } catch (Exception $e) {
            throw new Exception("Failed to create year folder: " . $e->getMessage());
        }

        // Step 4: Record in Google Sheets
        try {
            $values = [[
                $subFolder->id, 
                $folderName, 
                $categoryName, 
                $spreadsheetLink, 
                $profileName, 
                date("Y-m-d H:i:s")
            ]];
            
            $body = new Google_Service_Sheets_ValueRange(['values' => $values]);
            $sheetsService->spreadsheets_values->append(
                $spreadsheetId, 
                "User Folder Data!A:F", 
                $body, 
                ['valueInputOption' => 'RAW']
            );
        } catch (Exception $e) {
            error_log("Google Sheets save failed: " . $e->getMessage());
        }

        // Step 5: Save to MySQL (now properly nested inside the main try block)
        // Step 5: Save to MySQL with improved error handling
        try {
            if (!file_exists(__DIR__ . '/db.php')) {
                throw new Exception("Database configuration file missing");
            }
            require_once __DIR__ . '/db.php';
            
            $db = new Database();
            $conn = $db->connect();
            
            $query = "INSERT INTO userfolders (
                Folder_Name, 
                Category_Name, 
                SpreadsheetLink, 
                Created_By,
                Created_At
            ) VALUES (
                :folder_name, 
                :category_name, 
                :spreadsheet_link,
                :created_by,
                NOW()
            )";
            
            $stmt = $conn->prepare($query);
            
            $params = [
                ':folder_name' => $folderName,
                ':category_name' => $categoryName,
                ':spreadsheet_link' => $spreadsheetLink,
                ':created_by' => $profileName
            ];
            
            if (!$stmt->execute($params)) {
                $errorInfo = $stmt->errorInfo();
                throw new Exception("Database execute failed: " . $errorInfo[2]);
            }
            
            error_log("MySQL insert successful. Rows affected: " . $stmt->rowCount());
            
        } catch (Exception $e) {
            error_log("MySQL Error: " . $e->getMessage());
            // Continue even if MySQL fails
        }

        return $subFolder->id;

    } catch (Exception $e) {
        error_log("CRITICAL ERROR: " . $e->getMessage());
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => $e->getMessage()
        ]);
        exit;
    }
}

?>