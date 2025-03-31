<?php
function createFolder($folderName, $categoryName, $spreadsheetLink) {
    try {
        // Validate inputs
        if (empty($folderName) || empty($categoryName)) {
            throw new Exception("Folder name and category name are required!");
        }

        // Get session user ID (PHP session)
        session_start();
        $sessionUserId = $_SESSION['user_id'] ?? null;
        
        if (!$sessionUserId) {
            throw new Exception("Session ID not found!");
        }

        // Database connection (replace with your actual DB connection)
        $db = new PDO('mysql:host=localhost;dbname=your_db', 'username', 'password');
        
        // Get user data from database (replacing spreadsheet)
        $stmt = $db->prepare("SELECT * FROM users WHERE user_id = ?");
        $stmt->execute([$sessionUserId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$user) {
            throw new Exception("User not found in database!");
        }

        $userEmail = $user['email'];
        $profileName = $user['first_name'] . ' ' . $user['last_name'];

        // Google Drive API setup (requires Google API client)
        $client = getGoogleClient(); // You'll need to implement OAuth
        $driveService = new Google_Service_Drive($client);

        // Step 1: Check/create category folder
        $categoryFolderId = null;
        $query = "name='$categoryName' and mimeType='application/vnd.google-apps.folder' and 'root' in parents and trashed=false";
        $results = $driveService->files->listFiles(['q' => $query]);
        
        if (count($results->getFiles())) {
            $categoryFolder = $results->getFiles()[0];
            $categoryFolderId = $categoryFolder->getId();
        } else {
            $folderMetadata = new Google_Service_Drive_DriveFile([
                'name' => $categoryName,
                'mimeType' => 'application/vnd.google-apps.folder',
                'parents' => ['root']
            ]);
            $categoryFolder = $driveService->files->create($folderMetadata);
            $categoryFolderId = $categoryFolder->getId();
            
            // Share with user as viewer
            $permission = new Google_Service_Drive_Permission([
                'type' => 'user',
                'role' => 'reader',
                'emailAddress' => $userEmail
            ]);
            $driveService->permissions->create($categoryFolderId, $permission);
        }

        // Step 2: Create user folder
        $userFolderName = $user['first_name'] . ' ' . $user['last_name'];
        $query = "name='$userFolderName' and mimeType='application/vnd.google-apps.folder' and '$categoryFolderId' in parents and trashed=false";
        $results = $driveService->files->listFiles(['q' => $query]);
        
        if (count($results->getFiles())) {
            $userFolder = $results->getFiles()[0];
            $userFolderId = $userFolder->getId();
        } else {
            $folderMetadata = new Google_Service_Drive_DriveFile([
                'name' => $userFolderName,
                'mimeType' => 'application/vnd.google-apps.folder',
                'parents' => [$categoryFolderId],
                'description' => "Owned by: $userEmail"
            ]);
            $userFolder = $driveService->files->create($folderMetadata);
            $userFolderId = $userFolder->getId();
            
            // Share with user as editor
            $permission = new Google_Service_Drive_Permission([
                'type' => 'user',
                'role' => 'writer',
                'emailAddress' => $userEmail
            ]);
            $driveService->permissions->create($userFolderId, $permission);
        }

        // Step 3: Create subfolder
        $subFolderMetadata = new Google_Service_Drive_DriveFile([
            'name' => $folderName,
            'mimeType' => 'application/vnd.google-apps.folder',
            'parents' => [$userFolderId],
            'description' => "Owned by: $userEmail"
        ]);
        $subFolder = $driveService->files->create($subFolderMetadata);
        $subFolderId = $subFolder->getId();
        
        // Share subfolder
        $permission = new Google_Service_Drive_Permission([
            'type' => 'user',
            'role' => 'writer',
            'emailAddress' => $userEmail
        ]);
        $driveService->permissions->create($subFolderId, $permission);

        // Save to database (replacing spreadsheet)
        $stmt = $db->prepare("INSERT INTO user_folders (folder_id, folder_name, category_name, spreadsheet_link, created_by, created_at) 
                             VALUES (?, ?, ?, ?, ?, NOW())");
        $stmt->execute([$subFolderId, $folderName, $categoryName, $spreadsheetLink, $profileName]);

        return $subFolderId;
    } catch (Exception $e) {
        error_log("Error creating folder: " . $e->getMessage());
        return "Error creating folder: " . $e->getMessage();
    }
}

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

function getUserFolders($profileName, $categoryName = null) {
    try {
        $db = new PDO('mysql:host=localhost;dbname=your_db', 'username', 'password');
        
        $query = "SELECT folder_id, folder_name, category_name, spreadsheet_link 
                 FROM user_folders 
                 WHERE created_by = :profileName";
        
        $params = [':profileName' => $profileName];
        
        if ($categoryName) {
            $query .= " AND category_name = :categoryName";
            $params[':categoryName'] = $categoryName;
        }
        
        $stmt = $db->prepare($query);
        $stmt->execute($params);
        
        $folders = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $folders[] = [
                'id' => $row['folder_id'],
                'name' => $row['folder_name'],
                'category' => $row['category_name'] ?: 'Uncategorized',
                'spreadsheetLink' => $row['spreadsheet_link'] ?: ''
            ];
        }
        
        return $folders;
    } catch (PDOException $e) {
        error_log("Database error: " . $e->getMessage());
        return [];
    }
}
function renameFile($fileId, $newName) {
    try {
        $client = getGoogleClient();
        $driveService = new Google_Service_Drive($client);
        
        $file = $driveService->files->get($fileId);
        if ($file->getName() === $newName) {
            return ['success' => true, 'message' => 'No change needed'];
        }
        
        $file->setName($newName);
        $driveService->files->update($fileId, $file);
        
        // Update database if needed
        $db = new PDO('mysql:host=localhost;dbname=your_db', 'username', 'password');
        $stmt = $db->prepare("UPDATE user_files SET file_name = ? WHERE file_id = ?");
        $stmt->execute([$newName, $fileId]);
        
        return ['success' => true, 'message' => 'File renamed successfully'];
    } catch (Exception $e) {
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

function deleteFile($fileId) {
    try {
        $client = getGoogleClient();
        $driveService = new Google_Service_Drive($client);
        
        // Move to trash
        $driveService->files->delete($fileId);
        
        // Update database
        $db = new PDO('mysql:host=localhost;dbname=your_db', 'username', 'password');
        $stmt = $db->prepare("DELETE FROM user_files WHERE file_id = ?");
        $stmt->execute([$fileId]);
        
        return ['success' => true, 'message' => 'File deleted successfully'];
    } catch (Exception $e) {
        return ['success' => false, 'message' => $e->getMessage()];
    }
}
function getTotalsByDate() {
    try {
        $client = getGoogleClient();
        $sheetsService = new Google_Service_Sheets($client);
        
        $spreadsheetId = "1i73R9Pd4hHlKWvnqVz2cydCneohIW93zQjZx2D19-NE";
        $range = "Orders!A:Z";
        
        $response = $sheetsService->spreadsheets_values->get($spreadsheetId, $range);
        $values = $response->getValues();
        
        if (empty($values)) {
            return [];
        }
        
        $headers = $values[0];
        $timestampIndex = array_search("Timestamp", $headers);
        $totalIndex = array_search("Total", $headers);
        
        if ($timestampIndex === false || $totalIndex === false) {
            throw new Exception("Required columns not found");
        }
        
        $totalsByDate = [];
        
        for ($i = 1; $i < count($values); $i++) {
            $row = $values[$i];
            $timestamp = $row[$timestampIndex] ?? null;
            $total = $row[$totalIndex] ?? 0;
            
            if (!$timestamp) continue;
            
            try {
                $date = new DateTime($timestamp);
                $dateStr = $date->format('Y-m-d');
                $total = (float)$total;
                
                $totalsByDate[$dateStr] = ($totalsByDate[$dateStr] ?? 0) + $total;
            } catch (Exception $e) {
                continue;
            }
        }
        
        return $totalsByDate;
    } catch (Exception $e) {
        error_log("Error in getTotalsByDate: " . $e->getMessage());
        return [];
    }
}
?>
