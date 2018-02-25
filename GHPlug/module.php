<?php

declare(strict_types=1);
/**
 * @addtogroup ghoma
 * @{
 *
 * @file          module.php
 *
 * @author        Michael Tröger <micha@nall-chan.net>
 * @copyright     2018 Michael Tröger
 * @license       https://creativecommons.org/licenses/by-nc-sa/4.0/ CC BY-NC-SA 4.0
 *
 * @version       2.0
 */
require_once __DIR__ . '/../libs/GHomaTraits.php';  // diverse Klassen

/**
 * GHomaPlug ist die Klasse für die WLAN Steckdosen der Firma G-Homa
 * Erweitert ipsmodule.
 *
 * @property string $BufferIN Receive RAW Buffer.
 * @property GHMessage $LastMessage Letzte Antwort.
 * @property GHConnectState $ConnectState Status.
 * @property string $FullMac MAC
 * @property string $ShortMac MAC
 * @property string $TriggerCode
 * @property int $Port
 */
class GHomaPlug extends IPSModule
{
    use VariableHelper,
        DebugHelper,
        InstanceStatus,
        BufferHelper,
        VariableProfile {
        InstanceStatus::MessageSink as IOMessageSink; // MessageSink gibt es sowohl hier in der Klasse, als auch im Trait InstanceStatus. Hier wird für die Methode im Trait ein Alias benannt.
    }

    /**
     * Interne Funktion des SDK.
     */
    public function Create()
    {
        parent::Create();
        //$this->RequireParent("{3CFF0FD9-E306-41DB-9B5A-9D06D38576C3}");
        $DeviceIP = '';
        $this->ParentID = 0;
        if (IPS_GetKernelRunlevel() == KR_READY) {
            $ParentId = IPS_GetInstance($this->InstanceID)['ConnectionID'];
            if ($ParentId > 0) {
                if (IPS_GetInstance($ParentId)['ModuleInfo']['ModuleID'] != '{8062CF2B-600E-41D6-AD4B-1BA66C32D6ED}') {
                    $DeviceIP = IPS_GetProperty($ParentId, 'Host');
                    @IPS_DisconnectInstance($this->InstanceID);
                    @IPS_DeleteInstance($ParentId);
                    $ParentId = 0;
                }
            }
            if ($ParentId == 0) {
                $ParentId = @IPS_GetObjectIDByIdent('GHOMASSCK', 0);
                if ($ParentId == 0) {
                    $ParentId = @IPS_CreateInstance('{8062CF2B-600E-41D6-AD4B-1BA66C32D6ED}');
                    IPS_SetIdent($ParentId, 'GHOMASSCK');
                    IPS_SetName($ParentId, 'Server Socket (GHoma)');
                    IPS_SetProperty($ParentId, 'Port', 4196);
                    IPS_SetProperty($ParentId, 'Open', true);
                    IPS_ApplyChanges($ParentId);
                }
                @IPS_ConnectInstance($this->InstanceID, $ParentId);
            }
            $this->ParentID = $ParentId;
        }
        $this->RegisterTimer('Timeout', 0, 'GHOMA_Timeout($_IPS["TARGET"]);');
        $this->RegisterPropertyString('Host', $DeviceIP);
        $this->BufferIN = '';
        $this->LastMessage = null;
        $this->ConnectState = GHConnectState::UNKNOW;
        $this->FullMac = '';
        $this->Port = 0;
    }

    /**
     * Interne Funktion des SDK.
     */
    public function ApplyChanges()
    {
        $this->RegisterMessage(0, IPS_KERNELSTARTED);
        $this->RegisterMessage($this->InstanceID, FM_CONNECT);
        $this->RegisterMessage($this->InstanceID, FM_DISCONNECT);
        $this->BufferIN = '';
        $this->LastMessage = null;
        $this->ConnectState = GHConnectState::UNKNOW;
        $this->FullMac = '';
        $this->Port = 0;

        $this->RegisterVariableBoolean('STATE', 'STATE', '~Switch', 1);
        $this->EnableAction('STATE');
        $this->RegisterVariableBoolean('BUTTON', 'BUTTON', '', 2);
        $this->SetReceiveDataFilter('.*"ClientIP":"' . $this->ReadPropertyString('Host') . '".*');

        parent::ApplyChanges();

        // Anzeige Port in der INFO Spalte
        $this->SetSummary($this->ReadPropertyString('Host'));

        // Wenn Kernel nicht bereit, dann warten... KR_READY kommt ja gleich
        if (IPS_GetKernelRunlevel() != KR_READY) {
            return;
        }

//        $this->RegisterProfileFloat('GHoma.VaR', '', '', ' var', 0, 0, 0, 2);
        $this->RegisterProfileFloat('GHoma.VA', '', '', ' VA', 0, 0, 0, 2);

        $this->RegisterParent();

        // Wenn Parent aktiv, dann Anmeldung an der Hardware bzw. Datenabgleich starten
        if ($this->HasActiveParent()) {
            $this->IOChangeState(IS_ACTIVE);
        } else {
            $this->IOChangeState(IS_INACTIVE);
        }
    }

