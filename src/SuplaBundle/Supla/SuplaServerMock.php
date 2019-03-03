<?php
/*
 Copyright (C) AC SOFTWARE SP. Z O.O.

 This program is free software; you can redistribute it and/or
 modify it under the terms of the GNU General Public License
 as published by the Free Software Foundation; either version 2
 of the License, or (at your option) any later version.
 This program is distributed in the hope that it will be useful,
 but WITHOUT ANY WARRANTY; without even the implied warranty of
 MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 GNU General Public License for more details.
 You should have received a copy of the GNU General Public License
 along with this program; if not, write to the Free Software
 Foundation, Inc., 59 Temple Place - Suite 330, Boston, MA  02111-1307, USA.
 */

namespace SuplaBundle\Supla;

use Psr\Log\LoggerInterface;
use SuplaBundle\Model\LocalSuplaCloud;

/**
 * SuplaServer implementation to be used during development.
 */
class SuplaServerMock extends SuplaServer {
    public static $mockedResponses = [];

    public static $executedCommands = [];

    /** @var SuplaServerMockCommandsCollector */
    private $commandsCollector;

    public function __construct(SuplaServerMockCommandsCollector $commandsCollector, LoggerInterface $logger) {
        parent::__construct('', new LocalSuplaCloud('http://supla.local'), $logger);
        $this->commandsCollector = $commandsCollector;
    }

    protected function connect() {
        return true;
    }

    protected function disconnect() {
        return true;
    }

    protected function command($command) {
        $this->commandsCollector->addCommand($command);
        self::$executedCommands[] = $command;
        return $this->tryToHandleCommand($command);
    }

    private function tryToHandleCommand($cmd) {
        foreach (self::$mockedResponses as $command => $response) {
            if (preg_match("#$command#i", $cmd)) {
                unset(self::$mockedResponses[$command]);
                return $response;
            }
        }
        if (preg_match('#^IS-(IODEV|CLIENT)-CONNECTED:(\d+),(\d+)$#', $cmd, $match)) {
            return "CONNECTED:$match[3]\n";
        } elseif (preg_match('#^SET-(CG-)?(CHAR|RGBW|RAND-RGBW)-VALUE:.+$#', $cmd, $match)) {
            return 'OK:HURRA';
        } elseif (preg_match('#^GET-CHAR-VALUE:(\d+),(\d+),(\d+)#', $cmd, $match)) {
            return 'VALUE:' . rand(0, 1);
        } elseif (preg_match('#^GET-RGBW-VALUE:(\d+),(\d+),(\d+)#', $cmd, $match)) {
            $values = [rand(0, 0xFFFFFF), rand(0, 100), rand(0, 100)];
            if (rand(0, 1)) {
                $values[1] = 0; // simulate RGB turn off
            }
            if (rand(0, 1)) {
                $values[2] = 0; // simulate DIMMER turn off
            }
            return 'VALUE:' . implode(',', $values);
        } elseif (preg_match('#^GET-TEMPERATURE-VALUE:(\d+),(\d+),(\d+)#', $cmd, $match)) {
            return 'VALUE:' . (rand(-2000, 2000) / 1000);
        } elseif (preg_match('#^GET-((HUMIDITY)|(DOUBLE))-VALUE:(\d+),(\d+),(\d+)#', $cmd, $match)) {
            return 'VALUE:' . (rand(0, 1000) / 10);
        }
        return false;
    }

    public function isAlive(): bool {
        return true;
    }

    public static function mockResponse(string $command, string $response) {
        self::$mockedResponses[$command] = $response;
    }
}
