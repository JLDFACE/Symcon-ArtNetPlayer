<?php

class ArtNetPlayerController extends IPSModule
{
    // Datenschnittstelle Parent <-> Child
    private $DataID = '{AE7C1A00-0003-47AE-B000-0000000000E3}';

    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyString('Host', '');
        $this->RegisterPropertyInteger('Port', 8000);
        $this->RegisterPropertyInteger('Poll', 3);

        $this->RegisterTimer('Poll', 0, 'ANP_Poll($_IPS[\'TARGET\']);');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $host = trim($this->ReadPropertyString('Host'));
        if ($host === '') {
            $this->SetTimerInterval('Poll', 0);
            $this->SetStatus(104);
            return;
        }
        $ms = max(1, (int)$this->ReadPropertyInteger('Poll')) * 1000;
        $this->SetTimerInterval('Poll', $ms);
        $this->Poll();
    }

    // ----- Kind -> Controller: Kommandos, die REST-Aufrufe ausloesen -----
    public function ForwardData($JSONString)
    {
        $d = json_decode($JSONString, true);
        if (!is_array($d) || !isset($d['DataID']) || $d['DataID'] != $this->DataID) {
            return json_encode(array('ok' => false, 'error' => 'bad dataid'));
        }
        $cmd = isset($d['cmd']) ? (string)$d['cmd'] : '';
        $a = (isset($d['arg']) && is_array($d['arg'])) ? $d['arg'] : array();
        $pid = isset($a['player']) ? (int)$a['player'] : 0;

        $ok = true;
        switch ($cmd) {
            case 'on':     $this->Http('POST', "/player/$pid/on", null, $ok); break;
            case 'off':    $this->Http('POST', "/player/$pid/off", null, $ok); break;
            case 'stop':   $this->Http('POST', "/player/$pid/stop", null, $ok); break;
            case 'pause':  $this->Http('POST', "/player/$pid/pause", null, $ok); break;
            case 'master': $this->Http('POST', "/player/$pid/master", array('value' => (int)$a['value']), $ok); break;
            case 'play':   $this->Http('POST', "/player/$pid/play", array('program' => (string)$a['program']), $ok); break;
            case 'config': $this->Http('POST', "/player/$pid/config", isset($a['config']) ? $a['config'] : array(), $ok); break;
            case 'refresh': break;
            default: $ok = false;
        }
        if ($ok) {
            $this->Poll(); // sofort aktualisieren, damit die Kinder frische Werte sehen
        }
        return json_encode(array('ok' => $ok));
    }

    // ----- Polling: /status holen und an alle Kinder verteilen -----
    public function Poll()
    {
        $ok = true;
        $body = $this->Http('GET', '/status', null, $ok);
        if (!$ok || $body === '') {
            $this->SetStatus(201);
            return;
        }
        $status = json_decode($body, true);
        if (!is_array($status) || !isset($status['engine'])) {
            $this->SetStatus(201);
            return;
        }
        $this->SetStatus(102);
        $payload = array('DataID' => $this->DataID, 'status' => $status);
        @ $this->SendDataToChildren(json_encode($payload));
    }

    // Öffentlich: liefert das aktuelle /status als JSON-String (z.B. für Skripte)
    public function GetStatus()
    {
        $ok = true;
        return $this->Http('GET', '/status', null, $ok);
    }

    // ----- HTTP-Helfer (REST gegen das NAS-Tool) -----
    private function Http($method, $path, $body, &$ok)
    {
        $ok = true;
        $host = trim($this->ReadPropertyString('Host'));
        $port = (int)$this->ReadPropertyInteger('Port');
        if ($host === '' || $port <= 0 || $port > 65535) {
            $ok = false;
            return '';
        }
        $url = 'http://' . $host . ':' . $port . $path;

        $headers = array('Connection: close');
        $opts = array('http' => array(
            'method'        => $method,
            'timeout'       => 4,
            'ignore_errors' => true
        ));
        if ($body !== null) {
            $json = json_encode($body);
            $headers[] = 'Content-Type: application/json';
            $headers[] = 'Content-Length: ' . strlen($json);
            $opts['http']['content'] = $json;
        }
        $opts['http']['header'] = implode("\r\n", $headers) . "\r\n";

        $ctx = stream_context_create($opts);
        $data = @file_get_contents($url, false, $ctx);
        if ($data === false) {
            $ok = false;
            return '';
        }
        return (string)$data;
    }
}
