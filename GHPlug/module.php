<?php

declare(strict_types=1);
/**
 * @addtogroup ghoma
 * @{
 *
 * @file          module.php
 *
 * @author        Michael Tröger <micha@nall-chan.net>
 * @copyright     2019 Michael Tröger
 * @license       https://creativecommons.org/licenses/by-nc-sa/4.0/ CC BY-NC-SA 4.0
 *
 * @version       5.1
 */
require_once __DIR__ . '/../libs/GHomaTraits.php';  // diverse Klassen

/**
 * GHomaPlug ist die Klasse für die WLAN Steckdosen der Firma G-Homa
 * Erweitert ipsmodule.
 *
 * @property int $ParentID
 * @property string $BufferIN Receive RAW Buffer.
 * @property \GHoma\GHConnectState $ConnectState Status.
 * @property string $FullMac MAC
 * @property string $ShortMac MAC
 * @property string $TriggerCode
 * @property int $Port
 * @property string $IP
 * @property bool $InternState
 *
 * @method void RegisterParent()
 * @method void RegisterProfileFloat(string $Name, string $Icon, string $Prefix, string $Suffix, float $MinValue, float $MaxValue, float $StepSize, int $Digits)
 * @method void UnregisterProfile()
 * @method void SetValueBoolean(string $Ident, bool $value)
 * @method void SetValueFloat(string $Ident, float $value)
 * @method void SetValueInteger(string $Ident, int $value)
 * @method void SetValueString(string $Ident, string $value)
 * @method bool SendDebug(string $Message, mixed $Data, int $Format)
 */
