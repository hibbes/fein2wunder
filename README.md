# fein2wunder
Dies ist ein PHP-Script, welches die Daten des OK-Lab-Stuttgart-Feinstaubsensors entgegennimmt, für das Wonderground-Netzwerk aufbereitet und dorthin sendet.

Folgende Einstellungen müssen in der Konfiguration des Sensors gesetzt werden:

Eigene API-Haken setzen

Server: (Der eigene Servername)

Pfad: /data.php?id=XXXXX&key=YYYYY (XXXXX ist eure Wunderground-Stations-ID, YYYYY, euer API-Key dazu)

optional gibt es noch den Wert bmp1, dies ist ein Floatwert zur Kalibirierung des Drucksensors. Dieser erreichnet sich aus $Sensorwert (5.stelliger Pascal-Wert. Im Webinterface werden leider bisher nur die ersten 4 Stellen angezeigt) / Referenzwert in Hektopascal. 

Port: (Port auf dem der Webserver lauscht)

Beispielkonfiguration:

Server:
p238158.webspaceconfig.de
Pfad:
/data.php?id=IOFFENBU87&key=c654738vxgdu&bmp1=0.01037313
Port:
80

Das Skript errechnet zusätzlich aus relativer Luftfeuchte und Temperatur den Taupunkt und sendet den Wert ebenfalls mit.

Es sind bisher nur die Sensoren SDS011, DHT22 und BMP180 unterstützt.

Einen Proxy zum Testen findet ihr hier: p238158.webspaceconfig.de/data.php (Port 80)


