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

    /** Kategorien der DPT16-Textausgabe, Property heißt jeweils "Text" + Schlüssel */
    private const TEXT_CATEGORIES = ['Armed', 'Disarmed', 'Excluded', 'PreAlarm', 'Alarm', 'SirenOff', 'EkeyRejected'];

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
        $this->RegisterPropertyBoolean('RequestCanDisarmAway', false);

        $this->RegisterPropertyInteger('EkeyUserVariableID', 0);
        $this->RegisterPropertyInteger('EkeyFingerVariableID', 0);
        $this->RegisterPropertyInteger('EkeyActionVariableID', 0);
        $this->RegisterPropertyInteger('EkeyActionValue', 1);
        $this->RegisterPropertyString('EkeyUsers', '[]');
        $this->RegisterPropertyBoolean('EkeyNotifyRejected', true);

        $this->RegisterPropertyString('Sirens', '[]');
        $this->RegisterPropertyString('AlarmLights', '[]');
        $this->RegisterPropertyString('PreAlarmLights', '[]');
        $this->RegisterPropertyString('Shutters', '[]');

        $this->RegisterPropertyInteger('EntryDelay', 30);
        $this->RegisterPropertyInteger('SirenDelay', 15);
        $this->RegisterPropertyInteger('SirenDuration', 180);
        $this->RegisterPropertyInteger('AwayArmDelay', 300);

        $this->RegisterPropertyString('TextOutputs', '[]');
        $this->RegisterPropertyBoolean('TextReplaceUmlauts', true);
        foreach (self::TEXT_CATEGORIES as $category) {
            $this->RegisterPropertyBoolean('Text' . $category, true);
        }

        $this->RegisterPropertyInteger('VisuID', 0);
        $this->RegisterPropertyBoolean('NotifyArmDisarm', true);
        $this->RegisterPropertyBoolean('AllowVisuControl', false);
        $this->RegisterPropertyBoolean('TestMode', true);

        $this->RegisterAttributeString('ActiveContacts', '[]');
        $this->RegisterAttributeString('PendingContacts', '[]');
        $this->RegisterAttributeString('NextStep', '');
        $this->RegisterAttributeString('LastEkeyScan', '');
        $this->RegisterAttributeString('TriggerSource', '');

        $this->RegisterTimer('StepTimer', 0, 'IPS_RequestAction($_IPS[\'TARGET\'], \'TimerElapsed\', 0);');

        $this->ensureProfiles();
        $this->RegisterVariableInteger('Mode', 'Modus', 'Alarm.Mode', 10);
        $this->RegisterVariableInteger('State', 'Zustand', 'Alarm.State', 20);
        $this->RegisterVariableString('Trigger', 'Auslöser', '', 30);
        $this->RegisterVariableString('Excluded', 'Ausgeklammerte Kontakte', '', 40);
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
            $this->ekeyIDs()
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

        if ($this->ReadPropertyBoolean('AllowVisuControl')) {
            $this->EnableAction('Mode');
        } else {
            $this->DisableAction('Mode');
        }

        $this->lock(function () {
            $this->reconcileContacts();
            $this->setArmedStatus($this->mode() !== self::MODE_OFF);

            // Nach Neustart: einen offenen Schritt (Voralarm, Sirene aus ...) zeitnah nachholen
            if ($this->ReadAttributeString('NextStep') !== '' && $this->GetTimerInterval('StepTimer') === 0) {
                $this->SetTimerInterval('StepTimer', 1000);
            }
        });

        if ($missing) {
            $this->LogMessage('Variablen existieren nicht: ' . implode(', ', array_unique($missing)), KL_WARNING);
            $this->SetStatus(self::STATUS_MISSING_VARIABLE);
        } elseif (!$this->contacts()) {
            $this->SetStatus(self::STATUS_NO_CONTACTS);
        } else {
            $this->SetStatus(self::STATUS_OK);
        }
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
                        $this->ArmNight();
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
            if ($this->mode() !== self::MODE_OFF) {
                $this->doDisarm('Manuell unscharf geschaltet.');
            }
        });
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

        if (isset($contacts[$id])) {
            $this->onContact($id, (bool) $value);
        } elseif (isset($motion[$id])) {
            $this->onMotion($motion[$id], (bool) $value);
        } elseif (isset($presence[$id])) {
            $this->onPresence($presence[$id], (bool) $value);
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
                $this->doArm(self::MODE_NIGHT);
            } else {
                $this->setArmedStatus(true);
            }
            return;
        }

        if ($mode === self::MODE_NIGHT || ($mode === self::MODE_AWAY && $this->ReadPropertyBoolean('RequestCanDisarmAway'))) {
            $this->doDisarm('Über Scharf Request unscharf geschaltet.');
        } elseif ($mode === self::MODE_AWAY) {
            $this->log('Scharf Request 0 ignoriert: "Abwesend" wird nur über Anwesenheit unscharf.');
            $this->setArmedStatus(true);
        }
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
        $name = $accepted ? $this->matchEkeyUser($user, $finger) : null;

        if ($name === null) {
            $text = "Nicht berechtigter Fingerabdruck (Benutzer $user, Finger $finger).";
            if ($this->mode() !== self::MODE_OFF && $this->ReadPropertyBoolean('EkeyNotifyRejected')) {
                $this->notify('ekey', $text);
                $this->display('EkeyRejected', 'Finger abgel.');
            } else {
                $this->log("ekey: $text");
            }
            return;
        }

        if ($this->mode() === self::MODE_AWAY) {
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
                $this->display('Excluded', "OK: $name");
            }
            return;
        }

        if (in_array($id, $active, true)) {
            $this->onTrigger("Kontakt $name geöffnet", $name);
        }
    }

    private function onMotion(string $name, bool $value): void
    {
        if (!$value) {
            return;
        }

        $text = "Bewegung $name";
        $state = $this->state();

        switch ($this->mode()) {
            case self::MODE_AWAY:
                $this->onTrigger($text, $name);
                break;

            case self::MODE_NIGHT:
                // Nachts zählt Bewegung nur nach einer Kontaktöffnung, dann aber sofort voller Alarm
                if ($state === self::STATE_PREALARM || $state === self::STATE_ALARM) {
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

    private function onTrigger(string $text, string $source): void
    {
        switch ($this->state()) {
            case self::STATE_IDLE:
                $this->startPreAlarm($text, $source);
                break;
            case self::STATE_PREALARM:
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
        $this->setArmedStatus(true);

        $label = $mode === self::MODE_AWAY ? 'Abwesend' : 'Nacht';
        $text = "Scharf ($label).";
        $this->display('Armed', $mode === self::MODE_AWAY ? 'Scharf Abwes.' : 'Scharf Nacht');
        if ($pending) {
            $this->display('Excluded', count($pending) === 1 ? 'Offen: ' . $this->names($pending) : count($pending) . ' Kont. offen');
        }
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
        $this->SetValue('Mode', self::MODE_OFF);
        $this->SetValue('State', self::STATE_IDLE);
        $this->SetValue('Trigger', '');
        $this->updateExcluded();
        $this->setArmedStatus(false);
        $this->display('Disarmed', 'Unscharf');

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

    private function startPreAlarm(string $text, string $source): void
    {
        $delay = $this->ReadPropertyInteger('EntryDelay');
        $this->SetValue('State', self::STATE_PREALARM);
        $this->SetValue('Trigger', $text);
        $this->WriteAttributeString('TriggerSource', $source);
        $this->display('PreAlarm', "Voralarm {$delay}s");
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
            $this->display('Alarm', 'ALARM ' . $this->ReadAttributeString('TriggerSource'));
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
        $this->display('SirenOff', 'Sirene aus');
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

    private function setArmedStatus(bool $armed): void
    {
        $id = $this->ReadPropertyInteger('ArmedStatusVariableID');
        if ($id > 0) {
            $this->act($id, $armed);
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
        return 254;
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
        $this->WriteAttributeString('NextStep', $next);
        $this->SetTimerInterval('StepTimer', max(1, $seconds) * 1000);
    }

    private function clearTimer(): void
    {
        $this->SetTimerInterval('StepTimer', 0);
        $this->WriteAttributeString('NextStep', '');
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

    private function matchEkeyUser(int $user, int $finger): ?string
    {
        foreach ($this->listProperty('EkeyUsers') as $row) {
            $rowFinger = (int) ($row['FingerID'] ?? -1);
            if ((int) ($row['UserID'] ?? -1) === $user && ($rowFinger < 0 || $rowFinger === $finger)) {
                $name = trim((string) ($row['Name'] ?? ''));
                return $name !== '' ? $name : "Benutzer $user";
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
        foreach (['Shutters', 'TextOutputs'] as $property) {
            foreach ($this->listProperty($property) as $row) {
                $ids[] = (int) $row['VariableID'];
            }
        }
        return array_values(array_filter(array_unique($ids), fn ($id) => $id > 0));
    }

    /** Gibt einen Kurztext (DPT16, max. 14 Zeichen) auf allen Textausgaben aus, sofern die Kategorie aktiv ist. */
    private function display(string $category, string $text): void
    {
        $outputs = $this->listProperty('TextOutputs');
        if (!$outputs || !$this->ReadPropertyBoolean('Text' . $category)) {
            return;
        }
        if ($this->ReadPropertyBoolean('TextReplaceUmlauts')) {
            $text = strtr($text, ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'Ä' => 'Ae', 'Ö' => 'Oe', 'Ü' => 'Ue', 'ß' => 'ss']);
            $text = preg_replace('/[^\x20-\x7E]/', '', $text);
        }
        $text = mb_substr($text, 0, 14);
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
