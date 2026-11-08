<?php

declare(strict_types=1);

require __DIR__ . '/stubs.php';
require __DIR__ . '/../Alarmanlage/module.php';

const DOOR = 101, WINDOW = 102, MOTION_WZ = 201, MOTION_FLUR = 202;
const THILO = 301, NADINE = 302, REQUEST = 401, ARMED = 402;
const SIREN = 501, LIGHT = 601, DIMMER = 602, SHUTTER = 701;
const EKEY_USER = 801, EKEY_FINGER = 802, EKEY_ACTION = 803;
const TEXT = 901, COUNTDOWN = 902;

$failures = 0;
function check(string $label, bool $ok): void
{
    global $failures;
    echo ($ok ? '  ok   ' : '  FAIL ') . $label . "\n";
    if (!$ok) {
        $failures++;
    }
}

function setup(): Alarmanlage
{
    Sim::$vars = [];
    Sim::$profiles = ['Hue.Brightness' => ['MaxValue' => 254]];
    Sim::reset();
    Sim::addVar(DOOR, 'Haustür', VARIABLETYPE_BOOLEAN, false);
    Sim::addVar(WINDOW, 'Fenster Küche', VARIABLETYPE_BOOLEAN, false);
    Sim::addVar(MOTION_WZ, 'BWM Wohnzimmer', VARIABLETYPE_BOOLEAN, false);
    Sim::addVar(MOTION_FLUR, 'BWM Flur EG', VARIABLETYPE_BOOLEAN, false);
    Sim::addVar(THILO, 'Thilo', VARIABLETYPE_BOOLEAN, true);
    Sim::addVar(NADINE, 'Nadine', VARIABLETYPE_BOOLEAN, true);
    Sim::addVar(REQUEST, 'Scharf Request', VARIABLETYPE_BOOLEAN, false);
    Sim::addVar(ARMED, 'System Armed', VARIABLETYPE_BOOLEAN, false);
    Sim::addVar(SIREN, 'Sirene', VARIABLETYPE_BOOLEAN, false);
    Sim::addVar(LIGHT, 'Licht', VARIABLETYPE_BOOLEAN, false);
    Sim::addVar(DIMMER, 'Licht Helligkeit', VARIABLETYPE_INTEGER, 0, 'Hue.Brightness');
    Sim::addVar(SHUTTER, 'Rollladen', VARIABLETYPE_BOOLEAN, true);
    Sim::addVar(EKEY_USER, 'Benutzer ID', VARIABLETYPE_INTEGER, 0);
    Sim::addVar(EKEY_FINGER, 'Finger ID', VARIABLETYPE_INTEGER, 0);
    Sim::addVar(EKEY_ACTION, 'Aktion', VARIABLETYPE_INTEGER, 0);
    Sim::addVar(TEXT, 'Display Text', VARIABLETYPE_STRING, '');
    Sim::addVar(COUNTDOWN, 'Display Countdown', VARIABLETYPE_STRING, '');

    $m = new Alarmanlage();
    $m->Create();
    $m->properties = array_merge($m->properties, [
        'Contacts'              => json_encode([['VariableID' => DOOR, 'Name' => ''], ['VariableID' => WINDOW, 'Name' => 'Küche']]),
        'MotionSensors'         => json_encode([['VariableID' => MOTION_WZ, 'Name' => 'Wohnzimmer'], ['VariableID' => MOTION_FLUR, 'Name' => 'Flur EG']]),
        'Presence'              => json_encode([['VariableID' => THILO, 'Name' => ''], ['VariableID' => NADINE, 'Name' => '']]),
        'ArmRequestVariableID'  => REQUEST,
        'ArmedStatusVariableID' => ARMED,
        'Sirens'                => json_encode([['VariableID' => SIREN]]),
        'AlarmLights'           => json_encode([['SwitchVariableID' => LIGHT, 'BrightnessVariableID' => DIMMER]]),
        'Shutters'              => json_encode([['VariableID' => SHUTTER, 'OpenValue' => 0]]),
        'EkeyUserVariableID'    => EKEY_USER,
        'EkeyFingerVariableID'  => EKEY_FINGER,
        'EkeyActionVariableID'  => EKEY_ACTION,
        'EkeyUsers'             => json_encode([
            ['UserID' => 1, 'FingerID' => 3, 'Name' => 'Thilo'],
            ['UserID' => 2, 'FingerID' => -1, 'Name' => 'Nadine'],
        ]),
        'TextOutputs'           => json_encode([['VariableID' => TEXT]]),
        'CountdownOutputs'      => json_encode([['VariableID' => COUNTDOWN]]),
        'NightArmDelay'         => 0,
        'VisuID'                => 77777,
        'TestMode'              => false,
    ]);
    $m->ApplyChanges();
    return $m;
}

function scan(int $user, int $finger, int $action = 1): void
{
    Sim::$now += 10;
    Sim::update(EKEY_USER, $user);
    Sim::update(EKEY_FINGER, $finger);
    Sim::update(EKEY_ACTION, $action);
}

