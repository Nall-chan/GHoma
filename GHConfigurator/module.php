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
 * GHomaConfigurator ist die Klasse für einen Konfigurator in IPS für die WLAN Steckdosen der Firma G-Homa.
 * Erweitert ipsmodule.
 *
 * @property array $DevicesIP
 */
class GHomaConfigurator extends ipsmodule
{
    use BufferHelper,
        DebugHelper,
        InstanceStatus {
        InstanceStatus::MessageSink as IOMessageSink;
    }
    /**
     * Ein UDP-Socket.
     *
     * @var resource
     */
    private $UDPSocket = false;

    /**
     * Interne Funktion des SDK.
     */
    public function __destruct()
    {
        if ($this->UDPSocket != false) {
            fclose($this->UDPSocket);
        }
    }

    /**
     * Interne Funktion des SDK.
     */
    public function Create()
    {
        parent::Create();
        $this->RequireParent('{BAB408E0-0A0F-48C3-B14E-9FB2FA81F66A}');
        $this->DevicesIP = [];
    }

    /**
     * Interne Funktion des SDK.
     */
    public function ApplyChanges()
    {
        $this->RegisterMessage(0, IPS_KERNELSTARTED);
        $this->RegisterMessage($this->InstanceID, FM_CONNECT);
        $this->RegisterMessage($this->InstanceID, FM_DISCONNECT);

        parent::ApplyChanges();
        $this->DevicesIP = [];

        if (IPS_GetKernelRunlevel() != KR_READY) {
            return;
        }
        $this->RegisterParent();

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
        if ($State == IS_ACTIVE) {
            $this->SendSearchBroadcast();
            $this->SetStatus(IS_ACTIVE);
        } else {
            $this->SetStatus(IS_INACTIVE);
        }
    }

    /**
     * Interne Funktion des SDK.
     */
    public function GetConfigurationForParent()
    {
        $Config['Port'] = 48899; //Port wo wir hin senden
        $Config['Host'] = '255.255.255.255'; //Sende IP
        $Config['MulticastIP'] = '224.0.0.50'; // ohne Funktion
        $Config['BindPort'] = 48899; //Wir senden auf dem gleichen Port
        $Config['BindIP'] = '0.0.0.0'; // Auf allen Schnittstellen
        $Config['EnableBroadcast'] = true;
        $Config['EnableReuseAddress'] = true;
        $Config['EnableLoopback'] = false;
        return json_encode($Config);
    }

