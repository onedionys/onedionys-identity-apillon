<?php
$apiKey = $_COOKIE['onedionys_apillon_api_key'];
$apiSecret = $_COOKIE['onedionys_apillon_api_key_secret'];
$authorization = $_COOKIE['onedionys_apillon_authentication'];
$bucketUuid = $_COOKIE['onedionys_apillon_storage_uuid'];
$hostingUuid = $_COOKIE['onedionys_apillon_hosting_uuid'];

function toObject($array)
{
    return json_decode(json_encode($array));
}

function callAPI($method, $url, $data = false, $authorization, $category = 'default')
{
    $curl = curl_init();

    switch ($method) {
        case "POST":
            curl_setopt($curl, CURLOPT_POST, 1);
            if ($data) {
                curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($data));
            }
            break;
        case "GET":
            if ($data !== null && (is_array($data) || is_object($data))) {
                $url = sprintf("%s?%s", $url, http_build_query($data));
            }
            break;
        default:
            break;
    }

    curl_setopt($curl, CURLOPT_URL, $url);

    if($category == "default") {
        curl_setopt($curl, CURLOPT_HTTPHEADER, [
            'Authorization: ' . $authorization,
            'Content-Type: application/json'
        ]);
    }else {
        curl_setopt($curl, CURLOPT_HTTPHEADER, [
            'Authorization: ' . $authorization,
            'Content-Type: application/json',
            'x-api-key: ' . $_COOKIE['onedionys_apillon_api_key'],
            'x-api-secret: ' . $_COOKIE['onedionys_apillon_api_key_secret']
        ]);
    }

    curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);

    $result = curl_exec($curl);

    if (curl_error($curl)) {
        return toObject(['error' => curl_error($curl)]);
    }

    $statusCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);

    switch ($statusCode) {
        case 200:
        case 201:
            break;
        case 400:
            return toObject(['error' => 'Bad request. Check the request data and try again.']);
        case 401:
            return toObject(['error' => 'Unauthorized. Invalid API key or API key secret.']);
        case 403:
            return toObject(['error' => 'Forbidden. Insufficient permissions or unauthorized access to record.']);
        case 404:
            return toObject(['error' => 'Path not found. Invalid endpoint or resource.']);
        case 422:
            return toObject(['error' => 'Data validation failed. Invalid or missing fields.']);
        case 500:
            return toObject(['error' => 'Internal server error. Please try again later.']);
        default:
            return toObject(['error' => "Received HTTP code $statusCode"]);
    }

    $decodedResult = json_decode($result);
    if (json_last_error() !== JSON_ERROR_NONE) {
        return toObject(['error' => json_last_error_msg()]);
    }

    return toObject([
        'id' => $decodedResult->id ?? null,
        'status' => $statusCode,
        'data' => $decodedResult->data ?? null
    ]);
}

function uploadToBucket($type = 'buckets', $fileData, $path = '', $wrapWithDirectory = false, $directoryPath = ''){
    global $authorization, $bucketUuid, $hostingUuid;

    $files = [];
    foreach ($fileData as $data) {
        $files[] = [
            'fileName' => $data['fileName'],
            'contentType' => $data['contentType'],
            'path' => $path
        ];
    }

    if (empty($files)) {
        return [
            'status' => 0,
            'status_code' => 422,
            'message' => "No files to upload, make sure the files folder is filled with something",
            "info_error" => 'Failed to upload the file.',
            'data' => null
        ];
    }

    $url = "https://api.apillon.io/storage/buckets/$bucketUuid/upload";
    if($type == "buckets") {
        $url = "https://api.apillon.io/storage/buckets/$bucketUuid/upload";
    }else if($type == "websites") {
        $url = "https://api.apillon.io/hosting/websites/$hostingUuid/upload";
    }
    $postData = ['files' => $files];

    $response = callAPI('POST', $url, $postData, $authorization);

    if (isset($response->error)) {
        return [
            'status' => 0,
            'status_code' => 422,
            'message' => "API Error: " . $response->error,
            "info_error" => 'Failed to upload the file.',
            'data' => null
        ];
    }

    if (isset($response->data->sessionUuid)) {
        $sessionUuid = $response->data->sessionUuid;
        $uploadUrls = $response->data->files;

        $uploadedFiles = [];
        foreach ($fileData as $index => $data) {
            $uploadUrl = $uploadUrls[$index]->url;
            $filePath = $data['filePath'];

            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $uploadUrl);
            curl_setopt($ch, CURLOPT_PUT, 1);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_INFILE, fopen($filePath, 'r'));
            curl_setopt($ch, CURLOPT_INFILESIZE, filesize($filePath));

            $uploadResponse = json_decode(curl_exec($ch), true);
            curl_close($ch);

            if (isset($uploadResponse['error'])) {
                return [
                    'status' => 0,
                    'status_code' => 422,
                    'message' => "File Upload Error: " . $uploadResponse['error'],
                    "info_error" => 'Failed to upload the file.',
                    'data' => null
                ];
            }

            $uploadedFiles[] = $uploadResponse;
        }

        $endUpload = endUploadSession(
            $sessionUuid,
            $authorization,
            $bucketUuid,
            $wrapWithDirectory,
            $directoryPath
        );

        return [
            'status' => 1,
            'status_code' => 200,
            'message' => "Successfully uploaded the file to " . ($type == "buckets" ? "storage" : "hosting"),
            "info_error" => null,
            'data' => null
        ];
    } else {
        return [
            'status' => 0,
            'status_code' => 422,
            'message' => "Failed to upload a file session",
            "info_error" => 'Failed to upload the file.',
            'data' => null
        ];
    }
}

function endUploadSession($sessionUuid, $authorization, $bucketUuid, $wrapWithDirectory = false, $directoryPath = '')
{
    $url = "https://api.apillon.io/storage/buckets/$bucketUuid/upload/$sessionUuid/end";
    $data = [
        'wrapWithDirectory' => $wrapWithDirectory,
        'directoryPath' => $directoryPath
    ];

    return callAPI('POST', $url, $data, $authorization);
}