function armAway(): void
{
    Sim::update(THILO, false);
    Sim::update(NADINE, false);
    Sim::fireTimer();
}

function mode(Alarmanlage $m): int { return $m->values['Mode']; }
function state(Alarmanlage $m): int { return $m->values['State']; }

echo "Konfiguration\n";
$m = setup();
check('Status 102 (bereit)', $m->status === 102);
check('Alle Sensoren, Scharf Request und ekey abonniert', count($m->watched) === 10);

echo "Abwesend: offenes Fenster wird ausgeklammert und später überwacht\n";
$m = setup();
Sim::$vars[WINDOW]['value'] = true;
Sim::update(THILO, false);
check('Noch jemand zuhause, kein Timer', $m->timers['StepTimer'] === 0);
Sim::update(NADINE, false);
check('Niemand zuhause, Scharfschaltung geplant', $m->attributes['NextStep'] === 'armAway');
check('Timer löst armAway aus', Sim::fireTimer() === 'armAway');
check('Modus Abwesend', mode($m) === 1);
check('System Armed = 1', Sim::$vars[ARMED]['value'] === true);
check('Küche ausgeklammert', $m->values['Excluded'] === 'Küche');
check('Push mit offenem Kontakt', (bool) preg_grep('/Küche/', Sim::$pushes));
Sim::update(WINDOW, false);
check('Küche nach Schließen überwacht', $m->values['Excluded'] === '');
Sim::update(WINDOW, true);
check('Küche öffnen startet Voralarm', state($m) === 1);

echo "Abwesend: Heimkommen während Voralarm schaltet unscharf\n";
$m = setup();
Sim::update(THILO, false);
Sim::update(NADINE, false);
Sim::fireTimer();
Sim::update(DOOR, true);
check('Voralarm', state($m) === 1 && $m->attributes['NextStep'] === 'alarm');
Sim::update(THILO, true);
check('Unscharf', mode($m) === 0 && state($m) === 0);
check('Kein Timer mehr', $m->timers['StepTimer'] === 0);
check('System Armed = 0', Sim::$vars[ARMED]['value'] === false);
check('Sirene nie geschaltet', !in_array([SIREN, true], Sim::$actions, true));

echo "Abwesend: Rückkehr vor Ablauf der Scharfschalt-Verzögerung\n";
$m = setup();
Sim::update(THILO, false);
Sim::update(NADINE, false);
Sim::update(NADINE, true);
check('Scharfschaltung abgebrochen', $m->attributes['NextStep'] === '' && mode($m) === 0);

echo "Abwesend: vollständiger Alarmablauf\n";
$m = setup();
Sim::update(THILO, false);
Sim::update(NADINE, false);
Sim::fireTimer();
Sim::reset();
Sim::update(MOTION_WZ, true);
check('Bewegung löst ohne Voralarm sofort Alarm aus', state($m) === 2);
check('Auslöser und Displaytext', $m->values['Trigger'] === 'Bewegung Wohnzimmer' && in_array([TEXT, 'ALARM Wohnzimm'], Sim::$actions, true));
check('Kein Voralarm-Countdown', !in_array([COUNTDOWN, 'Alarm in 30s'], Sim::$actions, true));
check('Licht an, Helligkeit 254', in_array([LIGHT, true], Sim::$actions, true) && in_array([DIMMER, 254], Sim::$actions, true));
check('Rollladen auf (false)', in_array([SHUTTER, false], Sim::$actions, true));
check('Timer siren', Sim::fireTimer() === 'siren');
check('Sirene an', state($m) === 3 && Sim::$vars[SIREN]['value'] === true);
check('Timer sirenOff', Sim::fireTimer() === 'sirenOff');
check('Sirene aus, Alarm gespeichert', state($m) === 4 && Sim::$vars[SIREN]['value'] === false);
Sim::update(REQUEST, false);
check('Scharf Request 0 schaltet "Abwesend" nicht unscharf', mode($m) === 1);
Sim::update(NADINE, true);
check('Anwesenheit schaltet unscharf', mode($m) === 0 && state($m) === 0);

echo "Nacht: Bewegung allein wird ignoriert\n";
$m = setup();
Sim::update(REQUEST, true);
check('Modus Nacht', mode($m) === 2);
Sim::update(MOTION_FLUR, true);
check('Kein Voralarm', state($m) === 0);

echo "Nacht: Kontakt, dann Bewegung = sofort voller Alarm\n";
$m = setup();
Sim::update(REQUEST, true);
Sim::update(DOOR, true);
check('Voralarm', state($m) === 1);
Sim::update(MOTION_FLUR, true);
check('Sofort Sirene', state($m) === 3 && Sim::$vars[SIREN]['value'] === true);
check('Auslöser enthält beide', $m->values['Trigger'] === 'Kontakt Haustür geöffnet, Bewegung Flur EG');
Sim::update(REQUEST, false);
check('Scharf Request 0 schaltet unscharf', mode($m) === 0);
check('Sirene aus', Sim::$vars[SIREN]['value'] === false);