    /**
     * Interne Funktion des SDK.
     * Verarbeitet alle Nachrichten auf die wir uns registriert haben.
     *
     * @param int       $TimeStamp
     * @param int       $SenderID
     * @param int       $Message
     * @param array|int $Data
     */
    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
    {
        $this->IOMessageSink($TimeStamp, $SenderID, $Message, $Data);
        switch ($Message) {
            case IPS_KERNELSTARTED:
                $this->RegisterParent();
                if ($this->HasActiveParent()) {
                    $this->IOChangeState(IS_ACTIVE);
                } else {
                    $this->IOChangeState(IS_INACTIVE);
                }
                break;
        }
    }

    /**
     * Wird über den Trait InstanceStatus ausgeführt wenn sich der Status des Parent ändert.
     * Oder wenn sich die Zuordnung zum Parent ändert.
     *
     * @param int $State Der neue Status des Parent.
     */
    protected function IOChangeState($State)
    {
        // Wenn der IO Aktiv wurde
        if ($State == IS_ACTIVE) {
            if (trim($this->ReadPropertyString('Host')) == '') {
                $this->SetStatus(IS_INACTIVE);
                $this->ConnectState = GHConnectState::UNKNOW;
                $this->SetTimerInterval('Timeout', 0);
            } else {
                $this->SetTimerInterval('Timeout', 44 * 1000);
            }
        } else {
            $this->ConnectState = GHConnectState::UNKNOW;
            $this->SetTimerInterval('Timeout', 0);
        }
    }

    /**
     * Interne Funktion des SDK.
     */
    public function GetConfigurationForParent()
    {
        $Config['Port'] = 4196;
        return json_encode($Config);
    }

    /**
     * IPS-Instanz Funktion GHOMA_Timeout
     * Wenn drei Heartbeat-Pakte fehlen, wird der Plug als disconnected angenommen.
     */
    public function Timeout()
    {
        $this->SendDebug('Timeout', '', 0);
        $this->SetStatus(IS_EBASE + 3);
        $this->SetTimerInterval('Timeout', 0);
    }

    /**
     * IPS-Instanz Funktion GHOMA_SendSwitch.
     * Schaltet den Controller ein oder aus.
     *
     * @param bool $State true für ein, false für aus.
     *
     * @return bool True wenn Befehl erfolgreich ausgeführt wurde, sonst false.
     */
    private function SendSwitchAction(bool $State)
    {
        if ($this->ConnectState != GHConnectState::CONNECTED) {
            echo $this->Translate('Plug not connected');
            return;
        }
        $Message = new GHMessage(
                GHMessage::CMD_SWITCH, "\x01\x01\x0a\xe0" .
                $this->TriggerCode .
                $this->ShortMac .
                "\xff\xfe\x00\x00\x10\x11\x00\x00\x01\x00\x00\x00" . ($State ? "\xff" : "\x00"));

        $this->Send($Message);

        if (!$this->WaitForSwitch($State)) {
            echo $this->Translate('Plug not response');
        }
    }

    //################# PUBLIC

    /**
     * IPS-Instanz Funktion GHOMA_SendSwitch.
     * Schaltet den Controller ein oder aus.
     *
     * @param bool $State true für ein, false für aus.
     *
     * @return bool True wenn Befehl erfolgreich ausgeführt wurde, sonst false.
     */
    public function SendSwitch(bool $State)
    {
        if ($this->ConnectState != GHConnectState::CONNECTED) {
            trigger_error($this->Translate('Plug not connected'), E_USER_WARNING);
            return false;
        }
        $Message = new GHMessage(
                GHMessage::CMD_SWITCH, "\x01\x01\x0a\xe0" .
                $this->TriggerCode .
                $this->ShortMac .
                "\xff\xfe\x00\x00\x10\x11\x00\x00\x01\x00\x00\x00" . ($State ? "\xff" : "\x00"));

        $this->Send($Message);
        $Result = $this->WaitForSwitch($State);
        if (!$Result) {
            trigger_error($this->Translate('Plug not response'), E_USER_WARNING);
        }
        return $Result;
    }

