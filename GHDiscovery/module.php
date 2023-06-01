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
 * @version       5.0
 */
eval('declare(strict_types=1);namespace GHomaDiscovery {?>' . file_get_contents(dirname(__DIR__) . '/libs/helper/DebugHelper.php') . '}');

/**
 * GHomaDiscovery ist die Klasse für eine Discovery-Instanz in IPS für die WLAN Steckdosen der Firma G-Homa.
 * Erweitert ipsmodule.
 *
 * @method bool SendDebug(string $Message, mixed $Data, int $Format)
 */
class GHomaDiscovery extends IPSModule
{
    use \GHomaDiscovery\DebugHelper;

    /**
     * The maximum number of milliseconds that will be allowed for the discovery request.
     */
    const DISCOVERY_TIMEOUT = 3;
    const DISCOVERY_PORT = 48899;
    const DISCOVERY_MSG = 'HF-A11ASSISTHREAD';
    const DISCOVERY_ADDRESS = '255.255.255.255';
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

    public function RequestAction($Ident, $Value)
    {
        switch ($Ident) {
            case 'DeviceConfigOpen':
                $this->DisplayDeviceConfigForm($Value);
                break;
            case 'DHCPChange':
                $this->UpdateFormField('IPAddress', 'enabled', !$Value);
                $this->UpdateFormField('Subnet', 'enabled', !$Value);
                $this->UpdateFormField('Gateway', 'enabled', !$Value);
                break;
            case 'ChangeServerIP':
                    $this->UpdateFormField('ManualIP', 'enabled', ($Value == 'manual'));
                break;
            case 'WriteDevice':
                $Values = json_decode($Value, true);
                $Host = array_shift($Values);
                $Key = array_shift($Values);
                if (!$this->WriteDeviceData($Host, $Key, $Values)) {
                    set_error_handler([$this, 'ConfigResultHandler']);
                    trigger_error($this->Translate('Error on write data'), E_USER_NOTICE);
                    restore_error_handler();
                }
                break;
                case 'SendDeviceReset':
                    if (!$this->SendDeviceReset($Value)) {
                        set_error_handler([$this, 'ConfigResultHandler']);
                        trigger_error($this->Translate('Error on restart device'), E_USER_NOTICE);
                        restore_error_handler();
                    } else {
                        $this->ReloadForm();
                    }
                    break;
        }
    }
    /**
     * Interne Funktion des SDK.
     */
    public function GetConfigurationForm()
    {
        $Form = json_decode(file_get_contents(__DIR__ . '/form.json'), true);
        if ($this->GetStatus() == IS_CREATING) {
            return json_encode($Form);
        }
        $Devices = $this->Discover();
        ksort($Devices);
        $DeviceValues = [];
        $InstanceIDListe = $this->GetInstanceList('{5F0CF4B0-7395-4ABF-B10F-AA0109A0F016}', 'Host');
        foreach ($Devices as $IPAddress => $Data) {
            $AddDevice = [
                //'instanceID'        => 0,
                'IPAddress'              => $Data[0],
                'MAC'                    => $Data[1],
                'Model'                  => $Data[2],
                'name'                   => 'G-Home Plug - ' . $IPAddress
            ];
            $InstanceIdDevice = array_search($IPAddress, $InstanceIDListe);
            if ($InstanceIdDevice !== false) {
                $AddDevice['name'] = IPS_GetName($InstanceIdDevice);
                $AddDevice['instanceID'] = $InstanceIdDevice;
                $AddDevice['host'] = $IPAddress;
                unset($InstanceIDListe[$InstanceIdDevice]);
            }

            $AddDevice['create'] = [
                [
                    'moduleID'      => '{5F0CF4B0-7395-4ABF-B10F-AA0109A0F016}',
                    'location'      => [$this->Translate('G-Homa Devices')],
                    'configuration' => [
                        'Host'=> $IPAddress
                    ]
                ],
                [
                    'moduleID'      => '{8062CF2B-600E-41D6-AD4B-1BA66C32D6ED}',
                    'configuration' => [
                        'Port' => 4196,
                        'Open' => true
                    ]
                ]
            ];

            $DeviceValues[] = $AddDevice;
        }
        foreach ($InstanceIDListe as $InstanceIdDevice => $IPAddress) {
            $AddDevice = [
                'instanceID'             => $InstanceIdDevice,
                'IPAddress'              => $IPAddress,
                'MAC'                    => '',
                'Model'                  => '',
                'name'                   => IPS_GetName($InstanceIdDevice)
            ];
            $DeviceValues[] = $AddDevice;
        }
        $Form['actions'][0]['values'] = $DeviceValues;
        $this->SendDebug('FORM', json_encode($Form), 0);
        $this->SendDebug('FORM', json_last_error_msg(), 0);
        return json_encode($Form);
    }

