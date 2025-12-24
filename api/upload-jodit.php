<?php
/**
 * API - Jodit Image Upload
 * Handles image uploads from Jodit rich text editor
 */

session_start();
require_once __DIR__ . '/../includes/functions.php';

// Only admins can upload
if (!isAdmin()) {
    header('HTTP/1.1 403 Forbidden');
    echo json_encode(['success' => false, 'error' => 'Forbidden']);
    exit;
}

try {
    // Jodit sends files in 'files' array by default
    $fileKey = 'files';
    
    // Check if the file was sent in a different key
    if (!isset($_FILES[$fileKey]) || empty($_FILES[$fileKey]['name'][0])) {
        // Try 'file' (another common one)
        if (isset($_FILES['file'])) {
            $fileKey = 'file';
        } else {
            // Find any file key
            $keys = array_keys($_FILES);
            if (!empty($keys)) {
                $fileKey = $keys[0];
            } else {
                throw new Exception('No file uploaded.');
            }
        }
    }

    // handleUpload expects a single file key, but Jodit often sends an array of files even for single upload
    // We'll normalize it by creating a temporary single-file entry if it's an array
    if (is_array($_FILES[$fileKey]['name'])) {
        $_FILES['jodit_temp'] = [
            'name' => $_FILES[$fileKey]['name'][0],
            'type' => $_FILES[$fileKey]['type'][0],
            'tmp_name' => $_FILES[$fileKey]['tmp_name'][0],
            'error' => $_FILES[$fileKey]['error'][0],
            'size' => $_FILES[$fileKey]['size'][0]
        ];
        $fileKey = 'jodit_temp';
    }

    $filePath = handleUpload($fileKey, 'campaigns');
    
    if ($filePath) {
        // Return JSON response expected by Jodit
        // baseUrl is prepended by Jodit if 'baseurl' is provided, but we'll return the full path starting with /
        echo json_encode([
            'success' => true,
            'time' => date('Y-m-d H:i:s'),
            'data' => [
                'baseurl' => '/',
                'messages' => [],
                'files' => ['/' . $filePath],
                'isImages' => [true],
                'code' => 220
            ],
            'files' => ['/' . $filePath] // Jodit also looks for this top-level files array
        ]);
    } else {
        throw new Exception('Upload failed.');
    }
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'message' => $e->getMessage()
    ]);
}