    public function SendRaw(string $Value)
    {
        if ($this->ConnectState != GHConnectState::CONNECTED) {
            trigger_error($this->Translate('Plug not connected'), E_USER_WARNING);
            return false;
        }
        $Message = new GHMessage(
                GHMessage::CMD_SWITCH, "\x01\x01\x0a\xe0" .
                $this->TriggerCode .
                $this->ShortMac .
                "\xff\xfe" . $Value);

        $this->Send($Message);
    }

    //################# ActionHandler

    /**
     * Interne Funktion des SDK.
     */
    public function RequestAction($Ident, $Value)
    {
        switch ($Ident) {
            case 'STATE':
                $this->SendSwitchAction((bool) $Value);
                break;
            default:
                echo $this->Translate('Invalid Ident');
                break;
        }
    }

    //################# PRIVATE

    /**
     * Sendet die Initialisierung an den Controller und prüft die Rückmeldung.
     *
     * @throws Exception Wenn kein aktiver Parent verbunden ist.
     *
     * @return bool True bei Erfolg, sonst false.
     */
    private function SendInit()
    {
        $this->ConnectState = GHConnectState::UNKNOW;
        $Message = new GHMessage(
                GHMessage::CMD_INIT1, GHMessage::INIT1);
        $this->Send($Message);
        //return $this->WaitForConnect();
    }

    /**
     * Warte auf eine Änderung der Statusvariable 'STATE'.
     *
     * @param bool $State Der Status auf welchen gewartet wird.
     *
     * @return bool True wenn das Event eintrifft, false wenn Timeout erreicht wurde.
     */
    private function WaitForSwitch($State)
    {
        $vid = @$this->GetIDForIdent('STATE');
        if ($vid == false) {
            return false;
        }

        for ($i = 0; $i < 1000; $i++) {
            if (GetValueBoolean($vid) === $State) {
                return true;
            } else {
                IPS_Sleep(5);
            }
        }
        return false;
    }

    private function Decode(GHMessage $Message)
    {
        switch ($Message->Command) {
            case GHMessage::CMD_HEARTBEAT:
                $this->SetTimerInterval('Timeout', 0);
                $Message = new GHMessage(GHMessage::CMD_HEARTBEATREPLY, '');
                $this->Send($Message);
                $this->SetTimerInterval('Timeout', 44 * 1000);

                break;
            case GHMessage::CMD_INIT1REPLY:
                $this->ShortMac = substr($Message->Payload, 5, 3);
                $this->TriggerCode = substr($Message->Payload, 3, 2);
                $Message = new GHMessage(
                        GHMessage::CMD_INIT2, GHMessage::INIT2);
                $this->Send($Message);
                break;

            case GHMessage::CMD_INIT2REPLY:
                if ($Message->Payload[9] == chr(1)) {
                    $this->FullMac = substr($Message->Payload, 11, 6);
                }
                $this->SetStatus(IS_ACTIVE);
                $this->ConnectState = GHConnectState::CONNECTED;
                break;

            case GHMessage::CMD_STATUS:
                /*
                  Bytes               0  1  2  3  4  5  6  7  8  9 10 11 12 13 14 15 16 17 18 19 20
                  Messung            01 0A E0 35 23 D3 2B 8E FF FE 01 81 39 00 00 01 01 20 00 01 7F
                  Messung            01 0A E0 35 23 D3 2B 8E FF FE 01 81 39 00 00 01 02 00 00 01 55
                  Messung                                    FF FE 01 81 39 00 00 01 03 20 00 59 16
                  Messung            01 0A E0 35 23 D3 2B 8E FF FE 01 81 39 00 00 01 07 20 00 04 80
                  Messung            01 0A E0 35 23 D3 2B 8E FF FE 01 81 39 00 00 01 03 20 00 59 16
                  ???                01 0A E0 35 23 D3 2B 8E FF FE 01 81 39 00 00 01 02 00 00 00 32
                  SchaltenEin remote 01 0A E0 32 23 94 72 86 FF FE 01 11 11 00 00 01 00 00 00 FF
                  SchaltenEin remote 01 0A E0 35 23 D3 2B 8E FF FE 01 11 11 00 00 01 00 00 00 FF
                  SchaltenAus lokal  01 0A E0 32 23 94 72 86 FF FE 01 81 11 00 00 01 00 00 00 00
                 *                   01 0A E0 35 23 D3 3D 02 FF FE 01 81 31 00 00 02 00 00 00 01
                 */
                switch (ord($Message->Payload[12])) {
                    case 0x11: // STATE
                        $this->SetValueBoolean('STATE', ($Message->Payload[19] === chr(0xff)));
                        $this->SetValueBoolean('BUTTON', ($Message->Payload[11] === chr(0x81)));
                        break;
                    case 0x31:
                        $this->SetValueBoolean('ALARM', ($Message->Payload[15] === chr(0x02)), '~Alert');
                        break;
                    case 0x39: // MESSUNG
                        $Part = "\x00" . substr($Message->Payload, 18, 3);
                        $Value = unpack('N', $Part)[1];
                        switch (ord($Message->Payload[16])) {
                            case 0x01: // Watt
                                $this->SetValueFloat('Power', $Value / 100, 'Watt.14490');
                                break;
                            case 0x02: // Verbrauch
                                $this->SetValueFloat('Consumption', $Value / 1000, 'Electricity');
                                break;
                            case 0x03: // Volt
                                $this->SetValueFloat('Voltage', $Value / 100, 'Volt.230');
                                break;
                            case 0x04: // Ampere
                                $this->SetValueFloat('Current', $Value / 100, 'Ampere');
                                break;
                            case 0x05: // Hertz
                                $this->SetValueFloat('Frequenz', $Value / 100, 'Hertz.50');
                                break;
                            case 0x07: // Scheinleistung
                                $this->SetValueFloat('Output', $Value / 100, 'GHoma.VA');
                                break;
                            case 0x08: // Leistungsfaktor
                                $this->SetValueFloat('PowerFactor', $Value / 100, '');
                                break;
                        }
                        break;
                }

                $this->SetTimerInterval('Timeout', 0);
                $this->SetTimerInterval('Timeout', 44 * 1000);
                break;

            // FE Command ?
            // FE
            // 01 0A C0 35 23 D3 3D 02 00 00 00 0F
        }
    }