echo "Nacht: Kontakt ohne Bewegung = Voralarm, dann gestufter Alarm\n";
$m = setup();
Sim::update(REQUEST, true);
Sim::update(WINDOW, true);
Sim::fireTimer();
check('Alarm ohne Sirene', state($m) === 2 && Sim::$vars[SIREN]['value'] === false);
Sim::fireTimer();
check('Sirene nach Verzögerung', state($m) === 3);

echo "Scharf Request erneut 1 nach Unscharf (gleicher Wert)\n";
$m = setup();
Sim::update(REQUEST, true);
Sim::$vars[REQUEST]['value'] = true;
$m->Disarm();
Sim::update(REQUEST, true);
check('Wird wieder scharf', mode($m) === 2);

echo "Testmodus schaltet keine Sirene\n";
$m = setup();
$m->properties['TestMode'] = true;
Sim::update(REQUEST, true);
Sim::update(DOOR, true);
Sim::update(MOTION_WZ, true);
check('Zustand Sirene', state($m) === 3);
check('Sirene nicht geschaltet', Sim::$vars[SIREN]['value'] === false);

echo "Kontakt wird im scharfen Zustand zur Konfiguration hinzugefügt\n";
$m = setup();
Sim::addVar(103, 'Fenster Bad', VARIABLETYPE_BOOLEAN, true);
Sim::update(REQUEST, true);
$m->properties['Contacts'] = json_encode([['VariableID' => DOOR], ['VariableID' => WINDOW], ['VariableID' => 103]]);
$m->ApplyChanges();
check('Neuer offener Kontakt ausgeklammert', $m->values['Excluded'] === 'Fenster Bad');

echo "Zweiter Auslöser im Voralarm\n";
$m = setup();
armAway();
Sim::update(DOOR, true);
Sim::update(DOOR, false);
Sim::update(DOOR, true);
check('Gleicher Kontakt erneut: weiter Voralarm', state($m) === 1);
Sim::update(WINDOW, true);
check('Anderer Kontakt: sofort voller Alarm mit Sirene', state($m) === 3 && Sim::$vars[SIREN]['value'] === true);
check('Rollladen auf', Sim::$vars[SHUTTER]['value'] === false);

$m = setup();
armAway();
Sim::update(DOOR, true);
Sim::update(MOTION_FLUR, true);
check('Kontakt, dann Bewegung im Modus Abwesend: voller Alarm', state($m) === 3);

echo "ekey\n";
$m = setup();
armAway();
scan(1, 4);
check('Falscher Finger: bleibt scharf', mode($m) === 1);
check('Push bei nicht berechtigtem Finger', (bool) preg_grep('/Nicht berechtigter Fingerabdruck \(Benutzer 1, Finger 4\)/', Sim::$pushes));
scan(1, 3, 2);
check('Richtiger Finger, aber Aktion = Ablehnung: bleibt scharf', mode($m) === 1);
Sim::update(DOOR, true);
scan(1, 3);
check('Thilo Finger 3 im Voralarm: unscharf', mode($m) === 0 && state($m) === 0);

$m = setup();
armAway();
scan(2, 7);
check('Nadine mit beliebigem Finger: unscharf', mode($m) === 0);
$m->ArmAway();
scan(2, 7);
check('Gleicher Scan erneut: wieder unscharf', mode($m) === 0);

$m = setup();
armAway();
Sim::$now += 10;
Sim::update(EKEY_USER, 1);
check('Nur Benutzer-ID aktualisiert: noch keine Auswertung', mode($m) === 1);
Sim::update(EKEY_FINGER, 3);
Sim::update(EKEY_ACTION, 1);
check('Nach vollständigem Scan: unscharf', mode($m) === 0);

$m = setup();
Sim::update(REQUEST, true);
scan(1, 3);
check('Nachtmodus bleibt bei Fingerabdruck scharf', mode($m) === 2);

