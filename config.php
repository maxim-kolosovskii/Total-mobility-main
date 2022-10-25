<?php
return array(
    'redis.host' => 'localhost',
    'redis.port' => 6379,
    'postal_codes_key' => 'postal_codes',
    'gas_stations_key' => 'gas_stations',
    'locations.with.gas.station.file' => 'resources/laposte_hexasmal.csv',
    'locations.with.all.postal.codes.file' => 'resources/correspondance-code-insee-code-postal.csv',
    'access_token_endpoint' => 'https://login.salesforce.com/services/oauth2/token',
    'access_token_request_data' => [
        'grant_type' => 'password',
        'username' => 'xxxxxxxxxx',
        'password' => 'xxxxxxxxxx',
        'client_id' => 'xxxxxxxxxxxxxxx',
        'client_secret' => 'xxxxxxxxxxxxx'
    ],
    'find_company_by_id_endpoint' => 'https://salsa-totalenergies.my.salesforce.com/services/apexrest/diac/findbycompanyid',
    'card_prospect_upsert_endpoint' => 'https://mswebservices.infratotal.net/tp03/messages',
    'key_id' => 'xxxxxxxxxxxx'
);
