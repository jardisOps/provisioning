<?php

declare(strict_types=1);

namespace JardisOps\Provisioning\Service\Cli;

use JardisOps\Provisioning\Support\Data\Cluster;
use JardisOps\Provisioning\Support\Data\Node;
use JardisOps\Provisioning\Support\Data\NodeStatus;

/**
 * CLI output formatting.
 */
final class Output
{
    public function success(string $message): void
    {
        echo "\033[32m✓\033[0m {$message}\n";
    }

    public function error(string $message): void
    {
        echo "\033[31m✗\033[0m {$message}\n";
    }

    public function info(string $message): void
    {
        echo "  {$message}\n";
    }

    public function warning(string $message): void
    {
        echo "\033[33m!\033[0m {$message}\n";
    }

    public function cluster(Cluster $cluster): void
    {
        echo "\n";
        echo "Cluster: {$cluster->name} ({$cluster->mode->value} mode)\n";
        echo "Region:  {$cluster->region}\n";
        echo "\n";

        $nodes = $cluster->getNodes();
        if ($nodes !== []) {
            echo "Nodes (" . count($nodes) . "):\n";
            foreach ($nodes as $node) {
                $icon = $this->statusIcon($node->status);
                $private = $node->privateIp !== '' ? "  {$node->privateIp}" : '';
                echo "  {$icon} {$node->name}  {$node->role->value}  {$node->serverType}"
                    . "  {$node->status->value}  {$node->publicIp}{$private}\n";
            }
            echo "\n";
        }

        if ($cluster->network !== null) {
            echo "Private Network:\n";
            echo "  ✓ {$cluster->network->name}  {$cluster->network->subnet}"
                . "  " . count($nodes) . " nodes attached\n";
            echo "\n";
        }

        $firewalls = $cluster->getFirewalls();
        if ($firewalls !== []) {
            echo "Firewalls:\n";
            foreach ($firewalls as $fw) {
                $ports = array_map(
                    static fn($r): string => $r->port,
                    $fw->rules,
                );
                $portStr = $ports !== [] ? 'Ports: ' . implode(', ', $ports) : $fw->type;
                echo "  ✓ {$fw->name}  {$portStr}\n";
            }
            echo "\n";
        }

        if ($cluster->loadBalancer !== null) {
            $lb = $cluster->loadBalancer;
            $targetCount = count($lb->targets);
            echo "Load Balancer:\n";
            echo "  ✓ {$lb->name}  {$lb->publicIp}  {$targetCount} targets\n";
            echo "\n";
        }

        $dnsRecords = $cluster->getDnsRecords();
        if ($dnsRecords !== []) {
            echo "DNS Records:\n";
            foreach ($dnsRecords as $record) {
                echo "  ✓ {$record->subdomain}  {$record->type}  → {$record->target}\n";
            }
            echo "\n";
        }
    }

    public function json(Cluster $cluster): void
    {
        $data = [
            'mode' => $cluster->mode->value,
            'name' => $cluster->name,
            'region' => $cluster->region,
            'nodes' => array_map(static fn(Node $n): array => [
                'name' => $n->name,
                'role' => $n->role->value,
                'type' => $n->serverType,
                'status' => $n->status->value,
                'public_ip' => $n->publicIp,
                'private_ip' => $n->privateIp,
            ], $cluster->getNodes()),
        ];

        echo json_encode($data, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    }

    private function statusIcon(NodeStatus $status): string
    {
        return match ($status) {
            NodeStatus::Running => "\033[32m✓\033[0m",
            NodeStatus::Pending => "\033[33m⟳\033[0m",
            NodeStatus::Stopped => "\033[31m○\033[0m",
            NodeStatus::Deleted => "\033[31m✗\033[0m",
        };
    }
}
