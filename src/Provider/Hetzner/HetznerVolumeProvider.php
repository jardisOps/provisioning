<?php

declare(strict_types=1);

namespace JardisOps\Provisioning\Provider\Hetzner;

use JardisOps\Provisioning\Support\Contract\VolumeProviderInterface;
use JardisOps\Provisioning\Support\Data\Volume;
use RuntimeException;

/**
 * Hetzner Cloud implementation for block storage volumes.
 */
final class HetznerVolumeProvider implements VolumeProviderInterface
{
    public function __construct(
        private readonly HetznerApiClient $api,
    ) {
    }

    public function createVolume(Volume $volume, string $region): Volume
    {
        $data = [
            'name' => $volume->name,
            'size' => $volume->size,
            'location' => $region,
            'format' => 'ext4',
        ];

        if ($volume->serverId !== '') {
            $data['server'] = (int) $volume->serverId;
        }

        $response = $this->api->post('/volumes', $data);

        /** @var array<string, mixed> $vol */
        $vol = $response['volume'];

        if (isset($data['server'])) {
            $this->waitForAction($response);
        }

        return new Volume(
            name: (string) $vol['name'],
            size: (int) $vol['size'],
            serverId: $volume->serverId,
            id: (string) $vol['id'],
        );
    }

    public function attachVolume(string $volumeId, string $serverId): void
    {
        $response = $this->api->post("/volumes/{$volumeId}/actions/attach", [
            'server' => (int) $serverId,
        ]);

        $this->waitForAction($response);
    }

    public function detachVolume(string $volumeId): void
    {
        $response = $this->api->post("/volumes/{$volumeId}/actions/detach");

        $this->waitForAction($response);
    }

    public function deleteVolume(string $volumeId): void
    {
        $this->api->delete("/volumes/{$volumeId}");
    }

    public function getVolume(string $volumeId): Volume
    {
        $response = $this->api->get("/volumes/{$volumeId}");

        /** @var array<string, mixed> $vol */
        $vol = $response['volume'];

        return new Volume(
            name: (string) $vol['name'],
            size: (int) $vol['size'],
            serverId: (string) ($vol['server'] ?? ''),
            id: (string) $vol['id'],
        );
    }

    /**
     * @param array<string, mixed> $response
     */
    private function waitForAction(array $response): void
    {
        /** @var array<string, mixed> $action */
        $action = $response['action'] ?? [];
        $actionId = (string) ($action['id'] ?? '');

        if ($actionId === '') {
            return;
        }

        for ($i = 0; $i < 30; $i++) {
            sleep(2);

            $poll = $this->api->get("/actions/{$actionId}");

            /** @var array<string, mixed> $pollAction */
            $pollAction = $poll['action'] ?? [];
            $status = (string) ($pollAction['status'] ?? '');

            if ($status === 'success') {
                return;
            }

            if ($status === 'error') {
                throw new RuntimeException("Volume action {$actionId} failed");
            }
        }

        throw new RuntimeException("Volume action {$actionId} timed out");
    }
}