    /**
     * Interne Funktion des SDK.
     */
    public function GetConfigurationForm()
    {
        $Devices = $this->DevicesIP;
        if (count($Devices) == 0) {
            @$this->SendSearchBroadcast();
            IPS_Sleep(2000);
            $Devices = $this->DevicesIP;
        }
        $Total = count($Devices);
        $Liste = [];
        $DisconnectedPlugs = 0;
        $NewPlugs = 0;
        $this->SendDebug('Found', $Devices, 0);
        $InstanceIDListe = IPS_GetInstanceListByModuleID('{5F0CF4B0-7395-4ABF-B10F-AA0109A0F016}');
        foreach ($InstanceIDListe as $InstanceID) {
            $PlugIP = IPS_GetProperty($InstanceID, 'Host');
            $Plug = [
                'InstanceID' => $InstanceID,
                'PlugIP'     => $PlugIP,
                'PlugMAC'    => $this->Translate('unknow'),
                'PlugType'   => $this->Translate('unknow'),
                'Name'       => IPS_GetName($InstanceID)
            ];
            $FoundIndex = array_key_exists($PlugIP, $Devices);
            if ($FoundIndex === false) {
                $Plug['rowColor'] = '#ff0000';
                $DisconnectedPlugs++;
            } else {
                $Plug['PlugMAC'] = $Devices[$PlugIP][0];
                $Plug['PlugType'] = $Devices[$PlugIP][1];
                $Plug['rowColor'] = '#00ff00';
                unset($Devices[$PlugIP]);
            }

            $Liste[] = $Plug;
        }
        foreach ($Devices as $PlugIP => $PlugData) {
            $Plug = [
                'InstanceID' => 0,
                'PlugIP'     => $PlugIP,
                'PlugMAC'    => $PlugData[0],
                'PlugType'   => $PlugData[1],
                'Name'       => ''
            ];
            $Liste[] = $Plug;
            $NewPlugs++;
        }

        $data = json_decode(file_get_contents(__DIR__ . '/form.json'), true);
        if ($Total > 0) {
            $data['actions'][2]['label'] = sprintf($this->Translate('Plugs found: %d'), $Total);
        }
        if ($NewPlugs > 0) {
            $data['actions'][3]['label'] = sprintf($this->Translate('New plugs: %d'), $NewPlugs);
        }
        if ($DisconnectedPlugs > 0) {
            $data['actions'][4]['label'] = sprintf($this->Translate('Disconnected plugs: %d'), $DisconnectedPlugs);
        }
        $data['actions'][6]['values'] = array_merge($data['actions'][6]['values'], $Liste);

        $data['actions'][0]['onClick'] = '
    GHOMA_SendSearchBroadcast($id);
    echo "' . $this->Translate('Please close this form, and reopen it!') . '";
    ';

        $data['actions'][7]['onClick'] = '
    if (($Plugs["PlugIP"] == "") or ($Plugs["InstanceID"] > 0))
    {
        echo "' . $this->Translate('No plug selected!') . '";
        return;
    }
    $InstanceID = IPS_CreateInstance("{5F0CF4B0-7395-4ABF-B10F-AA0109A0F016}");
    if ($InstanceID == false) return;
    $ParentID = IPS_GetInstance($InstanceID)["ConnectionID"];
    if ($ParentID == 0)
    {
        echo "' . $this->Translate('Error on create instance.') . '";
        return;
    }
    @IPS_SetProperty($ParentID, "Host", $Plugs["PlugIP"]);
    @IPS_SetProperty($ParentID, "Open", true);
    @IPS_ApplyChanges($ParentID);
    IPS_SetName($InstanceID,"G-Home Plug - ".$Plugs["PlugIP"]);
    echo "OK";
    ';

        $data['actions'][8]['onClick'] = '
    if ($Plugs["PlugIP"] == "")
    {
        echo "' . $this->Translate('No plug selected!') . '";
        return;
    }
    print_r(GHOMA_GetDeviceData($id, $Plugs["PlugIP"]));
    ';

        $data['actions'][10]['onClick'] = '
    if ($Plugs["PlugIP"] == "")
    {
        echo "' . $this->Translate('No plug selected!') . '";
        return;
    }
    if ($Host == "")
    {
        echo "' . $this->Translate('No IPS Host given!') . '";
        return;
    }
    $result = GHOMA_WriteDeviceData($id,$Plugs["PlugIP"],"NETP","TCP,Client,4196,$Host");
    if ($result === true)
        echo "OK";
    else
        echo "' . $this->Translate('Error on reconfigure plug.') . '";
    ';

        $data['actions'][16]['onClick'] = '
    if ($Plugs["PlugIP"] == "")
    {
        echo "' . $this->Translate('No plug selected!') . '";
        return;
    }
    $Data[]= ($DHCP ? "DHCP" : "STATIC");
    $Data[]= $IPAddress;
    $Data[]= $Subnet;
    $Data[]= $Gateway;
    $result = GHOMA_WriteDeviceData($id,$Plugs["PlugIP"],"WANN", implode(",",$Data));
    if ($result === true)
        echo "OK";
    else
        echo "' . $this->Translate('Error on reconfigure plug.') . '";
    ';

        $data['actions'][19]['onClick'] = '
    if ($Plugs["PlugIP"] == "")
    {
        echo "' . $this->Translate('No plug selected!') . '";
        return;
    }
    $result = GHOMA_WriteDeviceData($id,$Plugs["PlugIP"],"WSDNS", $DNS);
    if ($result === true)
        echo "OK";
    else
        echo "' . $this->Translate('Error on reconfigure plug.') . '";
    ';

        $data['actions'][22]['onClick'] = '
    if ($Plugs["PlugIP"] == "")
    {
        echo "' . $this->Translate('No plug selected!') . '";
        return;
    }
    $result = GHOMA_WriteDeviceData($id,$Plugs["PlugIP"],"NTPSER", $NTP);
    if ($result === true)
        echo "OK";
    else
        echo "' . $this->Translate('Error on reconfigure plug.') . '";
    ';

        $data['actions'][26]['onClick'] = '
    if ($Plugs["PlugIP"] == "")
    {
        echo "' . $this->Translate('No plug selected!') . '";
        return;
    }
    if ($Host == "")
    {
        echo "' . $this->Translate('No IPS Host given!') . '";
        return;
    }
    $result = GHOMA_WriteDeviceData($id,$Plugs["PlugIP"],"WSSSID", $WSSSID);
    $Data[]= "WPA2PSK";
    $Data[]= "AES";
    $Data[]= $WSKEY;
    $result = $result && GHOMA_WriteDeviceData($id,$Plugs["PlugIP"],"WSKEY", implode(",",$Data));
    $result = $result && GHOMA_WriteDeviceData($id,$Plugs["PlugIP"],"WMODE","STA");
    $result = $result && GHOMA_WriteDeviceData($id,$Plugs["PlugIP"],"NETP","TCP,Client,4196,$Host");
    $result = $result && GHOMA_SendDeviceReset($id,$Plugs["PlugIP"]);
    if ($result === true)
        echo "OK";
    else
        echo "' . $this->Translate('Error on reconfigure plug.') . '";
    ';
        return json_encode($data);
    }

