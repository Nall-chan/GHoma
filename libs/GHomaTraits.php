<?php

declare(strict_types=1);
/*
 * @addtogroup ghoma
 * @{
 *
 * @package       GHoma
 * @file          GHomaTraits.php
 * @author        Michael Tröger <micha@nall-chan.net>
 * @copyright     2019 Michael Tröger
 * @license       https://creativecommons.org/licenses/by-nc-sa/4.0/ CC BY-NC-SA 4.0
 * @version       5.1
 *
 */

namespace GHoma;

eval('declare(strict_types=1);namespace GHoma {?>' . file_get_contents(__DIR__ . '/helper/BufferHelper.php') . '}');
eval('declare(strict_types=1);namespace GHoma {?>' . file_get_contents(__DIR__ . '/helper/DebugHelper.php') . '}');
eval('declare(strict_types=1);namespace GHoma {?>' . file_get_contents(__DIR__ . '/helper/ParentIOHelper.php') . '}');
eval('declare(strict_types=1);namespace GHoma {?>' . file_get_contents(__DIR__ . '/helper/VariableHelper.php') . '}');
eval('declare(strict_types=1);namespace GHoma {?>' . file_get_contents(__DIR__ . '/helper/VariableProfileHelper.php') . '}');

class GHConnectState
{
    const CONNECTED = -1;
    const UNKNOWN = 0;
    const WAITFORINIT1 = 1;
    const WAITFORINIT2 = 2;
}

class GHMessage
{
    const PREFIX = "\x5A\xA5";
    const POSTFIX = "\x5B\xB5";
    const INIT1 = "\x05\x0D\x07\x05\x07\x12";
    const INIT2 = "\x01";
    const CMD_INIT1 = 0x02;
    const CMD_INIT1REPLY = 0x03;
    const CMD_HEARTBEAT = 0x04;
    const CMD_INIT2 = 0x05;
    const CMD_HEARTBEATREPLY = 0x06;
    const CMD_INIT2REPLY = 0x07;
    const CMD_SWITCH = 0x10;
    const CMD_STATUS = 0x90;

    public $Command = 0;
    public $Payload = '';

    public function __construct(int $Command, string $Payload)
    {
        $this->Command = $Command;
        $this->Payload = $Payload;
    }

    public function toFrame()
    {
        $Payload = chr($this->Command) . $this->Payload;
        // Calculate length from payload +1 byte for command
        $len = strlen($Payload);
        $length2Bytes = pack('n', $len);

        // Calculate checksum from payload
        $sum = 0;
        for ($i = 0; $i < $len; $i++) {
            $sum += ord($Payload[$i]);
        }
        $checksum = 0xFF - ($sum & 255);

        // Return  result
        return
                self::PREFIX .
                $length2Bytes .
                $Payload .
                chr($checksum) .
                self::POSTFIX;
    }

    public static function CMDtoString($CMD)
    {
        switch ($CMD) {
            case self::CMD_INIT1:
                return 'CMD_INIT1';
            case self::CMD_INIT1REPLY:
                return 'CMD_INIT1REPLY';
            case self::CMD_HEARTBEAT:
                return 'CMD_HEARTBEAT';
            case self::CMD_INIT2:
                return 'CMD_INIT2';
            case self::CMD_HEARTBEATREPLY:
                return 'CMD_HEARTBEATREPLY';
            case self::CMD_INIT2REPLY:
                return 'CMD_INIT2REPLY';
            case self::CMD_SWITCH:
                return 'CMD_SWITCH';
            case self::CMD_STATUS:
                return 'CMD_STATUS';
        }
        return 'INVALID COMMAND';
    }
}

/* @} */
