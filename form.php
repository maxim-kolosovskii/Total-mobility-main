<?php

$successMessage = "Merci, nous avons bien reçu votre demande. Un conseiller TOTAL MOBILITY va vous recontacter.";
$validationErrorMessage = "Veuillez remplir tous les champs obligatoires !";
$acceptErrorMessage = "Veuillez accepter la politique de protection des données personnelles.";
$technicalErrorMessage = "Le service est actuellement indisponible. Veuillez réessayer ultérieurement.";
$postalCodeErrorMessage = "Le code postal n'existe pas";

if (empty($_POST['firstname']) || empty($_POST['lastname'])
    || empty($_POST['corporate_name']) || empty($_POST['email'])
    || empty($_POST['address_zip_code']) || empty($_POST['siret_number'])
    || empty($_POST['office_phone'])) {
    http_response_code(400);
    exit($validationErrorMessage);
}

if (empty($_POST['accept'])) {
    http_response_code(400);
    exit($acceptErrorMessage);
}

try {
    require 'location_search.php';
    $config = require 'config.php';

    $tokenEndpoint = $config['access_token_endpoint'];
    $tokenRequestData = $config['access_token_request_data'];
    $findCompanyEndpoint = $config['find_company_by_id_endpoint'];

    $accessToken = getAccessToken($tokenEndpoint, $tokenRequestData);
    if (is_null($accessToken)) {
        exit($technicalErrorMessage);
    }

    $companyData = [
        'country' => 'FR',
        'companyId' => $_POST['siret_number']
    ];
    $companyResponse = getCompanyById($findCompanyEndpoint, $companyData, $accessToken);

    if (isUpsertNeeded($companyResponse)) {
        $city = "NA";
        $zipCode = $_POST['address_zip_code'];
        $gasStationLocation = findNearbyGasStation($_POST['address_zip_code']);

        if (empty($gasStationLocation)) {
            exit($postalCodeErrorMessage);
        }

        $locations = explode(",", findNearbyGasStation($_POST['address_zip_code']));
        if (!empty($locations[0])) {
            $zipCode = $locations[0];
        }
        if (!empty($locations[1])) {
            $city = $locations[1];
        }

        $cardProspectData = getCardProspectData([
            'siretNumber' => $_POST['siret_number'],
            'zipCode' => $zipCode,
            'email' => $_POST['email'],
            'firstName' => $_POST['firstname'],
            'lastName' => $_POST['lastname'],
            'phoneNumber' => $_POST['office_phone'],
            'corporateName' => $_POST['corporate_name'],
            'city' => $city
        ]);

        cardProspectUpsert(
            $config['card_prospect_upsert_endpoint'], $_POST['siret_number'],
            $cardProspectData, getJwtToken(), $config['key_id']
        );
    }

    exit($successMessage);
} catch (\Exception $e) {
    http_response_code(500);
    if (
        (intval($e->detail->ServiceFaultContract->FaultExceptionCode) >= 0 && intval($e->detail->ServiceFaultContract->FaultExceptionCode) <= 29) ||
        (intval($e->detail->ServiceFaultContract->FaultExceptionCode) == 104)
    ) {
        print($e->detail->ServiceFaultContract->FaultExceptionMessage);
    } else {
        print($technicalErrorMessage);
    }
}

function getAccessToken(string $url, array $data): ?string
{
    $response = callAPI("POST", $url, http_build_query($data));

    if (is_null($response)) {
        return NULL;
    }

    $json = json_decode($response, true);

    if (array_key_exists('access_token', $json)) {
        return $json['access_token'];
    }

    return NULL;
}

function getCompanyById(string $url, array $data, string $token)
{
    $response = callAPI("GET", $url, http_build_query($data), $token);

    if (is_null($response)) {
        return NULL;
    }

    return json_decode($response, true);
}

function isUpsertNeeded(array $res): bool
{
    if ( array_key_exists('found', $res) && $res['found'] == FALSE ) {
        return TRUE;
    }

    if (array_key_exists('found', $res) && array_key_exists('ModifiedMoreThanSixtyDays', $res)
        && $res['found'] == TRUE && $res['ModifiedMoreThanSixtyDays'] == TRUE) {
        return TRUE;
    }
    return FALSE;
}

function cardProspectUpsert(string $url, string $siretNumber, array $data, string $token, string $keyId)
{
    $curl = curl_init($url);
    curl_setopt($curl, CURLOPT_URL, $url);
    curl_setopt($curl, CURLOPT_POST, true);
    curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($data, true));
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);

    $headers = array(
        "Accept: application/json",
        "ProspectNumber: DCE" . $siretNumber,
        "sender:dce",
        "SFDC_STACK_DEPTH: 1",
        "version: 1",
        "operation: CREATE",
        "KeyId: " . $keyId,
        "Authorization: Bearer " . $token
    );
    curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);

    $result = curl_exec($curl);

    if (!curl_errno($curl)) {
        switch ($http_code = curl_getinfo($curl, CURLINFO_HTTP_CODE)) {
            case 201:
            case 200:  # OK
                break;
            default:
                exit('Unexpected HTTP code: ' . $http_code);
        }
    }

    curl_close($curl);

    return $result;
}