echo "DPT16-Textausgabe\n";
function texts(): array
{
    return array_values(array_map(fn ($a) => $a[1], array_filter(Sim::$actions, fn ($a) => $a[0] === TEXT)));
}
$m = setup();
Sim::$vars[WINDOW]['value'] = true;
Sim::update(REQUEST, true);
check('Offener Kontakt als Zustand, "Scharf Nacht" als Hinweis', texts() === ['Offen: Kueche', 'Scharf Nacht']);
Sim::fireTextTimer();
check('Nach Hinweis zurück zu "Offen: Kueche"', end(Sim::$actions)[1] === 'Offen: Kueche');
Sim::reset();
Sim::update(WINDOW, false);
check('Beim Schließen: Zustand zurücknehmen, Hinweis OK', texts() === ['', 'OK: Kueche']);
Sim::fireTextTimer();
check('Hinweis wird mit leerem Text zurückgenommen', texts() === ['', 'OK: Kueche', '']);
Sim::reset();
Sim::update(DOOR, true);
Sim::fireTimer();
check('Voralarm und Alarm mit Auslöser', texts() === ['Voralarm 30s', 'ALARM Haustuer']);
check('Alle Texte höchstens 14 Zeichen', max(array_map('strlen', texts())) <= 14);
Sim::fireTimer();
Sim::reset();
Sim::fireTimer();
check('Hinweis "Sirene aus"', texts() === ['Sirene aus']);
Sim::fireTextTimer();
check('Danach wieder ALARM, bis unscharf', texts() === ['Sirene aus', 'ALARM Haustuer']);
Sim::reset();
Sim::update(REQUEST, false);
check('Unscharf: ALARM zurücknehmen, Hinweis Unscharf', texts() === ['', 'Unscharf']);
Sim::fireTextTimer();
check('Display danach leer', texts() === ['', 'Unscharf', '']);

$m = setup();
$m->properties['TextInfoDuration'] = 0;
Sim::update(REQUEST, true);
check('Anzeigedauer 0: kein Ausblend-Timer', $m->timers['TextTimer'] === 0 && texts() === ['Scharf Nacht']);

$m = setup();
Sim::update(REQUEST, true);
Sim::fireTextTimer();
Sim::update(DOOR, true);
Sim::reset();
scan(9, 9);
Sim::fireTextTimer();
check('ekey-Hinweis im Voralarm, danach wieder Voralarm', texts() === ['Finger abgel.', 'Voralarm 30s']);

$m = setup();
$m->properties['TextPreAlarm'] = false;
$m->properties['TextArmed'] = false;
armAway();
Sim::reset();
Sim::update(DOOR, true);
check('Abgewählte Kategorien werden nicht ausgegeben', texts() === []);
scan(9, 9);
check('ekey abgelehnt', texts() === ['Finger abgel.']);

$m = setup();
$m->properties['TextReplaceUmlauts'] = false;
Sim::update(REQUEST, true);
Sim::update(DOOR, true);
Sim::update(WINDOW, true);
$all = texts();
check('Ohne Ersetzung bleiben Umlaute erhalten', end($all) === 'ALARM Haustür');

echo "Weitere Statusvariablen\n";
$m = setup();
Sim::addVar(1001, 'LED Taster', VARIABLETYPE_BOOLEAN, false);
Sim::addVar(1002, 'Szene', VARIABLETYPE_INTEGER, 0);
Sim::addVar(1003, 'Nur Abwesend', VARIABLETYPE_BOOLEAN, false);
$m->properties['ArmedOutputs'] = json_encode([
    ['VariableID' => 1001, 'ArmedValue' => 1, 'DisarmedValue' => 0, 'Mode' => 0],
    ['VariableID' => 1002, 'ArmedValue' => 5, 'DisarmedValue' => 2, 'Mode' => 2],
    ['VariableID' => 1003, 'ArmedValue' => 1, 'DisarmedValue' => 0, 'Mode' => 1],
]);
$m->ApplyChanges();
check('Unscharf: Werte für unscharf', Sim::$vars[1001]['value'] === false && Sim::$vars[1002]['value'] === 2);
Sim::update(REQUEST, true);
check('Nacht: LED an, Szene 5, "nur Abwesend" bleibt aus',
    Sim::$vars[1001]['value'] === true && Sim::$vars[1002]['value'] === 5 && Sim::$vars[1003]['value'] === false);
Sim::update(REQUEST, false);
armAway();
check('Abwesend: LED an, Szene 2, "nur Abwesend" an',
    Sim::$vars[1001]['value'] === true && Sim::$vars[1002]['value'] === 2 && Sim::$vars[1003]['value'] === true);
Sim::update(THILO, true);
check('Unscharf: alles zurück', Sim::$vars[1001]['value'] === false && Sim::$vars[1003]['value'] === false);
check('System Armed weiterhin geschaltet', Sim::$vars[ARMED]['value'] === false);

echo "Ausgangsverzögerung Nacht und Countdown\n";
function countdowns(): array
{
    return array_values(array_map(fn ($a) => $a[1], array_filter(Sim::$actions, fn ($a) => $a[0] === COUNTDOWN)));
}
function tick(Alarmanlage $m, int $seconds): void
{
    // Zeit vergeht: Deadline rückt näher, dann ein Countdown-Tick
    $m->attributes['StepDeadline'] -= $seconds;
    $m->RequestAction('CountdownElapsed', 0);
}
$m = setup();
$m->properties['NightArmDelay'] = 30;
Sim::update(REQUEST, true);
check('Noch nicht scharf, Schritt armNight', mode($m) === 0 && $m->attributes['NextStep'] === 'armNight');
check('Countdown startet mit 30s', countdowns() === ['Scharf in 30s']);
check('Countdown-Timer läuft sekündlich', $m->timers['CountdownTimer'] === 1000);
tick($m, 1);
tick($m, 4);
check('Countdown zählt herunter', countdowns() === ['Scharf in 30s', 'Scharf in 29s', 'Scharf in 25s']);
check('System Armed während Verzögerung noch 0', Sim::$vars[ARMED]['value'] === false);
Sim::update(DOOR, true);
check('Kontakt während Verzögerung löst nichts aus', state($m) === 0);
Sim::update(DOOR, false);
Sim::reset();
Sim::fireTimer();
check('Nach Ablauf scharf', mode($m) === 2 && Sim::$vars[ARMED]['value'] === true);
check('Countdown mit leerem Text zurückgenommen', countdowns() === [''] && $m->timers['CountdownTimer'] === 0);

