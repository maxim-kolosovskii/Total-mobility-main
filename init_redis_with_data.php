<?php
require 'location_search.php';

addLocationsForPostalCodes();
addLocationsWithGasStations();

$result = findNearbyGasStation("92300");

echo $result;
