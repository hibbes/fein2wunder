<?php
// --- Fehleranzeige aktivieren ---
ini_set('display_errors', 1);
error_reporting(E_ALL);

// --- Header lesen ---
$headers = [];
if (isset($_SERVER['HTTP_SENSOR'])) $headers['Sensor'] = $_SERVER['HTTP_SENSOR'];
if (isset($_SERVER['HTTP_X_SENSOR'])) $headers['Sensor'] = $_SERVER['HTTP_X_SENSOR'];

// --- JSON-Body lesen ---
$json = file_get_contents('php://input');
file_put_contents(__DIR__ . "/incoming.log", date("Y-m-d H:i:s") . "\n" . $json . "\n---\n", FILE_APPEND);

$results = json_decode($json, true);
$now = gmdate("Y/m/d H:i:s");
$today = gmdate("Y-m-d");

// --- Sensorwerte extrahieren ---
$values = [];
if (isset($results["sensordatavalues"])) {
    foreach ($results["sensordatavalues"] as $entry) {
        $values[$entry["value_type"]] = $entry["value"];
    }
}

// --- Mapping: Temperatur, Feuchtigkeit, Druck auf WU-Formate ---
if (!isset($values["wtemperature"]) && isset($values["temperature"])) {
    $values["wtemperature"] = $values["temperature"];
}
if (!isset($values["whumidity"]) && isset($values["humidity"])) {
    $values["whumidity"] = $values["humidity"];
}
if (!isset($values["wpressure"]) && isset($values["BMP_pressure"])) {
    $values["wpressure"] = $values["BMP_pressure"] / 100.0; // Pa → hPa
}
if (!isset($values["SDS_P1"])) $values["SDS_P1"] = "";
if (!isset($values["SDS_P2"])) $values["SDS_P2"] = "";

// --- Weitere Pflichtfelder setzen ---
$values["key"]        = $_GET["key"]    ?? "";
$values["id"]         = $_GET["id"]     ?? ($_GET["sensor"] ?? "UNKNOWN");
$values["altitude"]   = $_GET["alt"]    ?? "";
$values["bmpcalibrate"] = $_GET["bmpc"] ?? "";
$values["signal"]     = isset($values["signal"]) ? substr($values["signal"], 0, -4) : "";

// --- Fahrenheit berechnen ---
if ($values["wtemperature"] !== "") {
    $values["fahrenheit"] = round(($values["wtemperature"] * 1.8) + 32, 2);
} else {
    $values["fahrenheit"] = "";
}

// --- Taupunkt berechnen ---
if ($values["wtemperature"] !== "" && $values["whumidity"] !== "") {
    $dew = $values["wtemperature"] - ((100 - $values["whumidity"]) / 5.0);
    $values["dew"] = round($dew, 2);
    $values["dewptf"] = round(($dew * 1.8) + 32, 2);
} else {
    $values["dewptf"] = "";
}

// --- Luftdruck in inHg umrechnen ---
if ($values["wpressure"] !== "") {
    $values["baroinch"] = round($values["wpressure"] / 33.8638866667, 4);
} else {
    $values["baroinch"] = "";
}

// --- Wunderground-URL erzeugen ---
if ($values["fahrenheit"] !== "" && $values["dewptf"] !== "" && $values["baroinch"] !== "" && $values["whumidity"] !== "") {
    $wunderurl = "https://weatherstation.wunderground.com/weatherstation/updateweatherstation.php"
        . "?ID=" . urlencode($values["id"])
        . "&PASSWORD=" . urlencode($values["key"])
        . "&dateutc=now"
        . "&tempf=" . $values["fahrenheit"]
        . "&dewptf=" . $values["dewptf"]
        . "&baromin=" . $values["baroinch"]
        . "&humidity=" . $values["whumidity"]
        . "&AqPM2.5=" . $values["SDS_P2"]
        . "&AqPM10=" . $values["SDS_P1"]
        . "&softwaretype=" . ($headers['Sensor'] ?? 'ESP')
        . "&action=updateraw";

    // --- Curl senden ---
    $curl = curl_init();
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_URL => $wunderurl,
        CURLOPT_USERAGENT => 'ESP-WU-Upload',
        CURLOPT_SSL_VERIFYPEER => false
    ]);
    $resp = curl_exec($curl);
    curl_close($curl);
} else {
    $wunderurl = "";
    $resp = "❌ Daten unvollständig – kein WU-Upload.";
}

// --- CSV-Datei schreiben ---
$csvdir = __DIR__ . "/data";
if (!file_exists($csvdir)) mkdir($csvdir, 0755, true);

$datafile = $csvdir . "/data-" . ($headers["Sensor"] ?? "unknown") . "-" . $today . ".csv";
if (!file_exists($datafile)) {
    file_put_contents($datafile,
        "Time;Altitude;Temp;Humidity;Dew;BMP_temperature;BMP_pressure;BMP_calibrate;P_Inches;BME280_temperature;BME280_humidity;BME280_pressure;PM2_5;PM10;Samples;Min_cycle;Max_cycle;Signal;wTemperature;wHumidity;wPressure;WunderID;WunderURL;WunderResponse\n"
    );
}

file_put_contents($datafile, implode(";", [
    $now,
    $values["altitude"] ?? "",
    $values["temperature"] ?? "",
    $values["humidity"] ?? "",
    $values["dew"] ?? "",
    $values["BMP_temperature"] ?? "",
    $values["BMP_pressure"] ?? "",
    $values["bmpcalibrate"] ?? "",
    $values["baroinch"] ?? "",
    $values["BME280_temperature"] ?? "",
    $values["BME280_humidity"] ?? "",
    $values["BME280_pressure"] ?? "",
    $values["SDS_P1"],
    $values["SDS_P2"],
    $values["samples"] ?? "",
    $values["min_micro"] ?? "",
    $values["max_micro"] ?? "",
    $values["signal"],
    $values["wtemperature"],
    $values["whumidity"],
    $values["wpressure"],
    $values["id"],
    $wunderurl,
    $resp
]) . "\n", FILE_APPEND);
?>
