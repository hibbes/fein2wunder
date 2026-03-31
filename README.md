# fein2wunder

PHP-Script, das Daten eines **OK-Lab / sensor.community Feinstaubsensors** (NodeMCU/ESP8266) empfängt, ins Weather Underground (WU)-Format umrechnet und dorthin weiterleitet. Zusätzlich werden alle Messwerte in täglichen CSV-Dateien archiviert.

## Einrichtung

In der Sensor-Weboberfläche unter *Eigene API*:

| Feld | Wert |
|------|------|
| Server | `dein-server.example.com` |
| Pfad | `/d.php?id=STATIONSID&key=APIKEY` |
| Port | `80` (oder `443` für HTTPS) |

### Optionale URL-Parameter

| Parameter | Beschreibung |
|-----------|-------------|
| `alt=360` | Stationshöhe in Metern über NN (für Druckkorrektur auf Meereshöhe) |
| `bmpc=1.01234` | Manueller Druckkorrekturfaktor (Alternative zu `alt`) |
| `t=1\|2\|3` | Temperatursensor: 1=DHT22 (Standard), 2=BMP180, 3=BME280 |
| `h=1\|3` | Feuchtigkeitssensor: 1=DHT22 (Standard), 3=BME280 |
| `p=1\|2` | Drucksensor: 1=BMP180 (Standard), 2=BME280 |

**Beispiele:**
```
/d.php?id=IOFFENBU87&key=c654738vxgdu&alt=360
/d.php?id=IOFFENBU87&key=c654738vxgdu&alt=360&t=3&h=3&p=2
```

## Ausgabe / Logging

- `incoming.log` – Rohdaten jedes empfangenen POST-Requests
- `data/data-SENSORID-DATUM.csv` – Tägliche CSV mit allen Messwerten

## Unterstützte Sensoren

| Sensor | Temperatur | Feuchtigkeit | Druck |
|--------|-----------|-------------|-------|
| DHT22 | ✅ (Standard) | ✅ (Standard) | — |
| BMP180 | ✅ (`t=2`) | — | ✅ (Standard) |
| BME280 | ✅ (`t=3`) | ✅ (`h=3`) | ✅ (`p=2`) |
| SDS011 | — | — | — (PM2.5/PM10) |

## Lizenz

MIT
