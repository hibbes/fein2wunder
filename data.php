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
// print transmitted values
echo "Sensor: ".$headers['Sensor']."\r\n";
// check if data dir exists, create if not
if (!file_exists('data')) {
	mkdir('data', 0755, true);
}


// save data values to CSV (one per day)
$datafile = "data/data-".$headers['Sensor']."-".$today.".csv";

if (!file_exists($datafile)) {
	fwrite($outfile,"Time;durP1;ratioP1;P1;durP2;ratioP2;P2;SDS_P1;SDS_P2;Temp;Humidity;Dew;BMP_temperature;BMP_pressure;BMP_calibrate;BME280_temperature;BME280_humidity;BME280_pressure;Samples;Min_cycle;Max_cycle;Signal;Wunderdate;WunderID;WunderURL;WunderResponse\n");
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
if (! isset($values["max_micro"])) { $values["max_micro"] = ""; }
if (! isset($values["signal"])) { $values["signal"] = ""; } else { $values["signal"] = substr($values["signal"],0,-4); }

//Wunderapi-Extensions *****************************
// date_default_timezone_set('UTC'); // Wunderground expects UTC
$wunderkey = $_GET["key"];  // API-Key you get, when you register your own Weatherstation an Wunderground
$wunderid = $_GET["id"];    // ID of your Weatherstation

$wunderdate=$today."+".date(H)."%3A".date(i)."%3A".date(s);

if($values['temperature']!=NULL){
$fahrenheit=round((($values['temperature']*1.8)+32),1);
}

if($values["humidity"]>=50){
$dew =  $values['temperature'] - ((100 - $values["humidity"])/5.0);	
} else {$dew = (((0.000002*pow($values['temperature'],4))+(0.0002*pow($values['temperature'],3))+(0.0095*pow($values['temperature'],2))+(0.337*$values['temperature'])+4.9034)*$values['humidity'])/100;}
	

if($dew==0){$dewptf=NULL;}
else{$dewptf=round(($dew*1.8)+32,1);}

// Aufbereitung der BMP-Werte
if($values['BMP_pressure']!=NULL){
	

 // Umrechnung auf Druck über NN und nach Inches
 $calibrate = ($values['BMP_pressure']*$_GET["bmp1"]);
 $baroinch=round($calibrate/33.8638866667,2);
}

$wunderurl="https://weatherstation.wunderground.com/weatherstation/updateweatherstation.php?ID=".$wunderid."&PASSWORD=".$wunderkey."&dateutc=".$wunderdate."&tempf=".$fahrenheit."&dewptf=".$dewptf."&baromin=".$baroinch."&humidity=".$values['humidity']."&AqPM2.5=".$values['SDS_P2']."&AqPM10=".$values['SDS_P1']."&softwaretype=".$headers['Sensor']."&action=updateraw";

// Get cURL resource
 
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

// Ende der Modifikation ***********************************


$outfile = fopen($datafile,"a");
// Logfile erweitert
fwrite($outfile,$now.";".$values["durP1"].";".$values["ratioP1"].";".$values["P1"].";".$values["durP2"].";".$values["ratioP2"].";".$values["P2"].";".$values["SDS_P1"].";".$values["SDS_P2"].";".$values["temperature"].";".$values["humidity"].";".$dew.";".$values["BMP_temperature"].";".$values["BMP_pressure"].";".$calibrate.";".$values["BME280_temperature"].";".$values["BME280_humidity"].";".$values["BME280_pressure"].";".$values["samples"].";".$values["min_micro"].";".$values["max_micro"].";".$values["signal"].";".$wunderdate.";".$wunderid.";".$wunderurl.";".$resp."\n");
fclose($outfile);
// echo $resp;
?>
ok