$m = setup();
$m->properties['NightArmDelay'] = 30;
Sim::update(REQUEST, true);
Sim::reset();
Sim::update(REQUEST, false);
check('Scharf Request 0 bricht Verzögerung ab', mode($m) === 0 && $m->attributes['NextStep'] === '' && countdowns() === ['']);

$m = setup();
$m->properties['NightArmDelay'] = 30;
Sim::update(REQUEST, true);
$m->Disarm();
check('ALARM_Disarm bricht Verzögerung ab', $m->attributes['NextStep'] === '');

echo "Countdown Abwesend und Voralarm\n";
$m = setup();
Sim::update(THILO, false);
Sim::update(NADINE, false);
check('Abwesend-Verzögerung im Format m:ss', countdowns() === ['Scharf in 5:00']);
tick($m, 61);
check('Nach 61 s', end(Sim::$actions)[1] === 'Scharf in 3:59');
Sim::update(NADINE, true);
check('Heimkommen nimmt Countdown zurück', end(Sim::$actions) === [COUNTDOWN, '']);

$m = setup();
armAway();
Sim::reset();
Sim::update(DOOR, true);
tick($m, 10);
check('Voralarm-Countdown', countdowns() === ['Alarm in 30s', 'Alarm in 20s']);
Sim::fireTimer();
check('Bei Alarm zurückgenommen', end(Sim::$actions) !== null && countdowns() === ['Alarm in 30s', 'Alarm in 20s', '']);

$m = setup();
$m->properties['CountdownTimeOnly'] = true;
$m->properties['CountdownArm'] = false;
armAway();
check('Schärf-Countdown abgewählt', countdowns() === []);
Sim::update(DOOR, true);
check('Nur Zeit', countdowns() === ['30s']);
scan(1, 3);
check('ekey während Voralarm nimmt Countdown zurück', countdowns() === ['30s', '']);

echo "HomeKit (eigene Variable)\n";
$m = setup();
$m->properties['HomeKitSource'] = 1;
$m->ApplyChanges();
check('Variable angelegt, Aus', $m->values['HomeKit'] === 3);
$m->RequestAction('HomeKit', 2);
check('Nacht', mode($m) === 2 && $m->values['HomeKit'] === 2);
$m->RequestAction('HomeKit', 0);
check('Zuhause bei Nacht scharf: bleibt Nacht, zeigt Zuhause', mode($m) === 2 && $m->values['HomeKit'] === 0);
$m->RequestAction('HomeKit', 3);
check('Aus schaltet unscharf', mode($m) === 0 && $m->values['HomeKit'] === 3);
$m->RequestAction('HomeKit', 0);
check('Zuhause aus unscharf schärft Nacht', mode($m) === 2 && $m->values['HomeKit'] === 0);
Sim::update(REQUEST, false);
Sim::update(REQUEST, true);
check('Über KNX geschärft: HomeKit zeigt Nacht', $m->values['HomeKit'] === 2);
$m->RequestAction('HomeKit', 1);
check('Abwesend über HomeKit, obwohl jemand zuhause', mode($m) === 1 && $m->values['HomeKit'] === 1);
Sim::update(DOOR, true);
Sim::fireTimer();
check('Alarm ausgelöst = true', $m->values['AlarmActive'] === true);
$m->properties['HomeKitCanDisarmAway'] = false;
$m->RequestAction('HomeKit', 3);
check('Aus bei Abwesend gesperrt', mode($m) === 1 && $m->values['HomeKit'] === 1);
Sim::update(THILO, false);
Sim::update(THILO, true);
check('Unscharf über Anwesenheit: HomeKit Aus, Alarm false', $m->values['HomeKit'] === 3 && $m->values['AlarmActive'] === false);

$m = setup();
$m->properties['HomeKitSource'] = 1;
$m->properties['NightArmDelay'] = 30;
$m->ApplyChanges();
$m->RequestAction('HomeKit', 2);
check('Mit Verzögerung: noch unscharf, HomeKit zeigt Ziel Nacht', mode($m) === 0 && $m->values['HomeKit'] === 2);
$m->RequestAction('HomeKit', 3);
check('Aus während Verzögerung bricht ab', $m->attributes['NextStep'] === '' && $m->values['HomeKit'] === 3);
$m->RequestAction('HomeKit', 1);
check('Abwesend mit Verzögerung und Countdown', $m->attributes['NextStep'] === 'armAwayManual' && end(Sim::$actions) === [COUNTDOWN, 'Scharf in 30s']);
Sim::fireTimer();
check('Danach Abwesend', mode($m) === 1);