    private function DeviceLogin(string $Host)
    {
        $this->SendDebug('Login:' . $Host, '', 0);

        $this->UDPSocket = fsockopen('udp://' . $Host, 48899, $errno, $errstr, 2);
        if (!$this->UDPSocket) {
            trigger_error("ERROR: $errno - $errstr", E_USER_NOTICE);
            $this->SendDebug('Error:' . $Host, $errno . ' - ' . $errstr, 0);
            return false;
        }
        fwrite($this->UDPSocket, 'HF-A11ASSISTHREAD');
        fread($this->UDPSocket, 100);
        fwrite($this->UDPSocket, '+ok');        // Reply to first received data
        fwrite($this->UDPSocket, "AT+VER\r\n");   // Request the version
        $ResultHardware = $this->ReadAnswer($this->UDPSocket);
        $DeviceData['Vendor'] = $ResultHardware[0];
        $DeviceData['Typ'] = $ResultHardware[1];
        $DeviceData['HW'] = $ResultHardware[2];
        return $DeviceData;
    }

    public function GetDeviceData(string $Host)
    {
        $DeviceData = @$this->DeviceLogin($Host);
        if ($DeviceData == false) {
            echo $this->Translate('Error on Read Data');
            return;
        }
        $ResultFirmware = $this->ReadDeviceData($Host, 'THVersion');
        $DeviceData['Hardware'] = explode(':', $ResultFirmware[0])[1];
        $DeviceData['Firmware'] = explode(':', $ResultFirmware[1])[1];

        $ResultProtocol = $this->ReadDeviceData($Host, 'NETP');
        $DeviceData['Protocol'] = $ResultProtocol[0];
        $DeviceData['Mode'] = $ResultProtocol[1];
        $DeviceData['Port'] = $ResultProtocol[2];
        $DeviceData['ServerIP'] = $ResultProtocol[3];

        $ResultSSID = $this->ReadDeviceData($Host, 'WSSSID');
        $DeviceData['SSID'] = $ResultSSID[0];

        $ResultQuality = $this->ReadDeviceData($Host, 'WSLQ');
        $DeviceData['WLAN'] = $ResultQuality[0];
        if (count($ResultQuality) > 1) {
            $DeviceData['Signal'] = $ResultQuality[1];
        }

        $ResultNetwork = $this->ReadDeviceData($Host, 'WANN');
        $DeviceData['DHCP'] = ($ResultNetwork[0] != 'STATIC');
        $DeviceData['Address'] = $ResultNetwork[1];
        $DeviceData['Subnet'] = $ResultNetwork[2];
        $DeviceData['Gateway'] = $ResultNetwork[3];

        $ResultDNS = $this->ReadDeviceData($Host, 'WSDNS');
        $DeviceData['DNS'] = $ResultDNS[0];

        $ResultNTP = $this->ReadDeviceData($Host, 'NTPSER');
        $DeviceData['NTP'] = $ResultNTP[0];
        $this->SendDebug('GetData' . $Host, $DeviceData, 0);
        return $DeviceData;
    }