    protected function ConfigResultHandler(int $errno, string $errstr)
    {
        echo $errstr . "\r\n";
        return true;
    }

    protected function GetConfigParam(&$item1, int $InstanceID, string $ConfigParam): void
    {
        $item1 = IPS_GetProperty($InstanceID, $ConfigParam);
    }

    private function GetInstanceList(string $GUID, string $ConfigParam): array
    {
        $InstanceIDList = IPS_GetInstanceListByModuleID($GUID);
        $InstanceIDList = array_flip(array_values($InstanceIDList));
        array_walk($InstanceIDList, [$this, 'GetConfigParam'], $ConfigParam);
        $this->SendDebug('Filter', $InstanceIDList, 0);
        return $InstanceIDList;
    }
    private function Discover()
    {
        $DevicesData = [];
        $MyIPs = array_column(Sys_GetNetworkInfo(), 'IP');
        foreach ($MyIPs as $IP) {
            $socket = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
            if ($socket) {
                socket_set_option($socket, SOL_SOCKET, SO_RCVTIMEO, ['sec' => 2, 'usec' => 100000]);
                socket_set_option($socket, SOL_SOCKET, SO_REUSEADDR, 1);
                socket_set_option($socket, SOL_SOCKET, SO_BROADCAST, 1);
                if (@socket_bind($socket, $IP, self::DISCOVERY_PORT) == false) {
                    continue;
                }
                $discoveryTimeout = time() + self::DISCOVERY_TIMEOUT;
                $this->SendDebug('Search:' . $IP, self::DISCOVERY_MSG, 0);
                if (@socket_sendto($socket, self::DISCOVERY_MSG, strlen(self::DISCOVERY_MSG), 0, self::DISCOVERY_ADDRESS, self::DISCOVERY_PORT) === false) {
                    $this->SendDebug('Error on send discovery message', $IP, 0);
                    @socket_close($socket);
                    continue;
                }
                $Data = '';
                $IPAddress = '';
                $Port = 0;
                do {
                    $Data = '';
                    $IPAddress = '';
                    if (0 == @socket_recvfrom($socket, $Data, 2048, 0, $IPAddress, $Port)) {
                        continue;
                    }
                    $this->SendDebug('Receive (' . $IPAddress . ')', $Data, 0);

                    $Response = explode(',', $Data);
                    if (count($Response) != 3) {
                        continue;
                    }
                    if (strpos($Response[2], 'HF-') !== 0) {
                        continue;
                    }
                    // 0 = Client-IP
                    // 1 = MAC
                    // 2 = Typ
                    $DevicesData[$IPAddress] = $Response;
                } while (time() < $discoveryTimeout);
                socket_close($socket);
            } else {
                $this->SendDebug('Error on create Socket ipv4', $IP, 0);
            }
        }
        $this->SendDebug('DevicesData', $DevicesData, 0);
        return $DevicesData;
    }

