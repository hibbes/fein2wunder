<?php
/**
 * fein2wunder – Feinstaubsensor-Bridge zu Weather Underground
 *
 * Empfängt JSON-Daten eines OK-Lab-Feinstaubsensors (luftdaten.info / sensor.community)
 * per HTTP-POST, konvertiert sie ins Weather Underground (WU)-Format und leitet sie
 * an die WU-API weiter. Die Rohdaten werden zusätzlich in einer täglichen CSV-Datei gespeichert.
 *
 * Aufruf-URL (aus der Sensor-Konfiguration):
 *   http://dein-server/d.php?id=STATIONSID&key=APIKEY
 *
 * Optionale URL-Parameter:
 *   alt=360          Stationshöhe in Metern über NN (für Druckkorrektur)
 *   bmpc=1.01234     Alternativer Multiplikator zur Druckkorrektur
 *   t=1|2|3          Temperatursensor-Auswahl (1=DHT22, 2=BMP180, 3=BME280)
 *   h=1|2|3          Feuchtigkeitssensor-Auswahl (1=DHT22, 2=BMP180, 3=BME280)
 *   p=1|2            Drucksensor-Auswahl (1=BMP180, 2=BME280)
 *
 * Voraussetzungen:
 *   - PHP mit cURL-Erweiterung
 *   - Schreibrechte auf __DIR__ für Logdatei und CSV-Ordner
 *
 * @author  hibbes
 * @license MIT
 */

// === Fehleranzeige deaktivieren (Produktion) ===
ini_set('display_errors', 0);
error_reporting(0);

// === Content-Type prüfen (JSON erwartet) ===
$contentType = $_SERVER['CONTENT_TYPE'] ?? '';
if (strpos($contentType, 'application/json') === false) {
    error_log("d.php: Unerwarteter Content-Type: " . $contentType);
    http_response_code(415);  // 415 Unsupported Media Type
    exit;
}

// === Sensor-ID aus HTTP-Headern lesen (optional, vom ESP gesendet) ===
$rawSensor = $_SERVER['HTTP_SENSOR'] ?? $_SERVER['HTTP_X_SENSOR'] ?? 'unknown';

// Path-Traversal-Schutz: nur alphanumerische Zeichen, Bindestrich und Unterstrich erlaubt
$headers['Sensor'] = preg_replace('/[^a-zA-Z0-9_-]/', '', $rawSensor);
if ($headers['Sensor'] === '') {
    $headers['Sensor'] = 'unknown';
}

// === JSON-Body lesen (POST-Nutzdaten vom Sensor) ===
// Eingabe auf 64 KB begrenzen, um Speicherprobleme zu vermeiden
$json = file_get_contents('php://input', false, null, 0, 65536);

// === Rohdaten protokollieren (zur Fehlersuche) ===
file_put_contents(__DIR__ . "/incoming.log",
    date("Y-m-d H:i:s") . "\n" . $json . "\n---\n",
    FILE_APPEND
);

// === JSON dekodieren ===
$results = json_decode($json, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    error_log("d.php: JSON-Fehler: " . json_last_error_msg());
    http_response_code(400);  // 400 Bad Request
    exit;
}

// === Alle Sensorwerte aus dem sensordatavalues-Array extrahieren ===
// Format: [{"value_type": "temperature", "value": "21.30"}, ...]
$values = [];
if (isset($results["sensordatavalues"]) && is_array($results["sensordatavalues"])) {
    foreach ($results["sensordatavalues"] as $entry) {
        if (isset($entry["value_type"], $entry["value"])) {
            $values[$entry["value_type"]] = $entry["value"];
        }
    }
}

// ═══════════════════════════════════════════════════════════════════════════
// SENSOR-AUSWAHL über URL-Parameter t, h, p
// Ermöglicht dem Betreiber, verschiedene Sensoren desselben Knotens zu wählen.
// ═══════════════════════════════════════════════════════════════════════════

// --- Temperatursensor: t=1 (DHT22, Standard), t=2 (BMP180), t=3 (BME280) ---
$tParam = isset($_GET['t']) ? intval($_GET['t']) : 1;
switch ($tParam) {
    case 2:  // BMP180-Temperatursensor
        $values['wtemperature'] = $values['BMP_temperature'] ?? $values['temperature'] ?? null;
        break;
    case 3:  // BME280-Temperatursensor
        $values['wtemperature'] = $values['BME280_temperature'] ?? $values['temperature'] ?? null;
        break;
    default: // DHT22 (Standard)
        $values['wtemperature'] = $values['temperature'] ?? null;
        break;
}

