# SymconAlarm

Alarmanlage als IP-Symcon-Modul (ab Symcon 7.0) für KNX-Kontakte, Bewegungsmelder und Anwesenheitserkennung.

## Funktionen

- **Scharf (Abwesend):** automatisch, wenn alle Personen eine einstellbare Zeit abwesend sind. Kontakte und Bewegungsmelder lösen nach der Eingangsverzögerung Alarm aus. Heimkommen schaltet unscharf, auch während des Voralarms.
- **Scharf (Nacht):** über den KNX „Scharf Request“ (1), nach einstellbarer Ausgangsverzögerung; Request 0 während der Verzögerung bricht ab. Kontaktöffnung startet einen Voralarm; Bewegung während des Voralarms löst sofort den vollen Alarm aus. Bewegung ohne vorherige Kontaktöffnung wird ignoriert. Request 0 schaltet unscharf.
- **ekey Fingerscanner:** Ein berechtigter Fingerabdruck (Benutzer-ID plus Finger-ID oder jeder Finger des Benutzers, optional geprüfte Aktion) schaltet „Abwesend“ unscharf, auch während Voralarm und Alarm. Nicht berechtigte Fingerabdrücke werden gemeldet.
- Ein zweiter, anderer Auslöser während des Voralarms bricht diesen ab und löst sofort den vollen Alarm mit Sirene aus.
- Beim Schärfen offene Kontakte werden ausgeklammert (Push mit Liste) und ab dem Schließen überwacht.
- Alarmstufen: Voralarm (Push, optional Orientierungslicht) → Alarm (Licht 100 %, Rollläden auf, Push) → Sirene → Sirene aus nach einstellbarer Dauer, Alarmzustand bleibt bis unscharf.
- Status „System Armed“ wird an KNX zurückgemeldet. Zusätzlich lassen sich beliebig viele Variablen mit eigenen Werten für scharf/unscharf schalten, wahlweise nur für Abwesend oder nur für Nacht.
- KNX-Textausgabe (DPT16, 14 Zeichen) auf beliebig viele Textvariablen; pro Meldungsart abschaltbar. Zustandsmeldungen (Alarm, Voralarm, ausgeklammerte Kontakte) bleiben stehen, bis der Zustand endet; Hinweise (Scharf, Unscharf, Kontakt überwacht, Sirene aus, ekey abgelehnt) werden nach einstellbarer Zeit ausgeblendet. Zurückgenommen wird jeweils mit leerem Text.
- Zweite DPT16-Ausgabe als Countdown („Scharf in 25s“, „Alarm in 25s“) während Schärf-Verzögerung und Voralarm, sekündlich aktualisiert.
- Testmodus: Sirenen werden nur protokolliert, nicht geschaltet (bei neuen Instanzen aktiv).

## Installation

1. Diesen Ordner per Git-URL über **Kern-Instanzen → Modules → Hinzufügen** einbinden oder nach `/var/lib/symcon/modules/SymconAlarm` (Windows: `C:\ProgramData\Symcon\modules\SymconAlarm`) kopieren und in **Modules** neu laden.
2. Instanz **Alarmanlage** anlegen.
3. Sensoren, KNX-Variablen, Aktoren und Kachel-Visualisierung im Formular eintragen, übernehmen.
4. Mit aktivem Testmodus beide Modi durchspielen (Meldungen-Log, Filter „Alarmanlage“), danach Testmodus abschalten.

## Öffentliche Funktionen

```php
ALARM_ArmNight(int $InstanzID);
ALARM_ArmAway(int $InstanzID);
ALARM_Disarm(int $InstanzID);
ALARM_GetStatusText(int $InstanzID): string;
```

## Tests

Die Logik lässt sich ohne Symcon gegen eine nachgebildete API prüfen:

```sh
php tests/run.php
```
