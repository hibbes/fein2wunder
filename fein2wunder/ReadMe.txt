Dies ist ein PHP-Script, welches die Daten des OK-Lab-Stuttgart-Feinstaubsensors entgegennimmt, für das Wonderground-Netzwerk aufbereitet und dorthin sendet.

Folgende Einstellungen müssen in der Konfiguration des Sensors gesetzt werden:

Eigene API-Haken setzen
Server: (Der eigene Servername)
Pfad: /data.php?wunderid=(StationsID)&wunderkey=(API-Key)
Port_ (Port auf dem der Webserver lauscht)

Das Skript errechnet zusätzlich aus relativer Luftfeuchte und Temperatur den Taupunkt und sendet den Wert ebenfalls mit.

Es sind bisher nur die Sensoren SDS011, DHT22 und (ungetestet) BMP180 unterstützt.


