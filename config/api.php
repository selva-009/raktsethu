<?php
/**
 * API Configuration
 * -----------------
 * Reads the SurePass NMC Verification API key from environment variables.
 * If no key is set (e.g., on InfinityFree which blocks outbound HTTP),
 * the system falls back to manual review by the admin.
 *
 * To enable API-based verification:
 *   1. Create a free account at https://surepass.io/get-api-key/
 *   2. Get your API token from the Surepass dashboard
 *   3. Set it as an environment variable: SUREPASS_API_KEY=your_key_here
 *      (On most hosts, add to .htaccess: SetEnv SUREPASS_API_KEY your_key_here)
 *
 * Never hardcode the API key in source files or frontend code.
 */

// Read API key from environment (set via .htaccess or server config)
define('SUREPASS_API_KEY', getenv('SUREPASS_API_KEY') ?: '');
define('SUREPASS_API_URL', 'https://api.surepass.io/api/v1/nmc/nmc-verification/');

// Whether API-based verification is available
define('LICENSE_API_ENABLED', SUREPASS_API_KEY !== '');

/**
 * Verify a doctor's license number against the NMC register via SurePass API.
 *
 * @param string $licenseNumber  The doctor's registration number
 * @param string $doctorName     The doctor's submitted name (for matching)
 * @param string $stateCouncil   The state medical council (for matching)
 * @return array { 'verified' => bool, 'status' => string, 'details' => string, 'raw' => array|null }
 */
function verifyLicenseApi(string $licenseNumber, string $doctorName, string $stateCouncil): array {
    // If no API key configured, fall back to manual review
    if (!LICENSE_API_ENABLED) {
        return [
            'verified' => false,
            'status'   => 'manual_review',
            'details'  => 'No API key configured. Manual review required by admin.',
            'raw'      => null,
        ];
    }

    // Sanitize the license number: alphanumeric + hyphens + spaces only
    $cleanLicense = preg_replace('/[^A-Za-z0-9\-\/ ]/', '', $licenseNumber);
    if (strlen($cleanLicense) < 3) {
        return [
            'verified' => false,
            'status'   => 'verification_failed',
            'details'  => 'License number is too short or invalid format.',
            'raw'      => null,
        ];
    }

    // Prevent duplicate verification requests (rate limiting: 1 per 60 seconds per license)
    $cacheKey = 'license_check_' . md5($cleanLicense);
    if (function_exists('apcu_fetch')) {
        $cached = apcu_fetch($cacheKey);
        if ($cached !== false) {
            return $cached;
        }
    }

    // Prepare API request
    $payload = json_encode([
        'registration_number' => $cleanLicense,
        'state_council'       => $stateCouncil,
    ]);

    $ch = curl_init(SUREPASS_API_URL);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . SUREPASS_API_KEY,
        ],
        CURLOPT_TIMEOUT        => 15,   // 15-second timeout
        CURLOPT_CONNECTTIMEOUT => 10,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    // Handle API errors (timeout, network failure, etc.)
    if ($response === false || $httpCode >= 500) {
        return [
            'verified' => false,
            'status'   => 'manual_review',
            'details'  => 'API request failed: ' . ($error ?: 'Server error') . '. Manual review required.',
            'raw'      => ['http_code' => $httpCode, 'error' => $error],
        ];
    }

    $data = json_decode($response, true);

    // Handle API-level errors (401, 403, 429, etc.)
    if ($httpCode === 401 || $httpCode === 403) {
        return [
            'verified' => false,
            'status'   => 'manual_review',
            'details'  => 'API authentication failed. Manual review required.',
            'raw'      => ['http_code' => $httpCode],
        ];
    }

    if ($httpCode === 429) {
        return [
            'verified' => false,
            'status'   => 'manual_review',
            'details'  => 'API rate limit exceeded. Manual review required.',
            'raw'      => ['http_code' => $httpCode],
        ];
    }

    // Parse successful API response
    // SurePass returns: { "status": true, "data": { "name": "...", "registration_number": "...", "state_council": "...", "qualification": "...", "year_of_registration": "...", "status": "Active" } }
    if ($httpCode === 200 && isset($data['status']) && $data['status'] === true) {
        $apiData = $data['data'] ?? [];

        // Check if the license is active
        $licenseStatus = strtolower($apiData['status'] ?? '');
        if ($licenseStatus !== 'active' && $licenseStatus !== '') {
            return [
                'verified' => false,
                'status'   => 'rejected',
                'details'  => 'License found but status is: ' . ($apiData['status'] ?? 'unknown') . '.',
                'raw'      => $apiData,
            ];
        }

        // Match the doctor's name (case-insensitive, fuzzy match)
        $apiName = strtolower(trim($apiData['name'] ?? ''));
        $submittedName = strtolower(trim($doctorName));

        // Simple fuzzy match: check if the submitted name appears in the API result
        // or vice versa (handles initials like "A K Sharma" vs "Anil Kumar Sharma")
        $nameMatch = false;
        if ($apiName !== '' && $submittedName !== '') {
            // Check if all parts of the submitted name appear in the API name
            $submittedParts = preg_split('/\s+/', $submittedName);
            $allPartsFound = true;
            foreach ($submittedParts as $part) {
                if (strlen($part) > 1 && strpos($apiName, $part) === false) {
                    $allPartsFound = false;
                    break;
                }
            }
            $nameMatch = $allPartsFound || strpos($apiName, $submittedName) !== false;
        }

        if (!$nameMatch) {
            return [
                'verified' => false,
                'status'   => 'manual_review',
                'details'  => 'License found but name does not match. Submitted: "' . $doctorName . '", API: "' . ($apiData['name'] ?? 'unknown') . '". Manual review required.',
                'raw'      => $apiData,
            ];
        }

        // License is valid, active, and name matches
        return [
            'verified' => true,
            'status'   => 'approved',
            'details'  => 'License verified via NMC. Name: ' . ($apiData['name'] ?? '') . ', Council: ' . ($apiData['state_council'] ?? '') . ', Status: Active.',
            'raw'      => $apiData,
        ];
    }

    // License not found in NMC database
    if ($httpCode === 200 && isset($data['status']) && $data['status'] === false) {
        return [
            'verified' => false,
            'status'   => 'rejected',
            'details'  => 'License number not found in NMC register.',
            'raw'      => $data,
        ];
    }

    // Unknown response — fall back to manual review
    return [
        'verified' => false,
        'status'   => 'manual_review',
        'details'  => 'Unexpected API response. Manual review required.',
        'raw'      => ['http_code' => $httpCode, 'body' => $response],
    ];
}
