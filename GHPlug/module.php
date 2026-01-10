<?php

declare(strict_types=1);
/**
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
 * @property string $FullMac MAC
 * @property string $ShortMac MAC
 * @property string $TriggerCode
 * @property int $Port
 * @property string $IP
 * @property bool $InternState
 *
 * @method void RegisterParent()
 * @method void UnregisterProfile(string $Profile)
 * @method void SetValueBoolean(string $Ident, bool $value)
 * @method void SetValueFloat(string $Ident, float $value)
 * @method void SetValueInteger(string $Ident, int $value)
 * @method void SetValueString(string $Ident, string $value)
 * @method bool SendDebug(string $Message, mixed $Data, int $Format)
 * @method bool IORequestAction(string $Ident, mixed $Value)
 * @method void IOMessageSink(int $TimeStamp, int $SenderID, int $Message, array $Data)
 */
class GHomaPlug extends IPSModuleStrict
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
    public function Create(): void
    {
        parent::Create();
        $DeviceIP = '';
        $this->ParentID = 0;
        $this->RegisterTimer(\GHoma\Device\Timer::HeartbeatTimeout, 0, 'IPS_RequestAction(' . $this->InstanceID . ',"' . \GHoma\Device\Timer::HeartbeatTimeout . '",true);');
        $this->RegisterPropertyString(\GHoma\Device\Property::Host, $DeviceIP);
        $this->BufferIN = '';
        $this->FullMac = '';
        $this->Port = 0;
    }

    /**
     * GetCompatibleParents
     *
     * @return string
     */
    public function GetCompatibleParents(): string
    {
        return json_encode([
            'type'     => 'require',
            'moduleIDs'=> [
                \GHoma\GUID::ServerSocket
            ]
        ]);
    }

    /**
     * Migrate
     * @param  string $JSONData
     * @return string
     */
    public function Migrate(string $JSONData): string
    {
        $Data = json_decode($JSONData);
        if (!property_exists($Data->configuration, \GHoma\Device\Property::Host)) {
            $Data->configuration->Host = '';
            // Instanz ohne Eigenschaft Host waren noch per ClientSocket angebunden.
            if (IPS_GetKernelRunlevel() == KR_READY) {
                $ParentId = IPS_GetInstance($this->InstanceID)['ConnectionID'];
                if ($ParentId > 0) {
                    // Konverter für alte Instanzen, welche ClientSockets als Parent nutzten.
                    if (IPS_GetInstance($ParentId)['ModuleInfo']['ModuleID'] != \GHoma\GUID::ServerSocket) {
                        // IP vom CS in unsere Config übernehmen
                        $Data->configuration->Host = IPS_GetProperty($ParentId, \GHoma\Device\Property::Host);
                        // Status merken
                        $Open = IPS_GetProperty($ParentId, 'Open');
                        //Trennen und
                        @IPS_DisconnectInstance($this->InstanceID);
                        $deleteCS = true;
                        foreach (IPS_GetInstanceList() as $Instance) {
                            if (IPS_GetInstance($Instance)['ConnectionID'] == $ParentId) {
                                // Wenn noch eine Instanz mit dem ClientSocket verbunden ist; nicht löschen.
                                $deleteCS = false;
                            }
                        }
                        if ($deleteCS) {
                            // löschen
                            @IPS_DeleteInstance($ParentId);
                        }
                        // passenden ServerSocket mit Port suchen
                        $ParentId == 0;
                        foreach (IPS_GetInstanceListByModuleID(\GHoma\GUID::ServerSocket) as $Instance) {
                            if (IPS_GetProperty($Instance, 'Port') == 4196) {
                                // gefunden
                                $ParentId = $Instance;
                                break;
                            }
                        }
                        if ($ParentId == 0) {
                            // nicht gefunden, neu erstellen
                            $ParentId = @IPS_CreateInstance(\GHoma\GUID::ServerSocket);
                            IPS_SetName($ParentId, 'Server Socket (GHoma)');
                            IPS_SetProperty($ParentId, 'Port', 4196);
                            if ($Open) {
                                IPS_SetProperty($ParentId, 'Open', true);
                            }
                            @IPS_ApplyChanges($ParentId);
                        }
                        // Verbinden mit gefundenen oder neu erstellten Parent
                        @IPS_ConnectInstance($this->InstanceID, $ParentId);
                    }
                }
            }
        }
        return json_encode($Data);
    }
    /**
     * ApplyChanges
     *
     * @return void
     */
    public function ApplyChanges(): void
    {
        $this->RegisterMessage(0, IPS_KERNELSTARTED);
        $this->RegisterMessage($this->InstanceID, FM_CONNECT);
        $this->RegisterMessage($this->InstanceID, FM_DISCONNECT);
        $this->SetTimerInterval(\GHoma\Device\Timer::HeartbeatTimeout, 0);
        $this->BufferIN = '';
        $this->FullMac = '';
        if ($this->Port > 0) {
            $this->SendDisconnect();
        }

        $this->RegisterVariableBoolean(
            'STATE',
            $this->Translate('State'),
            [
                'PRESENTATION' => VARIABLE_PRESENTATION_SWITCH
            ],
            1
        );
        $this->EnableAction('STATE');
        $this->RegisterVariableBoolean(
            'BUTTON',
            'Button',
            [],
            2
        );
        $this->SetReceiveDataFilter('.*"ClientIP":"' . $this->ReadPropertyString(\GHoma\Device\Property::Host) . '".*');

        parent::ApplyChanges();

        // Anzeige IP in der INFO Spalte
        $this->SetSummary($this->ReadPropertyString(\GHoma\Device\Property::Host));
        $this->IP = $this->ReadPropertyString(\GHoma\Device\Property::Host);
        $this->UnregisterProfile('GHoma.VA');

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
     * MessageSink
     *
     * Verarbeitet alle Nachrichten auf die wir uns registriert haben.
     *
     * @param int       $TimeStamp
     * @param int       $SenderID
     * @param int       $Message
     * @param array $Data
     * @return void
     */
    public function MessageSink(int $TimeStamp, int $SenderID, int $Message, array $Data): void
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
     * GetConfigurationForParent
     *
     * @return string
     */
    public function GetConfigurationForParent(): string
    {
        $Config['Port'] = 4196;
        return json_encode($Config);
    }

    /**
     * SendSwitch
     *
     * IPS-Instanz Funktion GHOMA_SendSwitch.
     * Schaltet den Aktor ein oder aus.
     *
     * @param bool $State true für ein, false für aus.
     *
     * @return bool True wenn Befehl erfolgreich ausgeführt wurde, sonst false.
     */
    public function SendSwitch(bool $State): bool
    {
        if ($this->GetStatus() != IS_ACTIVE) {
            set_error_handler([$this, 'ModulErrorHandler']);
            trigger_error($this->Translate('Device not connected'), E_USER_WARNING);
            restore_error_handler();
            return false;
        }
        $Message = new \GHoma\GHMessage(
            \GHoma\GHMessage::CMD_SWITCH,
            "\x01\x01\x0a\xe0" .
            $this->TriggerCode .
            $this->ShortMac .
            "\xff\xfe\x00\x00\x10\x11\x00\x00\x01\x00\x00\x00" . ($State ? "\xff" : "\x00")
        );

        $this->Send($Message);

        $Result = $this->WaitForSwitch($State);
        if (!$Result) {
            set_error_handler([$this, 'ModulErrorHandler']);
            trigger_error($this->Translate('Device not response'), E_USER_WARNING);
            restore_error_handler();
        }
        return $Result;
    }

    /**
     * RequestAction
     *
     * @param  string $Ident
     * @param  mixed $Value
     * @return void
     */
    public function RequestAction(string $Ident, mixed $Value): void
    {
        if ($this->IORequestAction($Ident, $Value)) {
            return;
        }
        switch ($Ident) {
            case 'STATE':
                $this->SendSwitch((bool) $Value);
                break;
            case \GHoma\Device\Timer::HeartbeatTimeout:
                $this->SendDebug('Error', \GHoma\Device\Timer::HeartbeatTimeout, 0);
                $this->LogMessage($this->Translate('Device send no heartbeat') . ':' . $this->ReadPropertyString(\GHoma\Device\Property::Host), KL_WARNING);
                $this->SetTimerInterval(\GHoma\Device\Timer::HeartbeatTimeout, 0);
                $this->SendDisconnect();
                $this->SetStatus(IS_EBASE + 3);
                break;
            default:
                echo $this->Translate('Invalid Ident');
                break;
        }
    }

    /**
     * ReceiveData
     *
     * @param  string $JSONString
     * @return string
     */
    public function ReceiveData(string $JSONString): string
    {
        $data = json_decode($JSONString);

        switch ($data->Type) {
            case 1: /* Connected */
                $this->Port = $data->ClientPort;
                $this->SendDebug('Connected', 'Port:' . $data->ClientPort, 0);
                $this->LogMessage($this->Translate('Device will connect to Symcon') . ':' . $this->ReadPropertyString(\GHoma\Device\Property::Host), KL_NOTIFY);
                $this->SendInit();
                return '';
            case 2: /* Disconnected */
                $this->SendDebug('Error', 'Disconnect Port:' . $data->ClientPort, 0);
                $this->LogMessage($this->Translate('Device is disconnected from Symcon') . ':' . $this->ReadPropertyString(\GHoma\Device\Property::Host), KL_WARNING);
                $this->SetTimerInterval(\GHoma\Device\Timer::HeartbeatTimeout, 0);
                $this->SetStatus(IS_EBASE + 3);
                return '';
        }
        // Datenstrom zusammenfügen
        $head = $this->BufferIN;
        $Data = $head . hex2bin($data->Buffer);
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
            $this->SendDebug('Receive Command', $GHMessage->Command, 0);
            $this->SendDebug('Receive Payload', $GHMessage->Payload, 1);
            $this->Decode($GHMessage);
        }
        return '';
    }

    /**
     * IOChangeState
     *
     * Wird über den Trait InstanceStatus ausgeführt wenn sich der Status des Parent ändert.
     * Oder wenn sich die Zuordnung zum Parent ändert.
     *
     * @param int $State Der neue Status des Parent.
     */
    protected function IOChangeState(int $State): void
    {
        if ($State == IS_ACTIVE) { // Wenn der IO Aktiv wurde
            if (trim($this->IP) == '') {
                $this->SetStatus(IS_INACTIVE);
                $this->SetTimerInterval(\GHoma\Device\Timer::HeartbeatTimeout, 0);
            } else {
                // Watchdog Timer für Reconnect
                $this->SendDebug('IO active', 'ready to connect from device', 0);
                $this->SetTimerInterval(\GHoma\Device\Timer::HeartbeatTimeout, 60 * 1000);
            }
        } else { // wenn IO inaktiv
            $this->SetStatus(IS_INACTIVE);
            $this->SendDebug('IO NOT active', 'no connection possible', 0);
            $this->SetTimerInterval(\GHoma\Device\Timer::HeartbeatTimeout, 0);
        }
    }

    /**
     * Send
     *
     * @param  \GHoma\GHMessage $Message
     * @return void
     */
    private function Send(\GHoma\GHMessage $Message): void
    {
        $this->SendDebug('Send Command', $Message->Command, 0);
        $this->SendDebug('Send Payload', $Message->Payload, 1);
        $this->SendDataToParent(
            json_encode(
                [
                    'DataID'     => \GHoma\GUID::SendToSocket,
                    'ClientIP'   => $this->IP,
                    'ClientPort' => $this->Port,
                    'Type'       => 0,
                    'Buffer'     => bin2hex(
                        $Message->toFrame()
                    )
                ]
            )
        );
    }

    /**
     * SendDisconnect
     *
     * @return void
     */
    private function SendDisconnect(): void
    {
        $this->SendDebug('Send Disconnect', '', 0);
        @$this->SendDataToParent(
            json_encode(
                [
                    'DataID'     => GHoma\GUID::SendToSocket,
                    'ClientIP'   => $this->IP,
                    'ClientPort' => $this->Port,
                    'Type'       => 2,
                    'Buffer'     => ''
                ]
            )
        );
    }

    /**
     * SendInit
     *
     * Sendet die Initialisierung an den Controller und prüft die Rückmeldung.
     *
     * @throws Exception Wenn kein aktiver Parent verbunden ist.
     *
     * @return void
     */
    private function SendInit(): void
    {
        $Message = new \GHoma\GHMessage(
            \GHoma\GHMessage::CMD_INIT1,
            \GHoma\GHMessage::INIT1
        );
        $this->Send($Message);
    }

    /**
     * WaitForSwitch
     *
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

    /**
     * Decode
     *
     * @param  \GHoma\GHMessage $Message
     * @return void
     */
    private function Decode(\GHoma\GHMessage $Message): void
    {
        $this->SetTimerInterval(\GHoma\Device\Timer::HeartbeatTimeout, 0);
        switch ($Message->Command) {
            case \GHoma\GHMessage::CMD_HEARTBEAT:
                $this->ShortMac = substr($Message->Payload, 5, 3);
                $this->TriggerCode = substr($Message->Payload, 3, 2);
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
                        $this->RegisterVariableBoolean(
                            'ALARM',
                            $this->Translate('Alert'),
                            [
                                'MIN'              => 0,
                                'DIGITS'           => 0,
                                'MULTILINE'        => false,
                                'ICON'             => 'Warning',
                                'INTERVALS_ACTIVE' => true,
                                'MAX'              => 1,
                                'PRESENTATION'     => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
                                'PERCENTAGE'       => false,
                                'OPTIONS'          => json_encode(
                                    [
                                        [
                                            'Value'       => false,
                                            'Caption'     => 'OK',
                                            'IconActive'  => false,
                                            'IconValue'   => '',
                                            'ColorActive' => true,
                                            'ColorValue'  => -1,
                                        ],
                                        [
                                            'Value'       => true,
                                            'Caption'     => $this->Translate('Alert'),
                                            'IconActive'  => false,
                                            'IconValue'   => '',
                                            'ColorActive' => true,
                                            'ColorValue'  => 16711680,
                                        ]

                                    ]
                                ),
                                'PREFIX'     => '',
                                'SUFFIX'     => '',
                                'USAGE_TYPE' => 0,
                            ]
                        );
                        $this->SetValueBoolean('ALARM', ($Message->Payload[15] === chr(0x02)));
                        break;
                    case 0x39: // MESSUNG
                        $Part = "\x00" . substr($Message->Payload, 18, 3);
                        $Value = unpack('N', $Part)[1];
                        switch (ord($Message->Payload[16])) {
                            case 0x01: // Watt
                                $this->RegisterVariableFloat(
                                    'Power',
                                    $this->Translate('Power'),
                                    [
                                        'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
                                        'TEMPLATE'     => VARIABLE_TEMPLATE_VALUE_PRESENTATION_POWER,
                                    ],
                                    3
                                );
                                $this->SetValueFloat('Power', $Value / 100);
                                break;
                            case 0x02: // Verbrauch
                                $this->RegisterVariableFloat(
                                    'Consumption',
                                    $this->Translate('Consumption'),
                                    [
                                        'ICON'                => 'bolt',
                                        'DECIMAL_SEPARATOR'   => 'Client',
                                        'COLOR'               => -1,
                                        'DISPLAY_TYPE'        => 0,
                                        'CONTENT_COLOR'       => -1,
                                        'DIGITS'              => 2,
                                        'INTERVALS'           => json_encode([
                                            [
                                                'ColorDisplay'        => -1,
                                                'ContentColorDisplay' => -1,
                                                'IntervalMinValue'    => 0.001,
                                                'IntervalMaxValue'    => 1,
                                                'ConstantActive'      => false,
                                                'ConstantValue'       => '',
                                                'ConversionFactor'    => 0.001,
                                                'PrefixActive'        => false,
                                                'PrefixValue'         => '',
                                                'SuffixActive'        => true,
                                                'SuffixValue'         => ' Wh',
                                                'DigitsActive'        => false,
                                                'DigitsValue'         => 2,
                                                'IconActive'          => false,
                                                'IconValue'           => '',
                                                'ColorActive'         => false,
                                                'ColorValue'          => -1,
                                                'ContentColorActive'  => false,
                                                'ContentColorValue'   => -1,
                                            ],
                                            [
                                                'ColorDisplay'        => -1,
                                                'ContentColorDisplay' => -1,
                                                'IntervalMinValue'    => 1000,
                                                'IntervalMaxValue'    => 999999,
                                                'ConstantActive'      => false,
                                                'ConstantValue'       => '',
                                                'ConversionFactor'    => 1000,
                                                'PrefixActive'        => false,
                                                'PrefixValue'         => '',
                                                'SuffixActive'        => true,
                                                'SuffixValue'         => 'MWh',
                                                'DigitsActive'        => true,
                                                'DigitsValue'         => 2,
                                                'IconActive'          => false,
                                                'IconValue'           => '',
                                                'ColorActive'         => false,
                                                'ColorValue'          => -1,
                                                'ContentColorActive'  => false,
                                                'ContentColorValue'   => -1,
                                            ],
                                        ]),
                                        'INTERVALS_ACTIVE'    => true,
                                        'MAX'                 => 0,
                                        'PRESENTATION'        => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
                                        'MIN'                 => 0,
                                        'PERCENTAGE'          => false,
                                        'PREFIX'              => '',
                                        'PREVIEW_STYLE'       => 1,
                                        'SHOW_PREVIEW'        => true,
                                        'SUFFIX'              => ' kWh',
                                        'THOUSANDS_SEPARATOR' => '',
                                        'USAGE_TYPE'          => 0,
                                    ],
                                    4
                                );
                                $this->SetValueFloat('Consumption', $Value / 1000);
                                break;
                            case 0x03: // Volt
                                $this->RegisterVariableFloat(
                                    'Voltage',
                                    $this->Translate('Voltage'),
                                    [
                                        'MIN'              => 207,
                                        'DIGITS'           => 1,
                                        'MULTILINE'        => false,
                                        'ICON'             => 'Electricity',
                                        'MAX'              => 253,
                                        'PRESENTATION'     => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
                                        'INTERVALS'        => '[]',
                                        'INTERVALS_ACTIVE' => false,
                                        'PERCENTAGE'       => false,
                                        'PREFIX'           => '',
                                        'SUFFIX'           => ' V',
                                        'USAGE_TYPE'       => 0
                                    ],
                                    5
                                );
                                $this->SetValueFloat('Voltage', $Value / 100);
                                break;
                            case 0x04: // Ampere
                                $this->RegisterVariableFloat(
                                    'Current',
                                    $this->Translate('Current'),
                                    [
                                        'ICON'                => 'Electricity',
                                        'DECIMAL_SEPARATOR'   => 'Client',
                                        'COLOR'               => -1,
                                        'DISPLAY_TYPE'        => 0,
                                        'CONTENT_COLOR'       => -1,
                                        'DIGITS'              => 2,
                                        'INTERVALS'           => '[]',
                                        'INTERVALS_ACTIVE'    => false,
                                        'MAX'                 => 0,
                                        'PRESENTATION'        => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
                                        'MIN'                 => 0,
                                        'PERCENTAGE'          => false,
                                        'PREFIX'              => '',
                                        'PREVIEW_STYLE'       => 1,
                                        'SHOW_PREVIEW'        => true,
                                        'SUFFIX'              => ' A',
                                        'THOUSANDS_SEPARATOR' => '',
                                        'USAGE_TYPE'          => 0
                                    ],
                                    6
                                );
                                $this->SetValueFloat('Current', $Value / 100);
                                break;
                            case 0x05: // Hertz
                                $this->RegisterVariableFloat(
                                    'Frequenz',
                                    $this->Translate('Frequenz'),
                                    [
                                        'MIN'              => 0,
                                        'DIGITS'           => 2,
                                        'MULTILINE'        => false,
                                        'ICON'             => 'Electricity',
                                        'MAX'              => 0,
                                        'PRESENTATION'     => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
                                        'INTERVALS'        => '[]',
                                        'INTERVALS_ACTIVE' => false,
                                        'PERCENTAGE'       => false,
                                        'PREFIX'           => '',
                                        'SUFFIX'           => ' Hz',
                                        'USAGE_TYPE'       => 0,
                                    ],
                                    7
                                );
                                $this->SetValueFloat('Frequenz', $Value / 100);
                                break;
                            case 0x07: // Scheinleistung
                                $this->RegisterVariableFloat(
                                    'Output',
                                    $this->Translate('Output'),
                                    [
                                        'MIN'              => 0,
                                        'DIGITS'           => 2,
                                        'MULTILINE'        => false,
                                        'ICON'             => '',
                                        'MAX'              => 0,
                                        'PRESENTATION'     => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
                                        'INTERVALS'        => '[]',
                                        'INTERVALS_ACTIVE' => false,
                                        'PERCENTAGE'       => false,
                                        'PREFIX'           => '',
                                        'SUFFIX'           => ' VA',
                                        'USAGE_TYPE'       => 0
                                    ],
                                    8
                                );
                                $this->SetValueFloat('Output', $Value / 100);
                                break;
                            case 0x08: // Leistungsfaktor
                                $this->RegisterVariableFloat(
                                    'PowerFactor',
                                    $this->Translate('Power factor'),
                                    [],
                                    9
                                );
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
        $this->SetTimerInterval(\GHoma\Device\Timer::HeartbeatTimeout, 60 * 1000);
    }

    /**
     * ModulErrorHandler
     *
     * @param  int $errno
     * @param  string $errstr
     * @return bool
     */
    private function ModulErrorHandler(int $errno, string $errstr): bool
    {
        echo $errstr . PHP_EOL;
        return true;
    }
}
