<?php

declare(strict_types=1);

// Minimale Nachbildung der Symcon-API, gerade genug für die Modultests.

const KR_READY = 10103;
const IPS_KERNELSTARTED = 10001;
const VM_UPDATE = 10603;
const KL_NOTIFY = 10203;
const KL_WARNING = 10204;
const VARIABLETYPE_BOOLEAN = 0;
const VARIABLETYPE_INTEGER = 1;
const VARIABLETYPE_FLOAT = 2;
const VARIABLETYPE_STRING = 3;

class Sim
{
    public static array $vars = [];     // id => ['name', 'type', 'value', 'profile']
    public static array $profiles = [];
    public static array $actions = [];  // Protokoll aller RequestAction-Aufrufe
    public static array $pushes = [];
    public static ?IPSModule $module = null;
    public static int $now = 1000000;
    public static array $instances = [];  // id => [ident => variableID]
    public static array $config = [];     // Eigenschaften fremder Instanzen (z. B. WebHook Control)

    public static function addVar(int $id, string $name, int $type, $value, string $profile = ''): void
    {
        self::$vars[$id] = ['name' => $name, 'type' => $type, 'value' => $value, 'profile' => $profile, 'updated' => 0];
    }

    public static function addHueLight(int $instanceID, int $onID, int $colorID, int $brightnessID, bool $on = false, int $color = 0xFFFFFF, int $brightness = 50): void
    {
        self::$instances[$instanceID] = ['on' => $onID, 'color' => $colorID, 'brightness' => $brightnessID];
        self::addVar($onID, 'Status', VARIABLETYPE_BOOLEAN, $on);
        self::addVar($colorID, 'Farbe', VARIABLETYPE_INTEGER, $color);
        self::addVar($brightnessID, 'Helligkeit', VARIABLETYPE_INTEGER, $brightness);
        self::$vars[$brightnessID]['ident'] = 'brightness';
    }

    public static function reset(): void
    {
        self::$actions = [];
        self::$pushes = [];
    }

    /** Setzt einen Sensorwert und stellt die VM_UPDATE-Nachricht zu. */
    public static function update(int $id, $value): void
    {
        $old = self::$vars[$id]['value'];
        self::$vars[$id]['value'] = $value;
        self::$vars[$id]['updated'] = self::$now;
        if (self::$module && in_array($id, self::$module->watched, true)) {
            self::$module->MessageSink(time(), $id, VM_UPDATE, [$value, $old !== $value, $old]);
        }
    }

    public static function fireTextTimer(): bool
    {
        $m = self::$module;
        if ($m->timers['TextTimer'] <= 0) {
            return false;
        }
        $m->RequestAction('TextTimerElapsed', 0);
        return true;
    }

    public static function fireTimer(): string
    {
        $m = self::$module;
        if ($m->timers['StepTimer'] <= 0) {
            return '';
        }
        $next = $m->attributes['NextStep'];
        $m->RequestAction('TimerElapsed', 0);
        return $next;
    }
}

class IPSModule
{
    public int $InstanceID = 99999;
    public array $properties = [];
    public array $attributes = [];
    public array $timers = [];
    public array $values = [];
    public array $watched = [];
    public int $status = 0;
    public array $log = [];

    public function __construct()
    {
        Sim::$module = $this;
    }

    public function Create() {}
    public function ApplyChanges() {}

    protected function RegisterPropertyString($n, $v) { $this->properties[$n] = $v; }
    protected function RegisterPropertyInteger($n, $v) { $this->properties[$n] = $v; }
    protected function RegisterPropertyBoolean($n, $v) { $this->properties[$n] = $v; }
    protected function ReadPropertyString($n) { return $this->properties[$n]; }
    protected function ReadPropertyInteger($n) { return $this->properties[$n]; }
    protected function ReadPropertyBoolean($n) { return $this->properties[$n]; }

    protected function RegisterAttributeString($n, $v) { $this->attributes[$n] = $v; }
    protected function ReadAttributeString($n) { return $this->attributes[$n]; }
    protected function WriteAttributeString($n, $v) { $this->attributes[$n] = $v; }
    protected function RegisterAttributeInteger($n, $v) { $this->attributes[$n] = $v; }
    protected function RegisterAttributeBoolean($n, $v) { $this->attributes[$n] = $v; }
    protected function ReadAttributeBoolean($n) { return $this->attributes[$n]; }
    protected function WriteAttributeBoolean($n, $v) { $this->attributes[$n] = $v; }
    protected function ReadAttributeInteger($n) { return $this->attributes[$n]; }
    protected function WriteAttributeInteger($n, $v) { $this->attributes[$n] = $v; }

    protected function RegisterTimer($n, $i, $s) { $this->timers[$n] = $i; }
    protected function SetTimerInterval($n, $i) { $this->timers[$n] = $i; }
    protected function GetTimerInterval($n) { return $this->timers[$n]; }

