<?php

declare(strict_types=1);

namespace JardisOps\Provisioning\Support\Contract;

use JardisOps\Provisioning\Support\Data\Volume;

/**
 * Manages block storage volumes.
 */
interface VolumeProviderInterface
{
    public function createVolume(Volume $volume, string $region): Volume;

    public function attachVolume(string $volumeId, string $serverId): void;

    public function detachVolume(string $volumeId): void;

    public function deleteVolume(string $volumeId): void;

    public function getVolume(string $volumeId): Volume;
}
