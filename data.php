<?php

// read sensor ID ('esp8266-'+ChipID)
if (isset($_SERVER['HTTP_SENSOR'])) $headers['Sensor'] = $_SERVER['HTTP_SENSOR'];
if (isset($_SERVER['HTTP_X_SENSOR']))$headers['Sensor'] = $_SERVER['HTTP_X_SENSOR'];
$json = file_get_contents('php://input');
$results = json_decode($json,true);
header_remove();
$now = gmstrftime("%Y/%m/%d %H:%M:%S");
$today = gmstrftime("%Y-%m-%d");

// copy sensor data values to values array
foreach ($results["sensordatavalues"] as $sensordatavalues) {
	$values[$sensordatavalues["value_type"]] = $sensordatavalues["value"];
}


// check if data dir exists, create if not
if (!file_exists('data')) {
	mkdir('data', 0755, true);
}


// save data values to CSV (one per day)
$datafile = "data/data-".$headers['Sensor']."-".$today.".csv";

if (!file_exists($datafile)) {
	$outfile = fopen($datafile,"a");
	fwrite($outfile,"Time;Altitude;Temp;Humidity;Dew;BMP_temperature;BMP_pressure;BMP_calibrate;BME280_temperature;BME280_humidity;BME280_pressure;Samples;Min_cycle;Max_cycle;Signal;WunderID;WunderURL;WunderResponse\n");
	fclose($outfile);
}

if (! isset($values["durP1"])) { $values["durP1"] = ""; }
if (! isset($values["ratioP1"])) { $values["ratioP1"] = ""; }
if (! isset($values["P1"])) { $values["P1"] = ""; }
if (! isset($values["durP2"])) { $values["durP2"] = ""; }
if (! isset($values["ratioP2"])) { $values["ratioP2"] = ""; }
if (! isset($values["P2"])) { $values["P2"] = ""; }
if (! isset($values["SDS_P1"])) { $values["SDS_P1"] = ""; }
if (! isset($values["SDS_P2"])) { $values["SDS_P2"] = ""; }
if (! isset($values["temperature"])) { $values["temperature"] = ""; }
if (! isset($values["humidity"])) { $values["humidity"] = ""; }
if (! isset($values["BMP_temperature"])) { $values["BMP_temperature"] = ""; }
if (! isset($values["BMP_pressure"])) { $values["BMP_pressure"] = ""; }
if (! isset($values["BME280_temperature"])) { $values["BME280_temperature"] = ""; }
if (! isset($values["BME280_humidity"])) { $values["BME280_humidity"] = ""; }
if (! isset($values["BME280_pressure"])) { $values["BME280_pressure"] = ""; }
if (! isset($values["samples"])) { $values["samples"] = ""; }
if (! isset($values["min_micro"])) { $values["min_micro"] = ""; }
if (! isset($values["signal"])) { $values["signal"] = ""; } else { $values["signal"] = substr($values["signal"],0,-4); }

// Wunderapi-Extensions 
if (! isset($values["fahrenheit"])) { $values["fahrenheit"] = ""; }
if (! isset($values["fahrenheit2"])) { $values["fahrenheit2"] = ""; }
if (! isset($values["dew"])) { $values["dew"] = ""; }
if (! isset($values["dewptf"])) { $values["dewptf"] = ""; }
if (! isset($values["key"])) { $values["key"] = $_GET["key"]; }
if (! isset($values["id"])) { $values["id"] = $_GET["id"]; }
if (! isset($values["baroinch"])) { $values["baroinch"] = ""; }
if (! isset($values["altitude"])) { $values["altitude"] = $_GET["alt"]; }
if (! isset($values["bmp1calibrate"])) { $values["bmp1calibrate"] = $_GET["bmp1"]; }

// takes values from DHT22 and convert celsius to fahrenheit, (wunderground expects fahrenheit)
if($values["temperature"]!=NULL){
	$values["fahrenheit"]=round((($values["temperature"]*1.8)+32),4);
}

// takes values from BMP and convert celsius to fahrenheit, (wunderground expects fahrenheit)
if($values["BMP_temperature"]!=NULL){
	$values["fahrenheit2"]=round((($values["temperature"]*1.8)+32),4);
}

// calulates dew-point from dht22-Temperature and DHT22-humidity and converts to fahrenheit
$values["dew"] = $values["temperature"] - ((100 - $values["humidity"])/5.0);

if($values["dew"] ==0){
	$values["dewptf"]=NULL;}
	
else{$values["dewptf"]=round(($values["dew"]*1.8)+32,2);}

// calibrates the bmp_pressure to sea-level and converts to inches
if($values["BMP_pressure"]!=NULL){
	// if altitude is transmitted
	if($values["altitude"]!=NULL){
		$calibrate = ($values["BMP_pressure"]/pow(1-($_GET["alt"]/44330.0),5.255))/100;
	
	} else {
		// if calibration-factor ist transmitted
			if($values["bmp1calibrate"]!=NULL){	
				$calibrate = ($values["BMP_pressure"]*$values["bmp1calibrate"]);}
				
		// or nothing is transmitted (but BMP_pressure)
			else{$calibrate = $values["BMP_pressure"];}
		}
	
	// convert to inches	
	$values["baroinch"]=$calibrate/33.8638866667;
}

// generates wunderground-URL-String
$wunderurl="https://weatherstation.wunderground.com/weatherstation/updateweatherstation.php?ID=".$values["id"]."&PASSWORD=".$values["key"]."&dateutc=now&tempf=".$values["fahrenheit"]."&temp2f=".$values["fahrenheit2"]."&dewptf=".$values["dewptf"]."&baromin=".$values["baroinch"]."&humidity=".$values['humidity']."&AqPM2.5=".$values['SDS_P2']."&AqPM10=".$values['SDS_P1']."&softwaretype=".$headers['Sensor']."&action=updateraw";

// Get cURL resource and sends Wundergrund url-String
$curl = curl_init();
// Set some options - we are passing in a useragent too here
curl_setopt_array($curl, array(
		CURLOPT_RETURNTRANSFER => 1,
		CURLOPT_URL => $wunderurl,
		CURLOPT_USERAGENT => 'ESP-Wunderground-Update'
));
// Send the request & save response to $resp
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
$resp = curl_exec($curl);
// Close request to clear up some resources
curl_close($curl);

// Writes logfile with most of the values
$outfile = fopen($datafile,"a");
fwrite($outfile,$now.";".$values["altitude"].";".$values["temperature"].";".$values["humidity"].";".$values["dew"].";".$values["BMP_temperature"].";".$values["BMP_pressure"].";".$calibrate.";".$values["BME280_temperature"].";".$values["BME280_humidity"].";".$values["BME280_pressure"].";".$values["samples"].";".$values["min_micro"].";".$values["max_micro"].";".$values["signal"].";".$values["id"].";".$wunderurl.";".$resp);
fclose($outfile);
?>
ok