    private function DisplayDeviceConfigForm($Host)
    {
        if ($Host) {
            $WaitForm = [[
                'type'      => 'Label',
                'width'     => '600px',
                'bold'      => true,
                'caption'   => $this->Translate('Please wait')
            ]];

            $this->SendDebug('Form:CurrentConfig', json_encode($WaitForm), 0);
            $this->UpdateFormField('CurrentConfig', 'items', json_encode($WaitForm));
            $Data = $this->GetDeviceData($Host);
            if ($Data) {
                $MyIPs = array_column(Sys_GetNetworkInfo(), 'IP');
                if (IPS_GetOption('NATPublicIP') !== '') {
                    $MyIPs[] = IPS_GetOption('NATPublicIP');
                }
                $IPOptions[] = [
                    'caption'=> $this->Translate('manual'),
                    'value'  => 'manual'
                ];
                foreach ($MyIPs as $IP) {
                    $IPOptions[] = [
                        'caption'=> $IP,
                        'value'  => $IP
                    ];
                }
                $CurrentConfig1 = [
                    [
                        'width'     => '200px',
                        'type'      => 'Label',
                        'bold'      => true,
                        'caption'   => $this->Translate('Device')
                    ],
                    [
                        'type'      => 'Label',
                        'caption'   => $this->Translate('Vendor: ') . $Data['Vendor']
                    ],
                    [
                        'type'      => 'Label',
                        'caption'   => $this->Translate('Type: ') . $Data['Typ']
                    ],
                    [
                        'type'      => 'Label',
                        'caption'   => $this->Translate('Hardware: ') . $Data['Hardware']
                    ],
                    [
                        'type'      => 'Label',
                        'caption'   => $this->Translate('Revision: ') . $Data['HW']
                    ],
                    [
                        'type'      => 'Label',
                        'caption'   => $this->Translate('Firmware: ') . $Data['Firmware']
                    ],
                    [
                        'type'      => 'Label',
                        'caption'   => $this->Translate('Wi-Fi mode: ') . $Data['WLAN']
                    ],
                    [
                        'type'      => 'Label',
                        'caption'   => $this->Translate('Wi-Fi signal: ') . $Data['Signal']
                    ],
                    [
                        'type'      => 'Label',
                        'caption'   => $this->Translate('Protocol: ') . $Data['Protocol']
                    ],
                    [
                        'type'      => 'Label',
                        'caption'   => $this->Translate('Port: ') . $Data['Port']
                    ],
                    [
                        'type'      => 'Label',
                        'caption'   => $this->Translate('Mode: ') . $Data['Mode']
                    ],
                    [
                        'type'      => 'Label',
                        'bold'      => true,
                        'caption'   => $this->Translate('Symcon paring')
                    ],
                    [
                        'type'      => 'Select',
                        'width'     => '180px',
                        'name'      => 'ServerIP',
                        'caption'   => 'IPS-Server IP',
                        'options'   => $IPOptions,
                        'value'     => (in_array($Data['ServerIP'], $MyIPs) ? $Data['ServerIP'] : 'manual'),
                        'onChange'  => 'IPS_RequestAction($id, \'ChangeServerIP\', $ServerIP);'
                    ],
                    [
                        'type'      => 'ValidationTextBox',
                        'width'     => '180px',
                        'name'      => 'ManualIP',
                        'caption'   => $this->Translate('manual Server IP'),
                        'enabled'   => !in_array($Data['ServerIP'], $MyIPs),
                        'validate'  => '^((25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)\\.(25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)\\.(25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)\\.(25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?))$|^$',
                        'value'     => (in_array($Data['ServerIP'], $MyIPs) ? '' : $Data['ServerIP'])

                    ],
                    [
                        'width'     => '180px',
                        'type'      => 'Button',
                        'caption'   => $this->Translate('paring'),
                        'onClick'   => [
                            '$Data[]= "' . $Host . '";',
                            '$Data[]= "NETP";',
                            '$Data[]= "TCP";',
                            '$Data[]= "Client";',
                            '$Data[]= "4196";',
                            '$Data[]= ($ServerIP == "manual" ? $ManualIP : $ServerIP);',
                            'if(IPS_RequestAction($id, \'WriteDevice\', json_encode($Data))){',
                            'IPS_RequestAction($id, \'DeviceConfigOpen\', "' . $Host . '");',
                            '}',
                        ]
                    ]
                ];

                $CurrentConfig2 = [
                    [
                        'width'     => '380px',
                        'type'      => 'Label',
                        'bold'      => true,
                        'caption'   => $this->Translate('Network')
                    ],
                    [
                        'type'      => 'RowLayout',
                        'items'     => [
                            [
                                'width'     => '180px',
                                'name'      => 'DHCP',
                                'type'      => 'CheckBox',
                                'caption'   => 'DHCP',
                                'value'     => (bool) $Data['DHCP'],
                                'onChange'  => 'IPS_RequestAction($id, \'DHCPChange\', $DHCP);'
                            ],
                            [
                                'width'     => '180px',
                                'type'      => 'Button',
                                'caption'   => $this->Translate('Write Network'),
                                'onClick'   => [
                                    '$Data[]= "' . $Host . '";',
                                    '$Data[]= "WANN";',
                                    '$Data[]= ($DHCP ? "DHCP" : "STATIC");',
                                    '$Data[]= $IPAddress;',
                                    '$Data[]= $Subnet;',
                                    '$Data[]= $Gateway;',
                                    '$Result = IPS_RequestAction($id, \'WriteDevice\', json_encode($Data));',
                                    '$Data=[];',
                                    '$Data[]= "' . $Host . '";',
                                    '$Data[]= "WSDNS";',
                                    '$Data[]= $DNS;',
                                    '$Result & IPS_RequestAction($id, \'WriteDevice\', json_encode($Data));',
                                    '$Data=[];',
                                    '$Data[]= "' . $Host . '";',
                                    '$Data[]= "NTPSER";',
                                    '$Data[]= $NTP;',
                                    '$Result & IPS_RequestAction($id, \'WriteDevice\', json_encode($Data));',
                                    'if ($Result){',
                                    'IPS_RequestAction($id, "SendDeviceReset", "' . $Host . '");',
                                    '}'
                                ]
                            ]
                        ]
                    ],
                    [
                        'width'     => '180px',
                        'name'      => 'IPAddress',
                        'type'      => 'ValidationTextBox',
                        'caption'   => $this->Translate('IP-Address'),
                        'enabled'   => $Data['DHCP'] == 0,
                        'validate'  => '^(25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)\\.(25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)\\.(25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)\\.(25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)$',
                        'value'     => $Data['Address']

                    ],
                    [
                        'width'     => '180px',
                        'name'      => 'Subnet',
                        'type'      => 'ValidationTextBox',
                        'caption'   => 'Subnet',
                        'enabled'   => $Data['DHCP'] == 0,
                        'validate'  => '^(25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)\\.(25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)\\.(25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)\\.(25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)$',
                        'value'     => $Data['Subnet']
                    ],
                    [
                        'width'     => '180px',
                        'name'      => 'Gateway',
                        'type'      => 'ValidationTextBox',
                        'caption'   => 'Gateway',
                        'enabled'   => $Data['DHCP'] == 0,
                        'validate'  => '^(25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)\\.(25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)\\.(25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)\\.(25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)$',
                        'value'     => $Data['Gateway']
                    ],
                    [
                        'width'     => '180px',
                        'name'      => 'DNS',
                        'type'      => 'ValidationTextBox',
                        'validate'  => '^(25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)\\.(25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)\\.(25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)\\.(25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)$',
                        'caption'   => 'DNS',
                        'value'     => $Data['DNS']
                    ],
                    [
                        'width'     => '180px',
                        'name'      => 'NTP',
                        'type'      => 'ValidationTextBox',
                        'validate'  => '^(25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)\\.(25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)\\.(25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)\\.(25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)$',
                        'caption'   => 'NTP',
                        'value'     => $Data['NTP']
                    ],
                    [
                        'type'      => 'RowLayout',
                        'items'     => [[
                            'width'     => '180px',
                            'type'      => 'Label',
                            'bold'      => true,
                            'caption'   => $this->Translate('Wi-Fi')
                        ],
                            [
                                'width'     => '180px',
                                'type'      => 'Button',
                                'caption'   => $this->Translate('Write Wi-Fi'),
                                'onClick'   => [
                                    '$Data[]= "' . $Host . '";',
                                    '$Data[]= "WSSSID";',
                                    '$Data[]= $WSSSID;',
                                    '$Result = IPS_RequestAction($id, \'WriteDevice\', json_encode($Data));',
                                    '$Data=[];',
                                    '$Data[]= "' . $Host . '";',
                                    '$Data[]= "WSKEY";',
                                    '$Data[]= "WPA2PSK";',
                                    '$Data[]= "AES";',
                                    '$Data[]= $WSKEY;',
                                    '$Result = IPS_RequestAction($id, \'WriteDevice\', json_encode($Data));',
                                    '$Data=[];',
                                    '$Data[]= "' . $Host . '";',
                                    '$Data[]= "WMODE";',
                                    '$Data[]= "STA";',
                                    '$Result = IPS_RequestAction($id, \'WriteDevice\', json_encode($Data));',
                                    'if ($Result){',
                                    'IPS_RequestAction($id, "SendDeviceReset", "' . $Host . '");',
                                    '}'
                                ]
                            ]
                        ]
                    ],
                    [
                        'width'     => '180px',
                        'name'      => 'WSSSID',
                        'type'      => 'ValidationTextBox',
                        'caption'   => $this->Translate('Wi-Fi SSID'),
                        'value'     => $Data['SSID']
                    ],
                    [
                        'width'     => '180px',
                        'name'      => 'WSKEY',
                        'type'      => 'PasswordTextBox',
                        'caption'   => $this->Translate('Key (only WPA2PSK)'),
                        'value'     => $Data['WSKEY']
                    ]
                ];
                $CurrentConfig = [
                    [
                        'type'  => 'ColumnLayout',
                        'items' => $CurrentConfig1
                    ],
                    [
                        'type'  => 'ColumnLayout',
                        'items' => $CurrentConfig2
                    ]

                ];
            } else {
                $CurrentConfig = [[
                    'type'      => 'Label',
                    'width'     => '600px',
                    'bold'      => true,
                    'color'     => 0xff0000,
                    'caption'   => $this->Translate('Device not reachable')
                ]];
            }
        } else {
            $CurrentConfig = [[
                'type'      => 'Label',
                'width'     => '600px',
                'bold'      => true,
                'color'     => 0xff0000,
                'caption'   => $this->Translate('No device selected')
            ]];
        }
        $this->SendDebug('Form:CurrentConfig', json_encode($CurrentConfig), 0);
        $this->UpdateFormField('CurrentConfig', 'items', json_encode($CurrentConfig));
    }