echo "HomeKit (vorhandene Variable)\n";
$m = setup();
Sim::addVar(1101, 'HomeKit Alarm', VARIABLETYPE_INTEGER, 3);
$m->properties['HomeKitSource'] = 2;
$m->properties['HomeKitVariableID'] = 1101;
$m->ApplyChanges();
check('Variable wird überwacht', in_array(1101, $m->watched, true));
Sim::update(1101, 2);
check('HomeKit Nacht schärft', mode($m) === 2);
Sim::update(REQUEST, false);
check('KNX unscharf schreibt 3 zurück', mode($m) === 0 && Sim::$vars[1101]['value'] === 3);
Sim::update(1101, 3);
check('Echo des eigenen Schreibens wird ignoriert', mode($m) === 0);
Sim::update(REQUEST, true);
Sim::update(REQUEST, false);
Sim::update(1101, 2);
check('Verspätetes Echo (2) schärft nicht erneut und wird korrigiert', mode($m) === 0 && Sim::$vars[1101]['value'] === 3);

echo "HomeKit: Zurückspringen\n";
$m = setup();
$m->properties['HomeKitSource'] = 1;
$m->ApplyChanges();
$m->RequestAction('HomeKit', 1);
$m->RequestAction('HomeKit', 2);
check('Abwesend → Nacht über HomeKit möglich', mode($m) === 2 && $m->values['HomeKit'] === 2);
$m->RequestAction('HomeKit', 1);
$m->RequestAction('HomeKit', 0);
check('Abwesend → Zuhause über HomeKit möglich', mode($m) === 2 && $m->values['HomeKit'] === 0);
$m->properties['HomeKitCanDisarmAway'] = false;
$m->RequestAction('HomeKit', 1);
$m->log = [];
$m->RequestAction('HomeKit', 2);
check('Gesperrt: bleibt Abwesend, Grund im Log', mode($m) === 1 && $m->values['HomeKit'] === 1
    && (bool) preg_grep('/"Nacht" abgelehnt/', $m->log));

$m = setup();
Sim::addVar(1101, 'HomeKit Alarm', VARIABLETYPE_INTEGER, 3);
$m->properties['HomeKitSource'] = 2;
$m->properties['HomeKitVariableID'] = 1101;
$m->ApplyChanges();
Sim::update(REQUEST, true);
Sim::update(REQUEST, false);
$m->attributes['HomeKitWrites'] = json_encode(array_map(fn ($w) => [$w[0], $w[1] - 4], json_decode($m->attributes['HomeKitWrites'], true)));
Sim::update(1101, 2);
check('Echter Befehl nach Ablauf des Echo-Fensters wird ausgeführt', mode($m) === 2);

$m = setup();
$m->properties['TestMode'] = true;
$m->properties['HomeKitSource'] = 2;
$m->properties['HomeKitVariableID'] = 1101;
Sim::addVar(1101, 'HomeKit Alarm', VARIABLETYPE_INTEGER, 3);
$m->ApplyChanges();
$m->ResetTestLog();
Sim::update(1101, 2);
Sim::update(1101, 3);
$log = json_decode($m->attributes['TestLog'], true);
check('Schnelle HomeKit-Wechsel zählen nicht als Flattern', $log['sensors']['1101']['flaps'] === 0 && $log['sensors']['1101']['events'] === 2);

echo "Testprotokoll\n";
$m = setup();
$m->properties['TestMode'] = true;
@unlink(IPS_GetLogDir() . 'Alarmanlage_99999_Test.csv');
$m->ApplyChanges();
$m->ResetTestLog();
Sim::update(DOOR, true);
Sim::update(DOOR, false);
Sim::update(MOTION_WZ, true);
$log = json_decode($m->attributes['TestLog'], true);
check('Kontakt: 2 Ereignisse, 1 Auslösung, 1 Flattern', $log['sensors'][(string) DOOR]['events'] === 2
    && $log['sensors'][(string) DOOR]['triggers'] === 1 && $log['sensors'][(string) DOOR]['flaps'] === 1);
