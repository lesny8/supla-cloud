<?php
namespace SuplaBundle\Tests\Model\ChannelActionExecutor;

use SuplaBundle\Entity\IODeviceChannel;
use SuplaBundle\Model\ChannelActionExecutor\ShutActionExecutor;
use SuplaBundle\Supla\SuplaServer;

class ShutActionExecutorTest extends \PHPUnit_Framework_TestCase {
    /**
     * @dataProvider expectedServerCommandsProvider
     */
    public function testExpectedServerCommands($actionParams, string $expectCommand) {
        $executor = new ShutActionExecutor();
        $server = $this->createMock(SuplaServer::class);
        $executor->setSuplaServer($server);
        $server->expects($this->once())->method('executeSetCommand')->with($expectCommand);
        $channel = $this->createMock(IODeviceChannel::class);
        $channel->method('buildServerSetCommand')->willReturnCallback(function ($type, $actionParams) {
            return 'SET-CHAR-VALUE:1,2,3,' . $actionParams[0];
        });
        $executor->execute($channel, $actionParams);
    }

    public function expectedServerCommandsProvider() {
        return [
            [['percentage' => 0], 'SET-CHAR-VALUE:1,2,3,10'],
            [['percentage' => 50], 'SET-CHAR-VALUE:1,2,3,60'],
            [['percentage' => 60], 'SET-CHAR-VALUE:1,2,3,70'],
            [['percentage' => 100], 'SET-CHAR-VALUE:1,2,3,110'],
            [[], 'SET-CHAR-VALUE:1,2,3,110'],
        ];
    }
}
