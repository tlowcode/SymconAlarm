<?php

declare(strict_types=1);

class Alarmanlage extends IPSModule
{
    private const MODE_OFF = 0;
    private const MODE_AWAY = 1;
    private const MODE_NIGHT = 2;

    private const STATE_IDLE = 0;
    private const STATE_PREALARM = 1;
    private const STATE_ALARM = 2;
    private const STATE_SIREN = 3;
    private const STATE_LATCHED = 4;

    private const MODE_LABELS = ['Unscharf', 'Abwesend', 'Nacht'];
    private const STATE_LABELS = ['Ruhe', 'Voralarm', 'Alarm', 'Alarm – Sirene', 'Alarm ausgelöst'];

    /** Werte des HomeKit-Sicherheitssystems (Profil SecuritySystem.HomeKit) */
    private const HK_HOME = 0;
    private const HK_AWAY = 1;
    private const HK_NIGHT = 2;
    private const HK_OFF = 3;

    private const HOMEKIT_NONE = 0;
    private const HOMEKIT_OWN = 1;
    private const HOMEKIT_EXTERNAL = 2;

    /** So lange gilt eine Aktualisierung mit dem zuletzt geschriebenen Wert als Echo und nicht als Befehl. */
    private const HOMEKIT_ECHO_SECONDS = 3;

    /** Nur bei diesen Ereignistypen ist schnelles Wechseln ein Hinweis auf einen unzuverlässigen Sensor. */
    private const FLAP_TYPES = ['Kontakt', 'Bewegung', 'Anwesenheit'];

    private const TEST_LOG_MAX = 200;

    /** Anmerkung zu einem ekey-Scan für das Testprotokoll, gesetzt während der Auswertung */
    private string $testNote = '';

    /** Kategorien der DPT16-Textausgabe, Property heißt jeweils "Text" + Schlüssel */
    private const TEXT_CATEGORIES = ['Armed', 'Disarmed', 'Excluded', 'PreAlarm', 'Alarm', 'SirenOff', 'EkeyRejected', 'Reminder', 'Perimeter'];
    private const WEBHOOK_CONTROL = '{015A6EB8-D6E5-4B93-B496-0D3F77AE9FE1}';
    /** Warmweiß (2700 K) für Hue-Lampen, deren vorherige Farbe unbekannt ist */
    private const HUE_WARM_WHITE_MIREK = 370;
    private const PERIMETER_MODES = ['immer', 'nur scharf', 'nur Abwesend', 'nur Nacht', 'nie'];

    private const STATUS_OK = 102;
    private const STATUS_NO_CONTACTS = 104;
    private const STATUS_MISSING_VARIABLE = 201;

    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyString('Contacts', '[]');
        $this->RegisterPropertyBoolean('ContactOpenValue', true);
        $this->RegisterPropertyString('MotionSensors', '[]');
        $this->RegisterPropertyString('Presence', '[]');

        $this->RegisterPropertyInteger('ArmRequestVariableID', 0);
        $this->RegisterPropertyInteger('ArmedStatusVariableID', 0);
        $this->RegisterPropertyString('ArmedOutputs', '[]');
        $this->RegisterPropertyBoolean('RequestCanDisarmAway', false);

        $this->RegisterPropertyInteger('EkeyUserVariableID', 0);
        $this->RegisterPropertyInteger('EkeyFingerVariableID', 0);
        $this->RegisterPropertyInteger('EkeyActionVariableID', 0);
        $this->RegisterPropertyInteger('EkeyActionValue', 1);
        $this->RegisterPropertyString('EkeyUsers', '[]');
        $this->RegisterPropertyBoolean('EkeyNotifyRejected', true);

        $this->RegisterPropertyString('PanicInputs', '[]');
        $this->RegisterPropertyBoolean('PanicSilent', false);
        $this->RegisterPropertyString('SilentAlarmOutputs', '[]');
        $this->RegisterPropertyInteger('SilentAlarmScriptID', 0);
        $this->RegisterPropertyInteger('SilentVisuID', 0);

        $this->RegisterPropertyBoolean('ReminderEnabled', false);
        $this->RegisterPropertyString('ReminderTime', '{"hour":23,"minute":0,"second":0}');
        $this->RegisterPropertyInteger('ReminderRepeatMinutes', 15);
        $this->RegisterPropertyInteger('ReminderMaxCount', 3);

        $this->RegisterPropertyBoolean('SimEnabled', false);
        $this->RegisterPropertyString('SimLights', '[]');
        $this->RegisterPropertyInteger('SimDayVariableID', 0);
        $this->RegisterPropertyString('SimStartTime', '{"hour":17,"minute":0,"second":0}');
        $this->RegisterPropertyString('SimEndTime', '{"hour":23,"minute":30,"second":0}');
        $this->RegisterPropertyInteger('SimOnMin', 20);
        $this->RegisterPropertyInteger('SimOnMax', 90);
        $this->RegisterPropertyInteger('SimOffMin', 10);
        $this->RegisterPropertyInteger('SimOffMax', 60);

        $this->RegisterPropertyString('WebHookName', 'alarm');
        $this->RegisterPropertyString('WebHookToken', '');
        $this->RegisterPropertyString('PerimeterHooks', '[]');
        $this->RegisterPropertyInteger('PerimeterCooldown', 60);
        $this->RegisterPropertyInteger('PerimeterDayVariableID', 0);
        $this->RegisterPropertyInteger('PerimeterSignalSeconds', 30);

        $this->RegisterPropertyInteger('LightSwitchVariableID', 0);
        $this->RegisterPropertyInteger('LightBrightnessVariableID', 0);
        $this->RegisterPropertyInteger('LightDayVariableID', 0);
        $this->RegisterPropertyInteger('LightAmbientVariableID', 0);
        $this->RegisterPropertyInteger('LightAmbientThreshold', 30);
        $this->RegisterPropertyInteger('LightBasePercent', 50);
        $this->RegisterPropertyInteger('LightMotionPercent', 100);
        $this->RegisterPropertyInteger('LightMotionSeconds', 600);

        $this->RegisterPropertyString('SignalLights', '[]');
        $this->RegisterPropertyInteger('SignalColor', 0xFF0000);
        $this->RegisterPropertyInteger('SignalInterval', 1000);
        $this->RegisterPropertyBoolean('SignalOnAlarm', true);
        $this->RegisterPropertyBoolean('SignalOnPreAlarm', false);
        $this->RegisterPropertyBoolean('SignalRestore', true);

        $this->RegisterPropertyString('Sirens', '[]');
        $this->RegisterPropertyString('AlarmLights', '[]');
        $this->RegisterPropertyString('PreAlarmLights', '[]');
        $this->RegisterPropertyBoolean('PreAlarmBlink', true);
        $this->RegisterPropertyInteger('PreAlarmBlinkInterval', 1000);
        $this->RegisterPropertyString('Shutters', '[]');

        $this->RegisterPropertyInteger('EntryDelay', 30);
        $this->RegisterPropertyInteger('SirenDelay', 15);
        $this->RegisterPropertyInteger('SirenDuration', 180);
        $this->RegisterPropertyInteger('AwayArmDelay', 300);
        $this->RegisterPropertyInteger('NightArmDelay', 30);

        $this->RegisterPropertyString('TextOutputs', '[]');
        $this->RegisterPropertyBoolean('TextReplaceUmlauts', true);
        $this->RegisterPropertyInteger('TextInfoDuration', 30);
        $this->RegisterPropertyString('CountdownOutputs', '[]');
        $this->RegisterPropertyBoolean('CountdownArm', true);
        $this->RegisterPropertyBoolean('CountdownPreAlarm', true);
        $this->RegisterPropertyBoolean('CountdownTimeOnly', false);
        foreach (self::TEXT_CATEGORIES as $category) {
            $this->RegisterPropertyBoolean('Text' . $category, true);
        }

        $this->RegisterPropertyInteger('VisuID', 0);
        $this->RegisterPropertyBoolean('NotifyArmDisarm', true);
        $this->RegisterPropertyBoolean('AllowVisuControl', false);
        $this->RegisterPropertyBoolean('TestMode', true);
        $this->RegisterPropertyInteger('TestFlapSeconds', 5);
        $this->RegisterPropertyBoolean('TestLogCsv', true);

        $this->RegisterPropertyInteger('HomeKitSource', self::HOMEKIT_NONE);
        $this->RegisterPropertyInteger('HomeKitVariableID', 0);
        $this->RegisterPropertyInteger('HomeKitHomeAs', self::HK_NIGHT);
        $this->RegisterPropertyBoolean('HomeKitCanDisarmAway', true);

        $this->RegisterAttributeString('ActiveContacts', '[]');
        $this->RegisterAttributeString('PendingContacts', '[]');
        $this->RegisterAttributeString('NextStep', '');
        $this->RegisterAttributeString('LastEkeyScan', '');
        $this->RegisterAttributeString('TriggerSource', '');
        $this->RegisterAttributeString('LastText', '');
        $this->RegisterAttributeString('LastCountdownText', '');
        $this->RegisterAttributeInteger('StepDeadline', 0);
        $this->RegisterAttributeBoolean('HomeKitHome', false);
        $this->RegisterAttributeString('HomeKitWrites', '[]');
        $this->RegisterAttributeString('TestLog', '{}');
        $this->RegisterAttributeBoolean('PreAlarmEntry', false);
        $this->RegisterAttributeBoolean('BlinkOn', false);
        $this->RegisterAttributeString('PerimeterLast', '{}');
        $this->RegisterAttributeInteger('SignalUntil', 0);
        $this->RegisterAttributeBoolean('SignalOn', false);
        $this->RegisterAttributeString('SignalSaved', '{}');
        $this->RegisterAttributeInteger('LightDeferred', -1);
        $this->RegisterAttributeInteger('ReminderCount', 0);
        $this->RegisterAttributeString('SimState', '{}');

        $this->RegisterTimer('StepTimer', 0, 'IPS_RequestAction($_IPS[\'TARGET\'], \'TimerElapsed\', 0);');
        $this->RegisterTimer('TextTimer', 0, 'IPS_RequestAction($_IPS[\'TARGET\'], \'TextTimerElapsed\', 0);');
        $this->RegisterTimer('CountdownTimer', 0, 'IPS_RequestAction($_IPS[\'TARGET\'], \'CountdownElapsed\', 0);');
        $this->RegisterTimer('ReminderTimer', 0, 'IPS_RequestAction($_IPS[\'TARGET\'], \'ReminderElapsed\', 0);');
        $this->RegisterTimer('BlinkTimer', 0, 'IPS_RequestAction($_IPS[\'TARGET\'], \'BlinkElapsed\', 0);');
        $this->RegisterTimer('LightTimer', 0, 'IPS_RequestAction($_IPS[\'TARGET\'], \'LightElapsed\', 0);');
        $this->RegisterTimer('SignalTimer', 0, 'IPS_RequestAction($_IPS[\'TARGET\'], \'SignalElapsed\', 0);');
        $this->RegisterTimer('SimTimer', 0, 'IPS_RequestAction($_IPS[\'TARGET\'], \'SimElapsed\', 0);');

