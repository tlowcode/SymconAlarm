# SymconAlarm

Alarmanlage als IP-Symcon-Modul (ab Symcon 7.0) für KNX-Kontakte, Bewegungsmelder und Anwesenheitserkennung.

## Funktionen

- **Scharf (Abwesend):** automatisch, wenn alle Personen eine einstellbare Zeit abwesend sind. Kontaktöffnungen starten den Voralarm (Eingangsverzögerung), Bewegungsmelder lösen sofort Alarm aus. Heimkommen schaltet unscharf, auch während des Voralarms.
- **Scharf (Nacht):** über den KNX „Scharf Request“ (1), nach einstellbarer Ausgangsverzögerung; Request 0 während der Verzögerung bricht ab. Kontaktöffnung startet einen Voralarm; Bewegung während des Voralarms löst sofort den vollen Alarm aus. Bewegung ohne vorherige Kontaktöffnung wird ignoriert. Request 0 schaltet unscharf.
- **ekey Fingerscanner:** Ein berechtigter Fingerabdruck (Benutzer-ID plus Finger-ID oder jeder Finger des Benutzers, optional geprüfte Aktion) schaltet „Abwesend“ unscharf, auch während Voralarm und Alarm. Nicht berechtigte Fingerabdrücke werden gemeldet.
- Ein zweiter, anderer Auslöser während des Voralarms bricht diesen ab und löst sofort den vollen Alarm mit Sirene aus.
- Beim Schärfen offene Kontakte werden ausgeklammert (Push mit Liste) und ab dem Schließen überwacht.
- Alarmstufen: Voralarm (Push, optional Orientierungslicht) → Alarm (Licht 100 %, Rollläden auf, Push) → Sirene → Sirene aus nach einstellbarer Dauer, Alarmzustand bleibt bis unscharf.
- Status „System Armed“ wird an KNX zurückgemeldet. Zusätzlich lassen sich beliebig viele Variablen mit eigenen Werten für scharf/unscharf schalten, wahlweise nur für Abwesend oder nur für Nacht.
- KNX-Textausgabe (DPT16, 14 Zeichen) auf beliebig viele Textvariablen; pro Meldungsart abschaltbar. Zustandsmeldungen (Alarm, Voralarm, ausgeklammerte Kontakte) bleiben stehen, bis der Zustand endet; Hinweise (Scharf, Unscharf, Kontakt überwacht, Sirene aus, ekey abgelehnt) werden nach einstellbarer Zeit ausgeblendet. Zurückgenommen wird jeweils mit leerem Text.
- Zweite DPT16-Ausgabe als Countdown („Scharf in 25s“, „Alarm in 25s“) während Schärf-Verzögerung und Voralarm, sekündlich aktualisiert.
- **Apple HomeKit:** Sicherheitssystem mit Zuhause/Abwesend/Nacht/Aus über die HomeKit Bridge (Modul „Apple HomeKit“ ≥ 1.6). Wahlweise legt das Modul die Variable selbst an oder synchronisiert eine vorhandene. Die Variable „Alarm ausgelöst“ dient als AlarmID.
- **Eingangsweg:** Kontakte und Bewegungsmelder können als Eingangsweg markiert werden. Startet eine Eingangsweg-Tür den Voralarm, lösen Eingangsweg-Melder (z. B. Flur) keinen sofortigen Alarm aus, die volle Eingangsverzögerung bleibt erhalten. Andere Melder und Bewegung ohne vorherige Tür lösen weiterhin sofort aus.
- **Überfall-Finger:** Ein als Überfall-Finger markierter ekey-Finger entschärft ganz normal, löst aber im Hintergrund einen stillen Alarm aus: Variable „Stiller Alarm“, Push an eine eigene Visualisierung, frei wählbare Ausgänge und optional ein Skript (`$_IPS['ALARM_REASON']`). Keine Sirene, kein Licht, keine Anzeige.
- **Panik-Taster:** beliebige Variablen, die bei 1 sofort den vollen Alarm mit Sirene auslösen, auch im unscharfen Zustand; wahlweise als stiller Alarm. Beendet wird über Unscharf, Scharf Request 0, HomeKit „Aus“ oder einen berechtigten Finger.
- **Erinnerung „Schärfen vergessen“:** Ist zur eingestellten Uhrzeit jemand zuhause und die Anlage nicht scharf, kommt eine Push-Nachricht mit den offenen Kontakten (optional DPT16 „Nicht scharf!“), wiederholt bis zur eingestellten Anzahl.
- **Anwesenheitssimulation:** Solange „Abwesend“ scharf ist, schalten ausgewählte Lichter im Zeitfenster und bei Dunkelheit (Tag-Variable) zufällig an und aus. Am Ende des Fensters werden sie ausgeschaltet.
- **Testmodus:** Sirenen werden nur protokolliert, nicht geschaltet (bei neuen Instanzen aktiv). Alle Sensorereignisse werden mit Reaktion der Anlage protokolliert: Statistik pro Sensor (Ereignisse, Auslösungen, Flattern), die letzten Ereignisse als HTML-Variable „Testprotokoll“ und optional als CSV im Symcon-Log-Ordner (`Alarmanlage_<InstanzID>_Test.csv`).

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
ALARM_Panic(int $InstanzID);
ALARM_ResetSilentAlarm(int $InstanzID);
ALARM_GetStatusText(int $InstanzID): string;
ALARM_GetTestReport(int $InstanzID): string;
ALARM_ResetTestLog(int $InstanzID);
```

## Tests

Die Logik lässt sich ohne Symcon gegen eine nachgebildete API prüfen:

```sh
php tests/run.php
```