function callAPI($method, $url, $data = false, $token = null): ?string
{
    if ($method == "GET") {
        $url = $url . '?' . $data;
    }
    $curl = curl_init($url);
    curl_setopt($curl, CURLOPT_URL, $url);
    if ($method == "POST") {
        curl_setopt($curl, CURLOPT_POST, true);
        curl_setopt($curl, CURLOPT_POSTFIELDS, $data);
    }
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);

    $headers = array(
        "Accept: application/json"
    );
    if (!is_null($token)) {
        array_push($headers, "Authorization: Bearer " . $token);
    }
    curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);

    $result = curl_exec($curl);

    if (!curl_errno($curl)) {
        switch ($http_code = curl_getinfo($curl, CURLINFO_HTTP_CODE)) {
            case 201:
            case 200:  # OK
                break;
            default:
                exit('Unexpected HTTP code: ' . $http_code);
        }
    }

    curl_close($curl);

    return $result;
}

function getClosedDate(): string
{
    $date = date("Y-m-d");
    $date = strtotime(date("Y-m-d", strtotime($date)) . " +1 month");
    return date("Y-m-d", $date);
}

function getCardProspectData(array $data): array
{
    $siretNumber = $data['siretNumber'];
    $zipCode = $data['zipCode'];
    $email = $data['email'];
    $firstName = $data['firstName'];
    $lastName = $data['lastName'];
    $phoneNumber = $data['phoneNumber'];
    $corporateName = $data['corporateName'];
    $city = $data['city'];
    $externalId = getExternalId();
 
    $data = [
        "data" => [
            "socialEntity" => [
                "externalId" => $externalId,
                "subsidiary" => "0100-Total Marketing France",
                "registrationNumber" => substr("$siretNumber", 0, -5),
                "socialReason" => $corporateName,
                "country" => "FR",
                "physicalEntities" => [
                    [
                        "accountType" => "POSTPAID",
                        "status" => "PR",
                        "affiliate" => "TF",
                        "city" => $city,
                        "country" => "FR",
                        "currency" => "EUR",
                        "customerOwner" => "F0000030",
                        "establishment" => $corporateName,
                        "externalSocialEntityId" => $externalId,
                        "language" => "fr_FR",
                        "origin" => "DC",
                        "prospectNumber" => $externalId,
                        "streetName" => "N/A",
                        "subsidiary" => "0100-Total Marketing France",
                        "type" => "PROSPECT",
                        "zipCode" => $zipCode,
                        "ratcom" => "FR14",
                        "registrationNumber" => $siretNumber,
                        "contactCustomers" => [
                            [
                                "administrator" => true,
                                "decisionMaker" => true,
                                "externalContactId" => $externalId,
                                "mailRecipient" => false,
                                "principal" => true,
                                "prospectNumber" => $externalId,
                                "contact" => [
                                    "email" => $email,
                                    "firstName" => $firstName,
                                    "externalContactId" => $externalId,
                                    "lastName" => $lastName,
                                    "phoneNumber" => $phoneNumber
                                ]
                            ]
                        ],
                        "opportunity" => [
                            "closedDate" => getClosedDate(),
                            "name" => "DCE-" . date('Y-m-d'),
                            "stageName" => "Qualification",
                            "volume" => 200
                        ]
                    ]
                ]
            ]
        ]
    ];
    error_log("november-2021-prospect: ".json_encode($data));
    return $data;
}

function getJwtToken(): ?string
{
    $key = file_get_contents("resources/dce-total-key.pem");

    $headers = array(
        'alg' => 'RS256',
        'typ' => 'JWT',
        'issuer' => 'expert-annonce-auto.com',
        'subject' => 'L0000000',
        'exp' => '24h'
    );

    $payload = array(
        'JWT_ISS' => 'expert-annonce-auto.com',
        'JWT_SUB' => 'L0000000',
        'Sender' => 'DCE',
        'version' => '1',
        'operation' => 'CREATE'
    );

    return generateJwt($headers, $payload, $key);
}

function generateJwt($headers, $payload, $key): string
{
    $headers_encoded = base64urlEncode(json_encode($headers));

    $payload_encoded = base64urlEncode(json_encode($payload));

    //build the signature
    openssl_sign("$headers_encoded.$payload_encoded", $signature, $key, 'sha256WithRSAEncryption');
    $signature_encoded = base64urlEncode($signature);

    return "$headers_encoded.$payload_encoded.$signature_encoded";
}

function base64urlEncode($str): string
{
    return rtrim(strtr(base64_encode($str), '+/', '-_'), '=');
}

function getExternalId(): string
{
    $config = require 'config.php';
    $redis = new Redis();

    $redis->connect($config['redis.host'], $config['redis.port']);

    $number = $redis->incr("dceExternalId");

    $redis->close();

    return "DCE-".str_repeat('0', 9 - strlen($number)).$number;
}
