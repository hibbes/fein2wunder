<?php
// === Fehleranzeige für Entwicklung aktivieren ===
ini_set('display_errors', 1);
error_reporting(E_ALL);

// === Sensor-ID aus HTTP-Headern lesen (optional, von ESP übertragen) ===
$headers = [];
if (isset($_SERVER['HTTP_SENSOR'])) {
    $headers['Sensor'] = $_SERVER['HTTP_SENSOR'];
} elseif (isset($_SERVER['HTTP_X_SENSOR'])) {
    $headers['Sensor'] = $_SERVER['HTTP_X_SENSOR'];
} else {
    $headers['Sensor'] = "unknown";
}

// === JSON-Body lesen (POST-Daten vom Sensor) ===
$json = file_get_contents('php://input');

// === Logging: Die empfangenen Rohdaten zur Analyse sichern ===
file_put_contents(__DIR__ . "/incoming.log", date("Y-m-d H:i:s") . "\n" . $json . "\n---\n", FILE_APPEND);

// === JSON-Daten dekodieren ===
$results = json_decode($json, true);
$values = [];

// === Sensorwerte aus dem JSON extrahieren ===
if (isset($results["sensordatavalues"])) {
    foreach ($results["sensordatavalues"] as $entry) {
        $values[$entry["value_type"]] = $entry["value"];
    }
}

// === Mapping: Sensorwerte auf Wunderground-kompatible Felder übertragen ===
// Temperatur und Feuchtigkeit (w-Variante)
if (isset($values["temperature"]) && !isset($values["wtemperature"])) {
    $values["wtemperature"] = $values["temperature"];
}
if (isset($values["humidity"]) && !isset($values["whumidity"])) {
    $values["whumidity"] = $values["humidity"];
}

// Druck (in Pa) → hPa
if (isset($values["BMP_pressure"]) && !isset($values["wpressure"])) {
    $values["wpressure"] = $values["BMP_pressure"] / 100.0;
}

// === Parameter aus URL (z. B. Sensorhöhe, API-Key, Station-ID) ===
$values["altitude"]     = $_GET["alt"]   ?? "";
$values["key"]          = $_GET["key"]   ?? "";
$values["id"]           = $_GET["id"]    ?? $results["esp8266id"] ?? "unknown";
$values["bmpcalibrate"] = $_GET["bmpc"]  ?? "";

// === Signal ggf. kürzen (z. B. "-79dBm") → "-79" ===
if (isset($values["signal"])) {
    $values["signal"] = substr($values["signal"], 0, -4);
}

// === Temperatur in Fahrenheit für WU berechnen ===
if (!empty($values["wtemperature"])) {
    $values["fahrenheit"] = round($values["wtemperature"] * 1.8 + 32, 2);
} else {
    $values["fahrenheit"] = "";
}

// === Taupunkt und dewptf berechnen ===
if (!empty($values["wtemperature"]) && !empty($values["whumidity"])) {
    $dew = $values["wtemperature"] - ((100 - $values["whumidity"]) / 5.0);
    $values["dew"]     = round($dew, 2);
    $values["dewptf"]  = round($dew * 1.8 + 32, 2);
} else {
    $values["dewptf"] = "";
}

// === Druck auf Meereshöhe kalibrieren ===
if (!empty($values["wpressure"])) {
    if (!empty($values["altitude"])) {
        // physikalische Standardformel
        $calibrate = $values["wpressure"] / pow(1 - ($values["altitude"] / 44330.0), 5.255);
    } elseif (!empty($values["bmpcalibrate"])) {
        // alternativ: benutzerdefinierter Kalibrierfaktor
        $calibrate = $values["wpressure"] * $values["bmpcalibrate"];
    } else {
        // keine Kalibrierung möglich
        $calibrate = $values["wpressure"];
    }

    // === Umrechnung in inHg für Wunderground ===
    $values["baroinch"] = round($calibrate / 33.8638866667, 4);
} else {
    $values["baroinch"] = "";
}

// === Zeitstempel für Logging ===
$now   = gmdate("Y/m/d H:i:s");
$today = gmdate("Y-m-d");

// === Wunderground-URL bauen ===
$wunderurl = "";
$resp = "❌ fehlende Pflichtdaten";

if (
    !empty($values["fahrenheit"]) &&
    !empty($values["dewptf"]) &&
    !empty($values["baroinch"]) &&
    !empty($values["whumidity"])
) {
    $wunderurl = "https://weatherstation.wunderground.com/weatherstation/updateweatherstation.php"
        . "?ID=" . urlencode($values["id"])
        . "&PASSWORD=" . urlencode($values["key"])
        . "&dateutc=now"
        . "&tempf=" . $values["fahrenheit"]
        . "&dewptf=" . $values["dewptf"]
        . "&baromin=" . $values["baroinch"]
        . "&humidity=" . $values["whumidity"]
        . "&AqPM2.5=" . ($values["SDS_P2"] ?? "")
        . "&AqPM10="  . ($values["SDS_P1"] ?? "")
        . "&softwaretype=" . urlencode($headers["Sensor"])
        . "&action=updateraw";

    // === Daten an Wunderground senden ===
    $curl = curl_init();
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_URL => $wunderurl,
        CURLOPT_USERAGENT => 'ESP8266-WU-Client',
        CURLOPT_SSL_VERIFYPEER => false
    ]);
    $resp = curl_exec($curl);
    curl_close($curl);
}

// === CSV-Datei vorbereiten ===
$csvdir = __DIR__ . "/data";
if (!file_exists($csvdir)) {
    mkdir($csvdir, 0755, true);
}
$filename = $csvdir . "/data-" . $headers["Sensor"] . "-" . $today . ".csv";

// === CSV-Headerzeile (nur wenn Datei neu) ===
if (!file_exists($filename)) {
    file_put_contents($filename, implode(";", [
        "Time", "Altitude", "Temp", "Humidity", "Dew", "BMP_temp", "BMP_pressure", "BMP_calibrate", "Pressure_inHg",
        "BME280_temp", "BME280_humidity", "BME280_pressure", "PM10", "PM2_5", "Samples", "Min_cycle", "Max_cycle",
        "Signal", "wTemp", "wHumidity", "wPressure_hPa", "WU_ID", "WU_URL", "WU_Response"
    ]) . "\n");
}

// === CSV-Datenzeile anhängen ===
file_put_contents($filename, implode(";", [
    $now,
    $values["altitude"] ?? "",
    $values["temperature"] ?? "",
    $values["humidity"] ?? "",
    $values["dew"] ?? "",
    $values["BMP_temperature"] ?? "",
    $values["BMP_pressure"] ?? "",
    $values["bmpcalibrate"] ?? "",
    $values["baroinch"],
    $values["BME280_temperature"] ?? "",
    $values["BME280_humidity"] ?? "",
    $values["BME280_pressure"] ?? "",
    $values["SDS_P1"] ?? "",
    $values["SDS_P2"] ?? "",
    $values["samples"] ?? "",
    $values["min_micro"] ?? "",
    $values["max_micro"] ?? "",
    $values["signal"] ?? "",
    $values["wtemperature"] ?? "",
    $values["whumidity"] ?? "",
    $values["wpressure"] ?? "",
    $values["id"] ?? "",
    $wunderurl,
    $resp
]) . "\n", FILE_APPEND);
?>