    private function ReadAnswer($fp)
    {
        $Line = stream_get_line($fp, 3000, "\r\n\r\n");
        if ($Line === '+ok') {
            $this->SendDebug('Result OK', $Line, 0);
            return true;
        }
        if (strpos($Line, '+ok') === false) {
            $this->SendDebug('Result ERR', $Line, 0);
            return false;
        }
        $Data = substr($Line, strpos($Line, '+ok') + 4);
        $this->SendDebug('Result', $Data, 0);
        return explode(',', $Data);
    }

    public function ReadDeviceData(string $Host, string $Key)
    {
        if ($this->UDPSocket == false) {
            if ($this->DeviceLogin($Host) == false) {
                return false;
            }
        }
        $this->SendDebug('Write:' . $Host . ':' . $Key, '', 0);
        fwrite($this->UDPSocket, 'AT+' . $Key . "\r\n");
        return $this->ReadAnswer($this->UDPSocket);
    }

    public function WriteDeviceData(string $Host, string $Key, string $Value)
    {
        if ($this->UDPSocket == false) {
            if ($this->DeviceLogin($Host) == false) {
                return false;
            }
        }
        $this->SendDebug('Write:' . $Host . ':' . $Key, $Value, 0);
        fwrite($this->UDPSocket, 'AT+' . $Key . '=' . $Value . "\r\n");
        return $this->ReadAnswer($this->UDPSocket);
    }

    public function SendDeviceReset(string $Host)
    {
        if ($this->UDPSocket == false) {
            if ($this->DeviceLogin($Host) == false) {
                return false;
            }
        }
        $this->SendDebug('Write:' . $Host . ':Z', '', 0);
        fwrite($this->UDPSocket, "AT+Z\r\n");
        return true;
    }

    public function SendSearchBroadcast()
    {
        $this->DevicesIP = [];
        $Payload = 'HF-A11ASSISTHREAD';
        $this->SendDebug('SendSearchBroadcast', $Payload, 0);
        $SendData = ['DataID' => '{79827379-F36E-4ADA-8A95-5F8D1DC92FA9}', 'Buffer' => utf8_encode($Payload)]; //, "ClientIP" => "255.255.255.255", "ClientPort" => 48899);
        $this->SendDataToParent(json_encode($SendData));
    }

    public function ReceiveData($JSONString)
    {
        $ReceiveData = json_decode($JSONString);
        $this->SendDebug('Receive', $ReceiveData, 0);
        $Data = utf8_decode($ReceiveData->Buffer);
        // 0 = Client-IP
        // 1 = MAC
        // 2 = Typ
        $Response = explode(',', $Data);
        if (count($Response) != 3) {
            return;
        }
        $DevicesIP = $this->DevicesIP;
        $DevicesIP[array_shift($Response)] = $Response;
        $this->DevicesIP = $DevicesIP;
        $this->SendDebug('AllDevices', $DevicesIP, 0);
    }
}

/* @} */