    protected function RegisterVariableInteger($i, $n, $p, $pos) { $this->values[$i] = 0; }
    protected function RegisterVariableString($i, $n, $p, $pos) { $this->values[$i] = ''; }
    protected function RegisterVariableBoolean($i, $n, $p, $pos) { $this->values[$i] = false; }
    protected function MaintainVariable($i, $n, $t, $p, $pos, $keep)
    {
        if (!$keep) {
            unset($this->values[$i]);
        } elseif (!array_key_exists($i, $this->values)) {
            $this->values[$i] = [VARIABLETYPE_BOOLEAN => false, VARIABLETYPE_INTEGER => 0, VARIABLETYPE_FLOAT => 0.0, VARIABLETYPE_STRING => ''][$t];
        }
    }
    protected function GetValue($i) { return $this->values[$i]; }
    protected function SetValue($i, $v) { $this->values[$i] = $v; }
    protected function GetIDForIdent($i) { return array_key_exists($i, $this->values) ? $i : false; }
    protected function EnableAction($i) {}
    protected function DisableAction($i) {}

    protected function RegisterMessage($id, $msg) { if ($msg === VM_UPDATE) { $this->watched[] = $id; } }
    protected function UnregisterMessage($id, $msg) { $this->watched = array_values(array_diff($this->watched, [$id])); }
    protected function GetMessageList() { return array_fill_keys($this->watched, [VM_UPDATE]); }
    protected function RegisterReference($id) {}
    protected function UnregisterReference($id) {}
    protected function GetReferenceList() { return []; }

    protected function SetStatus($s) { $this->status = $s; }
    protected function SendDebug($t, $m, $f) {}
    protected function LogMessage($m, $t) { $this->log[] = $m; }
}

function IPS_GetKernelRunlevel() { return KR_READY; }
function IPS_VariableExists($id) { return isset(Sim::$vars[$id]); }
function IPS_ObjectExists($id) { return isset(Sim::$vars[$id]); }
function IPS_InstanceExists($id) { return $id === 77777 || isset(Sim::$instances[$id]); }
function IPS_GetObject($id) { return ['ObjectIdent' => Sim::$vars[$id]['ident'] ?? '']; }
function IPS_GetObjectIDByIdent($ident, $parentID) { return Sim::$instances[$parentID][$ident] ?? false; }
function IPS_GetInstanceListByModuleID($moduleID) { return isset(Sim::$config[55555]) ? [55555] : []; }
function IPS_GetProperty($id, $name) { return Sim::$config[$id][$name]; }
function IPS_SetProperty($id, $name, $value) { Sim::$config[$id][$name] = $value; return true; }
function IPS_ApplyChanges($id) { return true; }
function IPS_GetName($id) { return Sim::$vars[$id]['name']; }
function IPS_GetVariable($id)
{
    $v = Sim::$vars[$id];
    return ['VariableType' => $v['type'], 'VariableProfile' => $v['profile'], 'VariableCustomProfile' => '', 'VariableUpdated' => $v['updated'], 'VariableAction' => $v['action'] ?? 0];
}
function IPS_VariableProfileExists($n) { return isset(Sim::$profiles[$n]); }
function IPS_GetVariableProfile($n) { return Sim::$profiles[$n]; }
function IPS_CreateVariableProfile($n, $t) { Sim::$profiles[$n] = ['MaxValue' => 0]; }
function IPS_SetVariableProfileAssociation($n, $v, $t, $i, $c) {}
function IPS_SemaphoreEnter($k, $t) { return true; }
function IPS_SemaphoreLeave($k) { return true; }
function GetValue($id) { return Sim::$vars[$id]['value']; }
function SetValue($id, $value)
{
    Sim::$vars[$id]['value'] = $value;
    Sim::$actions[] = [$id, $value];
    return true;
}
function IPS_GetLogDir()
{
    $dir = __DIR__ . '/.tmp/';
    @mkdir($dir);
    return $dir;
}
function GetValueFormatted($id) { return (string) Sim::$module->values[$id]; }
function RequestAction($id, $value)
{
    Sim::$vars[$id]['value'] = $value;
    Sim::$actions[] = [$id, $value];
    return true;
}
function IPS_ScriptExists($id) { return $id === 88888; }
function IPS_RunScriptEx($id, $params)
{
    Sim::$actions[] = ['script', $id, $params['ALARM_REASON']];
    return true;
}
function PHUE_setColor($instanceID, $color, $params)
{
    $vars = Sim::$instances[$instanceID];
    Sim::$vars[$vars['on']]['value'] = $params['on']['on'];
    Sim::$vars[$vars['color']]['value'] = $color;
    if (isset($params['dimming'])) {
        Sim::$vars[$vars['brightness']]['value'] = $params['dimming']['brightness'];
    }
    Sim::$actions[] = ['hue', $instanceID, $color, $params];
    return true;
}
function VISU_PostNotificationEx($id, $title, $text, $icon, $sound, $target)
{
    Sim::$pushes[] = "$title: $text";
    return 1;
}
