<?php

/**
 * Adds locations for all postal codes from resource file to redis.
 */
function addLocationsForPostalCodes()
{
    try {
        $config = require 'config.php';
        $redis = new Redis();
        //Connecting to Redis
        $redis->connect($config['redis.host'], $config['redis.port']);

        if (($handle = fopen($config['locations.with.all.postal.codes.file'], "r")) !== FALSE) {
            $row = 1;
            while (($data = fgetcsv($handle, 0, ";")) !== FALSE) {
                if ($row > 1) {
                    $code_postal = $data[1];
                    $longitude = null;
                    $latitude = null;
                    $coordinates = null;

                    if (!empty($data[9])) {
                        $coordinates = explode(",", $data[9]);
                        $longitude = $coordinates[0];
                        $latitude = $coordinates[1];
                    }

                    if ($code_postal != null && !empty($longitude) && !empty($latitude)) {
                        call_user_func_array(
                            array($redis, 'rawCommand'),
                            array('geoadd', $config['postal_codes_key'], trim($longitude), trim($latitude), trim($code_postal))
                        );
                    }
                }
                $row = $row + 1;
            }
            fclose($handle);
            $redis->close();
        }
    } catch (Exception $e) {
        print($e->getMessage());
    }
}

/**
 * Adds locations with gas stations from resource file to redis.
 */
function addLocationsWithGasStations()
{
    try {
        $config = require 'config.php';
        $redis = new Redis();
        //Connecting to Redis
        $redis->connect($config['redis.host'], $config['redis.port']);

        if (($handle = fopen($config['locations.with.gas.station.file'], "r")) !== FALSE) {
            $row = 1;
            while (($data = fgetcsv($handle, 0, ";")) !== FALSE) {
                if ($row > 1) {
                    $city = $data[1];
                    $code_postal = $data[2];
                    $longitude = null;
                    $latitude = null;
                    $coordinates = null;

                    if (!empty($data[5])) {
                        $coordinates = explode(",", $data[5]);
                        $longitude = $coordinates[0];
                        $latitude = $coordinates[1];
                    }

                    if ($code_postal != null && !empty($longitude) && !empty($latitude)) {
                        call_user_func_array(
                            array($redis, 'rawCommand'),
                            array('geoadd', $config['gas_stations_key'], trim($longitude), trim($latitude), trim($code_postal)."-".$city)
                        );
                    }
                }
                $row = $row + 1;
            }
            fclose($handle);
            $redis->close();
        }
    } catch (Exception $e) {
        print($e->getMessage());
    }
}

/**
 * Finds nearby location by specified postal code.
 *
 * @param $postal_code
 * @return string|null - comma separated string where first element is postal code, second and third elements are coordinates.
 *
 */
function findNearbyGasStation($postal_code): ?string
{
    $result = null;
    $location = null;

    try {
        $config = require 'config.php';
        $redis = new Redis();
        //Connecting to Redis
        $redis->connect($config['redis.host'], $config['redis.port']);

        //Get the geo position by postal code
        $pos = call_user_func_array(
            array($redis, 'rawCommand'),
            array('geopos', $config['postal_codes_key'], $postal_code)
        );

        if (!empty($pos) && is_array($pos) && count($pos) > 0) {
            if(count($pos[0]) > 0) {
                $geo_radius = call_user_func_array(
                    array($redis, 'rawCommand'),
                    array('georadius', $config['gas_stations_key'], $pos[0][0], $pos[0][1], '100', 'km', 'WITHDIST', 'WITHCOORD', 'ASC')
                );
            }

            if (!empty($geo_radius) && is_array($geo_radius)) {
                foreach ($geo_radius as $geo_position) {
                    $address = explode("-", $geo_position[0]);
                    $postal_code = $address[0];
                    $city = $address[1];

                    if (empty($location)) {
                        $location = array(
                            "postal_code" => $postal_code,
                            "city" => $city,
                            "dist" => $geo_position[1],
                            "longitude" => $geo_position[2][0],
                            "latitude" => $geo_position[2][1],
                        );
                    }

                    if (floatval($location["dist"]) > floatval($geo_position[1])) {
                        $location = array(
                            "postal_code" => $postal_code,
                            "city" => $city,
                            "dist" => $geo_position[1],
                            "longitude" => $geo_position[2][0],
                            "latitude" => $geo_position[2][1],
                        );
                    }
                }
            }
        }

        if (!empty($location)) {
            unset($location["dist"]);
            $result = implode(",", $location);
        }

        $redis->close();
    } catch (Exception $e) {
        print($e->getMessage());
    }

    return $result;
}

