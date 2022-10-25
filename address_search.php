<?php
$find = $_GET['term'];

try {
    //php-soap must be in the list of installed PHP modules!!!
    $client = new SoapClient( 'https://total-service.etocrm.fr/ReferentialPlateformService.svc?wsdl',array('trace' => true) );
/*    echo "<pre>";
        print_r($client->__getTypes());
        print_r($client->__getFunctions());
    echo "</pre>";
    die();*/

    //CREDENTIALS
    $profileName = 'DceReferential'; //Profile name is inserted here
    $key = 'LDZ)q03dkaMQ'; //Private Key is inserted here

    $salt = uniqid('',true);
    $token = hash('sha256',$salt.$key);
    $params = [
        'serviceProfilName' => $profileName,
        'saltKey' => $salt,
        'token' => $token,
        'requestInfos' => [
            'LocationArgType' => 'City',
            'LocationValue' => $find
        ]
    ];
    $response = $client->RetrieveCRMCityPostalCodeList($params);
    $data = $response->RetrieveCRMCityPostalCodeListResult->CityPostalCodeList->CityPostalCodeDataContract;
    $cities = array_map(function($element) {
        return $element->City;
    }, $data);

    header('Content-Type: application/json');
    print(json_encode(array_unique($cities)));
} catch (\Exception $e) {
    http_response_code(500);
    if (
        (intval($e->detail->ServiceFaultContract->FaultExceptionCode) >= 0 && intval($e->detail->ServiceFaultContract->FaultExceptionCode) <= 29 ) ||
        (intval($e->detail->ServiceFaultContract->FaultExceptionCode) == 104)
    ){
        print($e->detail->ServiceFaultContract->FaultExceptionMessage);
    } else {
        print('Error!');
    }
}