check('Bewegung als Auslösung gezählt', $log['sensors'][(string) MOTION_WZ]['triggers'] === 1);
Sim::update(REQUEST, true);
Sim::update(WINDOW, true);
scan(5, 5);
$log = json_decode($m->attributes['TestLog'], true);
check('Reaktion Scharfschaltung', $log['events'][2][5] === 'Modus Unscharf → Nacht');
check('Reaktion Voralarm', $log['events'][1][5] === 'Zustand → Voralarm');
check('ekey-Scan protokolliert', $log['events'][0][1] === 'ekey' && $log['events'][0][3] === 'Benutzer 5, Finger 5: abgelehnt');
check('HTML-Bericht enthält Sensoren', strpos($m->values['TestReport'], 'Haustür') !== false && strpos($m->values['TestReport'], 'Letzte Ereignisse') !== false);
$csv = file(IPS_GetLogDir() . 'Alarmanlage_99999_Test.csv');
check('CSV mit Kopfzeile und allen Ereignissen', count($csv) === 1 + count($log['events']) && strpos($csv[0], 'Zeit;Typ') === 0);
check('Textbericht', strpos($m->GetTestReport(), 'Kontakt Haustür: 2 Ereignisse, 1 Auslösungen, 1 Flattern') === 0);
$m->ResetTestLog();
check('Zurücksetzen leert Protokoll und CSV', json_decode($m->attributes['TestLog'], true) === [] && !file_exists(IPS_GetLogDir() . 'Alarmanlage_99999_Test.csv'));
$m->properties['TestMode'] = false;
$m->ApplyChanges();
Sim::update(MOTION_FLUR, true);
check('Ohne Testmodus kein Protokoll, Berichtsvariable entfernt', json_decode($m->attributes['TestLog'], true) === [] && !array_key_exists('TestReport', $m->values));

echo "Eingangsweg\n";
function entrySetup(): Alarmanlage
{
    $m = setup();
    $m->properties['Contacts'] = json_encode([['VariableID' => DOOR, 'Name' => '', 'EntryRoute' => true], ['VariableID' => WINDOW, 'Name' => 'Küche', 'EntryRoute' => false]]);
    $m->properties['MotionSensors'] = json_encode([['VariableID' => MOTION_WZ, 'Name' => 'Wohnzimmer', 'EntryRoute' => false], ['VariableID' => MOTION_FLUR, 'Name' => 'Flur EG', 'EntryRoute' => true]]);
    $m->ApplyChanges();
    return $m;
}
$m = entrySetup();
armAway();
Sim::update(DOOR, true);
Sim::update(MOTION_FLUR, true);
check('Abwesend: Haustür + Flur-BWM bleibt Voralarm', state($m) === 1);
Sim::update(MOTION_WZ, true);
check('Wohnzimmer-BWM eskaliert trotzdem sofort', state($m) === 3);

$m = entrySetup();
armAway();
Sim::update(WINDOW, true);
Sim::update(MOTION_FLUR, true);
check('Voralarm durch Küchenfenster: Flur-BWM eskaliert', state($m) === 3);

$m = entrySetup();
armAway();
Sim::update(MOTION_FLUR, true);
check('Abwesend: Flur-BWM ohne Kontakt löst sofort aus', state($m) === 2);

$m = entrySetup();
Sim::update(REQUEST, true);
Sim::fireTimer();
Sim::update(DOOR, true);
Sim::update(MOTION_FLUR, true);
check('Nacht: Haustür + Flur-BWM bleibt Voralarm', state($m) === 1);
Sim::update(MOTION_WZ, true);
check('Nacht: Wohnzimmer-BWM eskaliert', state($m) === 3);

echo "Überfall-Finger und Panik\n";
$m = setup();
Sim::addVar(1201, 'Stiller Ausgang', VARIABLETYPE_BOOLEAN, false);
$m->properties['EkeyUsers'] = json_encode([
    ['UserID' => 1, 'FingerID' => -1, 'Name' => 'Thilo', 'Duress' => false],
    ['UserID' => 1, 'FingerID' => 9, 'Name' => 'Thilo', 'Duress' => true],
]);
$m->properties['SilentAlarmOutputs'] = json_encode([['VariableID' => 1201, 'Value' => 1, 'ResetValue' => 0]]);
$m->properties['SilentAlarmScriptID'] = 88888;
$m->ApplyChanges();
armAway();
Sim::reset();
scan(1, 9);
check('Überfall-Finger entschärft ganz normal', mode($m) === 0);
check('Stiller Alarm gesetzt, Ausgang geschaltet, Skript gestartet', $m->values['SilentAlarm'] === true
    && Sim::$vars[1201]['value'] === true && (bool) array_filter(Sim::$actions, fn ($a) => $a[0] === 'script'));
check('Push "STILLER ALARM", keine Sirene', (bool) preg_grep('/^STILLER ALARM/', Sim::$pushes) && !in_array([SIREN, true], Sim::$actions, true));
check('Keine Anzeige auf dem Display', strpos((string) Sim::$vars[TEXT]['value'], 'ALARM') === false);
$m->ResetSilentAlarm();
check('Zurücksetzen', $m->values['SilentAlarm'] === false && Sim::$vars[1201]['value'] === false);
armAway();
scan(1, 3);
check('Normaler Finger: kein stiller Alarm', mode($m) === 0 && $m->values['SilentAlarm'] === false);

