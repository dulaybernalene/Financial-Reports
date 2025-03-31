<?php
// Replace with your service account credentials
require_once __DIR__ . '/vendor/autoload.php'; // Path to autoload.php

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
    $client->setScopes(Google_Service_Drive::DRIVE);
    $client->setAuthConfig('path/to/your/credentials.json');
    $client->setAccessType('offline');
    
    // Load previously authorized token if it exists
    $tokenPath = 'token.json';
    if (file_exists($tokenPath)) {
        $accessToken = json_decode(file_get_contents($tokenPath), true);
        $client->setAccessToken($accessToken);
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

function getUsername($sheetsService) {
    $sessionUserId = $_SESSION['user_id'] ?? null;
    $username = "Guest"; // Default username
    
    if ($sessionUserId) {
        // Get data from RegisteredUserData sheet
        $response = $sheetsService->spreadsheets_values->get(
            SHEET_ID,
            REGISTER_SHEET . '!A2:Z'
        );
        $userData = $response->getValues();

        foreach ($userData as $row) {
            if ($row[0] === $sessionUserId) {
                $username = $row[2] . " " . $row[3]; // First Name + Last Name
                break;
            }
        }
    }
    
    return $username;
}

function renderTemplate($page, $username) {
    $templateFile = __DIR__ . '/templates/' . strtolower($page) . '.php';
    
    if (!file_exists($templateFile)) {
        $templateFile = __DIR__ . '/templates/login.php';
    }

    ob_start();
    include $templateFile;
    $output = ob_get_clean();
    
    return $output;
}

// Main execution
$sheetsService = getSheetsService();
$username = getUsername($sheetsService);

// Determine which page to show
$page = $_GET['page'] ?? 'login';

// Render the appropriate template
switch ($page) {
    case 'register':
        $content = renderTemplate('register', $username);
        $title = 'Register Page';
        break;
    case 'index':
        $content = renderTemplate('index', $username);
        $title = 'Job Order';
        break;
    case 'inventory':
        $content = renderTemplate('inventory', $username);
        $title = 'Inventory';
        break;
    case 'analytics':
        $content = renderTemplate('analytics', $username);
        $title = 'Analytics';
        break;
    case 'cash-disbursement':
        $content = renderTemplate('cash-disbursement', $username);
        $title = 'Cash Disbursement';
        break;
    case 'templates':
        $content = renderTemplate('templates', $username);
        $title = 'Templates';
        break;
    default:
        $content = renderTemplate('login', '');
        $title = 'Login Page';
}
// Function to create folders
function createFolder($folderName, $categoryName, $spreadsheetLink) {
    session_start();
    
    if (!isset($_SESSION['user_id'])) {
        return "Error: User is not logged in.";
    }

    $client = getGoogleClient();
    $driveService = new Google_Service_Drive($client);
    $sheetsService = new Google_Service_Sheets($client);

    $sessionUserId = $_SESSION['user_id'];
    $spreadsheetId = "1WUBrNdwwnx-LdgIvXYKzdKfCN1bRfhucEt3Z9M6hHcA"; 
    $registeredSheet = "RegisteredUserData";

    // Retrieve user details
    $response = $sheetsService->spreadsheets_values->get($spreadsheetId, "$registeredSheet!A2:Z");
    $userData = $response->getValues();
    
    $userEmail = "";
    $firstName = "";
    $lastName = "";

    foreach ($userData as $row) {
        if ($row[0] == $sessionUserId) {
            $userEmail = $row[4];
            $firstName = $row[2];
            $lastName = $row[3];
            break;
        }
    }

    if (!$userEmail) {
        return "Error: User not found.";
    }

    $profileName = "$firstName $lastName";

    // Step 1: Check if category folder exists
    $categoryFolder = null;
    $query = "name='$categoryName' and mimeType='application/vnd.google-apps.folder' and trashed=false";
    $existingFolders = $driveService->files->listFiles(['q' => $query])->getFiles();

    if (!empty($existingFolders)) {
        $categoryFolder = $existingFolders[0];
    } else {
        // Create category folder
        $fileMetadata = new Google_Service_Drive_DriveFile([
            'name' => $categoryName,
            'mimeType' => 'application/vnd.google-apps.folder'
        ]);
        $categoryFolder = $driveService->files->create($fileMetadata, ['fields' => 'id']);
    }

    // Share category folder with user
    $driveService->permissions->create($categoryFolder->id, new Google_Service_Drive_Permission([
        'type' => 'user',
        'role' => 'reader',
        'emailAddress' => $userEmail
    ]));

    // Step 2: Create User Folder
    $userFolderName = "$firstName $lastName";
    $query = "name='$userFolderName' and '$categoryFolder->id' in parents and mimeType='application/vnd.google-apps.folder' and trashed=false";
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

    // Share user folder with user as EDITOR
    $driveService->permissions->create($userFolder->id, new Google_Service_Drive_Permission([
        'type' => 'user',
        'role' => 'writer',
        'emailAddress' => $userEmail
    ]));

    // Step 3: Create Subfolder
    $fileMetadata = new Google_Service_Drive_DriveFile([
        'name' => $folderName,
        'mimeType' => 'application/vnd.google-apps.folder',
        'parents' => [$userFolder->id]
    ]);
    $subFolder = $driveService->files->create($fileMetadata, ['fields' => 'id']);

    // Share subfolder with user as EDITOR
    $driveService->permissions->create($subFolder->id, new Google_Service_Drive_Permission([
        'type' => 'user',
        'role' => 'writer',
        'emailAddress' => $userEmail
    ]));

    // Step 4: Save folder data in Google Sheets
    $userSheet = "User Folder Data";
    $values = [[$subFolder->id, $folderName, $categoryName, $spreadsheetLink, $profileName, date("Y-m-d H:i:s")]];
    $body = new Google_Service_Sheets_ValueRange(['values' => $values]);

    $sheetsService->spreadsheets_values->append($spreadsheetId, "$userSheet!A:F", $body, ['valueInputOption' => 'RAW']);

    return $subFolder->id;
}

function getUserFolders($profileName, $categoryName = null) {
    $client = getGoogleClient();
    $sheetsService = new Google_Service_Sheets($client);

    $spreadsheetId = "1WUBrNdwwnx-LdgIvXYKzdKfCN1bRfhucEt3Z9M6hHcA"; 
    $userSheet = "User Folder Data";

    $response = $sheetsService->spreadsheets_values->get($spreadsheetId, "$userSheet!A2:Z");
    $data = $response->getValues();

    if (!$data) {
        return [];
    }

    // Get headers
    $headers = ["Folder ID", "Folder Name", "Category Name", "Spreadsheet Link", "Created By", "Created At"];
    $createdByIndex = array_search("Created By", $headers);
    $categoryIndex = array_search("Category Name", $headers);
    $folderIdIndex = array_search("Folder ID", $headers);
    $folderNameIndex = array_search("Folder Name", $headers);
    $spreadsheetLinkIndex = array_search("Spreadsheet Link", $headers);

    $folders = [];
    foreach ($data as $row) {
        if (strtolower($row[$createdByIndex]) !== strtolower($profileName)) {
            continue;
        }
        if ($categoryName && strtolower($row[$categoryIndex]) !== strtolower($categoryName)) {
            continue;
        }

        $folders[] = [
            'id' => $row[$folderIdIndex],
            'name' => $row[$folderNameIndex],
            'category' => $row[$categoryIndex] ?? "Uncategorized",
            'spreadsheetLink' => $row[$spreadsheetLinkIndex] ?? ""
        ];
    }

    return $folders;
}
function createTemplateInFolder($driveService, $sheetsService, $folderId, $templateName, $spreadsheetURL) {
    try {
        // Extract File ID from URL
        preg_match('/[-\w]{25,}/', $spreadsheetURL, $match);
        if (!$match) {
            return json_encode(['error' => 'Invalid spreadsheet URL']);
        }
        $templateFileId = $match[0];

        // Get the template file
        $templateFile = $driveService->files->get($templateFileId);
        if (!$templateFile) {
            return json_encode(['error' => 'Template not found']);
        }

        // Create a copy in the target folder
        $copyMetadata = new Google_Service_Drive_DriveFile([
            'name' => $templateName,
            'parents' => [$folderId]
        ]);
        $newFile = $driveService->files->copy($templateFileId, $copyMetadata);
        if (!$newFile) {
            return json_encode(['error' => 'Error copying template']);
        }

        // Open the copied spreadsheet
        $spreadsheet = $sheetsService->spreadsheets->get($newFile->getId());
        $sheetId = $spreadsheet->getSheets()[0]->getProperties()->getSheetId();

        // Extract month from filename
        $monthName = extractMonthFromFilename($templateName);
        if (!$monthName) {
            return json_encode(['error' => 'Could not determine the month from filename']);
        }

        // Overwrite dates in the first sheet
        $currentYear = date('Y');
        $monthIndex = getMonthIndex($monthName);
        if ($monthIndex === -1) {
            return json_encode(['error' => 'Invalid month in filename']);
        }

        overwriteDates($sheetsService, $newFile->getId(), $sheetId, $currentYear, $monthIndex);

        return json_encode([
            'success' => "Created '$templateName' in folder with updated dates.",
            'fileId' => $newFile->getId()
        ]);
    } catch (Exception $e) {
        return json_encode(['error' => $e->getMessage()]);
    }
}
function extractMonthFromFilename($filename) {
    $months = ["January", "February", "March", "April", "May", "June",
               "July", "August", "September", "October", "November", "December"];
    
    foreach ($months as $month) {
        if (strpos($filename, $month) !== false) {
            return $month;
        }
    }
    return null;
}
// ✅ FIXED: Get month index (0 = January, 11 = December)
function getMonthIndex($monthName) {
    $months = [
        "January", "February", "March", "April", "May", "June",
        "July", "August", "September", "October", "November", "December"
    ];
    return array_search($monthName, $months); // Returns false if not found
}

// Function to overwrite dates in the spreadsheet
function overwriteDates($sheetId, $sheetName, $year, $monthIndex) {
    $service = getSheetsService();
    $range = "$sheetName!A:A";
    
    // Retrieve existing dates from column A
    $response = $service->spreadsheets_values->get($sheetId, $range);
    $values = $response->getValues();
    
    $firstDate = new DateTime("$year-" . ($monthIndex + 1) . "-01");
    $currentDate = clone $firstDate;
    
    $updates = [];
    
    for ($i = 0; $i < count($values); $i++) {
        $cellValue = $values[$i][0] ?? '';
        
        if (strtotime($cellValue)) {
            $cellDate = new DateTime($cellValue);
            $cellMonth = (int) $cellDate->format("m") - 1;
            
            if ($cellMonth !== $monthIndex) {
                // Clear incorrect dates
                $updates[] = ["", $i + 1];
            } elseif ($currentDate->format("m") - 1 === $monthIndex) {
                // Insert correct dates
                $updates[] = [$currentDate->format("Y-m-d"), $i + 1];
                $currentDate->modify('+1 day');
            }
        }
    }
    
    // Prepare batch update request
    $data = [];
    foreach ($updates as [$value, $row]) {
        $data[] = [
            "range" => "$sheetName!A$row",
            "values" => [[$value]]
        ];
    }

    if (!empty($data)) {
        $body = new Google_Service_Sheets_BatchUpdateValuesRequest([
            "valueInputOption" => "RAW",
            "data" => $data
        ]);
        $service->spreadsheets_values->batchUpdate($sheetId, $body);
    }
}

// Example usage
$monthName = "March";
$monthIndex = getMonthIndex($monthName);
if ($monthIndex !== false) {
    overwriteDates(SHEET_ID, "Sheet1", 2025, $monthIndex);
}
// Helper function to locate the first date row (assumes dates are in column A)
function findFirstDateRow($sheetId, $sheetName) {
    $service = getSheetsService();
    $range = "$sheetName!A:A";
    
    $response = $service->spreadsheets_values->get($sheetId, $range);
    $values = $response->getValues();

    foreach ($values as $index => $row) {
        if (!empty($row[0]) && strtotime($row[0])) {
            return $index + 1; // Convert to 1-based index
        }
    }
    return 2; // Default to second row if no existing date is found
}

// Helper function to format dates as needed
function formatDate($date) {
    return date("m/d/Y", strtotime($date));
}

// Function to get files in a folder
function getFilesInFolder($folderId) {
    try {
        $service = getDriveService();
        $files = [];
        
        $query = "'$folderId' in parents and trashed = false";
        $response = $service->files->listFiles([
            'q' => $query,
            'fields' => 'files(id, name, webViewLink, parents)'
        ]);

        foreach ($response->getFiles() as $file) {
            $files[] = [
                'id' => $file->getId(),
                'name' => $file->getName(),
                'url' => $file->getWebViewLink(),
                'folderName' => $folderId  // Folder name lookup would require an extra request
            ];
        }

        return $files;
    } catch (Exception $e) {
        error_log("Error in getFilesInFolder: " . $e->getMessage());
        return [];
    }
}

// Function to get dropdown data from a sheet
function getDropdownData($sheetId, $sheetName) {
    try {
        $service = getSheetsService();
        $range = "$sheetName!A2:B"; // Get columns A (Name) and B (Link)
        
        $response = $service->spreadsheets_values->get($sheetId, $range);
        $values = $response->getValues();

        $dropdownData = [];
        foreach ($values as $row) {
            $dropdownData[] = [
                'name' => $row[0] ?? '',
                'link' => $row[1] ?? '#'
            ];
        }

        return $dropdownData;
    } catch (Exception $e) {
        return [['name' => 'Error fetching data', 'link' => '#']];
    }
}

// Example usage
$sheetName = "Sheet1";
$firstDateRow = findFirstDateRow(SHEET_ID, $sheetName);
$files = getFilesInFolder("your-folder-id");
$dropdownData = getDropdownData(SHEET_ID, $sheetName);

// Debugging output
var_dump($firstDateRow, $files, $dropdownData);
/**
 * Adds a new category to the spreadsheet
 */
function addCategory($categoryName, $spreadsheetLink) {
    try {
        $service = getSheetsService();
        
        // Check if category already exists
        $range = CATEGORIES_SHEET . "!A2:A";
        $response = $service->spreadsheets_values->get(SHEET_ID, $range);
        $values = $response->getValues();
        
        if ($values) {
            foreach ($values as $row) {
                if (strtolower($row[0]) === strtolower($categoryName)) {
                    return "Error: Category already exists!";
                }
            }
        }
        
        // Add new category
        $range = CATEGORIES_SHEET . "!A:C";
        $valueRange = new Google_Service_Sheets_ValueRange();
        $valueRange->setValues([
            [$categoryName, $spreadsheetLink, date('Y-m-d H:i:s')]
        ]);
        $params = [
            'valueInputOption' => 'RAW',
            'insertDataOption' => 'INSERT_ROWS'
        ];
        $service->spreadsheets_values->append(SHEET_ID, $range, $valueRange, $params);
        
        // Add to request category sheet
        $userEmail = $_SESSION['user_email'] ?? 'Unknown'; // Get from your authentication system
        
        $range = REQUEST_CATEGORY_SHEET . "!A:D";
        $valueRange = new Google_Service_Sheets_ValueRange();
        $valueRange->setValues([
            [$categoryName, $spreadsheetLink, $userEmail, "Pending"]
        ]);
        $service->spreadsheets_values->append(SHEET_ID, $range, $valueRange, $params);
        
        return "Category added successfully!";
    } catch (Exception $e) {
        return "Error: " . $e->getMessage();
    }
}
/**
 * Renames a folder in Drive and updates the spreadsheet
 */
function renameFolder($folderId, $newName, $categoryName) {
    try {
        $driveService = getDriveService();
        $sheetsService = getSheetsService();
        
        // Validate inputs
        if (empty($folderId) || empty($newName) || empty($categoryName)) {
            return ['success' => false, 'message' => "Missing required parameters"];
        }
        
        // 1. Rename the Drive folder
        try {
            $file = new Google_Service_Drive_DriveFile();
            $file->setName($newName);
            $driveService->files->update($folderId, $file);
        } catch (Exception $e) {
            return ['success' => false, 'message' => "Folder not found in Google Drive"];
        }
        
        // 2. Update the spreadsheet record
        $range = USER_FOLDER_DATA_SHEET . "!A1:Z";
        $response = $sheetsService->spreadsheets_values->get(SHEET_ID, $range);
        $values = $response->getValues();
        
        if (empty($values)) {
            return ['success' => false, 'message' => "User Folder Data sheet not found"];
        }
        
        $headers = $values[0];
        $folderIdIndex = array_search("Folder ID", $headers);
        $folderNameIndex = array_search("Folder Name", $headers);
        $categoryIndex = array_search("Category Name", $headers);
        
        if ($folderIdIndex === false || $folderNameIndex === false || $categoryIndex === false) {
            return ['success' => false, 'message' => "Required columns not found in sheet"];
        }
        
        $found = false;
        $rowNumber = 0;
        foreach ($values as $index => $row) {
            if ($index === 0) continue; // Skip header row
            
            if ((isset($row[$folderIdIndex]) && $row[$folderIdIndex] === $folderId) && (isset($row[$categoryIndex])) && $row[$categoryIndex] === $categoryName) {
                $found = true;
                $rowNumber = $index + 1; // Convert to 1-based index
                break;
            }
        }
        
        if (!$found) {
            return ['success' => false, 'message' => "Folder record not found in spreadsheet"];
        }
        
        // Update the specific cell
        $range = USER_FOLDER_DATA_SHEET . "!" . chr(65 + $folderNameIndex) . $rowNumber;
        $valueRange = new Google_Service_Sheets_ValueRange();
        $valueRange->setValues([[ $newName ]]);
        $params = ['valueInputOption' => 'RAW'];
        $sheetsService->spreadsheets_values->update(SHEET_ID, $range, $valueRange, $params);
        
        return ['success' => true, 'message' => "Folder renamed successfully in both Drive and Spreadsheet"];
        
    } catch (Exception $e) {
        return ['success' => false, 'message' => "Error: " . $e->getMessage()];
    }
}
/**
 * Deletes a folder from Drive and removes its record from the spreadsheet
 */
function deleteFolder($folderId, $categoryName) {
    try {
        $driveService = getDriveService();
        $sheetsService = getSheetsService();
        
        // 1. Move folder to trash in Drive
        try {
            $file = new Google_Service_Drive_DriveFile();
            $file->setTrashed(true);
            $driveService->files->update($folderId, $file);
        } catch (Exception $e) {
            error_log("Drive folder not found or already deleted: " . $e->getMessage());
        }
        
        // 2. Delete from spreadsheet
        $range = USER_FOLDER_DATA_SHEET . "!A1:Z";
        $response = $sheetsService->spreadsheets_values->get(SHEET_ID, $range);
        $values = $response->getValues();
        
        if (empty($values)) {
            return ['success' => false, 'message' => "Spreadsheet error: Sheet not found"];
        }
        
        $headers = $values[0];
        $folderIdIndex = array_search("Folder ID", $headers);
        $categoryIndex = array_search("Category Name", $headers);
        
        if ($folderIdIndex === false || $categoryIndex === false) {
            return ['success' => false, 'message' => "Required columns not found in sheet"];
        }
        
        $rowsToDelete = [];
        foreach ($values as $index => $row) {
            if ($index === 0) continue; // Skip header row
            
            if ((isset($row[$folderIdIndex]) && $row[$folderIdIndex] == $folderId) && 
                (isset($row[$categoryIndex])) && $row[$categoryIndex] == $categoryName) {
                $rowsToDelete[] = $index + 1; // Convert to 1-based index
            }
        }
        
        if (empty($rowsToDelete)) {
            return ['success' => false, 'message' => "Folder not found in spreadsheet"];
        }
        
        // Sort in descending order to delete from bottom up
        rsort($rowsToDelete);
        
        // Prepare batch update request to delete rows
        $requests = [];
        foreach ($rowsToDelete as $rowIndex) {
            $requests[] = [
                'deleteDimension' => [
                    'range' => [
                        'sheetId' => getSheetIdByName($sheetsService, USER_FOLDER_DATA_SHEET),
                        'dimension' => 'ROWS',
                        'startIndex' => $rowIndex - 1, // 0-based
                        'endIndex' => $rowIndex
                    ]
                ]
            ];
        }
        
        $batchUpdateRequest = new Google_Service_Sheets_BatchUpdateSpreadsheetRequest([
            'requests' => $requests
        ]);
        
        $sheetsService->spreadsheets->batchUpdate(SHEET_ID, $batchUpdateRequest);
        
        return ['success' => true, 'message' => "Folder deleted successfully"];
        
    } catch (Exception $e) {
        return ['success' => false, 'message' => "Error: " . $e->getMessage()];
    }
}
/**
 * Helper function to get sheet ID by name
 */
function getSheetIdByName($service, $sheetName) {
    $spreadsheet = $service->spreadsheets->get(SHEET_ID);
    foreach ($spreadsheet->getSheets() as $sheet) {
        if ($sheet->getProperties()->getTitle() == $sheetName) {
            return $sheet->getProperties()->getSheetId();
        }
    }
    return null;
}
function renameFile($fileId, $newName) {
    try {
        $driveService = getDriveService();
        $sheetsService = getSheetsService();
        
        // Sheet configuration (moved inside function)
        $sheetId = "1i73R9Pd4hHlKWvnqVz2cydCneohIW93zQjZx2D19-NE";
        $userFileDataSheet = 'User File Data';
        
        $file = $driveService->files->get($fileId);
        $oldName = $file->getName();
        
        if ($oldName === $newName) {
            return ['success' => true, 'message' => "No change needed"];
        }
        
        $updatedFile = new Google_Service_Drive_DriveFile();
        $updatedFile->setName($newName);
        $driveService->files->update($fileId, $updatedFile);
        
        $range = $userFileDataSheet . "!A1:Z";
        $response = $sheetsService->spreadsheets_values->get($sheetId, $range);
        $values = $response->getValues();
        
        if (!empty($values)) {
            $headers = $values[0];
            $fileIdCol = array_search("File ID", $headers);
            $fileNameCol = array_search("File Name", $headers);
            
            if ($fileIdCol !== false && $fileNameCol !== false) {
                foreach ($values as $index => $row) {
                    if ($index === 0) continue;
                    
                    if (isset($row[$fileIdCol]) && $row[$fileIdCol] === $fileId) {
                        $range = $userFileDataSheet . "!" . chr(65 + $fileNameCol) . ($index + 1);
                        $valueRange = new Google_Service_Sheets_ValueRange();
                        $valueRange->setValues([[ $newName ]]);
                        $params = ['valueInputOption' => 'RAW'];
                        $sheetsService->spreadsheets_values->update($sheetId, $range, $valueRange, $params);
                        break;
                    }
                }
            }
        }
        
        return ['success' => true, 'message' => "File renamed successfully"];
    } catch (Exception $e) {
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

function deleteFile($fileId) {
    try {
        $driveService = getDriveService();
        $sheetsService = getSheetsService();
        
        // Sheet configuration (moved inside function)
        $sheetId = "1i73R9Pd4hHlKWvnqVz2cydCneohIW93zQjZx2D19-NE";
        $userFileDataSheet = 'User File Data';
        
        $updatedFile = new Google_Service_Drive_DriveFile();
        $updatedFile->setTrashed(true);
        $driveService->files->update($fileId, $updatedFile);
        
        $range = $userFileDataSheet . "!A1:Z";
        $response = $sheetsService->spreadsheets_values->get($sheetId, $range);
        $values = $response->getValues();
        
        if (!empty($values)) {
            $headers = $values[0];
            $fileIdCol = array_search("File ID", $headers);
            
            if ($fileIdCol !== false) {
                $rowsToDelete = [];
                foreach ($values as $index => $row) {
                    if ($index === 0) continue;
                    
                    if (isset($row[$fileIdCol]) && $row[$fileIdCol] === $fileId) {
                        $rowsToDelete[] = $index + 1;
                    }
                }
                
                rsort($rowsToDelete);
                
                $requests = [];
                foreach ($rowsToDelete as $rowIndex) {
                    $requests[] = [
                        'deleteDimension' => [
                            'range' => [
                                'sheetId' => getSheetIdByName($sheetsService, $userFileDataSheet, $sheetId),
                                'dimension' => 'ROWS',
                                'startIndex' => $rowIndex - 1,
                                'endIndex' => $rowIndex
                            ]
                        ]
                    ];
                }
                
                $batchUpdateRequest = new \Google\Service\Sheets\BatchUpdateSpreadsheetRequest([
                    'requests' => $requests
                ]);
                
                $sheetsService->spreadsheets->batchUpdate($sheetId, $batchUpdateRequest);
            }
        }
        
        return ['success' => true, 'message' => "File deleted successfully"];
    } catch (Exception $e) {
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

function getTotalsByDate() {
    try {
        $sheetsService = getSheetsService();
        
        // Sheet configuration (moved inside function)
        $sheetId = "1i73R9Pd4hHlKWvnqVz2cydCneohIW93zQjZx2D19-NE";
        $ordersSheet = 'Orders';
        
        $range = $ordersSheet . "!A1:Z";
        $response = $sheetsService->spreadsheets_values->get($sheetId, $range);
        $values = $response->getValues();
        
        if (empty($values)) {
            return ['success' => false, 'message' => "No data found in the sheet"];
        }
        
        $headers = $values[0];
        $timestampIndex = array_search("Timestamp", $headers);
        $totalIndex = array_search("Total", $headers);
        
        if ($timestampIndex === false || $totalIndex === false) {
            return ['success' => false, 'message' => "Required columns not found"];
        }
        
        $totalsByDate = [];
        
        foreach ($values as $index => $row) {
            if ($index === 0) continue;
            
            $timestamp = $row[$timestampIndex] ?? null;
            $total = floatval($row[$totalIndex] ?? 0);
            
            if (empty($timestamp)) continue;
            
            try {
                $date = new DateTime($timestamp);
                $dateStr = $date->format('Y-m-d');
                $totalsByDate[$dateStr] = ($totalsByDate[$dateStr] ?? 0) + $total;
            } catch (Exception $e) {
                error_log("Invalid date format in row $index: " . $timestamp);
                continue;
            }
        }
        
        return ['success' => true, 'data' => $totalsByDate];
    } catch (Exception $e) {
        return ['success' => false, 'message' => $e->getMessage()];
    }
}
/**
 * Extracts specified columns from a sheet and sums values by date.
 */
function extractAndFilterDates($fileId) {
    try {
        if (!$fileId || !is_string($fileId) || trim($fileId) === "") { 
            error_log("Invalid fileId: " . $fileId);
            return [];
        }

        error_log("Opening file: " . $fileId);

        $driveService = getDriveService();
        $sheetsService = getSheetsService();

        try {
            $file = $driveService->files->get($fileId);
        } catch (Exception $e) {
            error_log("File not found with ID: " . $fileId);
            return [];
        }

        try {
            $spreadsheet = $sheetsService->spreadsheets->get($fileId);
        } catch (Exception $e) {
            error_log("Could not open spreadsheet: " . $fileId);
            return [];
        }

        $sheets = $spreadsheet->getSheets();
        if (empty($sheets)) {
            error_log("No sheets found in the spreadsheet.");
            return [];
        }

        $sheet = $sheets[0];
        $sheetTitle = $sheet->getProperties()->getTitle();
        $range = $sheetTitle . '!A1:Z';
        $response = $sheetsService->spreadsheets_values->get($fileId, $range);
        $data = $response->getValues();

        $extractedDates = [];
        foreach ($data as $row) {
            foreach ($row as $cell) {
                if (strtotime($cell) !== false) {
                    $date = new DateTime($cell);
                    $formattedDate = $date->format('Y-m-d');
                    if (!in_array($formattedDate, $extractedDates)) {
                        $extractedDates[] = $formattedDate;
                    }
                }
            }
        }

        error_log("Extracted Dates: " . implode(', ', $extractedDates));

        $totalsByDate = getTotalsByDate();
        $jobOrdersData = getJobOrdersTotalsByDate();
        $jobOrdersDiscountData = getJobOrdersDiscountTotalsByDate();
        $cashDisbursementsData = getCashDisbursementTotalsByDate();

        $filteredTotals = [];
        $grandTotal = 0;
        $grandDiscountTotal = 0;

        foreach ($extractedDates as $date) {
            $totalFromOrders = $totalsByDate[$date] ?? 0;
            $jobOrderTotal = isset($jobOrdersData[$date]['grossSales']) ? $jobOrdersData[$date]['grossSales'] : 0;
            $jobOrderDiscount = $jobOrdersDiscountData[$date] ?? 0;
            $cashDisbursementTotal = $cashDisbursementsData[$date] ?? 0;

            $sumTotal = $totalFromOrders + $jobOrderTotal + $cashDisbursementTotal;
            $grandTotal += $sumTotal;
            $grandDiscountTotal += $jobOrderDiscount;

            $filteredTotals[$date] = [
                'totalFromOrders' => $totalFromOrders,
                'jobOrderTotal' => $jobOrderTotal,
                'cashDisbursementTotal' => $cashDisbursementTotal,
                'sumTotal' => $sumTotal,
                'discount' => $jobOrderDiscount
            ];
        }

        error_log("=== FINAL TOTALS ===");
        error_log("Grand Total: " . $grandTotal);
        error_log("Grand Discount Total: " . $grandDiscountTotal);
        error_log("Grand Net Total: " . ($grandTotal - $grandDiscountTotal));

        pasteSumTotalToSheet($fileId, $filteredTotals);
        error_log("Data pasted to sheet for fileId: " . $fileId);

        return [
            'filteredTotals' => $filteredTotals,
            'grandTotal' => $grandTotal,
            'grandDiscountTotal' => $grandDiscountTotal,
            'grandNetTotal' => $grandTotal - $grandDiscountTotal
        ];
    } catch (Exception $error) {
        error_log("Error in extractAndFilterDates: " . $error->getMessage());
        return [];
    }
}
function pasteSumTotalToSheet($fileId, $filteredTotals) {
    try {
        if (!$fileId || !is_string($fileId) || trim($fileId) === "") {
            error_log("Invalid fileId provided: " . $fileId);
            return;
        }

        error_log("Opening file with ID: " . $fileId);
        
        $sheetsService = getSheetsService();
        $driveService = getDriveService();

        // Get spreadsheet metadata
        try {
            $spreadsheet = $sheetsService->spreadsheets->get($fileId);
        } catch (Exception $e) {
            error_log("Could not open spreadsheet: " . $fileId);
            return;
        }

        // Get the sheet by name
        $sheetName = "SMS Sales Report";
        $range = "{$sheetName}!A1:Z";
        $response = $sheetsService->spreadsheets_values->get($fileId, $range);
        if (!$response) {
            error_log("SMS Sales Report sheet not found.");
            return;
        }

        // Get the parent folder name (year)
        try {
            $file = $driveService->files->get($fileId, ['fields' => 'parents, name']);
            $parentId = $file->getParents()[0] ?? null;
            $filename = $file->getName();
        } catch (Exception $e) {
            error_log("Error retrieving file details.");
            return;
        }

        if (!$parentId) {
            error_log("File is not in any folder.");
            return;
        }

        try {
            $folder = $driveService->files->get($parentId, ['fields' => 'name']);
            $folderName = $folder->getName();
        } catch (Exception $e) {
            error_log("Error retrieving parent folder details.");
            return;
        }

        // Extract year from folder name
        if (preg_match('/(20\d{2})/', $folderName, $matches)) {
            $folderYear = (int) $matches[0];
            error_log("Using year from folder: " . $folderYear);
        } else {
            error_log("No year found in folder name: " . $folderName);
            return;
        }

        // Extract month from filename
        $months = [
            "January", "February", "March", "April", "May", "June", 
            "July", "August", "September", "October", "November", "December"
        ];

        $folderMonth = null;
        foreach ($months as $index => $month) {
            if (stripos($filename, $month) !== false) {
                $folderMonth = $index;
                break;
            }
        }

        if ($folderMonth === null) {
            error_log("Could not determine month from filename: " . $filename);
            return;
        }

        // Define week ranges
        $weekRanges = [
            "1" => ["A5:A11", "F5:F11", "G5:G11"],
            "2" => ["A16:A22", "F16:F22", "G16:G22"],
            "3" => ["A27:A33", "F27:F33", "G27:G33"],
            "4" => ["A38:A44", "F38:F44", "G38:G44"],
            "5" => ["A49:A55", "F49:F55", "G49:G55"]
        ];

        // Filter and sort totals to match both month and year
        $sortedTotals = [];
        foreach ($filteredTotals as $dateStr => $totals) {
            $dateParts = explode('-', $dateStr);
            if (count($dateParts) !== 3) {
                error_log("Invalid date format: " . $dateStr);
                continue;
            }

            $year = (int) $dateParts[0];
            $month = (int) $dateParts[1] - 1; // Months are 0-indexed
            $day = (int) $dateParts[2];

            if ($year === $folderYear && $month === $folderMonth) {
                $adjustedDate = new DateTime("$folderYear-" . ($month + 1) . "-$day");
                $sortedTotals[] = [$adjustedDate, $totals];
            }
        }

        usort($sortedTotals, function ($a, $b) {
            return $a[0] <=> $b[0];
        });

        error_log("Filtered totals for " . ($folderMonth + 1) . "/" . $folderYear . ": " . print_r($sortedTotals, true));

        $updates = [];
        $index = 0;
        foreach ($weekRanges as $week => [$dateRange, $salesRange, $discountRange]) {
            if ($index >= count($sortedTotals)) break;

            // Prepare new values
            $newDates = [];
            $newSales = [];
            $newDiscounts = [];

            for ($i = 0; $i < 7; $i++) {
                if ($index < count($sortedTotals)) {
                    [$date, $totals] = $sortedTotals[$index];
                    $newDates[] = [$date->format('Y-m-d')];
                    $newSales[] = [$totals['sumTotal'] ?? 0];
                    $newDiscounts[] = [$totals['discount'] ?? 0];
                    $index++;
                } else {
                    $newDates[] = [""];
                    $newSales[] = [0];
                    $newDiscounts[] = [0];
                }
            }

            $updates[] = new Google_Service_Sheets_ValueRange([
                'range' => "{$sheetName}!{$dateRange}",
                'values' => $newDates
            ]);
            $updates[] = new Google_Service_Sheets_ValueRange([
                'range' => "{$sheetName}!{$salesRange}",
                'values' => $newSales
            ]);
            $updates[] = new Google_Service_Sheets_ValueRange([
                'range' => "{$sheetName}!{$discountRange}",
                'values' => $newDiscounts
            ]);
        }

        // Execute batch update
        if (!empty($updates)) {
            $batchUpdateRequest = new Google_Service_Sheets_BatchUpdateValuesRequest([
                'valueInputOption' => 'RAW',
                'data' => $updates
            ]);
            $sheetsService->spreadsheets_values->batchUpdate($fileId, $batchUpdateRequest);
            error_log("Successfully updated " . count($sortedTotals) . " records for " . ($folderMonth + 1) . "/" . $folderYear);
        }

    } catch (Exception $error) {
        error_log("Error updating SMS Sales Report: " . $error->getMessage());
    }
}
function getHeaderMap($sheetService, $spreadsheetId, $sheetName, $headerRowIndex) {
    try {
        $range = "{$sheetName}!{$headerRowIndex}:{$headerRowIndex}";
        $response = $sheetService->spreadsheets_values->get($spreadsheetId, $range);
        $headers = $response->getValues()[0] ?? [];

        $headerMap = [];
        foreach ($headers as $index => $header) {
            if (!empty($header)) {
                $headerName = strtolower(trim($header));
                $headerMap[$headerName] = $index + 1; // Convert to 1-based index
            }
        }

        return $headerMap;
    } catch (Exception $e) {
        error_log("Error retrieving headers: " . $e->getMessage());
        return [];
    }
}

function getJobOrdersTotalsByDate($sheetService) {
    $spreadsheetId = "1i73R9Pd4hHlKWvnqVz2cydCneohIW93zQjZx2D19-NE";
    $sheetName = "JobOrders";

    try {
        // Fetch all data from the sheet
        $range = "{$sheetName}!A:Z"; // Adjust range if necessary
        $response = $sheetService->spreadsheets_values->get($spreadsheetId, $range);
        $data = $response->getValues();

        if (count($data) < 2) {
            error_log("No data found in the sheet.");
            return [];
        }

        // Get header map
        $headerMap = getHeaderMap($sheetService, $spreadsheetId, $sheetName, 1);
        if (!isset($headerMap['date']) || !isset($headerMap['gross sales'])) {
            error_log('"Date" or "Gross Sales" column not found.');
            return [];
        }

        $dateIndex = $headerMap['date'] - 1; // Convert to 0-based index
        $grossSalesIndex = $headerMap['gross sales'] - 1;

        $totalsByDate = []; // Store summed totals per unique date

        // Loop through rows and sum totals by date
        for ($i = 1; $i < count($data); $i++) {
            $row = $data[$i];

            $date = $row[$dateIndex] ?? "";
            $grossSales = isset($row[$grossSalesIndex]) ? floatval($row[$grossSalesIndex]) : 0;

            error_log("Row $i: Raw Date=$date, Raw Gross Sales=$grossSales");

            // Convert date
            $dateObject = DateTime::createFromFormat("m/d/Y", $date) ?: DateTime::createFromFormat("Y-m-d", $date);

            if (!$dateObject) {
                error_log("Skipping row $i, invalid date: " . $date);
                continue;
            }

            // Format date as "yyyy-MM-dd"
            $dateStr = $dateObject->format("Y-m-d");

            // Sum totals for each date
            if (!isset($totalsByDate[$dateStr])) {
                $totalsByDate[$dateStr] = ["grossSales" => 0];
            }

            $totalsByDate[$dateStr]["grossSales"] += $grossSales;

            error_log("Processed Row $i: Date=$dateStr, Gross Sales=$grossSales");
        }

        // Log final results
        error_log("Summed Totals by Date:");
        if (empty($totalsByDate)) {
            error_log("No valid data was processed.");
        } else {
            foreach ($totalsByDate as $date => $sums) {
                error_log("$date: Gross Sales=" . $sums["grossSales"]);
            }
        }

        return $totalsByDate;
    } catch (Exception $e) {
        error_log("Error processing Job Orders totals: " . $e->getMessage());
        return [];
    }
}
function getCashDisbursementTotalsByDate($sheetId, $apiKey) {
    $sheetName = "CashDisbursement";
    $url = "https://sheets.googleapis.com/v4/spreadsheets/$sheetId/values/$sheetName?key=$apiKey";

    $response = file_get_contents($url);
    $data = json_decode($response, true);

    if (!isset($data['values']) || count($data['values']) < 2) {
        error_log("No data found in the sheet.");
        return [];
    }

    $headers = $data['values'][0];
    $dateIndex = array_search("Date", $headers);
    $totalAmountIndex = array_search("Total Amount", $headers);

    if ($dateIndex === false || $totalAmountIndex === false) {
        error_log('"Date" or "Total Amount" column not found.');
        return [];
    }

    $totalsByDate = [];

    foreach (array_slice($data['values'], 1) as $i => $row) {
        $date = $row[$dateIndex] ?? null;
        $totalAmount = isset($row[$totalAmountIndex]) ? floatval($row[$totalAmountIndex]) : 0;

        if (!$date || strtotime($date) === false) {
            error_log("Skipping row " . ($i + 1) . ", invalid date: " . json_encode($date));
            continue;
        }

        $dateStr = date("Y-m-d", strtotime($date));
        $totalsByDate[$dateStr] = ($totalsByDate[$dateStr] ?? 0) + $totalAmount;
    }

    return $totalsByDate;
}

function extractSalesData($fileId, $apiKey) {
    $sheetName = "SMS Sales Report";
    $url = "https://sheets.googleapis.com/v4/spreadsheets/$fileId/values/$sheetName?key=$apiKey";

    $response = file_get_contents($url);
    $data = json_decode($response, true);

    if (!isset($data['values']) || count($data['values']) < 2) {
        error_log("SMS Sales Report sheet not found or empty.");
        return ['grossSalesByDate' => [], 'totalGrossSales' => 0];
    }

    $headers = $data['values'][0];
    $dateIndex = array_search("Date", $headers);
    $grossSalesIndex = array_search("Gross Sales", $headers);

    if ($dateIndex === false || $grossSalesIndex === false) {
        error_log("Required columns not found.");
        return ['grossSalesByDate' => [], 'totalGrossSales' => 0];
    }

    $grossSalesByDate = [];
    $totalGrossSales = 0;

    foreach (array_slice($data['values'], 1) as $row) {
        $dateCell = $row[$dateIndex] ?? null;
        $grossSalesCell = isset($row[$grossSalesIndex]) ? floatval($row[$grossSalesIndex]) : 0;

        if (!$dateCell || strtotime($dateCell) === false) {
            continue;
        }

        $dateStr = date("Y-m-d", strtotime($dateCell));
        $grossSalesByDate[$dateStr] = ($grossSalesByDate[$dateStr] ?? 0) + $grossSalesCell;
        $totalGrossSales += $grossSalesCell;
    }

    return ['grossSalesByDate' => $grossSalesByDate, 'totalGrossSales' => $totalGrossSales];
}
function extractSalesData($fileId) {
    try {
        $sheetsService = getSheetsService();
        
        // Get the spreadsheet
        $spreadsheet = $sheetsService->spreadsheets->get($fileId);
        $sheets = $spreadsheet->getSheets();
        
        // Find the "SMS Sales Report" sheet
        $targetSheet = null;
        foreach ($sheets as $sheet) {
            if ($sheet->getProperties()->getTitle() === "SMS Sales Report") {
                $targetSheet = $sheet;
                break;
            }
        }
        
        if (!$targetSheet) {
            error_log("SMS Sales Report sheet not found");
            return [
                'grossSalesByDate' => [],
                'totalGrossSales' => 0
            ];
        }
        
        // Get all data from the sheet
        $range = $targetSheet->getProperties()->getTitle();
        $response = $sheetsService->spreadsheets_values->get($fileId, $range);
        $data = $response->getValues();
        
        if (empty($data)) {
            return [
                'grossSalesByDate' => [],
                'totalGrossSales' => 0
            ];
        }
        
        $headers = $data[0];
        
        // Find column indexes
        $dateIndex = array_search("Date", $headers);
        $grossSalesIndex = array_search("Gross Sales", $headers);
        
        if ($dateIndex === false || $grossSalesIndex === false) {
            error_log("Required columns not found");
            return [
                'grossSalesByDate' => [],
                'totalGrossSales' => 0
            ];
        }
        
        $grossSalesByDate = [];
        $totalGrossSales = 0;
        
        // Start from row 1 to skip headers
        for ($i = 1; $i < count($data); $i++) {
            $row = $data[$i];
            
            // Skip if row doesn't have enough columns
            if (!isset($row[$dateIndex]) || !isset($row[$grossSalesIndex])) {
                continue;
            }
            
            $dateCell = $row[$dateIndex];
            $grossSalesCell = $row[$grossSalesIndex];
            
            // Parse date (assuming it comes as a string in format like "2023-01-01")
            $date = DateTime::createFromFormat('Y-m-d', $dateCell);
            if (!$date) {
                // Try another format if needed
                $date = DateTime::createFromFormat('m/d/Y', $dateCell);
                if (!$date) {
                    continue;
                }
            }
            
            $dateStr = $date->format('Y-m-d');
            $grossSales = is_numeric($grossSalesCell) ? (float)$grossSalesCell : 0;
            
            // Sum gross sales by date
            if (!isset($grossSalesByDate[$dateStr])) {
                $grossSalesByDate[$dateStr] = 0;
            }
            $grossSalesByDate[$dateStr] += $grossSales;
            $totalGrossSales += $grossSales;
        }
        
        return [
            'grossSalesByDate' => $grossSalesByDate,
            'totalGrossSales' => $totalGrossSales
        ];
        
    } catch (Exception $error) {
        error_log("Error extracting sales data: " . $error->getMessage());
        return [
            'grossSalesByDate' => [],
            'totalGrossSales' => 0
        ];
    }
}
function getJobOrdersDiscountTotalsByDate() {
    try {
        $sheetId = "1i73R9Pd4hHlKWvnqVz2cydCneohIW93zQjZx2D19-NE";
        $sheetName = "JobOrders";

        // Get the Google Sheets service
        $sheetsService = getSheetsService();
        
        // Get the spreadsheet
        $spreadsheet = $sheetsService->spreadsheets->get($sheetId);
        $sheets = $spreadsheet->getSheets();
        
        // Find the target sheet
        $targetSheet = null;
        foreach ($sheets as $sheet) {
            if ($sheet->getProperties()->getTitle() === $sheetName) {
                $targetSheet = $sheet;
                break;
            }
        }
        
        if (!$targetSheet) {
            error_log("Sheet \"$sheetName\" not found.");
            return [];
        }

        // Get all data from the sheet
        $range = $sheetName;
        $response = $sheetsService->spreadsheets_values->get($sheetId, $range);
        $data = $response->getValues();
        
        if (count($data) < 2) {
            error_log("No data found in the sheet.");
            return [];
        }

        // Find column indexes
        $headers = $data[0];
        $dateIndex = array_search("Date", $headers);
        $discountIndex = array_search("Discount", $headers);
        
        if ($dateIndex === false || $discountIndex === false) {
            error_log("\"Date\" or \"Discount\" column not found.");
            return [];
        }

        $discountTotalsByDate = []; // Store summed discounts per unique date

        // Loop through rows and sum discounts by date
        for ($i = 1; $i < count($data); $i++) {
            $row = $data[$i];
            
            // Skip if row doesn't have enough columns
            if (!isset($row[$dateIndex]) || !isset($row[$discountIndex])) {
                error_log("Skipping row $i: missing date or discount value");
                continue;
            }

            $rawDate = $row[$dateIndex];
            $rawDiscount = $row[$discountIndex];
            
            // Log raw values
            error_log("Row $i: Raw Date=$rawDate, Raw Discount=$rawDiscount");

            // Parse discount
            $discount = is_numeric($rawDiscount) ? (float)$rawDiscount : 0;

            // Parse date
            try {
                // Try different date formats
                $date = null;
                if (is_numeric($rawDate)) {
                    // Google Sheets serial date (days since 1900)
                    $date = DateTime::createFromFormat('Y-m-d', '1899-12-30');
                    $date->add(new DateInterval('P' . (int)$rawDate . 'D'));
                } else {
                    // Try common string formats
                    $date = DateTime::createFromFormat('Y-m-d', $rawDate);
                    if (!$date) {
                        $date = DateTime::createFromFormat('m/d/Y', $rawDate);
                    }
                    if (!$date) {
                        $date = DateTime::createFromFormat('d/m/Y', $rawDate);
                    }
                }

                if (!$date) {
                    error_log("Skipping row $i, invalid date: $rawDate");
                    continue;
                }

                $dateStr = $date->format('Y-m-d');

                // Sum discounts for each date
                if (!isset($discountTotalsByDate[$dateStr])) {
                    $discountTotalsByDate[$dateStr] = 0;
                }
                $discountTotalsByDate[$dateStr] += $discount;

                // Log processed values
                error_log("Processed Row $i: Date=$dateStr, Discount=$discount");

            } catch (Exception $e) {
                error_log("Error processing row $i: " . $e->getMessage());
                continue;
            }
        }

        // Log final results
        error_log("Summed Discounts by Date:");
        if (empty($discountTotalsByDate)) {
            error_log("No valid data was processed.");
        } else {
            foreach ($discountTotalsByDate as $date => $discount) {
                error_log("$date: $discount");
            }
        }

        return $discountTotalsByDate;

    } catch (Exception $e) {
        error_log("Error in getJobOrdersDiscountTotalsByDate: " . $e->getMessage());
        return [];
    }
}
function correctWeeklyDates($fileId) {
    try {
        $driveService = getDriveService();
        $sheetsService = getSheetsService();

        // Get file and filename
        $file = $driveService->files->get($fileId);
        $filename = $file->getName();

        // Get the spreadsheet
        $spreadsheet = $sheetsService->spreadsheets->get($fileId);
        $sheets = $spreadsheet->getSheets();

        // Find the target sheet
        $sheet = null;
        foreach ($sheets as $s) {
            if ($s->getProperties()->getTitle() === "SMS Sales Report") {
                $sheet = $s;
                break;
            }
        }

        if (!$sheet) {
            error_log("Sheet 'SMS Sales Report' not found!");
            return false;
        }

        // Extract month from filename
        preg_match('/(January|February|March|April|May|June|July|August|September|October|November|December)/i', $filename, $monthMatch);
        
        if (empty($monthMatch)) {
            error_log("Could not determine month from filename: " . $filename);
            return false;
        }

        $monthName = $monthMatch[0];
        $months = ["January", "February", "March", "April", "May", "June", 
                  "July", "August", "September", "October", "November", "December"];
        $monthIndex = array_search($monthName, $months);

        if ($monthIndex === false) {
            error_log("Invalid month name in filename: " . $monthName);
            return false;
        }

        // Get year from parent folder
        $folderYear = null;
        $parents = $driveService->parents->listParents($fileId);
        
        if (!empty($parents->getItems())) {
            $folder = $parents->getItems()[0];
            $folderName = $folder->getName();
            preg_match('/(20\d{2})/', $folderName, $yearMatch);
            
            if (!empty($yearMatch)) {
                $folderYear = (int)$yearMatch[0];
            }
        }

        if (!$folderYear) {
            error_log("Could not determine year from folder name");
            return false;
        }

        // Set the correct starting date (first day of month)
        $startDate = new DateTime();
        $startDate->setDate($folderYear, $monthIndex + 1, 1); // PHP months are 1-12

        // Get all data from the sheet
        $range = "SMS Sales Report";
        $response = $sheetsService->spreadsheets_values->get($fileId, $range);
        $data = $response->getValues();

        $weekRegex = '/^Week \d+$/';
        $weekSections = [];

        // Find all "Week X" positions
        foreach ($data as $r => $row) {
            if (!empty($row) && preg_match($weekRegex, $row[0])) {
                $weekSections[] = [
                    'row' => $r,
                    'weekLabel' => $row[0],
                    'dateRow' => $r + 3 // Dates start 3 rows below "Week X"
                ];
            }
        }

        if (empty($weekSections)) {
            error_log("No 'Week X' sections found.");
            return false;
        }

        // Prepare batch update requests
        $requests = [];
        $currentDate = clone $startDate;

        // Process each week section starting from first day of month
        foreach ($weekSections as $w => $week) {
            $weekRow = $week['row'];
            $dateRow = $week['dateRow'];

            // Calculate week end date (6 days after start)
            $weekEnd = clone $currentDate;
            $weekEnd->add(new DateInterval('P6D'));

            // Adjust week end if it goes into next month
            if ($weekEnd->format('n') != $monthIndex + 1) {
                $weekEnd = new DateTime();
                $weekEnd->setDate($folderYear, $monthIndex + 2, 0); // Last day of current month
            }

            // Update week range in header (column B)
            $weekRange = $currentDate->format('F d, Y') . ' - ' . $weekEnd->format('F d, Y');
            $requests[] = new Google_Service_Sheets_Request([
                'updateCells' => [
                    'range' => [
                        'sheetId' => $sheet->getProperties()->getSheetId(),
                        'startRowIndex' => $weekRow,
                        'endRowIndex' => $weekRow + 1,
                        'startColumnIndex' => 1,
                        'endColumnIndex' => 2
                    ],
                    'rows' => [
                        'values' => [
                            ['userEnteredValue' => ['stringValue' => $weekRange]]
                        ]
                    ],
                    'fields' => 'userEnteredValue'
                ]
            ]);

            // Update dates and day names for the week
            for ($d = 0; $d < 7; $d++) {
                $cellDate = clone $currentDate;
                $cellDate->add(new DateInterval('P' . $d . 'D'));

                // Stop if we've gone into next month
                if ($cellDate->format('n') != $monthIndex + 1) break;

                // Prepare date cell update (column A)
                $requests[] = new Google_Service_Sheets_Request([
                    'updateCells' => [
                        'range' => [
                            'sheetId' => $sheet->getProperties()->getSheetId(),
                            'startRowIndex' => $dateRow + $d,
                            'endRowIndex' => $dateRow + $d + 1,
                            'startColumnIndex' => 0,
                            'endColumnIndex' => 1
                        ],
                        'rows' => [
                            'values' => [
                                ['userEnteredValue' => ['numberValue' => $this->dateToGoogleSheetsSerial($cellDate)]]
                            ]
                        ],
                        'fields' => 'userEnteredValue.numberValue,userEnteredFormat.numberFormat'
                    ]
                ]);

                // Prepare day name cell update (column B)
                $dayNames = ["Sun", "Mon", "Tue", "Wed", "Thu", "Fri", "Sat"];
                $dayName = $dayNames[(int)$cellDate->format('w')];
                
                $requests[] = new Google_Service_Sheets_Request([
                    'updateCells' => [
                        'range' => [
                            'sheetId' => $sheet->getProperties()->getSheetId(),
                            'startRowIndex' => $dateRow + $d,
                            'endRowIndex' => $dateRow + $d + 1,
                            'startColumnIndex' => 1,
                            'endColumnIndex' => 2
                        ],
                        'rows' => [
                            'values' => [
                                ['userEnteredValue' => ['stringValue' => $dayName]]
                            ]
                        ],
                        'fields' => 'userEnteredValue'
                    ]
                ]);
            }

            // Move to next week (7 days after current start)
            $currentDate->add(new DateInterval('P7D'));

            // Stop if we've gone into next month
            if ($currentDate->format('n') != $monthIndex + 1) break;
        }

        // Execute all updates in a single batch
        if (!empty($requests)) {
            $batchUpdateRequest = new Google_Service_Sheets_BatchUpdateSpreadsheetRequest([
                'requests' => $requests
            ]);
            $sheetsService->spreadsheets->batchUpdate($fileId, $batchUpdateRequest);
        }

        error_log("All weekly dates and day names updated successfully for " . $monthName . " " . $folderYear);
        return true;

    } catch (Exception $e) {
        error_log("Error in correctWeeklyDates: " . $e->getMessage());
        return false;
    }
}

// Helper function to convert DateTime to Google Sheets serial date
private function dateToGoogleSheetsSerial(DateTime $date) {
    $unixTimestamp = $date->getTimestamp();
    $googleSheetsTimestamp = ($unixTimestamp / 86400) + 25569; // 25569 = days between 1900 and 1970
    return $googleSheetsTimestamp;
}
/**
 * Helper function to format date as "Month DD, YYYY"
 * @param DateTime $date The date to format
 * @return string Formatted date string
 */
function formatDate(DateTime $date) {
    return $date->format('F d, Y'); // 'F' = full month name, 'd' = day with leading zero, 'Y' = 4-digit year
}

/**
 * Helper function to get day name from a DateTime object
 * @param DateTime $date The date to get day name for
 * @return string 3-letter day name (e.g., "Mon")
 */
function getDayName(DateTime $date) {
    $days = ["Sun", "Mon", "Tue", "Wed", "Thu", "Fri", "Sat"];
    return $days[(int)$date->format('w')]; // 'w' = numeric day of week (0=Sunday)
}
?>
