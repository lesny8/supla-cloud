<?php
namespace SuplaBundle\Model\ChannelStateGetter;

use SuplaBundle\Entity\IODeviceChannel;
use SuplaBundle\Enums\ChannelFunction;
use SuplaBundle\Supla\SuplaServerAware;

class ConnectedChannelStateGetter implements SingleChannelStateGetter {
    use SuplaServerAware;

    public function getState(IODeviceChannel $channel): array {
        return ['connected' => $this->suplaServer->isDeviceConnected($channel->getIoDevice())];
    }

    public function supportedFunctions(): array {
        return ChannelFunction::values();
    }
}