// --- Feuchtigkeitssensor: h=1 (DHT22, Standard), h=3 (BME280) ---
$hParam = isset($_GET['h']) ? intval($_GET['h']) : 1;
switch ($hParam) {
    case 3:  // BME280-Feuchtigkeit
        $values['whumidity'] = $values['BME280_humidity'] ?? $values['humidity'] ?? null;
        break;
    default: // DHT22 (Standard)
        $values['whumidity'] = $values['humidity'] ?? null;
        break;
}

// --- Drucksensor: p=1 (BMP180, Standard), p=2 (BME280) ---
$pParam = isset($_GET['p']) ? intval($_GET['p']) : 1;
switch ($pParam) {
    case 2:  // BME280-Druck (Wert direkt in Pa)
        $rawPressure = $values['BME280_pressure'] ?? $values['BMP_pressure'] ?? null;
        break;
    default: // BMP180-Druck (Wert in Pa)
        $rawPressure = $values['BMP_pressure'] ?? null;
        break;
}
// Druck von Pascal (Pa) in Hektopascal (hPa) umrechnen
if ($rawPressure !== null && !isset($values['wpressure'])) {
    $values['wpressure'] = floatval($rawPressure) / 100.0;
}

// ═══════════════════════════════════════════════════════════════════════════
// URL-Parameter: Stations-ID, API-Key, Höhe, Druckkalibrierung
// ═══════════════════════════════════════════════════════════════════════════
$values['altitude']     = isset($_GET['alt'])  ? floatval($_GET['alt'])  : 0.0;
$values['key']          = isset($_GET['key'])  ? trim($_GET['key'])      : '';
$values['id']           = isset($_GET['id'])   ? trim($_GET['id'])
                         : (isset($results['esp8266id']) ? trim($results['esp8266id']) : 'unknown');
$values['bmpcalibrate'] = isset($_GET['bmpc']) ? floatval($_GET['bmpc']) : 0.0;

// === Signal: Einheit abschneiden ("-79dBm" → "-79") ===
// Nur kürzen, wenn das Signal-String tatsächlich mit "dBm" endet
if (isset($values['signal']) && substr($values['signal'], -3) === 'dBm') {
    $values['signal'] = substr($values['signal'], 0, -3);
}

// === Temperatur in Fahrenheit (für Weather Underground) ===
if (!empty($values['wtemperature'])) {
    $values['fahrenheit'] = round(floatval($values['wtemperature']) * 1.8 + 32, 2);
} else {
    $values['fahrenheit'] = '';
}

// === Taupunkt berechnen (Näherungsformel nach August-Roche-Magnus) ===
// Taupunkt T_d ≈ T - (100 - RH) / 5   (gültig für RH > 50%)
if (!empty($values['wtemperature']) && !empty($values['whumidity'])) {
    $dew = floatval($values['wtemperature']) - ((100 - floatval($values['whumidity'])) / 5.0);
    $values['dew']    = round($dew, 2);
    $values['dewptf'] = round($dew * 1.8 + 32, 2);   // Taupunkt in °F für WU
} else {
    $values['dewptf'] = '';
}

// === Luftdruck auf Meereshöhe korrigieren ===
if (!empty($values['wpressure'])) {
    $wpressure = floatval($values['wpressure']);

    if (!empty($values['altitude'])) {
        // Barometrische Höhenformel: p₀ = p / (1 - h/44330)^5.255
        $base = 1 - (floatval($values['altitude']) / 44330.0);
        if ($base > 0) {
            $calibrate = $wpressure / pow($base, 5.255);
        } else {
            error_log("d.php: Ungültige Höhe – Druckkorrektur übersprungen.");
            $calibrate = $wpressure;
        }
    } elseif (!empty($values['bmpcalibrate'])) {
        // Alternativer Multiplikator (manuell kalibriert)
        $calibrate = $wpressure * floatval($values['bmpcalibrate']);
    } else {
        $calibrate = $wpressure;  // keine Korrektur
    }

    // Umrechnung von hPa in inHg (Zoll Quecksilbersäule, Einheit für WU)
    $values['baroinch'] = round($calibrate / 33.8638866667, 4);
} else {
    $values['baroinch'] = '';
}

// === Zeitstempel ===
$now   = gmdate("Y/m/d H:i:s");
$today = gmdate("Y-m-d");