    private function GetDeviceData(string $Host)
    {
        $DeviceData = $this->DeviceLogin($Host);
        if ($DeviceData == false) {
            $this->SendDebug('Error', 'Error on Read Data', 0);
            return false;
        }
        try {
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
            $ResultWSKEY = $this->ReadDeviceData($Host, 'WSKEY');
            $DeviceData['WSKEY'] = $ResultWSKEY[2];
            $ResultQuality = $this->ReadDeviceData($Host, 'WSLQ');
            $DeviceData['WLAN'] = $ResultQuality[0];
            if (count($ResultQuality) > 1) {
                $DeviceData['Signal'] = $ResultQuality[1];
            }
            $ResultNetwork = $this->ReadDeviceData($Host, 'WANN');
            $DeviceData['DHCP'] = (strtolower($ResultNetwork[0]) != 'static');
            $DeviceData['Address'] = $ResultNetwork[1];
            $DeviceData['Subnet'] = $ResultNetwork[2];
            $DeviceData['Gateway'] = $ResultNetwork[3];
            $ResultDNS = $this->ReadDeviceData($Host, 'WSDNS');
            $DeviceData['DNS'] = $ResultDNS[0];
            $ResultNTP = $this->ReadDeviceData($Host, 'NTPSER');
            $DeviceData['NTP'] = $ResultNTP[0];
            $this->SendDebug('GetData' . $Host, $DeviceData, 0);
        } catch (\Throwable $th) {
            return false;
        }

        return $DeviceData;
    }

