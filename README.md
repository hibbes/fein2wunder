# fein2wunder
Dies ist ein PHP-Script, welches die Daten des OK-Lab-Stuttgart-Feinstaubsensors entgegennimmt, für das Wonderground-Netzwerk aufbereitet und dorthin sendet.

Folgende Einstellungen müssen in der Konfiguration des Sensors gesetzt werden:

Eigene API-Haken setzen

Server: (Der eigene Servername)

Pfad: /data.php?id=XXXXX&key=YYYYY (XXXXX ist eure Wunderground-Stations-ID, YYYYY, euer API-Key dazu)

optional gibt es noch den Wert bmp1, dies ist ein Floatwert zur Umrechnung der Druckwerte auf NN. Dieser erreichnet sich aus $Sensorwert (5.stelliger Pascal-Wert. Im Webinterface werden leider bisher nur die ersten 4 Stellen angezeigt) / einem Referenzwert in Hektopascal (am besten in Weather Undergrund nach dem Wert einer vertrauenswürdigen Wetterstation in der Nähe suchen).

Alternativ dazu kann man auch einfach die Höhe der Station über NN in Metern mitgeben. Der Parameter heißt dann "alt"

Mit den Parametern "t" und "h" kann bestimmt werden welcher der verfügbaren Temperatur- und Feuchtigkeitssensoren als Datenquelle verwendet werden.

Standard für Temperatur und relative Feuschte ist der DHT-22
Standard für Druck ist der BMP_180-Sensor

t=2 --> Temperatur vom BMP_180-Sensor
t=3 --> Temperatur vom BME_280-Sensor

h=3 --> relative Feuchte vom BME_280-Sensor

p=2 --> Druck vom BME_280 Sensor

Port: (Port auf dem der Webserver lauscht)

Beispielkonfiguration:

Server:
p238158.webspaceconfig.de
Pfad:

/data.php?id=IOFFENBU87&key=c654738vxgdu
oder:
/data.php?id=IOFFENBU87&key=c654738vxgdu&bmp1=0.01037313
oder:
/data.php?id=IOFFENBU87&key=c654738vxgdu&alt=360
oder:
/data.php?id=IOFFENBU87&key=c654738vxgdu&alt=360&t=1&h=2&h=3&p=2

Port:
80

Das Skript errechnet zusätzlich aus relativer Luftfeuchte und Temperatur den Taupunkt und sendet den Wert ebenfalls mit.

Es sind bisher nur die Sensoren SDS011, DHT22 und BMP180 unterstützt (habe z. Zt. keine weiteren Sensoren zum Ausprobieren)

Das Skript läuft bereits auf folgendem Server und kann dort gerne ausprobiert werden: p238158.webspaceconfig.de/d.php (Port 80)