// ═══════════════════════════════════════════════════════════════════════════
// Daten an Weather Underground senden
// ═══════════════════════════════════════════════════════════════════════════
$resp = "fehlende Pflichtdaten";

if (!empty($values['fahrenheit']) && !empty($values['dewptf'])
    && !empty($values['baroinch']) && !empty($values['whumidity'])) {

    // WU-Update-URL zusammenstellen (alle Pflichtfelder vorhanden)
    $wunderurl = "https://weatherstation.wunderground.com/weatherstation/updateweatherstation.php"
        . "?ID="       . urlencode($values['id'])
        . "&PASSWORD=" . urlencode($values['key'])
        . "&dateutc=now"
        . "&tempf="    . $values['fahrenheit']
        . "&dewptf="   . $values['dewptf']
        . "&baromin="  . $values['baroinch']
        . "&humidity=" . $values['whumidity']
        . "&AqPM2.5="  . urlencode($values['SDS_P2'] ?? '')
        . "&AqPM10="   . urlencode($values['SDS_P1'] ?? '')
        . "&softwaretype=" . urlencode($headers['Sensor'])
        . "&action=updateraw";

    // HTTP-Request an WU via cURL
    $curl = curl_init();
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_URL            => $wunderurl,
        CURLOPT_USERAGENT      => 'ESP8266-WU-Client',
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_TIMEOUT        => 10,   // 10 Sekunden Timeout
    ]);
    $resp = curl_exec($curl);
    if ($resp === false) {
        error_log("d.php: cURL-Fehler: " . curl_error($curl));
        $resp = "curl error";
    }
    curl_close($curl);
}

// ═══════════════════════════════════════════════════════════════════════════
// CSV-Logging: Tägliche Datei im Unterverzeichnis /data/
// ═══════════════════════════════════════════════════════════════════════════

// Verzeichnis anlegen falls nicht vorhanden
$csvdir = __DIR__ . "/data";
if (!file_exists($csvdir)) {
    mkdir($csvdir, 0750, true);
}
$filename = $csvdir . "/data-" . $headers['Sensor'] . "-" . $today . ".csv";

/**
 * Schützt einen CSV-Zellenwert vor CSV-Injection.
 *
 * Angreifer könnten versuchen, Formeln in Tabellenkalkulationen einzuschleusen
 * (z. B. "=CMD|..."). Dieser Schutz setzt ein führendes Apostroph vor
 * potenziell gefährliche Startzeichen.
 *
 * @param mixed $value zu escapender Wert
 * @return string sicherer CSV-Wert
 */
function csvEscape($value) {
    $value = (string)$value;
    if (strlen($value) > 0 && in_array($value[0], ['=', '+', '@', '-', "\t", "\r"])) {
        $value = "'" . $value;
    }
    return $value;
}

// Kopfzeile in neue Tagesdatei schreiben
if (!file_exists($filename)) {
    file_put_contents($filename, implode(";", [
        "Time", "Altitude", "Temp", "Humidity", "Dew",
        "BMP_temp", "BMP_pressure", "BMP_calibrate", "Pressure_inHg",
        "BME280_temp", "BME280_humidity", "BME280_pressure",
        "PM10", "PM2_5", "Samples", "Min_cycle", "Max_cycle",
        "Signal", "wTemp", "wHumidity", "wPressure_hPa", "WU_ID", "WU_Response"
    ]) . "\n");
}

// Datensatz anhängen (API-Key wird bewusst NICHT gespeichert)
$csvRow = array_map('csvEscape', [
    $now,
    $values['altitude']         ?? '',
    $values['temperature']      ?? '',
    $values['humidity']         ?? '',
    $values['dew']              ?? '',
    $values['BMP_temperature']  ?? '',
    $values['BMP_pressure']     ?? '',
    $values['bmpcalibrate']     ?? '',
    $values['baroinch'],
    $values['BME280_temperature'] ?? '',
    $values['BME280_humidity']    ?? '',
    $values['BME280_pressure']    ?? '',
    $values['SDS_P1']  ?? '',
    $values['SDS_P2']  ?? '',
    $values['samples'] ?? '',
    $values['min_micro'] ?? '',
    $values['max_micro'] ?? '',
    $values['signal']   ?? '',
    $values['wtemperature'] ?? '',
    $values['whumidity']    ?? '',
    $values['wpressure']    ?? '',
    $values['id']           ?? '',
    $resp
]);
file_put_contents($filename, implode(";", $csvRow) . "\n", FILE_APPEND);
?>
