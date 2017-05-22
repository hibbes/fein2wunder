# fein2wunder
Dies ist ein PHP-Script, welches die Daten des OK-Lab-Stuttgart-Feinstaubsensors entgegennimmt, für das Wonderground-Netzwerk aufbereitet und dorthin sendet.

Folgende Einstellungen müssen in der Konfiguration des Sensors gesetzt werden:

Eigene API-Haken setzen
Server: (Der eigene Servername)
Pfad: /data.php?wunderid=XXXXX&wunderkey=YYYYY (XXXXX ist eure Wunderground-Stations-ID, YYYYY, euer API-Key dazu)
Port: (Port auf dem der Webserver lauscht)

Das Skript errechnet zusätzlich aus relativer Luftfeuchte und Temperatur den Taupunkt und sendet den Wert ebenfalls mit.

Es sind bisher nur die Sensoren SDS011, DHT22 und (ungetestet) BMP180 unterstützt.

Einen Proxy zum Testen findet ihr hier: p238158.webspaceconfig.de/data.phpwunderid=(StationsID)&wunderkey=(API-Key) (Port 80)


