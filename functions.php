<?php
    use WHMCS\Database\Capsule;

    /**
     * Obtain a cURL handle given a URL, POST data and optional extra options
     * @param $URL
     * @param $data
     * @param array $extra_opts
     * @return resource
     */
    function getCurlHandle($URL, $data, $extra_opts=[]) {
        $ch = curl_init();

        curl_setopt($ch, CURLOPT_URL,  $URL);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT ,30);
        curl_setopt($ch, CURLOPT_TIMEOUT, 400); // Timeout in seconds

        foreach ($extra_opts as $opt => $value) {
            curl_setopt($ch, $opt, $value);
        }

        return $ch;
    }

    /**
     * Send a request to the given API endpoint on the server.
     * @param array $params
     * @param array $data API parameters to supply
     * @param string $endPoint Admin API to use
     * @param bool $assoc (Optional) Return associative array instead of object
     * @return array|object|string
     */
    function performAPIRequest($params, $data, $endPoint, $assoc=true){
        $query = http_build_query($data);
        $ch = getCurlHandle(
            getHost($params).'/api/v1/admin/'.$endPoint,
            $query,
            [CURLOPT_FOLLOWLOCATION => true]
        );

        $response = curl_exec($ch);

        if ($error = curl_error($ch)){
            $ret = ['curlerror' => $error];
        } else {
            $ret = json_decode($response, $assoc);
        }

        curl_close($ch);
        return $ret;
    }

    /**
     * Stream a client software download.
     * @param array $params
     * @param array $data Request data
     * @param string $action API endpoint
     * @return string
     */
    function softwareDownload($params, $data, $action) {
        $query = http_build_query($data);
        $ch = getCurlHandle(
            getHost($params).'/api/v1/admin/'.$action,
            $query,
            [CURLOPT_FOLLOWLOCATION => true]
        );

        // Streaming download
        return curl_exec($ch);
    }

    /**
     * Format bytes count into human-readable form.
     * @param $bytes
     * @return string
     */
    function formatBytes($bytes) {
        if ($bytes >= 1073741824) {
            $ret = number_format($bytes / 1073741824, 2) . ' GB';

        } else if ($bytes >= 1048576) {
            $ret = number_format($bytes / 1048576, 2) . ' MB';

        } else if ($bytes >= 1024) {
            $ret = number_format($bytes / 1024, 2) . ' KB';

        } else if ($bytes > 1) {
            $ret = $bytes . ' bytes';

        } else if ($bytes == 1) {
            $ret = $bytes . ' byte';

        } else {
            $ret = '0 bytes';
        }

        return $ret;
    }

    /**
     * Convert Comet job classification code to human readable string
     *
     * @param int $code
     * @return string
     */
    function formatJobType($code) {
        switch ($code) {
            case 4001:
                $ret = 'Backup';
                break;
            case 4002:
                $ret = 'Restore';
                break;
            case 4003:
                $ret = 'Retention';
                break;
            case 4004:
                $ret = 'Vault Unlock';
                break;
            case 4005:
                $ret = 'Snapshot Deletion';
                break;
            case 4006:
                $ret = 'Re-measure Vault Size';
                break;
            case 4007:
                $ret = 'Software Update';
                break;
            case 4008:
                $ret = 'Import';
                break;
            case 4000:
            default:
                $ret = 'Unknown';
        }

        return $ret;
    }

    /**
     * Convert Comet status code to human readable form.
     * @param $code
     * @return string
     */
    function formatStatusType($code) {
        if ($code >= 5000 && $code <= 5999) {
            $ret = 'Running';
        } else if ($code >= 6000 && $code <= 6999) {
            $ret = 'Success';
        } else {
            $ret = 'Error';
        }

        return $ret;
    }

    /**
     * Escape html entities (safe for use within `<tag properties="">' as well)
     * n.b. ENT_SUBSTITUTE means PHP 5.4.0+
     *
     * @param string $str Raw text
     * @return string HTML
     */
    function hesc($str) {
        return @htmlentities($str, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * Outputs API response message or a generic error message containing encoded response details if no API response message present
     * @param array $response
     * @param bool $noHTML
     * @return string;
     */
    function handleErrorResponse($response, $noHTML=false) {
        if (isset($response['Message'])) {
            return $response['Message'];
        } else {
            return 'An error has occurred - please contact support. '.($noHTML ? ' Error detail: ' : '<br> <span style="color:darkred;"> Error detail:</span>').base64_encode(var_export($response, true));
        }
    }

    /**
     * Sets the suspension state of an account to the provided value
     * @param array $params
     * @param bool $suspended
     * @return string
     */
    function modifyAccountSuspensionState($params, $suspended) {
        $baseRequestData = [
            'Username' => $params['serverusername'],
            'AuthType' => 'Password',
            'Password' => $params['serverpassword'],
            'TargetUser' => $params['username']
        ];

        $profile = performAPIRequest(
            $params,
            $baseRequestData,
            'get-user-profile-and-hash',
            false
        );

        if (is_object($profile) && property_exists($profile, 'ProfileHash')) {
            $profileData = $profile->Profile;
            $profileData->IsSuspended = $suspended; // Modify suspension state value

            $response = performAPIRequest(
                $params,
                $baseRequestData + [
                    'ProfileData' => json_encode($profileData),
                    'RequireHash' => $profile->ProfileHash
                ],
                'set-user-profile-hash'
            );

            if (array_key_exists('Status', $response) && $response['Status'] === 200) {
                return 'success';
            } else {
                return handleErrorResponse($response, true);
            }
        } else {
            return "Error Fetching User.";
        }
    }

    /**
     * Checks for the existence of a policy group on the Comet server and creates it if necessary.
     * @param array $params
     * @param string $policyGroupGUID
     */
    function maybeCreatePolicyGroup($params, $policyGroupGUID) {
        $baseRequestData = [
            'Username' => $params['serverusername'],
            'AuthType' => 'Password',
            'Password' => $params['serverpassword'],
            'PolicyID' => $policyGroupGUID
        ];

        $policy = performAPIRequest(
            $params,
            $baseRequestData,
            'policies/get'
        );

        if (empty($policy['PolicyHash'])) {
            performAPIRequest(
                $params,
                $baseRequestData + [
                    'Policy' => json_encode([
                        'Description' => $policyGroupGUID,
                        'Policy' => [
                            'PreventChangeAccountPassword' => true,
                            'ModeAdminResetPassword' => 3,
                            'PreventDeleteStorageVault' => true,
                            'PreventAddCustomStorageVault' => true,
                            'PreventRequestStorageVault' => true,
                            'StorageVaultProviders' => [
                                'AllowedProvidersWhenRestricted' => [1003],
                                'ShouldRestrictProviderList' => true
                            ],
                            'ProtectedItemEngineTypes' => [
                                'AllowedEngineTypeWhenRestricted' => [],
                                'ShouldRestrictEngineTypeList' => false
                            ],
                        ]
                    ])
                ],
                'policies/set'
            );
        }
    }

    /**
     * Retrieves a user profile and updates it to reflect the currently configured account restrictions in WHMCS.
     * @param array $params
     * @return string
     */
    function applyRestrictions($params) {
        // Prepare base API request params
        $baseRequestData = [
            'Username' => $params['serverusername'],
            'AuthType' => 'Password',
            'Password' => $params['serverpassword'],
            'TargetUser' => $params['username'],
        ];

        // Retrieve profile content for modification
        $profile = performAPIRequest($params, $baseRequestData,'get-user-profile-and-hash', false);

        // Sanity check
        if (is_object($profile) && property_exists($profile, 'ProfileHash')) {
            $profileData = $profile->Profile;

            // Apply storage vault quota if set
            if (
                !empty($params['configoptions']['storage_vault_quota_gb']) &&
                !empty($profileData->Destinations)
            ) {
                foreach (array_keys((array)$profileData->Destinations) as $destinationGUID) {
                    $profileData->Destinations->$destinationGUID->StorageLimitEnabled = true;
                    $profileData->Destinations->$destinationGUID->StorageLimitBytes = intval($params['configoptions']['storage_vault_quota_gb']) * pow(1024, 3);
                }
            }

            // Apply protected items quota if set
            $profileData->AllProtectedItemsQuotaEnabled     = !empty($params['configoptions']['protected_item_quota_gb']);
            $profileData->AllProtectedItemsQuotaBytes       = (empty($params['configoptions']['protected_item_quota_gb']) ? 0 : intval($params['configoptions']['protected_item_quota_gb']) * pow(1024,3));

            // Apply device quota if set
            $profileData->MaximumDevices                    = intval((empty($params['configoptions']['number_of_devices']) ? 0 : intval($params['configoptions']['number_of_devices'])));

            // Apply policy group if set
            $profileData->PolicyID                          = (empty($params['configoption1']) ? '' : $params['configoption1']);

            // Prepare request
            $updateProfileRequestData = $baseRequestData + [
                    'ProfileData'           => json_encode($profileData),
                    'RequireHash'           => $profile->ProfileHash
                ];

            // Update user
            $response = performAPIRequest($params, $updateProfileRequestData,'set-user-profile-hash');

            if (isset($response['Status']) && $response['Status'] == 200) {
                return 'success';
            } else {
                return 'Account creation succeeded, however an error occurred during configuration - please check to confirm whether account details are correct. Error detail: '.base64_encode(var_export($response, true));
            }
        } else {
            return 'Couldn\'t retrieve profile';
        }
    }

    /**
     * Produce a usable server hostname
     * @param $params
     * @return string
     */
    function getHost($params) {
        $hostname =  preg_replace(["^http://^i","^https://^i", "^/^"],"", $params['serverhostname']);
        return $params['serverhttpprefix'].'://'.$hostname;
    }