        $this->ensureProfiles();
        $this->RegisterVariableInteger('Mode', 'Modus', 'Alarm.Mode', 10);
        $this->RegisterVariableInteger('State', 'Zustand', 'Alarm.State', 20);
        $this->RegisterVariableString('Trigger', 'Auslöser', '', 30);
        $this->RegisterVariableString('Excluded', 'Ausgeklammerte Kontakte', '', 40);
        $this->RegisterVariableBoolean('AlarmActive', 'Alarm ausgelöst', '~Alert', 25);
        $this->RegisterVariableBoolean('SilentAlarm', 'Stiller Alarm', '~Alert', 26);
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $this->RegisterMessage(0, IPS_KERNELSTARTED);
        if (IPS_GetKernelRunlevel() !== KR_READY) {
            return;
        }

        $this->ensureProfiles();

        foreach ($this->GetMessageList() as $senderID => $messages) {
            foreach ($messages as $message) {
                if ($message === VM_UPDATE) {
                    $this->UnregisterMessage($senderID, VM_UPDATE);
                }
            }
        }
        foreach ($this->GetReferenceList() as $id) {
            $this->UnregisterReference($id);
        }

        $missing = [];
        $watch = array_merge(
            array_keys($this->contacts()),
            array_keys($this->motionSensors()),
            array_keys($this->presence()),
            [$this->ReadPropertyInteger('ArmRequestVariableID')],
            $this->ekeyIDs(),
            [$this->externalHomeKitID()],
            array_keys($this->namedList('PanicInputs')),
            [$this->lightConfigured() ? $this->lightDayID() : 0]
        );
        foreach ($watch as $id) {
            if ($id <= 0) {
                continue;
            }
            if (!IPS_VariableExists($id)) {
                $missing[] = $id;
                continue;
            }
            $this->RegisterMessage($id, VM_UPDATE);
            $this->RegisterReference($id);
        }
        foreach ($this->outputIDs() as $id) {
            if (!IPS_VariableExists($id)) {
                $missing[] = $id;
                continue;
            }
            $this->RegisterReference($id);
        }
        foreach ($this->listProperty('SignalLights') as $row) {
            $instanceID = (int) ($row['InstanceID'] ?? 0);
            if ($instanceID <= 0) {
                continue;
            }
            if (!IPS_InstanceExists($instanceID) || !$this->hueVariables($instanceID)) {
                $missing[] = $instanceID;
                continue;
            }
            $this->RegisterReference($instanceID);
        }
        $this->ensureWebHook();

        $scriptID = $this->ReadPropertyInteger('SilentAlarmScriptID');
        if ($scriptID > 0) {
            if (IPS_ScriptExists($scriptID)) {
                $this->RegisterReference($scriptID);
            } else {
                $missing[] = $scriptID;
            }
        }

        $ownHomeKit = $this->ReadPropertyInteger('HomeKitSource') === self::HOMEKIT_OWN;
        $this->MaintainVariable('HomeKit', 'HomeKit Sicherheitssystem', VARIABLETYPE_INTEGER, 'SecuritySystem.HomeKit', 50, $ownHomeKit);
        if ($ownHomeKit) {
            $this->EnableAction('HomeKit');
        }
        $this->MaintainVariable('TestReport', 'Testprotokoll', VARIABLETYPE_STRING, '~HTMLBox', 60, $this->ReadPropertyBoolean('TestMode'));

        if ($this->ReadPropertyBoolean('AllowVisuControl')) {
            $this->EnableAction('Mode');
        } else {
            $this->DisableAction('Mode');
        }

        $this->lock(function () {
            $this->reconcileContacts();
            $this->updateArmedOutputs();
            $this->refreshDisplay();
            $this->updateTestReport();

            // Nach Neustart: einen offenen Schritt (Voralarm, Sirene aus ...) zeitnah nachholen
            if ($this->ReadAttributeString('NextStep') !== '' && $this->GetTimerInterval('StepTimer') === 0) {
                $this->SetTimerInterval('StepTimer', 1000);
            }
            $this->updateCountdown();
            $this->scheduleReminder();
            $this->updateSimulation();
        });

