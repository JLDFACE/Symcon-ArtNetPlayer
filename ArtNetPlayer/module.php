<?php

class ArtNetPlayer extends IPSModule
{
    // Datenschnittstelle Parent <-> Child (muss zum Controller passen)
    private $DataID = '{AE7C1A00-0003-47AE-B000-0000000000E3}';
    // Controller-Modul (fuer ConnectParent / Auto-Anlage)
    private $ControllerID = '{AE7C1A00-0001-47AE-B000-0000000000C1}';

    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyInteger('PlayerID', 1);
        $this->SetBuffer('Programs', json_encode(array()));

        $this->EnsureProfiles();

        $this->RegisterVariableBoolean('Power', 'Ein/Aus', '~Switch', 10);
        $this->EnableAction('Power');

        $this->RegisterVariableInteger('Master', 'Master', 'ANP.Percent', 20);
        $this->EnableAction('Master');

        $this->RegisterVariableInteger('Program', 'Programm', $this->ProgProfile(), 30);
        $this->EnableAction('Program');

        $this->RegisterVariableFloat('Position', 'Position', 'ANP.PercentF', 40);

        $this->RegisterVariableBoolean('Loop', 'Loop', '~Switch', 50);
        $this->EnableAction('Loop');

        $this->ConnectParent($this->ControllerID);
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        $this->EnsureProfiles();
        // Beim Parent ein sofortiges Update anstossen
        $this->SendToParent('refresh', array('player' => (int)$this->ReadPropertyInteger('PlayerID')));
    }

    public function Refresh()
    {
        $this->SendToParent('refresh', array('player' => (int)$this->ReadPropertyInteger('PlayerID')));
    }

    // ----- WebFront/Action -> Kommando an Controller -----
    public function RequestAction($Ident, $Value)
    {
        $pid = (int)$this->ReadPropertyInteger('PlayerID');

        if ($Ident == 'Power') {
            $on = (bool)$Value;
            $this->SetValueSafe('Power', $on);
            $this->SendToParent($on ? 'on' : 'off', array('player' => $pid));

        } elseif ($Ident == 'Master') {
            $v = max(0, min(100, (int)$Value));
            $this->SetValueSafe('Master', $v);
            $this->SendToParent('master', array('player' => $pid, 'value' => $v));

        } elseif ($Ident == 'Program') {
            $idx = (int)$Value;
            $names = json_decode($this->GetBuffer('Programs'), true);
            if (is_array($names) && isset($names[$idx])) {
                $this->SetValueSafe('Program', $idx);
                $this->SendToParent('play', array('player' => $pid, 'program' => $names[$idx]));
            }

        } elseif ($Ident == 'Loop') {
            $on = (bool)$Value;
            $this->SetValueSafe('Loop', $on);
            $this->SendToParent('config', array('player' => $pid, 'config' => array('loop' => $on)));
        }
    }

    // ----- Controller -> Kind: Status-Broadcast -----
    public function ReceiveData($JSONString)
    {
        $d = json_decode($JSONString, true);
        if (!is_array($d) || !isset($d['status']['engine']['players'])) {
            return '';
        }
        $pid = (int)$this->ReadPropertyInteger('PlayerID');
        $me = null;
        foreach ($d['status']['engine']['players'] as $p) {
            if ((int)$p['id'] == $pid) { $me = $p; break; }
        }
        if ($me === null) {
            $this->SetStatus(104);
            return '';
        }
        $this->SetStatus(102);

        $this->SetValueSafe('Power', ($me['state'] != 'stop'));
        $this->SetValueSafe('Master', (int)$me['master']);
        $this->SetValueSafe('Loop', !empty($me['loop']));

        $dur = isset($me['duration_ms']) ? (int)$me['duration_ms'] : 0;
        $pos = isset($me['position_ms']) ? (int)$me['position_ms'] : 0;
        $this->SetValueSafe('Position', $dur > 0 ? round(100.0 * $pos / $dur, 1) : 0.0);

        $names = array();
        if (isset($me['programs']) && is_array($me['programs'])) {
            foreach ($me['programs'] as $pr) {
                if (isset($pr['name'])) $names[] = (string)$pr['name'];
            }
        }
        $this->UpdateProgramProfile($names, isset($me['program']) ? (string)$me['program'] : '');
        return '';
    }

    // ----- Profile -----
    private function ProgProfile()
    {
        return 'ANP.Prog.' . $this->InstanceID;
    }

    private function EnsureProfiles()
    {
        if (!IPS_VariableProfileExists('ANP.Percent')) {
            IPS_CreateVariableProfile('ANP.Percent', 1);
            IPS_SetVariableProfileIcon('ANP.Percent', 'Intensity');
        }
        IPS_SetVariableProfileValues('ANP.Percent', 0, 100, 1);
        IPS_SetVariableProfileText('ANP.Percent', '', ' %');

        if (!IPS_VariableProfileExists('ANP.PercentF')) {
            IPS_CreateVariableProfile('ANP.PercentF', 2);
            IPS_SetVariableProfileDigits('ANP.PercentF', 1);
        }
        IPS_SetVariableProfileValues('ANP.PercentF', 0, 100, 0);
        IPS_SetVariableProfileText('ANP.PercentF', '', ' %');

        $p = $this->ProgProfile();
        if (!IPS_VariableProfileExists($p)) {
            IPS_CreateVariableProfile($p, 1); // Integer mit Assoziationen (Programmliste)
        }
    }

    private function UpdateProgramProfile($names, $current)
    {
        $prev = json_decode($this->GetBuffer('Programs'), true);
        if (!is_array($prev)) $prev = array();

        if ($prev !== $names) {
            $p = $this->ProgProfile();
            // alte Assoziationen entfernen (leerer Name loescht die Assoziation)
            foreach (IPS_GetVariableProfile($p)['Associations'] as $as) {
                @IPS_SetVariableProfileAssociation($p, $as['Value'], '', '', -1);
            }
            for ($i = 0; $i < count($names); $i++) {
                @IPS_SetVariableProfileAssociation($p, $i, $names[$i], '', -1);
            }
            $this->SetBuffer('Programs', json_encode($names));
        }

        $idx = array_search($current, $names, true);
        if ($idx !== false) {
            $this->SetValueSafe('Program', (int)$idx);
        }
    }

    private function SetValueSafe($ident, $val)
    {
        $vid = @$this->GetIDForIdent($ident);
        if ($vid && GetValue($vid) != $val) {
            $this->SetValue($ident, $val);
        }
    }

    private function SendToParent($cmd, $arg)
    {
        $payload = array('DataID' => $this->DataID, 'cmd' => $cmd, 'arg' => $arg);
        @ $this->SendDataToParent(json_encode($payload));
    }
}
