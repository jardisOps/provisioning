<?php

declare(strict_types=1);

namespace JardisOps\Provisioning\Support\Data;

/**
 * Generated cloud-init user-data script for server hardening.
 */
final readonly class CloudInitScript
{
    public function __construct(
        public string $deployUser,
        public string $sshPublicKey,
        public int $sshPort = 22,
        public bool $disableRoot = true,
        public bool $disablePasswordAuth = true,
        public bool $autoUpdates = true,
        public bool $fail2ban = true,
    ) {
    }

    public function render(): string
    {
        $script = "#cloud-config\n";
        $script .= "package_update: true\n";
        $script .= "package_upgrade: true\n\n";

        $script .= "packages:\n";
        if ($this->fail2ban) {
            $script .= "  - fail2ban\n";
        }
        if ($this->autoUpdates) {
            $script .= "  - unattended-upgrades\n";
        }

        $script .= "\nusers:\n";
        $script .= "  - name: {$this->deployUser}\n";
        $script .= "    groups: sudo\n";
        $script .= "    shell: /bin/bash\n";
        $script .= "    sudo: ALL=(ALL) NOPASSWD:ALL\n";
        $script .= "    ssh_authorized_keys:\n";
        $script .= "      - {$this->sshPublicKey}\n";

        $disableRoot = $this->disableRoot ? 'true' : 'false';
        $script .= "\ndisable_root: {$disableRoot}\n";

        $script .= "\nwrite_files:\n";
        $script .= "  - path: /etc/ssh/sshd_config.d/99-provisioning.conf\n";
        $script .= "    content: |\n";
        $script .= "      Port {$this->sshPort}\n";
        if ($this->disablePasswordAuth) {
            $script .= "      PasswordAuthentication no\n";
        }
        if ($this->disableRoot) {
            $script .= "      PermitRootLogin no\n";
        }

        $script .= "\nruncmd:\n";
        $script .= "  - systemctl restart sshd\n";
        if ($this->fail2ban) {
            $script .= "  - systemctl enable fail2ban\n";
            $script .= "  - systemctl start fail2ban\n";
        }
        $script .= "  - reboot\n";

        return $script;
    }
}