        if ($missing) {
            $this->LogMessage('Variablen oder Instanzen existieren nicht: ' . implode(', ', array_unique($missing)), KL_WARNING);
            $this->SetStatus(self::STATUS_MISSING_VARIABLE);
        } elseif (!$this->contacts()) {
            $this->SetStatus(self::STATUS_NO_CONTACTS);
        } else {
            $this->SetStatus(self::STATUS_OK);
        }
    }

    public function GetConfigurationForm()
    {
        $form = file_get_contents(__DIR__ . '/form.json');
        return str_replace('{HOOK}', $this->hookPath(), $form);
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
    {
        if ($Message === IPS_KERNELSTARTED) {
            $this->ApplyChanges();
            return;
        }
        if ($Message !== VM_UPDATE) {
            return;
        }
        $this->lock(function () use ($SenderID, $Data) {
            $this->onVariable($SenderID, $Data[0], (bool) $Data[1]);
        });
    }

    public function RequestAction($Ident, $Value)
    {
        switch ($Ident) {
            case 'TimerElapsed':
                $this->lock(function () {
                    $this->onTimer();
                });
                break;

            case 'TextTimerElapsed':
                $this->lock(function () {
                    $this->refreshDisplay();
                });
                break;

            case 'HomeKit':
                $this->lock(function () use ($Value) {
                    $this->onHomeKit((int) $Value);
                });
                break;

            case 'ReminderElapsed':
                $this->lock(function () {
                    $this->onReminder();
                });
                break;

            case 'BlinkElapsed':
                $this->lock(function () {
                    $this->blink();
                });
                break;

            case 'LightElapsed':
                $this->lock(function () {
                    $this->SetTimerInterval('LightTimer', 0);
                    $this->SendDebug('Bewegungslicht', 'Bewegungszeit abgelaufen.', 0);
                    $this->setLightPercent($this->lightRestPercent());
                });
                break;

            case 'SignalElapsed':
                $this->lock(function () {
                    $this->signalTick();
                });
                break;

            case 'SimElapsed':
                $this->lock(function () {
                    $this->updateSimulation();
                });
                break;

            case 'CountdownElapsed':
                $this->lock(function () {
                    $this->updateCountdown();
                });
                break;

            case 'Mode':
                if (!$this->ReadPropertyBoolean('AllowVisuControl')) {
                    throw new Exception('Modus darf nicht über die Visualisierung geschaltet werden.');
                }
                switch ((int) $Value) {
                    case self::MODE_OFF:
                        $this->Disarm();
                        break;
                    case self::MODE_AWAY:
                        $this->ArmAway();
                        break;
                    case self::MODE_NIGHT:
                        $this->lock(function () {
                            $this->requestArmNight();
                        });
                        break;
                }
                break;

            default:
                throw new Exception("Unbekannter Ident $Ident");
        }
    }

    // ---------- Öffentliche Funktionen (ALARM_*) ----------

    public function ArmNight(): void
    {
        $this->lock(function () {
            $this->doArm(self::MODE_NIGHT);
        });
    }

    public function ArmAway(): void
    {
        $this->lock(function () {
            $this->doArm(self::MODE_AWAY);
        });
    }

    public function Disarm(): void
    {
        $this->lock(function () {
            if ($this->mode() !== self::MODE_OFF || $this->state() !== self::STATE_IDLE) {
                $this->doDisarm('Manuell unscharf geschaltet.');
            } elseif ($this->isArmPending()) {
                $this->cancelPendingArm('Manuell abgebrochen.');
            }
        });
    }

    /** Löst einen Panikalarm aus, z. B. aus einem Skript. */
    public function Panic(): void
    {
        $this->lock(function () {
            $this->onPanic('Skript');
        });
    }

    public function ResetSilentAlarm(): void
    {
        $this->lock(function () {
            $this->SetValue('SilentAlarm', false);
            foreach ($this->listProperty('SilentAlarmOutputs') as $row) {
                $this->act((int) $row['VariableID'], $row['ResetValue'] ?? 0);
            }
            $this->log('Stiller Alarm zurückgesetzt.');
        });
    }

    public function ResetTestLog(): void
    {
        $this->lock(function () {
            $this->WriteAttributeString('TestLog', '{}');
            $file = $this->testCsvPath();
            if (file_exists($file)) {
                unlink($file);
            }
            $this->updateTestReport();
        });
    }

    public function GetTestReport(): string
    {
        $log = $this->testLog();
        if (!$log['sensors']) {
            return 'Noch keine Ereignisse protokolliert.';
        }
        $lines = [];
        foreach ($log['sensors'] as $sensor) {
            $lines[] = sprintf(
                '%s %s: %d Ereignisse, %d Auslösungen, %d Flattern, zuletzt %s',
                $sensor['type'], $sensor['name'], $sensor['events'], $sensor['triggers'], $sensor['flaps'], date('d.m.Y H:i:s', $sensor['last'])
            );
        }
        return implode("\n", $lines) . "\nCSV: " . $this->testCsvPath();
    }

    public function GetStatusText(): string
    {
        $lines = [
            'Modus: ' . GetValueFormatted($this->GetIDForIdent('Mode')),
            'Zustand: ' . GetValueFormatted($this->GetIDForIdent('State')),
            'Überwachte Kontakte: ' . ($this->names($this->readIDs('ActiveContacts')) ?: '-'),
            'Ausgeklammert: ' . ($this->names($this->readIDs('PendingContacts')) ?: '-'),
            'Auslöser: ' . ($this->GetValue('Trigger') ?: '-'),
            'Nächster Schritt: ' . ($this->ReadAttributeString('NextStep') ?: '-'),
            'Jemand zuhause: ' . ($this->anyoneHome() ? 'ja' : 'nein'),
        ];
        return implode("\n", $lines);
    }

    // ---------- Ereignisse ----------

    private function onVariable(int $id, $value, bool $changed): void
    {
        if (!$this->ReadPropertyBoolean('TestMode')) {
            $this->dispatchVariable($id, $value, $changed);
            return;
        }
        $before = $this->testBefore();
        $this->testNote = '';
        $this->dispatchVariable($id, $value, $changed);
        $this->recordTestEvent($id, $value, $changed, $before);
    }

    private function dispatchVariable(int $id, $value, bool $changed): void
    {
        if ($id === $this->lightDayID() && $this->lightConfigured()) {
            if ($changed) {
                $this->onLightDayChange();
            }
            return;
        }
        if ($id === $this->externalHomeKitID()) {
            if (!$changed) {
                return;
            }
            if ($this->isOwnHomeKitWrite((int) $value)) {
                $this->SendDebug('HomeKit', "Echo des eigenen Schreibvorgangs ($value) ignoriert.", 0);
                return;
            }
            $this->onHomeKit((int) $value);
            return;
        }
        if ($id === $this->ReadPropertyInteger('ArmRequestVariableID')) {
            // Jede Aktualisierung zählt, damit eine erneute 1 nach Unscharf über Anwesenheit ankommt
            $this->onArmRequest((bool) $value);
            return;
        }
        if (in_array($id, $this->ekeyIDs(), true)) {
            // Gleicher Finger zweimal hintereinander liefert dieselben Werte, daher jede Aktualisierung
            $this->onEkeyUpdate();
            return;
        }
        if (!$changed) {
            return;
        }

        $contacts = $this->contacts();
        $motion = $this->motionSensors();
        $presence = $this->presence();
        $panic = $this->namedList('PanicInputs');

        if (isset($contacts[$id])) {
            $this->onContact($id, (bool) $value);
        } elseif (isset($motion[$id])) {
            $this->onMotion($id, (bool) $value);
        } elseif (isset($presence[$id])) {
            $this->onPresence($presence[$id], (bool) $value);
        } elseif (isset($panic[$id]) && $value) {
            $this->onPanic($panic[$id]);
        }
    }

    private function onTimer(): void
    {
        $next = $this->ReadAttributeString('NextStep');
        $this->clearTimer();

        switch ($next) {
            case 'armAway':
                if (!$this->anyoneHome() && $this->state() === self::STATE_IDLE) {
                    $this->doArm(self::MODE_AWAY);
                }
                break;
            case 'armNight':
                if ($this->mode() === self::MODE_OFF) {
                    $this->doArm(self::MODE_NIGHT);
                }
                break;
            case 'armAwayManual':
                if ($this->mode() !== self::MODE_AWAY && $this->state() === self::STATE_IDLE) {
                    $this->doArm(self::MODE_AWAY);
                }
                break;
            case 'alarm':
                if ($this->state() === self::STATE_PREALARM) {
                    $this->startAlarm(false);
                }
                break;
            case 'siren':
                $this->sirenOn();
                break;
            case 'sirenOff':
                if ($this->state() === self::STATE_SIREN) {
                    $this->sirenOff();
                }
                break;
        }
    }

    private function onArmRequest(bool $value): void
    {
        $mode = $this->mode();

        if ($value) {
            if ($mode === self::MODE_OFF) {
                $this->WriteAttributeBoolean('HomeKitHome', false);
                $this->requestArmNight();
            } else {
                $this->updateArmedOutputs();
            }
            return;
        }

        if ($mode === self::MODE_OFF && $this->state() !== self::STATE_IDLE) {
            $this->doDisarm('Alarm über Scharf Request beendet.');
            return;
        }
        if ($mode === self::MODE_OFF && $this->isArmPending()) {
            $this->cancelPendingArm('Über Scharf Request abgebrochen.');
            return;
        }

        if ($mode === self::MODE_NIGHT || ($mode === self::MODE_AWAY && $this->ReadPropertyBoolean('RequestCanDisarmAway'))) {
            $this->doDisarm('Über Scharf Request unscharf geschaltet.');
        } elseif ($mode === self::MODE_AWAY) {
            $this->log('Scharf Request 0 ignoriert: "Abwesend" wird nur über Anwesenheit unscharf.');
            $this->updateArmedOutputs();
        }
    }

    /** Schärft "Nacht" nach der Ausgangsverzögerung, bei 0 sofort. */
    private function requestArmNight(): void
    {
        if ($this->mode() !== self::MODE_OFF || $this->ReadAttributeString('NextStep') === 'armNight') {
            return;
        }
        if ($this->state() !== self::STATE_IDLE) {
            $this->log('Schärfen abgelehnt: Alarm aktiv, zuerst unscharf schalten.');
            return;
        }
        $delay = $this->ReadPropertyInteger('NightArmDelay');
        if ($delay <= 0) {
            $this->doArm(self::MODE_NIGHT);
            return;
        }
        $this->log("Scharf (Nacht) in $delay s.");
        $this->setTimer('armNight', $delay);
    }

    /** Schärft "Abwesend" auf Anforderung (HomeKit) nach der Ausgangsverzögerung, unabhängig von der Anwesenheit. */
    private function requestArmAwayManual(): void
    {
        if ($this->mode() === self::MODE_AWAY || $this->state() !== self::STATE_IDLE || $this->ReadAttributeString('NextStep') === 'armAwayManual') {
            return;
        }
        $delay = $this->ReadPropertyInteger('NightArmDelay');
        if ($delay <= 0) {
            $this->doArm(self::MODE_AWAY);
            return;
        }
        $this->log("Scharf (Abwesend) in $delay s.");
        $this->setTimer('armAwayManual', $delay);
    }

    private function isArmPending(): bool
    {
        return in_array($this->ReadAttributeString('NextStep'), ['armNight', 'armAwayManual'], true);
    }

    private function cancelPendingArm(string $reason): void
    {
        $this->clearTimer();
        $this->WriteAttributeBoolean('HomeKitHome', false);
        $this->updateArmedOutputs();
        $this->log("Scharfschaltung abgebrochen. $reason");
    }

    // ---------- HomeKit ----------

    private function onHomeKit(int $value): void
    {
        $mode = $this->mode();
        $canLeaveAway = $this->ReadPropertyBoolean('HomeKitCanDisarmAway');
        $label = ['Zuhause', 'Abwesend', 'Nacht', 'Aus'][$value] ?? (string) $value;
        $this->log("HomeKit: \"$label\" angefordert.");

        switch ($value) {
            case self::HK_OFF:
                if ($mode === self::MODE_NIGHT || ($mode === self::MODE_AWAY && $canLeaveAway)) {
                    $this->doDisarm('Über HomeKit unscharf geschaltet.');
                } elseif ($mode === self::MODE_AWAY) {
                    $this->rejectHomeKit($label, '"Abwesend" darf über HomeKit nicht unscharf geschaltet werden (Formular: HomeKit "Aus" darf auch "Abwesend" unscharf schalten).');
                } elseif ($this->state() !== self::STATE_IDLE) {
                    $this->doDisarm('Alarm über HomeKit beendet.');
                } elseif ($this->isArmPending()) {
                    $this->cancelPendingArm('Über HomeKit abgebrochen.');
                }
                break;

            case self::HK_HOME:
            case self::HK_NIGHT:
                if ($value === self::HK_HOME && $this->ReadPropertyInteger('HomeKitHomeAs') !== self::HK_NIGHT) {
                    $this->rejectHomeKit($label, '"Zuhause" ist im Formular auf "Ignorieren" gestellt.');
                    break;
                }
                if ($mode === self::MODE_AWAY) {
                    if (!$canLeaveAway) {
                        $this->rejectHomeKit($label, 'Wechsel von "Abwesend" nur erlaubt, wenn HomeKit "Abwesend" unscharf schalten darf.');
                        break;
                    }
                    if ($this->state() !== self::STATE_IDLE) {
                        $this->rejectHomeKit($label, 'Alarm aktiv, zuerst "Aus" wählen.');
                        break;
                    }
                }
                $this->WriteAttributeBoolean('HomeKitHome', $value === self::HK_HOME);
                if ($mode === self::MODE_AWAY) {
                    $this->doArm(self::MODE_NIGHT);
                } else {
                    $this->requestArmNight();
                }
                break;

            case self::HK_AWAY:
                if ($mode !== self::MODE_AWAY && $this->state() !== self::STATE_IDLE) {
                    $this->rejectHomeKit($label, 'Alarm aktiv, zuerst "Aus" wählen.');
                    break;
                }
                $this->requestArmAwayManual();
                break;

            default:
                $this->rejectHomeKit($label, 'Unbekannter Wert.');
        }
    }

    /** HomeKit zeigt danach wieder den tatsächlichen Zustand an, der Grund steht im Log. */
    private function rejectHomeKit(string $label, string $reason): void
    {
        $this->log("HomeKit: \"$label\" abgelehnt. $reason");
    }

    private function homeKitValue(): int
    {
        $nightValue = $this->ReadAttributeBoolean('HomeKitHome') ? self::HK_HOME : self::HK_NIGHT;
        switch ($this->mode()) {
            case self::MODE_AWAY:
                return self::HK_AWAY;
            case self::MODE_NIGHT:
                return $nightValue;
        }
        switch ($this->ReadAttributeString('NextStep')) {
            case 'armNight':
                return $nightValue;
            case 'armAwayManual':
                return self::HK_AWAY;
        }
        return self::HK_OFF;
    }

    /** Hält HomeKit-Variable und "Alarm ausgelöst" auf dem aktuellen Stand. */
    private function syncStatusVariables(): void
    {
        $alarm = $this->state() >= self::STATE_ALARM;
        if ($this->GetValue('AlarmActive') !== $alarm) {
            $this->SetValue('AlarmActive', $alarm);
        }

        $value = $this->homeKitValue();
        switch ($this->ReadPropertyInteger('HomeKitSource')) {
            case self::HOMEKIT_OWN:
                if ($this->GetValue('HomeKit') !== $value) {
                    $this->SetValue('HomeKit', $value);
                }
                break;

            case self::HOMEKIT_EXTERNAL:
                $id = $this->externalHomeKitID();
                if ($id <= 0 || !IPS_VariableExists($id) || GetValue($id) === $value) {
                    break;
                }
                $writes = array_filter(json_decode($this->ReadAttributeString('HomeKitWrites'), true) ?: [], fn ($w) => $w[1] > time() - self::HOMEKIT_ECHO_SECONDS);
                $writes[] = [$value, time()];
                $this->WriteAttributeString('HomeKitWrites', json_encode(array_values($writes)));
                if (IPS_GetVariable($id)['VariableAction'] > 0) {
                    $this->act($id, $value);
                } else {
                    SetValue($id, $value);
                }
                break;
        }
    }

    /** Eigene Schreibvorgänge kommen als Aktualisierung zurück und dürfen nicht als HomeKit-Befehl gelten. */
    private function isOwnHomeKitWrite(int $value): bool
    {
        foreach (json_decode($this->ReadAttributeString('HomeKitWrites'), true) ?: [] as [$written, $time]) {
            if ($written === $value && $time > time() - self::HOMEKIT_ECHO_SECONDS) {
                return true;
            }
        }
        return false;
    }

    private function externalHomeKitID(): int
    {
        return $this->ReadPropertyInteger('HomeKitSource') === self::HOMEKIT_EXTERNAL ? $this->ReadPropertyInteger('HomeKitVariableID') : 0;
    }

    // ---------- Testprotokoll ----------

    private function testLog(): array
    {
        $log = json_decode($this->ReadAttributeString('TestLog'), true);
        return (is_array($log) ? $log : []) + ['sensors' => [], 'events' => []];
    }

    private function testBefore(): array
    {
        return [$this->mode(), $this->state(), count($this->readIDs('PendingContacts')), $this->ReadAttributeString('NextStep')];
    }

    private function testCsvPath(): string
    {
        return IPS_GetLogDir() . 'Alarmanlage_' . $this->InstanceID . '_Test.csv';
    }

    private function recordTestEvent(int $id, $value, bool $changed, array $before): void
    {
        $this->writeTestEvent((string) $id, $this->describeTestEvent($id, $value, $changed), $before);
    }

    /** @param array{string, string, string, bool} $event Typ, Name, Wert als Text, wäre ein Auslöser */
    private function writeTestEvent(string $key, array $event, array $before): void
    {
        [$type, $name, $text, $wouldTrigger] = $event;
        if ($type === '') {
            return;
        }

        $reaction = [];
        if ($this->mode() !== $before[0]) {
            $reaction[] = 'Modus ' . self::MODE_LABELS[$before[0]] . ' → ' . self::MODE_LABELS[$this->mode()];
        }
        if ($this->state() !== $before[1]) {
            $reaction[] = 'Zustand → ' . self::STATE_LABELS[$this->state()];
        }
        if (count($this->readIDs('PendingContacts')) < $before[2] && $this->mode() === $before[0]) {
            $reaction[] = 'Kontakt jetzt überwacht';
        }
        $next = $this->ReadAttributeString('NextStep');
        if ($next !== $before[3] && in_array($next, ['armAway', 'armNight', 'armAwayManual'], true)) {
            $reaction[] = 'Scharfschaltung geplant';
        }
        $reaction = implode(', ', $reaction) ?: '-';

        $now = time();
        $log = $this->testLog();
        $sensor = $log['sensors'][$key] ?? ['type' => $type, 'name' => $name, 'events' => 0, 'triggers' => 0, 'flaps' => 0, 'last' => 0];
        $flap = in_array($type, self::FLAP_TYPES, true)
            && $sensor['last'] > 0 && $now - $sensor['last'] < $this->ReadPropertyInteger('TestFlapSeconds');
        $sensor['name'] = $name;
        $sensor['events']++;
        $sensor['triggers'] += $wouldTrigger ? 1 : 0;
        $sensor['flaps'] += $flap ? 1 : 0;
        $sensor['last'] = $now;
        $log['sensors'][$key] = $sensor;

        $entry = [$now, $type, $name, $text, self::MODE_LABELS[$this->mode()], $reaction, $flap];
        array_unshift($log['events'], $entry);
        $log['events'] = array_slice($log['events'], 0, self::TEST_LOG_MAX);
        $this->WriteAttributeString('TestLog', json_encode($log));

        $this->SendDebug('Test', "$type $name: $text | $reaction" . ($flap ? ' | FLATTERN' : ''), 0);
        if ($this->ReadPropertyBoolean('TestLogCsv')) {
            $file = $this->testCsvPath();
            $line = implode(';', [date('Y-m-d H:i:s', $now), $type, $name, $text, $entry[4], $reaction, $flap ? 'ja' : 'nein']) . "\n";
            if (!file_exists($file)) {
                $line = "Zeit;Typ;Sensor;Wert;Modus;Reaktion;Flattern\n" . $line;
            }
            @file_put_contents($file, $line, FILE_APPEND);
        }
        $this->updateTestReport();
    }

    /** @return array{string, string, string, bool} Typ, Name, Wert als Text, wäre ein Auslöser */
    private function describeTestEvent(int $id, $value, bool $changed): array
    {
        $contacts = $this->contacts();
        $motion = $this->motionSensors();
        $presence = $this->presence();

        if (isset($contacts[$id]) && $changed) {
            $open = (bool) $value === $this->ReadPropertyBoolean('ContactOpenValue');
            return ['Kontakt', $contacts[$id], $open ? 'offen' : 'geschlossen', $open];
        }
        if (isset($motion[$id]) && $changed) {
            return ['Bewegung', $motion[$id], $value ? 'Bewegung' : 'Ende', (bool) $value];
        }
        if (isset($presence[$id]) && $changed) {
            return ['Anwesenheit', $presence[$id], $value ? 'zuhause' : 'weg', false];
        }
        if ($id === $this->ReadPropertyInteger('ArmRequestVariableID')) {
            return ['KNX', 'Scharf Request', $value ? '1' : '0', false];
        }
        $panic = $this->namedList('PanicInputs');
        if (isset($panic[$id]) && $changed) {
            return ['Panik', $panic[$id], $value ? 'ausgelöst' : 'Ende', (bool) $value];
        }
        if ($id === $this->externalHomeKitID() && $changed) {
            return ['HomeKit', 'Sicherheitssystem', ['Zuhause', 'Abwesend', 'Nacht', 'Aus'][(int) $value] ?? (string) $value, false];
        }
        if ($this->testNote !== '') {
            return ['ekey', 'Fingerscanner', $this->testNote, false];
        }
        return ['', '', '', false];
    }

    private function updateTestReport(): void
    {
        if (!$this->ReadPropertyBoolean('TestMode') || @$this->GetIDForIdent('TestReport') === false) {
            return;
        }
        $log = $this->testLog();
        $esc = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES);
        $style = 'style="border-collapse:collapse;width:100%" border="1" cellpadding="4"';

        $html = '<b>Sensoren</b><table ' . $style . '><tr><th>Typ</th><th>Sensor</th><th>Ereignisse</th><th>Auslösungen</th><th>Flattern (&lt; '
            . $this->ReadPropertyInteger('TestFlapSeconds') . ' s)</th><th>Zuletzt</th></tr>';
        $sensors = $log['sensors'];
        uasort($sensors, fn ($a, $b) => $b['flaps'] <=> $a['flaps'] ?: $b['triggers'] <=> $a['triggers']);
        foreach ($sensors as $s) {
            $warn = $s['flaps'] > 0 ? ' style="color:#d00;font-weight:bold"' : '';
            $html .= "<tr><td>{$esc($s['type'])}</td><td>{$esc($s['name'])}</td><td>{$s['events']}</td><td>{$s['triggers']}</td><td$warn>{$s['flaps']}</td><td>"
                . date('d.m. H:i:s', $s['last']) . '</td></tr>';
        }
        $html .= '</table><br><b>Letzte Ereignisse</b><table ' . $style . '><tr><th>Zeit</th><th>Typ</th><th>Sensor</th><th>Wert</th><th>Modus</th><th>Reaktion</th></tr>';
        foreach (array_slice($log['events'], 0, 50) as [$time, $type, $name, $text, $mode, $reaction, $flap]) {
            $warn = $flap ? ' style="color:#d00"' : '';
            $html .= "<tr$warn><td>" . date('d.m. H:i:s', $time) . "</td><td>{$esc($type)}</td><td>{$esc($name)}</td><td>{$esc($text)}</td><td>{$esc($mode)}</td><td>{$esc($reaction)}</td></tr>";
        }
        $html .= '</table>';
        $this->SetValue('TestReport', $html);
    }

    private function onPresence(string $name, bool $value): void
    {
        if ($this->anyoneHome()) {
            if ($this->ReadAttributeString('NextStep') === 'armAway') {
                $this->clearTimer();
                $this->log('Scharfschaltung abgebrochen, jemand ist zuhause.');
            }
            if ($value && $this->mode() === self::MODE_AWAY) {
                $this->doDisarm("$name ist nach Hause gekommen.");
            }
            return;
        }

        if ($this->mode() === self::MODE_AWAY || $this->state() !== self::STATE_IDLE) {
            return;
        }
        $delay = $this->ReadPropertyInteger('AwayArmDelay');
        $this->log("Niemand zuhause. Scharf (Abwesend) in $delay s.");
        $this->setTimer('armAway', $delay);
    }

    private function onEkeyUpdate(): void
    {
        if ($this->ReadPropertyInteger('EkeyUserVariableID') <= 0 || $this->ReadPropertyInteger('EkeyFingerVariableID') <= 0) {
            return;
        }
        $ids = $this->ekeyIDs();

        // Ein Scan aktualisiert alle ekey-Variablen nacheinander; erst auswerten, wenn alle frisch sind
        $stamps = array_map(fn ($id) => IPS_GetVariable($id)['VariableUpdated'], $ids);
        if (max($stamps) - min($stamps) > 2) {
            return;
        }
        $values = array_map(fn ($id) => (string) GetValue($id), $ids);
        $scanKey = implode(':', array_merge($stamps, $values));
        if ($scanKey === $this->ReadAttributeString('LastEkeyScan')) {
            return;
        }
        $this->WriteAttributeString('LastEkeyScan', $scanKey);

        $user = (int) GetValue($this->ReadPropertyInteger('EkeyUserVariableID'));
        $finger = (int) GetValue($this->ReadPropertyInteger('EkeyFingerVariableID'));
        $actionID = $this->ReadPropertyInteger('EkeyActionVariableID');
        $accepted = $actionID <= 0 || (int) GetValue($actionID) === $this->ReadPropertyInteger('EkeyActionValue');
        $match = $accepted ? $this->matchEkeyUser($user, $finger) : null;
        $name = $match['name'] ?? null;

        $this->testNote = "Benutzer $user, Finger $finger: " . ($name === null ? 'abgelehnt' : "erkannt ($name)");
        if ($name === null) {
            $text = "Nicht berechtigter Fingerabdruck (Benutzer $user, Finger $finger).";
            if ($this->mode() !== self::MODE_OFF && $this->ReadPropertyBoolean('EkeyNotifyRejected')) {
                $this->notify('ekey', $text);
                $this->showInfo('EkeyRejected', 'Finger abgel.');
            } else {
                $this->log("ekey: $text");
            }
            return;
        }

        // Überfall-Finger: nach außen ganz normal, im Hintergrund stiller Alarm
        if ($match['duress']) {
            $this->silentAlarm("Überfall-Finger von $name am Fingerscanner benutzt.");
        }

        if ($this->mode() === self::MODE_AWAY || ($this->mode() === self::MODE_OFF && $this->state() !== self::STATE_IDLE)) {
            $this->doDisarm("Fingerabdruck $name erkannt.");
        } else {
            $this->log("ekey: $name erkannt, \"Abwesend\" ist nicht aktiv.");
        }
    }

    private function onContact(int $id, bool $value): void
    {
        if ($this->mode() === self::MODE_OFF) {
            return;
        }

        $name = $this->contacts()[$id];
        $isOpen = ($value === $this->ReadPropertyBoolean('ContactOpenValue'));
        $active = $this->readIDs('ActiveContacts');
        $pending = $this->readIDs('PendingContacts');

        if (!$isOpen) {
            if (in_array($id, $pending, true)) {
                $this->writeIDs('PendingContacts', array_diff($pending, [$id]));
                $active[] = $id;
                $this->writeIDs('ActiveContacts', $active);
                $this->updateExcluded();
                $this->notify('Kontakt überwacht', "$name wurde geschlossen und wird jetzt überwacht.");
                $this->refreshDisplay();
                $this->showInfo('Excluded', "OK: $name");
            }
            return;
        }

        if (in_array($id, $active, true)) {
            $this->onTrigger("Kontakt $name geöffnet", $name, $this->isEntryRoute('Contacts', $id));
        }
    }

    private function onMotion(int $id, bool $value): void
    {
        if (!$value) {
            return;
        }

        $name = $this->motionSensors()[$id];
        $text = "Bewegung $name";
        $state = $this->state();
        $entry = $this->isEntryRoute('MotionSensors', $id);

        switch ($this->mode()) {
            case self::MODE_AWAY:
                // Voralarm gibt es nur für Kontakte; Bewegung im leeren Haus ist sofort Alarm
                if ($state === self::STATE_IDLE) {
                    $this->SetValue('Trigger', $text);
                    $this->WriteAttributeString('TriggerSource', $name);
                    $this->startAlarm(false);
                } else {
                    $this->onTrigger($text, $name, $entry);
                }
                break;

            case self::MODE_NIGHT:
                // Nachts zählt Bewegung nur nach einer Kontaktöffnung, dann aber sofort voller Alarm
                if ($state === self::STATE_PREALARM && $entry && $this->ReadAttributeBoolean('PreAlarmEntry')) {
                    $this->log("$text auf dem Eingangsweg, Voralarm läuft weiter.");
                } elseif ($state === self::STATE_PREALARM || $state === self::STATE_ALARM) {
                    $this->appendTrigger($text);
                    $this->startAlarm(true);
                } elseif ($state === self::STATE_LATCHED) {
                    $this->notify('Alarm', "Weiterer Auslöser: $text", 'alarm');
                } elseif ($state === self::STATE_IDLE) {
                    $this->log("$text im Nachtmodus ohne Kontaktöffnung, ignoriert.");
                }
                break;
        }
    }

    private function onTrigger(string $text, string $source, bool $entry = false): void
    {
        switch ($this->state()) {
            case self::STATE_IDLE:
                $this->startPreAlarm($text, $source, $entry);
                break;
            case self::STATE_PREALARM:
                // Wer über den Eingangsweg hereinkommt, bekommt die volle Eingangsverzögerung
                if ($entry && $this->ReadAttributeBoolean('PreAlarmEntry')) {
                    $this->log("$text auf dem Eingangsweg, Voralarm läuft weiter.");
                    break;
                }
                // Ein zweiter, anderer Auslöser bestätigt den Einbruch: Voralarm abbrechen, voller Alarm
                if (strpos($this->GetValue('Trigger'), $text) === false) {
                    $this->appendTrigger($text);
                    $this->startAlarm(true);
                }
                break;
            case self::STATE_LATCHED:
                $this->notify('Alarm', "Weiterer Auslöser: $text", 'alarm');
                break;
            default:
                $this->appendTrigger($text);
                break;
        }
    }

    // ---------- Scharf / Unscharf ----------

    private function doArm(int $mode): void
    {
        if ($this->mode() === $mode) {
            return;
        }
        if ($this->state() !== self::STATE_IDLE) {
            $this->log('Schärfen abgelehnt: Alarm aktiv, zuerst unscharf schalten.');
            return;
        }

        $active = [];
        $pending = [];
        foreach (array_keys($this->contacts()) as $id) {
            if ($this->isContactOpen($id)) {
                $pending[] = $id;
            } else {
                $active[] = $id;
            }
        }

        $this->clearTimer();
        $this->writeIDs('ActiveContacts', $active);
        $this->writeIDs('PendingContacts', $pending);
        $this->SetValue('Mode', $mode);
        $this->SetValue('State', self::STATE_IDLE);
        $this->SetValue('Trigger', '');
        $this->updateExcluded();
        $this->updateArmedOutputs();

        $this->updateSimulation();

        $label = $mode === self::MODE_AWAY ? 'Abwesend' : 'Nacht';
        $text = "Scharf ($label).";
        $this->refreshDisplay();
        $this->showInfo('Armed', $mode === self::MODE_AWAY ? 'Scharf Abwes.' : 'Scharf Nacht');
        if ($pending) {
            $text .= ' Offen, wird nach dem Schließen überwacht: ' . $this->names($pending) . '.';
            $this->notify('Alarmanlage scharf', $text);
        } elseif ($this->ReadPropertyBoolean('NotifyArmDisarm')) {
            $this->notify('Alarmanlage scharf', $text);
        } else {
            $this->log($text);
        }
    }

    private function doDisarm(string $reason): void
    {
        $state = $this->state();

        $this->clearTimer();
        if ($state >= self::STATE_ALARM) {
            $this->switchSirens(false);
        }

        $this->writeIDs('ActiveContacts', []);
        $this->writeIDs('PendingContacts', []);
        $this->WriteAttributeBoolean('HomeKitHome', false);
        $this->SetValue('Mode', self::MODE_OFF);
        $this->SetValue('State', self::STATE_IDLE);
        $this->SetValue('Trigger', '');
        $this->updateExcluded();
        $this->updateArmedOutputs();
        $this->updateSimulation();
        $this->refreshDisplay();
        $this->showInfo('Disarmed', 'Unscharf');

        if ($state !== self::STATE_IDLE) {
            $this->notify('Alarmanlage unscharf', "$reason Alarm beendet.");
        } elseif ($this->ReadPropertyBoolean('NotifyArmDisarm')) {
            $this->notify('Alarmanlage unscharf', $reason);
        } else {
            $this->log($reason);
        }
    }

    /** Hält die Kontaktlisten aktuell, wenn die Konfiguration im scharfen Zustand geändert wird. */
    private function reconcileContacts(): void
    {
        if ($this->mode() === self::MODE_OFF) {
            return;
        }
        $configured = array_keys($this->contacts());
        $active = array_intersect($this->readIDs('ActiveContacts'), $configured);
        $pending = array_intersect($this->readIDs('PendingContacts'), $configured);

        foreach (array_diff($configured, $active, $pending) as $id) {
            if ($this->isContactOpen($id)) {
                $pending[] = $id;
            } else {
                $active[] = $id;
            }
        }
        $this->writeIDs('ActiveContacts', $active);
        $this->writeIDs('PendingContacts', $pending);
        $this->updateExcluded();
    }

    // ---------- Alarmstufen ----------

    private function startPreAlarm(string $text, string $source, bool $entry): void
    {
        $delay = $this->ReadPropertyInteger('EntryDelay');
        $this->SetValue('State', self::STATE_PREALARM);
        $this->SetValue('Trigger', $text);
        $this->WriteAttributeString('TriggerSource', $source);
        $this->WriteAttributeBoolean('PreAlarmEntry', $entry);
        $this->refreshDisplay();
        $this->switchLights('PreAlarmLights');
        $this->notify('Voralarm', "$text. Alarm in $delay s, wenn nicht unscharf.", 'buzzer');

        if ($delay <= 0) {
            $this->startAlarm(false);
        } else {
            $this->setTimer('alarm', $delay);
        }
    }

    private function startAlarm(bool $full): void
    {
        if ($this->state() < self::STATE_ALARM) {
            $this->SetValue('State', self::STATE_ALARM);
            $this->switchLights('AlarmLights');
            foreach ($this->listProperty('Shutters') as $row) {
                $this->act((int) $row['VariableID'], $row['OpenValue'] ?? 0);
            }
            $this->notify('ALARM', $this->GetValue('Trigger'), 'alarm');
            $this->refreshDisplay();
        }

        if ($full || $this->ReadPropertyInteger('SirenDelay') <= 0) {
            $this->sirenOn();
        } elseif ($this->state() === self::STATE_ALARM) {
            $this->setTimer('siren', $this->ReadPropertyInteger('SirenDelay'));
        }
    }

    private function sirenOn(): void
    {
        if ($this->state() !== self::STATE_ALARM) {
            return;
        }
        $this->SetValue('State', self::STATE_SIREN);
        $this->switchSirens(true);
        $this->setTimer('sirenOff', $this->ReadPropertyInteger('SirenDuration'));
    }

    private function sirenOff(): void
    {
        $this->switchSirens(false);
        $this->SetValue('State', self::STATE_LATCHED);
        $this->notify('Alarm', 'Sirene abgeschaltet. Anlage bleibt im Alarmzustand, bis unscharf geschaltet wird.');
        $this->showInfo('SirenOff', 'Sirene aus');
    }

    // ---------- Perimeter (WebHooks) ----------

    private function hookPath(): string
    {
        $name = trim($this->ReadPropertyString('WebHookName'), " /");
        return '/hook/' . ($name !== '' ? $name : 'alarm');
    }

    /** Trägt den WebHook in der WebHook Control ein (bzw. entfernt ihn, wenn keine Auslöser konfiguriert sind). */
    private function ensureWebHook(): void
    {
        $ids = IPS_GetInstanceListByModuleID(self::WEBHOOK_CONTROL);
        if (!$ids) {
            if ($this->listProperty('PerimeterHooks')) {
                $this->LogMessage('Keine WebHook Control gefunden, Perimeter-WebHooks sind nicht erreichbar.', KL_WARNING);
            }
            return;
        }
        $wanted = $this->listProperty('PerimeterHooks') ? $this->hookPath() : '';
        $hooks = json_decode(IPS_GetProperty($ids[0], 'Hooks'), true) ?: [];
        $new = [];
        $found = false;
        foreach ($hooks as $hook) {
            if ($hook['Hook'] === $wanted) {
                if ($hook['TargetID'] !== $this->InstanceID) {
                    $this->LogMessage("WebHook $wanted gehörte Objekt {$hook['TargetID']} und wird übernommen.", KL_WARNING);
                }
                $hook['TargetID'] = $this->InstanceID;
                $found = true;
            } elseif ($hook['TargetID'] === $this->InstanceID) {
                continue;
            }
            $new[] = $hook;
        }
        if ($wanted !== '' && !$found) {
            $new[] = ['Hook' => $wanted, 'TargetID' => $this->InstanceID];
        }
        if ($new !== $hooks) {
            IPS_SetProperty($ids[0], 'Hooks', json_encode($new));
            IPS_ApplyChanges($ids[0]);
        }
    }

    /** Aufruf: /hook/alarm/<Schlüssel> oder /hook/alarm?trigger=<Schlüssel>, optional &token=... und &state=0 (Ende, wird ignoriert). */
    protected function ProcessHookData()
    {
        $hook = (string) ($_SERVER['HOOK'] ?? $this->hookPath());
        $path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
        $key = strpos($path, $hook) === 0 ? trim(substr($path, strlen($hook)), '/') : '';
        if ($key === '') {
            $key = (string) ($_GET['trigger'] ?? '');
        }

        $token = $this->ReadPropertyString('WebHookToken');
        if ($token !== '' && !hash_equals($token, (string) ($_GET['token'] ?? ''))) {
            @http_response_code(403);
            $this->log("WebHook ohne gültigen Token abgelehnt ($key).");
            echo 'Forbidden';
            return;
        }

        $row = null;
        foreach ($this->listProperty('PerimeterHooks') as $candidate) {
            if (strcasecmp(trim((string) ($candidate['Key'] ?? '')), $key) === 0) {
                $row = $candidate;
                break;
            }
        }
        if ($row === null) {
            @http_response_code(404);
            $this->SendDebug('WebHook', "Unbekannter Auslöser \"$key\"", 0);
            echo 'Unknown trigger';
            return;
        }

        $state = strtolower((string) ($_GET['state'] ?? $_GET['motion'] ?? '1'));
        if (!in_array($state, ['0', 'false', 'off', 'end', 'stop'], true)) {
            $this->lock(function () use ($row) {
                $this->onPerimeterHook($row);
            });
        }
        echo 'OK';
    }

    private function onPerimeterHook(array $row): void
    {
        $test = $this->ReadPropertyBoolean('TestMode');
        $before = $test ? $this->testBefore() : [];
        $this->testNote = '';
        $light = ($row['Light'] ?? false) ? $this->onLightMotion() : '';
        $this->onPerimeter($row);
        if ($light !== '') {
            $this->testNote .= ($this->testNote !== '' ? ', ' : '') . "Licht: $light";
        }
        if ($test) {
            $key = trim((string) $row['Key']);
            $this->writeTestEvent('hook:' . $key, ['Perimeter', $this->perimeterName($row), $this->testNote, false], $before);
        }
    }

    private function perimeterName(array $row): string
    {
        $name = trim((string) ($row['Name'] ?? ''));
        return $name !== '' ? $name : trim((string) $row['Key']);
    }

    private function onPerimeter(array $row): void
    {
        $name = $this->perimeterName($row);
        $mode = $this->mode();
        $active = [
            0 => true,
            1 => $mode !== self::MODE_OFF,
            2 => $mode === self::MODE_AWAY,
            3 => $mode === self::MODE_NIGHT,
            4 => false,
        ][(int) ($row['Modes'] ?? 0)] ?? true;
        if (!$active) {
            $this->testNote = 'Warnung ' . self::PERIMETER_MODES[(int) $row['Modes']];
            $this->SendDebug('Perimeter', "$name ignoriert: Modus " . self::MODE_LABELS[$mode], 0);
            return;
        }
        if (($row['DarkOnly'] ?? false) && $this->isDaylight()) {
            $this->testNote = 'ignoriert (Tag)';
            $this->SendDebug('Perimeter', "$name ignoriert: Tag", 0);
            return;
        }

        if ($row['Signal'] ?? true) {
            $until = time() + $this->ReadPropertyInteger('PerimeterSignalSeconds');
            $this->WriteAttributeInteger('SignalUntil', max($until, $this->ReadAttributeInteger('SignalUntil')));
        }

        $key = strtolower(trim((string) $row['Key']));
        $last = json_decode($this->ReadAttributeString('PerimeterLast'), true) ?: [];
        if (time() - ($last[$key] ?? 0) < $this->ReadPropertyInteger('PerimeterCooldown')) {
            $this->testNote = 'gemeldet (Sperrzeit, kein Push)';
            return;
        }
        $last[$key] = time();
        $this->WriteAttributeString('PerimeterLast', json_encode($last));
        $this->testNote = 'gemeldet';

        $this->showInfo('Perimeter', "Perimeter $name");
        if ($row['Push'] ?? true) {
            $this->notify('Perimeter', "Auslösung: $name", 'buzzer');
        } else {
            $this->log("Perimeter: $name");
        }
    }

    private function isDaylight(): bool
    {
        $id = $this->ReadPropertyInteger('PerimeterDayVariableID') ?: $this->ReadPropertyInteger('SimDayVariableID');
        return $id > 0 && IPS_VariableExists($id) && (bool) GetValue($id);
    }

    // ---------- Bewegungslicht (z. B. Garten) ----------

    private function lightConfigured(): bool
    {
        return $this->ReadPropertyInteger('LightSwitchVariableID') > 0;
    }

    private function lightDayID(): int
    {
        return $this->ReadPropertyInteger('LightDayVariableID')
            ?: $this->ReadPropertyInteger('PerimeterDayVariableID')
            ?: $this->ReadPropertyInteger('SimDayVariableID');
    }

    private function isLightDay(): bool
    {
        $id = $this->lightDayID();
        return $id > 0 && IPS_VariableExists($id) && (bool) GetValue($id);
    }

    /** Ruhezustand: nachts Grundhelligkeit, tagsüber aus. */
    private function lightRestPercent(): int
    {
        return $this->isLightDay() ? 0 : $this->ReadPropertyInteger('LightBasePercent');
    }

    /** @return string Ergebnis für das Testprotokoll */
    private function onLightMotion(): string
    {
        if (!$this->lightConfigured()) {
            return '';
        }
        $ambientID = $this->ReadPropertyInteger('LightAmbientVariableID');
        $threshold = $this->ReadPropertyInteger('LightAmbientThreshold');
        if ($this->isLightDay() && $ambientID > 0 && $threshold > 0 && IPS_VariableExists($ambientID) && GetValue($ambientID) >= $threshold) {
            $this->SendDebug('Bewegungslicht', 'Bewegung am Tag, aber hell genug.', 0);
            return 'zu hell';
        }
        $percent = $this->ReadPropertyInteger('LightMotionPercent');
        $seconds = $this->ReadPropertyInteger('LightMotionSeconds');
        $this->SendDebug('Bewegungslicht', "Bewegung: $percent % für $seconds s.", 0);
        $this->setLightPercent($percent);
        // Jede Bewegung verlängert die Zeit
        $this->SetTimerInterval('LightTimer', 0);
        $this->SetTimerInterval('LightTimer', $seconds * 1000);
        return "$percent %";
    }

    private function onLightDayChange(): void
    {
        if ($this->GetTimerInterval('LightTimer') > 0) {
            $this->SendDebug('Bewegungslicht', 'Tag/Nacht-Wechsel, Bewegungszeit läuft noch.', 0);
            return;
        }
        $this->setLightPercent($this->lightRestPercent());
    }

    private function setLightPercent(int $percent): void
    {
        $switchID = $this->ReadPropertyInteger('LightSwitchVariableID');
        // Blinkt die Lampe gerade als Signallampe, wird der Zustand erst nach dem Signal gesetzt
        if ($this->GetTimerInterval('SignalTimer') > 0 && in_array($switchID, array_column($this->signalLights(), 'on'), true)) {
            $this->WriteAttributeInteger('LightDeferred', $percent);
            return;
        }
        if (!IPS_VariableExists($switchID)) {
            return;
        }
        if ($percent <= 0) {
            if (GetValue($switchID)) {
                $this->act($switchID, false);
            }
            return;
        }
        if (!GetValue($switchID)) {
            $this->act($switchID, true);
        }
        $brightnessID = $this->ReadPropertyInteger('LightBrightnessVariableID');
        if ($brightnessID > 0 && IPS_VariableExists($brightnessID)) {
            $this->act($brightnessID, (int) round($this->maxValue($brightnessID) * $percent / 100));
        }
    }

    // ---------- Signallampen (z. B. Hue im Garten) ----------

    /** @return array{on: int, color: int, brightness: int, ct: int}|null Variablen einer Lampen-/Gruppeninstanz (Philips HUE V2) */
    private function hueVariables(int $instanceID): ?array
    {
        $on = @IPS_GetObjectIDByIdent('on', $instanceID);
        $color = @IPS_GetObjectIDByIdent('color', $instanceID);
        if (!$on || !$color) {
            return null;
        }
        return [
            'on'         => $on,
            'color'      => $color,
            'brightness' => (int) @IPS_GetObjectIDByIdent('brightness', $instanceID),
            'ct'         => (int) @IPS_GetObjectIDByIdent('color_temperature', $instanceID),
        ];
    }

    /** @return array<int, array{on: int, color: int, brightness: int, ct: int}> */
    private function signalLights(): array
    {
        $lights = [];
        foreach ($this->listProperty('SignalLights') as $row) {
            $instanceID = (int) ($row['InstanceID'] ?? 0);
            if ($instanceID > 0 && IPS_InstanceExists($instanceID) && ($vars = $this->hueVariables($instanceID))) {
                $lights[$instanceID] = $vars;
            }
        }
        return $lights;
    }

    private function signalWanted(): bool
    {
        $state = $this->state();
        return ($this->ReadPropertyBoolean('SignalOnAlarm') && ($state === self::STATE_ALARM || $state === self::STATE_SIREN))
            || ($this->ReadPropertyBoolean('SignalOnPreAlarm') && $state === self::STATE_PREALARM)
            || time() < $this->ReadAttributeInteger('SignalUntil');
    }

    /** Startet oder beendet das Blinken der Signallampen passend zum Zustand. */
    private function updateSignal(): void
    {
        $running = $this->GetTimerInterval('SignalTimer') > 0;
        $lights = $this->signalLights();
        $wanted = $lights && $this->signalWanted();

        if ($wanted && !$running) {
            $saved = [];
            foreach ($lights as $instanceID => $vars) {
                $saved[$instanceID] = [
                    'on'         => (bool) GetValue($vars['on']),
                    'color'      => (int) GetValue($vars['color']),
                    'brightness' => $vars['brightness'] > 0 ? (int) GetValue($vars['brightness']) : 0,
                    'ct'         => $vars['ct'] > 0 ? (int) GetValue($vars['ct']) : 0,
                ];
            }
            $this->WriteAttributeString('SignalSaved', json_encode($saved));
            foreach (array_keys($lights) as $instanceID) {
                $this->hueColor($instanceID, $lights[$instanceID], $this->ReadPropertyInteger('SignalColor'), 100);
            }
            $this->WriteAttributeBoolean('SignalOn', true);
            $this->SetTimerInterval('SignalTimer', max(500, $this->ReadPropertyInteger('SignalInterval')));
        } elseif (!$wanted && $running) {
            $this->SetTimerInterval('SignalTimer', 0);
            $this->WriteAttributeInteger('SignalUntil', 0);
            $this->restoreSignalLights($lights);
            $deferred = $this->ReadAttributeInteger('LightDeferred');
            if ($deferred >= 0) {
                $this->WriteAttributeInteger('LightDeferred', -1);
                $this->setLightPercent($deferred);
            }
        }
    }

    private function signalTick(): void
    {
        if (!$this->signalWanted()) {
            return;
        }
        $on = !$this->ReadAttributeBoolean('SignalOn');
        $this->WriteAttributeBoolean('SignalOn', $on);
        foreach ($this->signalLights() as $instanceID => $vars) {
            if ($on) {
                $this->hueColor($instanceID, $vars, $this->ReadPropertyInteger('SignalColor'), 100);
            } else {
                $this->act($vars['on'], false);
            }
        }
    }

    private function restoreSignalLights(array $lights): void
    {
        $saved = json_decode($this->ReadAttributeString('SignalSaved'), true) ?: [];
        $this->WriteAttributeString('SignalSaved', '{}');
        foreach ($lights as $instanceID => $vars) {
            $before = $saved[$instanceID] ?? null;
            if ($before === null || !$this->ReadPropertyBoolean('SignalRestore')) {
                $this->act($vars['on'], false);
                continue;
            }
            // Hue übernimmt Farbe und Helligkeit nur im eingeschalteten Zustand, daher erst setzen, dann ggf. aus.
            // Gruppen melden ihre Farbe meist nicht (Variable 0 = schwarz): dann zurück auf Weiß statt Rot.
            if ($before['color'] > 0) {
                $this->hueColor($instanceID, $vars, $before['color'], $before['brightness']);
            } elseif ($vars['ct'] > 0) {
                $this->act($vars['ct'], ($before['ct'] ?? 0) >= 153 ? $before['ct'] : self::HUE_WARM_WHITE_MIREK);
                if ($vars['brightness'] > 0 && $before['brightness'] > 0) {
                    $this->act($vars['brightness'], $before['brightness']);
                }
            }
            if (!$before['on']) {
                $this->act($vars['on'], false);
            }
        }
    }

    /** Schaltet eine Hue-Lampe/-Gruppe ein, mit Farbe und Helligkeit in einem Befehl ohne Überblendung. */
    private function hueColor(int $instanceID, array $vars, int $color, int $brightness): void
    {
        // Schwarz (0) lässt die Farbumrechnung im Hue-Modul durch null teilen
        if ($color <= 0) {
            return;
        }
        $params = ['on' => ['on' => true], 'dynamics' => ['duration' => 0]];
        if ($brightness > 0) {
            $params['dimming'] = ['brightness' => min(100, $brightness)];
        }
        if (function_exists('PHUE_setColor')) {
            try {
                PHUE_setColor($instanceID, $color, $params);
                return;
            } catch (Throwable $e) {
                $this->log("Hue $instanceID (" . IPS_GetName($instanceID) . '): ' . $e->getMessage());
            }
        }
        $this->act($vars['color'], $color);
        if ($vars['brightness'] > 0 && $brightness > 0) {
            $this->act($vars['brightness'], $brightness);
        }
    }

    // ---------- Überfall und Panik ----------

    private function onPanic(string $name): void
    {
        if ($this->ReadPropertyBoolean('PanicSilent')) {
            $this->silentAlarm("Panik-Taster $name ausgelöst.");
            return;
        }
        $this->log("Panik-Taster $name ausgelöst.");
        if ($this->state() === self::STATE_IDLE || $this->state() === self::STATE_PREALARM) {
            $this->SetValue('Trigger', "Panik $name");
            $this->WriteAttributeString('TriggerSource', 'Panik');
        } else {
            $this->appendTrigger("Panik $name");
        }
        if ($this->state() === self::STATE_LATCHED) {
            $this->SetValue('State', self::STATE_ALARM);
        }
        $this->startAlarm(true);
    }

    /** Stiller Alarm: keine Sirene, kein Licht, keine Anzeige, nur Benachrichtigung und Ausgänge. */
    private function silentAlarm(string $reason): void
    {
        $this->SetValue('SilentAlarm', true);
        foreach ($this->listProperty('SilentAlarmOutputs') as $row) {
            $this->act((int) $row['VariableID'], $row['Value'] ?? 1);
        }

        $scriptID = $this->ReadPropertyInteger('SilentAlarmScriptID');
        if ($scriptID > 0 && IPS_ScriptExists($scriptID)) {
            IPS_RunScriptEx($scriptID, ['ALARM_INSTANCE' => $this->InstanceID, 'ALARM_REASON' => $reason]);
        }

        $this->LogMessage("STILLER ALARM: $reason", KL_WARNING);
        $visuID = $this->ReadPropertyInteger('SilentVisuID') ?: $this->ReadPropertyInteger('VisuID');
        if ($visuID > 0 && IPS_InstanceExists($visuID) && function_exists('VISU_PostNotificationEx')) {
            @VISU_PostNotificationEx($visuID, 'STILLER ALARM', mb_substr($reason, 0, 256), 'Alert', 'alarm', 0);
        }
    }

    // ---------- Erinnerung "Schärfen vergessen" ----------

    private function scheduleReminder(): void
    {
        $this->WriteAttributeInteger('ReminderCount', 0);
        if (!$this->ReadPropertyBoolean('ReminderEnabled')) {
            $this->SetTimerInterval('ReminderTimer', 0);
            return;
        }
        $this->SetTimerInterval('ReminderTimer', $this->secondsUntil($this->ReadPropertyString('ReminderTime')) * 1000);
    }

    private function onReminder(): void
    {
        $forgotten = $this->mode() === self::MODE_OFF && !$this->isArmPending()
            && $this->state() === self::STATE_IDLE && $this->anyoneHome();
        if (!$forgotten) {
            $this->scheduleReminder();
            return;
        }

        $count = $this->ReadAttributeInteger('ReminderCount') + 1;
        $open = array_filter(array_keys($this->contacts()), fn ($id) => $this->isContactOpen($id));
        $text = 'Die Alarmanlage ist noch nicht scharf.';
        if ($open) {
            $text .= ' Offen: ' . $this->names($open) . '.';
        }
        $this->notify('Schärfen vergessen?', $text);
        $this->showInfo('Reminder', 'Nicht scharf!');

        $repeat = $this->ReadPropertyInteger('ReminderRepeatMinutes');
        if ($repeat > 0 && $count < $this->ReadPropertyInteger('ReminderMaxCount')) {
            $this->WriteAttributeInteger('ReminderCount', $count);
            $this->SetTimerInterval('ReminderTimer', $repeat * 60 * 1000);
        } else {
            $this->scheduleReminder();
        }
    }

    /** Sekunden bis zur nächsten Uhrzeit aus einem SelectTime-Wert ({"hour":..,"minute":..,"second":..}). */
    private function secondsUntil(string $time): int
    {
        $t = json_decode($time, true) ?: [];
        $target = mktime((int) ($t['hour'] ?? 0), (int) ($t['minute'] ?? 0), (int) ($t['second'] ?? 0));
        if ($target <= time()) {
            $target = strtotime('+1 day', $target);
        }
        return $target - time();
    }

    private function secondsOfDay(string $time): int
    {
        $t = json_decode($time, true) ?: [];
        return (int) ($t['hour'] ?? 0) * 3600 + (int) ($t['minute'] ?? 0) * 60 + (int) ($t['second'] ?? 0);
    }

    // ---------- Anwesenheitssimulation ----------

    /** Läuft minütlich, solange "Abwesend" scharf ist; schaltet Lichter im Zeitfenster bei Dunkelheit zufällig. */
    private function updateSimulation(): void
    {
        $lights = array_keys($this->namedList('SimLights'));
        if (!$this->ReadPropertyBoolean('SimEnabled') || !$lights || $this->mode() !== self::MODE_AWAY) {
            $this->SetTimerInterval('SimTimer', 0);
            $this->WriteAttributeString('SimState', '{}');
            return;
        }
        $this->SetTimerInterval('SimTimer', 60 * 1000);

        if ($this->state() !== self::STATE_IDLE) {
            return;
        }
        $sim = json_decode($this->ReadAttributeString('SimState'), true) ?: [];

        if (!$this->simulationWindowActive()) {
            foreach ($sim as $id => [$on]) {
                if ($on) {
                    $this->act((int) $id, false);
                }
            }
            if ($sim) {
                $this->log('Anwesenheitssimulation: Zeitfenster beendet, Lichter aus.');
            }
            $this->WriteAttributeString('SimState', '{}');
            return;
        }

        $now = time();
        foreach ($lights as $id) {
            $key = (string) $id;
            if (!isset($sim[$key])) {
                // Gestaffelter Start, damit nicht alle Lichter gleichzeitig angehen
                $sim[$key] = [false, $now + 60 * random_int(0, max(0, $this->ReadPropertyInteger('SimOffMax')))];
                continue;
            }
            [$on, $next] = $sim[$key];
            if ($now < $next) {
                continue;
            }
            $on = !$on;
            $this->act($id, $on);
            $minutes = $on
                ? random_int($this->ReadPropertyInteger('SimOnMin'), max($this->ReadPropertyInteger('SimOnMin'), $this->ReadPropertyInteger('SimOnMax')))
                : random_int($this->ReadPropertyInteger('SimOffMin'), max($this->ReadPropertyInteger('SimOffMin'), $this->ReadPropertyInteger('SimOffMax')));
            $sim[$key] = [$on, $now + 60 * $minutes];
            $this->SendDebug('Simulation', IPS_GetName($id) . ($on ? ' an' : ' aus') . " für $minutes min", 0);
        }
        $this->WriteAttributeString('SimState', json_encode($sim));
    }

    private function simulationWindowActive(): bool
    {
        $dayID = $this->ReadPropertyInteger('SimDayVariableID');
        if ($dayID > 0 && IPS_VariableExists($dayID) && GetValue($dayID)) {
            return false;
        }
        $now = (int) date('G') * 3600 + (int) date('i') * 60 + (int) date('s');
        $start = $this->secondsOfDay($this->ReadPropertyString('SimStartTime'));
        $end = $this->secondsOfDay($this->ReadPropertyString('SimEndTime'));
        // Fenster über Mitternacht (z. B. 17:00 bis 01:00) ist erlaubt
        return $start <= $end ? ($now >= $start && $now < $end) : ($now >= $start || $now < $end);
    }

    private function isEntryRoute(string $property, int $id): bool
    {
        foreach ($this->listProperty($property) as $row) {
            if ((int) ($row['VariableID'] ?? 0) === $id) {
                return (bool) ($row['EntryRoute'] ?? false);
            }
        }
        return false;
    }

    // ---------- Aktoren ----------

    private function switchSirens(bool $on): void
    {
        foreach ($this->listProperty('Sirens') as $row) {
            $id = (int) $row['VariableID'];
            if ($this->ReadPropertyBoolean('TestMode')) {
                $this->log('Testmodus: Sirene ' . $id . ($on ? ' würde eingeschaltet.' : ' würde ausgeschaltet.'));
                continue;
            }
            $this->act($id, $on);
        }
    }

    /** Lässt die Voralarm-Lampen blinken, solange der Voralarm läuft. */
    private function updateBlink(): void
    {
        $blinking = $this->GetTimerInterval('BlinkTimer') > 0;
        $wanted = $this->state() === self::STATE_PREALARM && $this->ReadPropertyBoolean('PreAlarmBlink')
            && $this->listProperty('PreAlarmLights');
        if ($wanted && !$blinking) {
            $this->WriteAttributeBoolean('BlinkOn', true);
            $this->SetTimerInterval('BlinkTimer', max(250, $this->ReadPropertyInteger('PreAlarmBlinkInterval')));
        } elseif (!$wanted && $blinking) {
            $this->SetTimerInterval('BlinkTimer', 0);
            // Nach dem Voralarm bleiben die Lampen als Orientierungslicht an
            if (!$this->ReadAttributeBoolean('BlinkOn')) {
                $this->switchBlinkLights(true);
            }
        }
    }

    private function blink(): void
    {
        if ($this->state() !== self::STATE_PREALARM) {
            return;
        }
        $on = !$this->ReadAttributeBoolean('BlinkOn');
        $this->WriteAttributeBoolean('BlinkOn', $on);
        $this->switchBlinkLights($on);
    }

    private function switchBlinkLights(bool $on): void
    {
        foreach ($this->listProperty('PreAlarmLights') as $row) {
            $this->act((int) $row['SwitchVariableID'], $on);
        }
    }

    private function switchLights(string $property): void
    {
        foreach ($this->listProperty($property) as $row) {
            $this->act((int) $row['SwitchVariableID'], true);
            $brightnessID = (int) ($row['BrightnessVariableID'] ?? 0);
            if ($brightnessID > 0 && IPS_VariableExists($brightnessID)) {
                $this->act($brightnessID, $this->maxValue($brightnessID));
            }
        }
    }

    /** Setzt "System Armed" und alle Statusvariablen der Liste passend zum aktuellen Modus. */
    private function updateArmedOutputs(): void
    {
        $mode = $this->mode();
        $id = $this->ReadPropertyInteger('ArmedStatusVariableID');
        if ($id > 0) {
            $this->act($id, $mode !== self::MODE_OFF);
        }
        foreach ($this->listProperty('ArmedOutputs') as $row) {
            $rowMode = (int) ($row['Mode'] ?? 0);
            $armed = $mode !== self::MODE_OFF && ($rowMode === 0 || $rowMode === $mode);
            $this->act((int) $row['VariableID'], $armed ? ($row['ArmedValue'] ?? 1) : ($row['DisarmedValue'] ?? 0));
        }
    }

    private function act(int $id, $value): void
    {
        if ($id <= 0 || !IPS_VariableExists($id)) {
            $this->log("Variable $id existiert nicht.");
            return;
        }
        switch (IPS_GetVariable($id)['VariableType']) {
            case VARIABLETYPE_BOOLEAN:
                $value = (bool) $value;
                break;
            case VARIABLETYPE_INTEGER:
                $value = (int) $value;
                break;
            case VARIABLETYPE_FLOAT:
                $value = (float) $value;
                break;
        }
        if (!@RequestAction($id, $value)) {
            $this->log("Schalten von $id (" . IPS_GetName($id) . ') fehlgeschlagen.');
        }
    }

    private function maxValue(int $id)
    {
        $var = IPS_GetVariable($id);
        $profile = $var['VariableCustomProfile'] ?: $var['VariableProfile'];
        if ($profile !== '' && IPS_VariableProfileExists($profile)) {
            return IPS_GetVariableProfile($profile)['MaxValue'];
        }
        // Philips HUE V2 ab 2.1 nutzt Darstellungen statt Profilen, Helligkeit 0 bis 100
        return IPS_GetObject($id)['ObjectIdent'] === 'brightness' ? 100 : 254;
    }

    // ---------- Hilfsfunktionen ----------

    private function mode(): int
    {
        return (int) $this->GetValue('Mode');
    }

    private function state(): int
    {
        return (int) $this->GetValue('State');
    }

    private function isContactOpen(int $id): bool
    {
        return IPS_VariableExists($id) && (bool) GetValue($id) === $this->ReadPropertyBoolean('ContactOpenValue');
    }

    private function anyoneHome(): bool
    {
        foreach (array_keys($this->presence()) as $id) {
            if (IPS_VariableExists($id) && GetValue($id)) {
                return true;
            }
        }
        return false;
    }

    private function setTimer(string $next, int $seconds): void
    {
        $seconds = max(1, $seconds);
        $this->WriteAttributeString('NextStep', $next);
        $this->WriteAttributeInteger('StepDeadline', time() + $seconds);
        $this->SetTimerInterval('StepTimer', $seconds * 1000);
        $this->updateCountdown();
    }

    private function clearTimer(): void
    {
        $this->SetTimerInterval('StepTimer', 0);
        $this->WriteAttributeString('NextStep', '');
        $this->updateCountdown();
    }

    /** Zweite Textausgabe: Restzeit von Schärf-Verzögerung und Voralarm, sekündlich aktualisiert. */
    private function updateCountdown(): void
    {
        $next = $this->ReadAttributeString('NextStep');
        $active = (in_array($next, ['armAway', 'armNight', 'armAwayManual'], true) && $this->ReadPropertyBoolean('CountdownArm'))
            || ($next === 'alarm' && $this->ReadPropertyBoolean('CountdownPreAlarm'));

        if (!$active || !$this->listProperty('CountdownOutputs')) {
            $this->SetTimerInterval('CountdownTimer', 0);
            $this->sendText('', 'CountdownOutputs', 'LastCountdownText');
            return;
        }

        $remaining = max(0, $this->ReadAttributeInteger('StepDeadline') - time());
        $time = $remaining >= 60 ? sprintf('%d:%02d', intdiv($remaining, 60), $remaining % 60) : "{$remaining}s";
        if (!$this->ReadPropertyBoolean('CountdownTimeOnly')) {
            $time = ($next === 'alarm' ? 'Alarm in ' : 'Scharf in ') . $time;
        }
        $this->sendText($time, 'CountdownOutputs', 'LastCountdownText');
        $this->SetTimerInterval('CountdownTimer', 1000);
    }

    private function appendTrigger(string $text): void
    {
        $current = $this->GetValue('Trigger');
        if (strpos($current, $text) === false) {
            $this->SetValue('Trigger', $current === '' ? $text : "$current, $text");
        }
        $this->log("Weiterer Auslöser: $text");
    }

    private function updateExcluded(): void
    {
        $this->SetValue('Excluded', $this->names($this->readIDs('PendingContacts')));
    }

    private function names(array $ids): string
    {
        $contacts = $this->contacts();
        return implode(', ', array_map(fn ($id) => $contacts[$id] ?? "#$id", $ids));
    }

    private function readIDs(string $attribute): array
    {
        $ids = json_decode($this->ReadAttributeString($attribute), true);
        return is_array($ids) ? array_map('intval', $ids) : [];
    }

    private function writeIDs(string $attribute, array $ids): void
    {
        $this->WriteAttributeString($attribute, json_encode(array_values(array_unique($ids))));
    }

    private function listProperty(string $name): array
    {
        $rows = json_decode($this->ReadPropertyString($name), true);
        return is_array($rows) ? $rows : [];
    }

    /** @return array<int, string> VariablenID => Anzeigename */
    private function namedList(string $property): array
    {
        $result = [];
        foreach ($this->listProperty($property) as $row) {
            $id = (int) ($row['VariableID'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $name = trim((string) ($row['Name'] ?? ''));
            if ($name === '') {
                $name = IPS_ObjectExists($id) ? IPS_GetName($id) : "#$id";
            }
            $result[$id] = $name;
        }
        return $result;
    }

    private function contacts(): array
    {
        return $this->namedList('Contacts');
    }

    private function motionSensors(): array
    {
        return $this->namedList('MotionSensors');
    }

    private function presence(): array
    {
        return $this->namedList('Presence');
    }

    private function ekeyIDs(): array
    {
        $ids = [
            $this->ReadPropertyInteger('EkeyUserVariableID'),
            $this->ReadPropertyInteger('EkeyFingerVariableID'),
            $this->ReadPropertyInteger('EkeyActionVariableID'),
        ];
        return array_values(array_filter($ids, fn ($id) => $id > 0));
    }

    /** @return array{name: string, duress: bool}|null */
    private function matchEkeyUser(int $user, int $finger): ?array
    {
        // Ein exakt eingetragener Finger hat Vorrang vor "jeder Finger", damit ein Überfall-Finger greift
        $rows = $this->listProperty('EkeyUsers');
        usort($rows, fn ($a, $b) => ((int) ($a['FingerID'] ?? -1) < 0) <=> ((int) ($b['FingerID'] ?? -1) < 0));
        foreach ($rows as $row) {
            $rowFinger = (int) ($row['FingerID'] ?? -1);
            if ((int) ($row['UserID'] ?? -1) === $user && ($rowFinger < 0 || $rowFinger === $finger)) {
                $name = trim((string) ($row['Name'] ?? ''));
                return ['name' => $name !== '' ? $name : "Benutzer $user", 'duress' => (bool) ($row['Duress'] ?? false)];
            }
        }
        return null;
    }

    private function outputIDs(): array
    {
        $ids = [$this->ReadPropertyInteger('ArmedStatusVariableID')];
        foreach ($this->listProperty('Sirens') as $row) {
            $ids[] = (int) $row['VariableID'];
        }
        foreach (['AlarmLights', 'PreAlarmLights'] as $property) {
            foreach ($this->listProperty($property) as $row) {
                $ids[] = (int) $row['SwitchVariableID'];
                $ids[] = (int) ($row['BrightnessVariableID'] ?? 0);
            }
        }
        $ids[] = $this->ReadPropertyInteger('SimDayVariableID');
        foreach (['LightSwitchVariableID', 'LightBrightnessVariableID', 'LightDayVariableID', 'LightAmbientVariableID', 'PerimeterDayVariableID'] as $property) {
            $ids[] = $this->ReadPropertyInteger($property);
        }
        foreach (['Shutters', 'TextOutputs', 'CountdownOutputs', 'ArmedOutputs', 'SilentAlarmOutputs', 'SimLights'] as $property) {
            foreach ($this->listProperty($property) as $row) {
                $ids[] = (int) $row['VariableID'];
            }
        }
        return array_values(array_filter(array_unique($ids), fn ($id) => $id > 0));
    }

    /** Zustandsmeldung für die Textausgabe; leer nimmt die Meldung auf dem Display zurück. */
    private function persistentText(): string
    {
        $state = $this->state();
        if ($state >= self::STATE_ALARM && $this->ReadPropertyBoolean('TextAlarm')) {
            return 'ALARM ' . $this->ReadAttributeString('TriggerSource');
        }
        if ($state === self::STATE_PREALARM && $this->ReadPropertyBoolean('TextPreAlarm')) {
            return 'Voralarm ' . $this->ReadPropertyInteger('EntryDelay') . 's';
        }
        $pending = $this->readIDs('PendingContacts');
        if ($this->mode() !== self::MODE_OFF && $pending && $this->ReadPropertyBoolean('TextExcluded')) {
            return count($pending) === 1 ? 'Offen: ' . $this->names($pending) : count($pending) . ' Kont. offen';
        }
        return '';
    }

    /** Zeigt die aktuelle Zustandsmeldung und beendet einen laufenden Hinweis. */
    private function refreshDisplay(): void
    {
        $this->SetTimerInterval('TextTimer', 0);
        $this->sendText($this->persistentText());
    }

    /** Zeigt einen Hinweis; nach der Anzeigedauer kehrt das Display zur Zustandsmeldung zurück. */
    private function showInfo(string $category, string $text): void
    {
        if (!$this->ReadPropertyBoolean('Text' . $category)) {
            return;
        }
        $this->sendText($text);
        $duration = $this->ReadPropertyInteger('TextInfoDuration');
        $this->SetTimerInterval('TextTimer', $duration > 0 ? $duration * 1000 : 0);
    }

    private function sendText(string $text, string $outputsProperty = 'TextOutputs', string $lastAttribute = 'LastText'): void
    {
        $outputs = $this->listProperty($outputsProperty);
        if (!$outputs) {
            return;
        }
        if ($this->ReadPropertyBoolean('TextReplaceUmlauts')) {
            $text = strtr($text, ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'Ä' => 'Ae', 'Ö' => 'Oe', 'Ü' => 'Ue', 'ß' => 'ss']);
            $text = preg_replace('/[^\x20-\x7E]/', '', $text);
        }
        $text = mb_substr($text, 0, 14);
        if ($text === $this->ReadAttributeString($lastAttribute)) {
            return;
        }
        $this->WriteAttributeString($lastAttribute, $text);
        foreach ($outputs as $row) {
            $this->act((int) $row['VariableID'], $text);
        }
    }

    private function notify(string $title, string $text, string $sound = ''): void
    {
        $this->log("$title: $text");
        $visuID = $this->ReadPropertyInteger('VisuID');
        if ($visuID > 0 && IPS_InstanceExists($visuID) && function_exists('VISU_PostNotificationEx')) {
            @VISU_PostNotificationEx($visuID, mb_substr($title, 0, 32), mb_substr($text, 0, 256), 'Alert', $sound, 0);
        }
    }

    private function log(string $message): void
    {
        $this->SendDebug('Alarmanlage', $message, 0);
        $this->LogMessage($message, KL_NOTIFY);
    }

    private function lock(callable $fn): void
    {
        $key = 'Alarmanlage_' . $this->InstanceID;
        if (!IPS_SemaphoreEnter($key, 10000)) {
            $this->LogMessage('Semaphore belegt, Ereignis verworfen.', KL_WARNING);
            return;
        }
        try {
            $fn();
            $this->updateBlink();
            $this->updateSignal();
            $this->syncStatusVariables();
        } finally {
            IPS_SemaphoreLeave($key);
        }
    }

    private function ensureProfiles(): void
    {
        $profiles = [
            'Alarm.Mode' => [
                [0, 'Unscharf', '', 0x00AA00],
                [1, 'Scharf (Abwesend)', '', 0xFF0000],
                [2, 'Scharf (Nacht)', '', 0xFF8800],
            ],
            'SecuritySystem.HomeKit' => [
                [0, 'Zuhause', '', -1],
                [1, 'Abwesend', '', -1],
                [2, 'Nacht', '', -1],
                [3, 'Aus', '', -1],
            ],
            'Alarm.State' => [
                [0, 'Ruhe', '', 0x00AA00],
                [1, 'Voralarm', '', 0xFFCC00],
                [2, 'Alarm', '', 0xFF0000],
                [3, 'Alarm – Sirene', '', 0xFF0000],
                [4, 'Alarm ausgelöst', '', 0xAA0000],
            ],
        ];
        foreach ($profiles as $name => $associations) {
            if (IPS_VariableProfileExists($name)) {
                continue;
            }
            IPS_CreateVariableProfile($name, VARIABLETYPE_INTEGER);
            foreach ($associations as $a) {
                IPS_SetVariableProfileAssociation($name, $a[0], $a[1], $a[2], $a[3]);
            }
        }
    }
}
