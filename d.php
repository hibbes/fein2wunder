<?php
// === Fehleranzeige deaktivieren (Produktion) ===
ini_set('display_errors', 0);
error_reporting(0);

// === Content-Type prüfen (JSON erwartet) ===
$contentType = $_SERVER['CONTENT_TYPE'] ?? '';
if (strpos($contentType, 'application/json') === false) {
    error_log("d.php: Unexpected Content-Type: " . $contentType);
    http_response_code(415);
    exit;
}

// === Sensor-ID aus HTTP-Headern lesen (optional, von ESP übertragen) ===
$rawSensor = "";
if (isset($_SERVER['HTTP_SENSOR'])) {
    $rawSensor = $_SERVER['HTTP_SENSOR'];
} elseif (isset($_SERVER['HTTP_X_SENSOR'])) {
    $rawSensor = $_SERVER['HTTP_X_SENSOR'];
} else {
    $rawSensor = "unknown";
}
// Path-Traversal-Sanitierung: nur alphanumerische Zeichen, Bindestrich und Unterstrich erlaubt
$headers['Sensor'] = preg_replace('/[^a-zA-Z0-9_-]/', '', $rawSensor);
if ($headers['Sensor'] === '') {
    $headers['Sensor'] = 'unknown';
}

// === JSON-Body lesen (POST-Daten vom Sensor) ===
// Content-Length begrenzen (max. 64 KB)
$maxInputSize = 65536;
$json = file_get_contents('php://input', false, null, 0, $maxInputSize);

// === Logging: Die empfangenen Rohdaten zur Analyse sichern ===
file_put_contents(__DIR__ . "/incoming.log", date("Y-m-d H:i:s") . "\n" . $json . "\n---\n", FILE_APPEND);

// === JSON-Daten dekodieren ===
$results = json_decode($json, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    error_log("d.php: JSON decode error: " . json_last_error_msg());
    http_response_code(400);
    exit;
}
$values = [];