class GHomaPlug extends IPSModule
{
    use \GHoma\BufferHelper,
        \GHoma\DebugHelper,
        \GHoma\InstanceStatus,
        \GHoma\VariableHelper,
        \GHoma\VariableProfileHelper {
        \GHoma\InstanceStatus::MessageSink as IOMessageSink;
        \GHoma\InstanceStatus::RequestAction as IORequestAction;
    }
    /**
     * Interne Funktion des SDK.
     */
    public function Create()
    {
        parent::Create();
        // $this->RequireParent("{3CFF0FD9-E306-41DB-9B5A-9D06D38576C3}");
        // Funktioniert nicht, alle Devices sollen an einem
        // ServerSocket hängen, welcher auf Port 4196 empfängt.
        $DeviceIP = '';
        $this->ParentID = 0;
        // Beim Update des Module -> Symcon läuft
        if (IPS_GetKernelRunlevel() == KR_READY) {
            $ParentId = IPS_GetInstance($this->InstanceID)['ConnectionID'];
            if ($ParentId > 0) {
                // Konverter für alte Instanzen, welche ClientSockets als Parent nutzten.
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
                    @IPS_ApplyChanges($ParentId);
                }
                @IPS_ConnectInstance($this->InstanceID, $ParentId);
            }
            $this->ParentID = $ParentId;
        }
        $this->RegisterTimer('HeartbeatTimeout', 0, 'IPS_RequestAction(' . $this->InstanceID . ',"HeartbeatTimeout",true);');
        $this->RegisterPropertyString('Host', $DeviceIP);
        $this->BufferIN = '';
        $this->ConnectState = \GHoma\GHConnectState::UNKNOWN;
        $this->FullMac = '';
        $this->Port = 0;
    }

    public function Destroy()
    {
        if (!IPS_InstanceExists($this->InstanceID)) {
            $this->UnregisterProfile('GHoma.VA');
        }
        //Never delete this line!
        parent::Destroy();
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
        $this->ConnectState = \GHoma\GHConnectState::UNKNOWN;
        $this->FullMac = '';
        if ($this->Port > 0) {
            $this->SendDisconnect();
        }

        $this->RegisterVariableBoolean('STATE', 'STATE', '~Switch', 1);
        $this->EnableAction('STATE');
        $this->RegisterVariableBoolean('BUTTON', 'BUTTON', '', 2);
        $this->SetReceiveDataFilter('.*"ClientIP":"' . $this->ReadPropertyString('Host') . '".*');

        parent::ApplyChanges();

        // Anzeige IP in der INFO Spalte
        $this->SetSummary($this->ReadPropertyString('Host'));
        $this->IP = $this->ReadPropertyString('Host');
        $this->RegisterProfileFloat('GHoma.VA', '', '', ' VA', 0, 0, 0, 2);

        // Wenn Kernel nicht bereit, dann warten... KR_READY kommt ja gleich
        if (IPS_GetKernelRunlevel() != KR_READY) {
            return;
        }

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
                $this->UnregisterMessage(0, IPS_KERNELSTARTED);
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
     * Interne Funktion des SDK.
     */
    public function GetConfigurationForParent()
    {
        $Config['Port'] = 4196;
        return json_encode($Config);
    }

    //################# PUBLIC
    /**
     * IPS-Instanz Funktion GHOMA_SendSwitch.
     * Schaltet den Aktor ein oder aus.
     *
     * @param bool $State true für ein, false für aus.
     *
     * @return bool True wenn Befehl erfolgreich ausgeführt wurde, sonst false.
     */
    public function SendSwitch(bool $State): bool
    {
        if ($this->ConnectState != \GHoma\GHConnectState::CONNECTED) {
            trigger_error($this->Translate('Device not connected'), E_USER_WARNING);
            return false;
        }
        $Message = new \GHoma\GHMessage(
                \GHoma\GHMessage::CMD_SWITCH, "\x01\x01\x0a\xe0" .
                $this->TriggerCode .
                $this->ShortMac .
                "\xff\xfe\x00\x00\x10\x11\x00\x00\x01\x00\x00\x00" . ($State ? "\xff" : "\x00"));

        $this->Send($Message);

        $Result = $this->WaitForSwitch($State);
        if (!$Result) {
            trigger_error($this->Translate('Device not response'), E_USER_WARNING);
        }
        return $Result;
    }

    //################# ActionHandler
    /**
     * Interne Funktion des SDK.
     */
    public function RequestAction($Ident, $Value)
    {
        if ($this->IORequestAction($Ident, $Value)) {
            return;
        }
        switch ($Ident) {
            case 'STATE':
                $this->SendSwitch((bool) $Value);
                break;
                case 'HeartbeatTimeout':
                    $this->ConnectState = \GHoma\GHConnectState::UNKNOWN;
                    $this->SendDebug('Error', 'HeartbeatTimeout', 0);
                    $this->LogMessage($this->Translate('Device is disconnected from Symcon'), KL_WARNING);
                    $this->SetTimerInterval('HeartbeatTimeout', 0);
                    $this->SendDisconnect();
                    $this->SetStatus(IS_EBASE + 3);
                    break;
            default:
                echo $this->Translate('Invalid Ident');
                break;
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
                $this->Port = $data->ClientPort;
                $this->SendDebug('Connected', 'Port:' . $data->ClientPort, 0);
                $this->LogMessage($this->Translate('Device will connect to Symcon'), KL_NOTIFY);
                $this->SendInit();
                return '';
            case 2: /* Disconnected */
                $this->ConnectState = \GHoma\GHConnectState::UNKNOWN;
                $this->SendDebug('Error', 'Disconnect Port:' . $data->ClientPort, 0);
                $this->LogMessage($this->Translate('Device is disconnected from Symcon'), KL_WARNING);
                $this->SetTimerInterval('HeartbeatTimeout', 0);
                $this->SetStatus(IS_EBASE + 3);
                return '';
        }
        // Datenstrom zusammenfügen
        $head = $this->BufferIN;
        $Data = $head . utf8_decode($data->Buffer);
        // Stream in einzelne Pakete schneiden
        $Lines = explode(\GHoma\GHMessage::POSTFIX, $Data);
        $tail = array_pop($Lines);
        $this->BufferIN = $tail;
        foreach ($Lines as $Line) {
            $Start = strpos($Line, \GHoma\GHMessage::PREFIX);
            if ($Start === false) {
                $this->SendDebug('Receive invalid line', $Line, 1);
                $this->SendDebug('PREFIX error', strpos($Line, \GHoma\GHMessage::PREFIX), 0);
                continue;
            }
            $Line = substr($Line, $Start + 2);
            $Len = unpack('n', substr($Line, 0, 2))[1];
            $Checksum = ord($Line[strlen($Line) - 1]);
            $Payload = substr($Line, 2, -1);
            if ($Len != strlen($Payload)) {
                $this->SendDebug('Got invalid frame', \GHoma\GHMessage::PREFIX . $Line, 0);
                continue;
            }
            $sum = 0;
            for ($i = 0; $i < $Len; $i++) {
                $sum += ord($Payload[$i]);
            }
            $checksumcalc = 0xFF - ($sum & 255);

            if ($Checksum != $checksumcalc) {
                $this->SendDebug('Wrong CRC', $Payload, 0);
                continue;
            }
            $GHMessage = new \GHoma\GHMessage(ord($Payload[0]), substr($Payload, 1));
            $this->SendDebug('Receive', $GHMessage, 0);
            $this->Decode($GHMessage);
        }
        return '';
    }

    /**
     * Wird über den Trait InstanceStatus ausgeführt wenn sich der Status des Parent ändert.
     * Oder wenn sich die Zuordnung zum Parent ändert.
     *
     * @param int $State Der neue Status des Parent.
     */
    protected function IOChangeState(int $State): void
    {
        $this->ConnectState = \GHoma\GHConnectState::UNKNOWN;
        if ($State == IS_ACTIVE) { // Wenn der IO Aktiv wurde
            if (trim($this->IP) == '') {
                $this->SetStatus(IS_INACTIVE);
                $this->SetTimerInterval('HeartbeatTimeout', 0);
            } else {
                // Watchdog Timer für Reconnect
                $this->SendDebug('IO opend', 'ready to connect from device', 0);
                $this->SetTimerInterval('HeartbeatTimeout', 44 * 1000);
            }
        } else { // wenn IO inaktiv
            $this->SetStatus(IS_INACTIVE);
            $this->SendDebug('IO NOT opend', 'no connection possible', 0);
            $this->SetTimerInterval('HeartbeatTimeout', 0);
        }
    }
    //################# PRIVATE
    private function Send(\GHoma\GHMessage $Message): void
    {
        $this->SendDebug('Send', $Message, 0);
        $this->SendDataToParent(
            json_encode(
                [
                    'DataID'     => '{C8792760-65CF-4C53-B5C7-A30FCC84FEFE}',
                    'ClientIP'   => $this->IP,
                    'ClientPort' => $this->Port,
                    'Type'       => 0,
                    'Buffer'     => utf8_encode(
                            $Message->toFrame()
                        )
                ]
            )
        );
    }
    private function SendDisconnect(): void
    {
        $this->SendDebug('Send Disconnect', '', 0);
        @$this->SendDataToParent(
            json_encode(
                [
                    'DataID'     => '{C8792760-65CF-4C53-B5C7-A30FCC84FEFE}',
                    'ClientIP'   => $this->IP,
                    'ClientPort' => $this->Port,
                    'Type'       => 2,
                    'Buffer'     => ''
                ]
            )
        );
    }

    /**
     * Sendet die Initialisierung an den Controller und prüft die Rückmeldung.
     *
     * @throws Exception Wenn kein aktiver Parent verbunden ist.
     *
     * @return bool True bei Erfolg, sonst false.
     */
    private function SendInit(): void
    {
        $this->ConnectState = \GHoma\GHConnectState::UNKNOWN;
        $Message = new \GHoma\GHMessage(
                 \GHoma\GHMessage::CMD_INIT1,
                 \GHoma\GHMessage::INIT1
                );
        $this->Send($Message);
    }

    /**
     * Warte auf eine Änderung der internen Statusvariable 'STATE'.
     *
     * @param bool $State Der Status auf welchen gewartet wird.
     *
     * @return bool True wenn das Event eintrifft, false wenn Timeout erreicht wurde.
     */
    private function WaitForSwitch(bool $State): bool
    {
        for ($i = 0; $i < 1000; $i++) {
            if ($this->InternState === $State) {
                return true;
            } else {
                IPS_Sleep(5);
            }
        }
        return false;
    }

    private function Decode(\GHoma\GHMessage $Message): void
    {
        $this->SetTimerInterval('HeartbeatTimeout', 0);
        switch ($Message->Command) {
            case \GHoma\GHMessage::CMD_HEARTBEAT:
                $this->ShortMac = substr($Message->Payload, 5, 3);
                $this->TriggerCode = substr($Message->Payload, 3, 2);
               /* if ($this->ConnectState != \GHoma\GHConnectState::CONNECTED) {
                    $this->SetStatus(IS_ACTIVE);
                    $this->ConnectState = \GHoma\GHConnectState::CONNECTED;
                }*/
                $Message = new \GHoma\GHMessage(\GHoma\GHMessage::CMD_HEARTBEATREPLY, '');
                $this->Send($Message);
                break;
            case \GHoma\GHMessage::CMD_INIT1REPLY:
                $this->ShortMac = substr($Message->Payload, 5, 3);
                $this->TriggerCode = substr($Message->Payload, 3, 2);
                $Message = new \GHoma\GHMessage(
                        \GHoma\GHMessage::CMD_INIT2,
                        \GHoma\GHMessage::INIT2
                    );
                $this->Send($Message);
                break;

            case \GHoma\GHMessage::CMD_INIT2REPLY:
                if ($Message->Payload[9] == chr(1)) {
                    $this->FullMac = substr($Message->Payload, 11, 6);
                }
                $this->SetStatus(IS_ACTIVE);
                $this->ConnectState = \GHoma\GHConnectState::CONNECTED;
                break;

            case \GHoma\GHMessage::CMD_STATUS:
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
                        $this->InternState = ($Message->Payload[19] === chr(0xff));
                        $this->SetValueBoolean('STATE', ($Message->Payload[19] === chr(0xff)));
                        $this->SetValueBoolean('BUTTON', ($Message->Payload[11] === chr(0x81)));
                        break;
                    case 0x31:
                        $this->RegisterVariableBoolean('ALARM', 'ALARM', '~Alert');
                        $this->SetValueBoolean('ALARM', ($Message->Payload[15] === chr(0x02)));
                        break;
                    case 0x39: // MESSUNG
                        $Part = "\x00" . substr($Message->Payload, 18, 3);
                        $Value = unpack('N', $Part)[1];
                        switch (ord($Message->Payload[16])) {
                            case 0x01: // Watt
                                $this->RegisterVariableFloat('Power', $this->Translate('Power'), 'Watt.14490');
                                $this->SetValueFloat('Power', $Value / 100);
                                break;
                            case 0x02: // Verbrauch
                                $this->RegisterVariableFloat('Consumption', $this->Translate('Consumption'), 'Electricity');
                                $this->SetValueFloat('Consumption', $Value / 1000);
                                break;
                            case 0x03: // Volt
                                $this->RegisterVariableFloat('Voltage', $this->Translate('Voltage'), 'Volt.230');
                                $this->SetValueFloat('Voltage', $Value / 100);
                                break;
                            case 0x04: // Ampere
                                $this->RegisterVariableFloat('Current', $this->Translate('Current'), 'Ampere');
                                $this->SetValueFloat('Current', $Value / 100);
                                break;
                            case 0x05: // Hertz
                                $this->RegisterVariableFloat('Frequenz', $this->Translate('Frequenz'), 'Hertz.50');
                                $this->SetValueFloat('Frequenz', $Value / 100);
                                break;
                            case 0x07: // Scheinleistung
                                $this->RegisterVariableFloat('Output', $this->Translate('Output'), 'GHoma.VA');
                                $this->SetValueFloat('Output', $Value / 100);
                                break;
                            case 0x08: // Leistungsfaktor
                                $this->RegisterVariableFloat('PowerFactor', $this->Translate('Powerfactor'), '');
                                $this->SetValueFloat('PowerFactor', $Value / 100);
                                break;
                        }
                        break;
                }
                break;

            // FE Command ?
            // FE
            // 01 0A C0 35 23 D3 3D 02 00 00 00 0F
        }
        $this->SetTimerInterval('HeartbeatTimeout', 44 * 1000);
    }
}

/* @} */
