<?php
// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', 'debug.log'); // This will create a debug.log file in the same directory

// Set headers for JSON response and CORS
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

// Define access constant for security
define('ALLOWED_ACCESS', true);

// Load required files
require_once 'ApiHandler.php';

// Function to sanitize output
function sanitizeOutput($data) {
    if (is_array($data)) {
        return array_map('sanitizeOutput', $data);
    }
    return htmlspecialchars($data, ENT_QUOTES, 'UTF-8');
}

try {
    // Get and validate the request data
    $rawData = file_get_contents('php://input');
    error_log("Received raw request data: " . $rawData);

    if (!$rawData) {
        throw new Exception('No data received');
    }

    $data = json_decode($rawData, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception('Invalid JSON: ' . json_last_error_msg());
    }

    // Validate required fields
    if (!isset($data['query'])) {
        throw new Exception('Query parameter is required');
    }

    // Process the request
    $query = trim($data['query']);
    $type = isset($data['type']) ? strtolower(trim($data['type'])) : 'text';
    
    // Validate query is not empty
    if (empty($query)) {
        throw new Exception('Query cannot be empty');
    }

    // Initialize API handler
    $apiHandler = new ApiHandler();
    $response = [];

    // Process different types of searches
    switch ($type) {
        case 'images':
            error_log("Processing image search for query: " . $query);
            $pixabayResponse = $apiHandler->searchImages($query);
            
            if (isset($pixabayResponse['error'])) {
                throw new Exception($pixabayResponse['error']);
            }

            // Format image results
            $response = [
                'success' => true,
                'type' => 'images',
                'hits' => $pixabayResponse['hits'] ?? [],
                'total' => $pixabayResponse['total'] ?? 0,
                'totalHits' => $pixabayResponse['totalHits'] ?? 0
            ];
            break;

        case 'text':
            error_log("Processing text search with AI summary for query: " . $query);
            
            // Get AI summary
            $aiSummary = $apiHandler->getAISummary($query);
            error_log("AI Summary received: " . substr($aiSummary, 0, 500) . "...");

            // Format text search results
            $response = [
                'success' => true,
                'type' => 'text',
                'ai_summary' => $aiSummary,
                'search_results' => [] // Add your regular search results here if needed
            ];
            break;

        case 'videos':
            error_log("Processing video search for query: " . $query);
            // Add video search handling if needed
            $response = [
                'success' => true,
                'type' => 'videos',
                'results' => [] // Add video search results here
            ];
            break;

        default:
            throw new Exception('Invalid search type: ' . $type);
    }

    // Log successful response
    error_log("Sending successful response for type {$type}");
    
    // Sanitize and send response
    echo json_encode(sanitizeOutput($response));

} catch (Exception $e) {
    // Log the error
    error_log("Error in search.php: " . $e->getMessage());
    
    // Send error response
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => true,
        'message' => $e->getMessage(),
        'type' => $type ?? 'unknown'
    ]);
}

// Function to write to debug log
function debugLog($message, $data = null) {
    $logMessage = date('Y-m-d H:i:s') . " - " . $message;
    if ($data !== null) {
        $logMessage .= "\nData: " . print_r($data, true);
    }
    error_log($logMessage);
}

// Helper function to validate URLs
function isValidUrl($url) {
    return filter_var($url, FILTER_VALIDATE_URL) !== false;
}

// Helper function to check if string is valid JSON
function isValidJSON($string) {
    json_decode($string);
    return json_last_error() === JSON_ERROR_NONE;
}