// === Sensorwerte aus dem JSON extrahieren ===
if (isset($results["sensordatavalues"]) && is_array($results["sensordatavalues"])) {
    foreach ($results["sensordatavalues"] as $entry) {
        if (isset($entry["value_type"], $entry["value"])) {
            $values[$entry["value_type"]] = $entry["value"];
        }
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

// === Parameter aus URL (z. B. Sensorhöhe, Station-ID) validieren ===
// API-Key wird nur für den Wunderground-Request verwendet, nicht gespeichert
$values["altitude"]     = isset($_GET["alt"])  ? floatval($_GET["alt"])  : 0.0;
$values["key"]          = isset($_GET["key"])  ? trim($_GET["key"])      : "";
$values["id"]           = isset($_GET["id"])   ? trim($_GET["id"])       : (isset($results["esp8266id"]) ? trim($results["esp8266id"]) : "unknown");
$values["bmpcalibrate"] = isset($_GET["bmpc"]) ? floatval($_GET["bmpc"]) : 0.0;

// === Signal ggf. kürzen (z. B. "-79dBm") → "-79" ===
if (isset($values["signal"])) {
    $values["signal"] = substr($values["signal"], 0, -4);
}

// === Temperatur in Fahrenheit für WU berechnen ===
if (!empty($values["wtemperature"])) {
    $values["fahrenheit"] = round(floatval($values["wtemperature"]) * 1.8 + 32, 2);
} else {
    $values["fahrenheit"] = "";
}

// === Taupunkt und dewptf berechnen ===
if (!empty($values["wtemperature"]) && !empty($values["whumidity"])) {
    $humidity = floatval($values["whumidity"]);
    // Division-by-Zero-Schutz: Nenner ist immer 5.0, kein Risiko hier,
    // aber wir stellen sicher, dass whumidity ein valider Float ist
    $dew = floatval($values["wtemperature"]) - ((100 - $humidity) / 5.0);
    $values["dew"]     = round($dew, 2);
    $values["dewptf"]  = round($dew * 1.8 + 32, 2);
} else {
    $values["dewptf"] = "";
}

// === Druck auf Meereshöhe kalibrieren ===
if (!empty($values["wpressure"])) {
    $wpressure = floatval($values["wpressure"]);
    if (!empty($values["altitude"])) {
        $altitudeVal = floatval($values["altitude"]);
        // Division-by-Zero-Schutz für pow()-Basis
        $base = 1 - ($altitudeVal / 44330.0);
        if ($base <= 0) {
            error_log("d.php: Ungültige Höhe führt zu ungültigem Drucknennwert.");
            $calibrate = $wpressure;
        } else {
            $calibrate = $wpressure / pow($base, 5.255);
        }
    } elseif (!empty($values["bmpcalibrate"])) {
        $bmpcalibrateVal = floatval($values["bmpcalibrate"]);
        // Division-by-Zero-Schutz: hier wird multipliziert, kein Divisor
        $calibrate = $wpressure * $bmpcalibrateVal;
    } else {
        $calibrate = $wpressure;
    }

    // === Umrechnung in inHg für Wunderground ===
    // Division-by-Zero-Schutz (Konstante ist != 0, aber explizit geprüft)
    $divisor = 33.8638866667;
    $values["baroinch"] = ($divisor != 0) ? round($calibrate / $divisor, 4) : "";
} else {
    $values["baroinch"] = "";
}

// === Zeitstempel für Logging ===
$now   = gmdate("Y/m/d H:i:s");
$today = gmdate("Y-m-d");

// === Wunderground-URL bauen ===
$wunderurl = "";
$resp = "fehlende Pflichtdaten";

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
        . "&AqPM2.5=" . urlencode($values["SDS_P2"] ?? "")
        . "&AqPM10="  . urlencode($values["SDS_P1"] ?? "")
        . "&softwaretype=" . urlencode($headers["Sensor"])
        . "&action=updateraw";

    // === Daten an Wunderground senden ===
    $curl = curl_init();
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_URL => $wunderurl,
        CURLOPT_USERAGENT => 'ESP8266-WU-Client',
        CURLOPT_SSL_VERIFYPEER => true
    ]);
    $resp = curl_exec($curl);
    // curl Fehlerbehandlung
    if ($resp === false) {
        error_log("d.php: curl_exec fehlgeschlagen: " . curl_error($curl));
        $resp = "curl error";
    }
    curl_close($curl);
}

// === CSV-Datei vorbereiten ===
$csvdir = __DIR__ . "/data";
if (!file_exists($csvdir)) {
    mkdir($csvdir, 0750, true);
}
$filename = $csvdir . "/data-" . $headers["Sensor"] . "-" . $today . ".csv";

// === CSV-Escape-Funktion gegen CSV-Injection ===
function csvEscape($value) {
    $value = (string)$value;
    // Escape Werte, die mit gefährlichen Zeichen beginnen (CSV-Injection)
    if (strlen($value) > 0 && in_array($value[0], ['=', '+', '@', '-', "\t", "\r"])) {
        $value = "'" . $value;
    }
    return $value;
}

// === CSV-Headerzeile (nur wenn Datei neu) ===
if (!file_exists($filename)) {
    file_put_contents($filename, implode(";", [
        "Time", "Altitude", "Temp", "Humidity", "Dew", "BMP_temp", "BMP_pressure", "BMP_calibrate", "Pressure_inHg",
        "BME280_temp", "BME280_humidity", "BME280_pressure", "PM10", "PM2_5", "Samples", "Min_cycle", "Max_cycle",
        "Signal", "wTemp", "wHumidity", "wPressure_hPa", "WU_ID", "WU_Response"
    ]) . "\n");
}

// === CSV-Datenzeile anhängen (API-Key wird NICHT gespeichert) ===
$csvRow = array_map('csvEscape', [
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
    $resp
]);
file_put_contents($filename, implode(";", $csvRow) . "\n", FILE_APPEND);
?>