$m = setup();
Sim::addVar(1301, 'Panik Schlafzimmer', VARIABLETYPE_BOOLEAN, false);
$m->properties['PanicInputs'] = json_encode([['VariableID' => 1301, 'Name' => 'Schlafzimmer']]);
$m->ApplyChanges();
Sim::update(1301, true);
check('Panik im Modus Unscharf: voller Alarm', state($m) === 3 && $m->values['Trigger'] === 'Panik Schlafzimmer');
Sim::update(REQUEST, true);
check('Schärfen während Alarm wird abgelehnt', $m->attributes['NextStep'] === 'sirenOff' && mode($m) === 0);
Sim::update(REQUEST, false);
check('Scharf Request 0 beendet Panikalarm', state($m) === 0);
Sim::update(1301, false);
$m->properties['PanicSilent'] = true;
Sim::reset();
Sim::update(1301, true);
check('Stiller Panik-Taster: kein Alarm, stiller Alarm', state($m) === 0 && $m->values['SilentAlarm'] === true);
$m->properties['PanicSilent'] = false;
$m->Panic();
$m->Disarm();
check('ALARM_Panic und ALARM_Disarm', state($m) === 0 && $m->values['AlarmActive'] === false);

echo "Erinnerung Schärfen vergessen\n";
$m = setup();
$m->properties['ReminderEnabled'] = true;
$m->properties['ReminderMaxCount'] = 2;
$m->ApplyChanges();
check('Timer auf nächste Erinnerungszeit', $m->timers['ReminderTimer'] > 0 && $m->timers['ReminderTimer'] <= 86400000);
Sim::$vars[WINDOW]['value'] = true;
$m->RequestAction('ReminderElapsed', 0);
check('Push mit offenem Fenster', (bool) preg_grep('/noch nicht scharf\. Offen: Küche/', Sim::$pushes));
check('Wiederholung in 15 min', $m->timers['ReminderTimer'] === 15 * 60 * 1000);
check('DPT16 Hinweis', in_array('Nicht scharf!', texts(), true));
Sim::reset();
$m->RequestAction('ReminderElapsed', 0);
check('Nach max. Anzahl wieder auf den nächsten Tag', count(Sim::$pushes) === 1 && $m->attributes['ReminderCount'] === 0
    && $m->timers['ReminderTimer'] !== 15 * 60 * 1000);
Sim::reset();
Sim::update(REQUEST, true);
Sim::fireTimer();
$m->RequestAction('ReminderElapsed', 0);
check('Scharf: keine Erinnerung', !preg_grep('/Schärfen vergessen/', Sim::$pushes));

echo "Anwesenheitssimulation\n";
$m = setup();
Sim::addVar(1401, 'Licht Küche', VARIABLETYPE_BOOLEAN, false);
Sim::addVar(1402, 'Licht Bad', VARIABLETYPE_BOOLEAN, false);
Sim::addVar(1403, 'Es ist Tag', VARIABLETYPE_BOOLEAN, false);
$m->properties['SimEnabled'] = true;
$m->properties['SimLights'] = json_encode([['VariableID' => 1401, 'Name' => ''], ['VariableID' => 1402, 'Name' => '']]);
$m->properties['SimDayVariableID'] = 1403;
$m->properties['SimStartTime'] = '{"hour":0,"minute":0,"second":0}';
$m->properties['SimEndTime'] = '{"hour":24,"minute":0,"second":0}';
$m->ApplyChanges();
check('Unscharf: Simulation aus', $m->timers['SimTimer'] === 0);
armAway();
check('Abwesend: Simulation läuft minütlich', $m->timers['SimTimer'] === 60000 && count(json_decode($m->attributes['SimState'], true)) === 2);
$m->attributes['SimState'] = json_encode(['1401' => [false, 0], '1402' => [false, time() + 3600]]);
$m->RequestAction('SimElapsed', 0);
$sim = json_decode($m->attributes['SimState'], true);
check('Fälliges Licht geht an, anderes wartet', Sim::$vars[1401]['value'] === true && Sim::$vars[1402]['value'] === false
    && $sim['1401'][1] >= time() + 20 * 60 && $sim['1401'][1] <= time() + 90 * 60);
Sim::update(1403, true);
$m->RequestAction('SimElapsed', 0);
check('Bei Tag: eingeschaltete Lichter wieder aus', Sim::$vars[1401]['value'] === false && $m->attributes['SimState'] === '{}');
Sim::update(1403, false);
$m->RequestAction('SimElapsed', 0);
$m->attributes['SimState'] = json_encode(['1401' => [false, 0], '1402' => [false, time() + 3600]]);
$m->RequestAction('SimElapsed', 0);
Sim::update(THILO, true);
check('Heimkommen: Simulation stoppt, Licht bleibt an', $m->timers['SimTimer'] === 0 && Sim::$vars[1401]['value'] === true);

echo $failures === 0 ? "\nAlle Tests bestanden.\n" : "\n$failures Test(s) fehlgeschlagen.\n";
exit($failures === 0 ? 0 : 1);
