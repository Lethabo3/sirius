<?php
// Prevent direct file access
if (!defined('ALLOWED_ACCESS')) {
    die('Direct access not permitted');
}

class ApiHandler {
    // Store configuration and API keys
    private $config;
    private $cache = [];
    private $cacheDuration = 300; // 5 minutes default cache duration
    
    public function __construct() {
        // Load configuration file containing API keys
        define('ALLOWED_ACCESS', true);
        $this->config = require 'config.php';
        
        // Verify required API keys exist
        if (!isset($this->config['api_keys'])) {
            throw new Exception('Configuration error: API keys not found');
        }
        
        error_log("ApiHandler initialized successfully");
    }
    
    /**
     * Makes HTTP requests to external APIs with comprehensive error handling
     */
    private function makeRequest($url, $headers = [], $postData = null) {
        error_log("Starting API request to: $url");
        
        // Initialize cURL with error checking
        $ch = curl_init($url);
        if (!$ch) {
            throw new Exception("Failed to initialize cURL");
        }
        
        // Set up common cURL options
        $curlOptions = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_HTTPHEADER => $headers,
            CURLINFO_HEADER_OUT => true,
            CURLOPT_SSL_VERIFYPEER => true
        ];
        
        // Add POST-specific options if needed
        if ($postData !== null) {
            $curlOptions[CURLOPT_POST] = true;
            $curlOptions[CURLOPT_POSTFIELDS] = json_encode($postData);
            error_log("POST data: " . json_encode($postData));
        }
        
        curl_setopt_array($ch, $curlOptions);
        
        // Execute request and gather response info
        $response = curl_exec($ch);
        $error = curl_error($ch);
        $info = curl_getinfo($ch);
        $httpCode = $info['http_code'];
        
        // Log response details
        error_log("API Response Code: " . $httpCode);
        error_log("cURL Info: " . print_r($info, true));
        
        if ($error) {
            error_log("cURL Error: " . $error);
            curl_close($ch);
            throw new Exception("API request failed: $error");
        }
        
        curl_close($ch);
        
        // Check for HTTP errors
        if ($httpCode >= 400) {
            error_log("HTTP Error: Response code $httpCode");
            error_log("Response body: " . $response);
            throw new Exception("API returned error code: $httpCode");
        }
        
        // Parse JSON response
        $decoded = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log("JSON decode error: " . json_last_error_msg());
            error_log("Raw response: " . $response);
            throw new Exception("Failed to decode API response: " . json_last_error_msg());
        }
        
        return $decoded;
    }
    
    /**
     * Generates AI summaries using the Gemini API
     */
    public function getAISummary($query) {
        error_log("Generating AI summary for query: $query");
        
        $url = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash-latest:generateContent';
        
        // Set up headers with API key
        $headers = [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $this->config['api_keys']['gemini']
        ];
        
        // Prepare request data for Gemini API
        $postData = [
            'contents' => [[
                'parts' => [[
                    'text' => $query
                ]]
            ]],
            'generationConfig' => [
                'temperature' => 0.7,
                'topK' => 40,
                'topP' => 0.95,
                'maxOutputTokens' => 1024
            ]
        ];
        
        try {
            error_log("Making request to Gemini API");
            $response = $this->makeRequest($url, $headers, $postData);
            
            error_log("Gemini API response: " . print_r($response, true));
            
            // Validate response structure
            if (!isset($response['candidates'][0]['content']['parts'][0]['text'])) {
                throw new Exception("Unexpected API response structure");
            }
            
            $summary = $response['candidates'][0]['content']['parts'][0]['text'];
            error_log("Successfully generated AI summary");
            
            return $summary;
            
        } catch (Exception $e) {
            error_log("Failed to generate AI summary: " . $e->getMessage());
            throw new Exception("AI summary generation failed: " . $e->getMessage());
        }
    }
    
    /**
     * Searches for images using the Pixabay API
     */
    public function searchImages($query, $page = 1, $perPage = 24) {
        error_log("Searching images for query: $query");
        
        // Build Pixabay API URL with search parameters
        $url = 'https://pixabay.com/api/?' . http_build_query([
            'key' => $this->config['api_keys']['pixabay'],
            'q' => urlencode($query),
            'page' => $page,
            'per_page' => $perPage,
            'safesearch' => true,
            'image_type' => 'photo',
            'lang' => 'en'
        ]);
        
        try {
            // Check cache first
            $cacheKey = 'pixabay_' . md5($url);
            if (isset($this->cache[$cacheKey]) && 
                (time() - $this->cache[$cacheKey]['time'] < $this->cacheDuration)) {
                return $this->cache[$cacheKey]['data'];
            }
            
            error_log("Making request to Pixabay API");
            $response = $this->makeRequest($url);
            
            // Cache the results
            $this->cache[$cacheKey] = [
                'time' => time(),
                'data' => $response
            ];
            
            error_log("Successfully retrieved " . count($response['hits'] ?? []) . " images");
            return $response;
            
        } catch (Exception $e) {
            error_log("Image search failed: " . $e->getMessage());
            throw new Exception("Image search failed: " . $e->getMessage());
        }
    }
}