    private function ReadDeviceData(string $Host, string $Key)
    {
        if ($this->UDPSocket == false) {
            if ($this->DeviceLogin($Host) == false) {
                return false;
            }
        }
        $this->SendDebug('Write:' . $Host . ':' . $Key, '', 0);
        fwrite($this->UDPSocket, 'AT+' . $Key . "\r\n");
        $Data = $this->ReadAnswer();
        if ($Data) {
            return explode(',', $Data);
        }
        return false;
    }

    private function WriteDeviceData(string $Host, string $Key, array $Values)
    {
        if ($this->UDPSocket == false) {
            if ($this->DeviceLogin($Host) == false) {
                return false;
            }
        }
        $Value = implode(',', $Values);
        $this->SendDebug('Write:' . $Host . ':' . $Key, $Value, 0);
        fwrite($this->UDPSocket, 'AT+' . $Key . '=' . $Value . "\r\n");
        $Data = $this->ReadAnswer();
        if ($Data === true) {
            return true;
        } elseif ($Data === false) {
            return false;
        }
        return explode(',', $Data);
    }

    private function SendDeviceReset(string $Host)
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

    private function DeviceLogin(string $Host)
    {
        $this->SendDebug('Login:' . $Host, '', 0);
        $this->UDPSocket = fsockopen('udp://' . $Host, 48899, $errno, $errstr, 2);
        if (!$this->UDPSocket) {
            trigger_error('ERROR:' . $errno . '-' . $errstr, E_USER_NOTICE);
            $this->SendDebug('Error:' . $Host, $errno . ' - ' . $errstr, 0);
            return false;
        }
        stream_set_timeout($this->UDPSocket, 2);
        fwrite($this->UDPSocket, 'HF-A11ASSISTHREAD');
        fread($this->UDPSocket, 100);
        fwrite($this->UDPSocket, '+ok');        // Reply to first received data
        fwrite($this->UDPSocket, "AT+VER\r\n");   // Request the version
        $Data = $this->ReadAnswer();
        if (!$Data) {
            return false;
        }
        $ResultHardware = explode(',', $Data);
        $DeviceData['Vendor'] = $ResultHardware[0];
        $DeviceData['Typ'] = $ResultHardware[1];
        $DeviceData['HW'] = $ResultHardware[2];
        return $DeviceData;
    }

    private function ReadAnswer()
    {
        $Line = stream_get_line($this->UDPSocket, 3000, "\r\n\r\n");
        if ($Line === false) {
            return false;
        }
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
        return $Data; //explode(',', $Data);
    }
}

/* @} */