    //################# DATAPOINTS

    /**
     * Interne Funktion des SDK.
     */
    public function ReceiveData($JSONString)
    {
        $data = json_decode($JSONString);

        switch ($data->Type) {
            case 1: /* Connected */
                $this->SendDebug('Connected', '', 0);
                $this->Port = $data->ClientPort;
                $this->SendInit();
                return;
            case 2: /* Disconnected */
                $this->SendDebug('Disconnected', '', 0);
                $this->SetTimerInterval('Timeout', 0);
                $this->SetStatus(IS_EBASE + 3);
                $this->ConnectState = GHConnectState::UNKNOW;
                return;
        }
        // Datenstream zusammenfügen
        $head = $this->BufferIN;
        $Data = $head . utf8_decode($data->Buffer);
        // Stream in einzelne Pakete schneiden
        $Lines = explode(GHMessage::POSTFIX, $Data);
        $tail = array_pop($Lines);
        $this->BufferIN = $tail;
        foreach ($Lines as $Line) {
            $Start = strpos($Line, GHMessage::PREFIX);
            if ($Start === false) {
                $this->SendDebug('Receive invalid line', $Line, 1);
                $this->SendDebug('PREFIX error', strpos($Line, GHMessage::PREFIX), 0);
                continue;
            }
            $this->SendDebug('Receive Frame', $Line, 1);
            $Line = substr($Line, $Start + 2);
            $Len = unpack('n', substr($Line, 0, 2))[1];
            $Checksum = ord($Line[strlen($Line) - 1]);
            $Payload = substr($Line, 2, -1);
            $this->SendDebug('Frame Len', $Len, 0);
            $this->SendDebug('Frame Checksum', $Checksum, 0);
            $this->SendDebug('Frame Payload', $Payload, 1);
            if ($Len != strlen($Payload)) {
                $this->SendDebug('Got invalid frame', '', 0);
                continue;
            }
            $sum = 0;
            for ($i = 0; $i < $Len; $i++) {
                $sum += ord($Payload[$i]);
            }
            $checksumcalc = 0xFF - ($sum & 255);

            if ($Checksum != $checksumcalc) {
                $this->SendDebug('Wrong CRC', $Line, 0);
                continue;
            }
            $GHMessage = new GHMessage(ord($Payload[0]), substr($Payload, 1));
            $this->SendDebug('Receive', $GHMessage, 0);

            $this->Decode($GHMessage);
        }
        return true;
    }

    /**
     * Interne Funktion des SDK.
     */
    protected function Send(GHMessage $Message)
    {
//        if (!$this->HasActiveParent()) {
//            throw new Exception($this->Translate("Instance has no active Parent."), E_USER_NOTICE);
//        }
        $this->SendDebug('Send', $Message, 0);
        $this->SendDataToParent(json_encode(
                        [
                            'DataID'     => '{C8792760-65CF-4C53-B5C7-A30FCC84FEFE}',
                            'ClientIP'   => $this->ReadPropertyString('Host'),
                            'ClientPort' => $this->Port,
                            'Buffer'     => utf8_encode($Message->toFrame())])
        );
    }
}

/* @} */
