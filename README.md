# JardisOps Provisioning

**Cloud-Infrastruktur per Code.** Ein Server oder ein ganzer Cluster — konfiguriert in `.env`, provisioniert mit einem Befehl.

JardisOps Provisioning automatisiert den kompletten Lifecycle deiner Cloud-Infrastruktur: Server erstellen, Netzwerk aufbauen, Firewalls konfigurieren, DNS-Records anlegen, Load Balancer einrichten. Alles provider-unabhaengig, alles ueber ENV-Konfiguration steuerbar.

```bash
make provision
# ✓ SSH key registered
# ✓ Server jardis-prod created (49.12.xx.xx)
# ✓ Firewall configured (22, 80, 443)
# ✓ DNS records created (api.jardis.io, portal.jardis.io)
# ✓ Provisioning complete
```

---

## Features

- **Zwei Modi** — Single-Server (Docker Compose) oder Multi-Node Cluster (K3S/Kubernetes)
- **Multi-Provider** — Infrastruktur und DNS getrennt, jeweils austauschbar per ENV
- **Server-Haertung** — Cloud-Init mit Deploy-User, SSH-only, Fail2Ban, Auto-Updates
- **State-Tracking** — Weiss welche Ressourcen existieren, idempotent ausfuehrbar
- **Secret-Support** — API-Tokens verschluesselt in `.env` via `jardissupport/secret`
- **Keine Abhaengigkeit** — Standalone PHP-Package, kein Terraform, kein Ansible

### Unterstuetzte Provider

| Typ | Provider | Status |
|-----|----------|--------|
| Infrastruktur | Hetzner Cloud | implementiert |
| DNS | Hetzner DNS | implementiert |
| DNS | INWX | implementiert |
| Infrastruktur | DigitalOcean, AWS | vorbereitet (Interface) |
| DNS | Cloudflare, Route53 | vorbereitet (Interface) |

---

## Installation

```bash
composer require jardisops/provisioning
```

### Voraussetzungen

- PHP 8.2+
- Docker (fuer Entwicklung)
- Ein Hetzner Cloud Account mit API-Token
- Ein INWX Account (oder anderer DNS-Provider)

---

## First Steps

```bash
composer require jardisops/provisioning
vendor/bin/provision init
```

`init` richtet dein Projekt automatisch ein:

| Was | Datei da? | Aktion |
|-----|-----------|--------|
| `.env.provision.example` | nein → erstellt (Hetzner-Template) | ja → uebersprungen |
| `Makefile` | nein → erstellt mit Include | ja → `provision.mk` Include angehaengt |
| `.gitignore` | nein → erstellt mit Provisioning-Eintraegen | ja → fehlende Eintraege ergaenzt |

Danach:

1. `.env.provision.example` liegt nach `init` im Projekt-Root — kopiere sie als `.env` und trage deine API-Tokens ein (am besten verschluesselt, siehe [Secrets](#secrets))
2. `make provision` — fertig

> **Anderer DNS-Provider?** INWX-Template liegt unter `vendor/jardisops/provisioning/src/Provider/Inwx/.env.example`.
> Weitere Provider-Templates unter `vendor/jardisops/provisioning/src/Provider/<Name>/.env.example`.

Die CLI sucht `.env` im aktuellen Verzeichnis. Liegt sie woanders:

```bash
vendor/bin/provision provision --env-path=mein/pfad
# oder mit Makefile:
make provision ARGS="--env-path=mein/pfad"
```

---

## Schnellstart: Single-Server mit Hetzner

Ein Server bei Hetzner mit Firewall und DNS. Alles in einer `.env`:

### 1. ENV einrichten

```env
# Provider
INFRA_PROVIDER=hetzner
DNS_PROVIDER=hetzner
PROVISION_MODE=single

# API — verschluesselt (siehe Secrets)
HETZNER_API_TOKEN=secret(aes:k9Xp2mV8nQ3wR6yT...)
# DNS-Token nur noetig wenn DNS in anderem Hetzner-Projekt liegt
# HETZNER_DNS_TOKEN=secret(aes:...)
HETZNER_REGION=fsn1
HETZNER_IMAGE=ubuntu-24.04

# SSH
SSH_KEY_PATH=~/.ssh/id_ed25519.pub

# Server
SERVER_NAME=mein-server
SERVER_TYPE=cpx31
# VOLUME_SIZE=50

# Security (Cloud-Init)
SECURITY_DEPLOY_USER=deploy
SECURITY_SSH_PORT=2222
SECURITY_DISABLE_ROOT=true
SECURITY_DISABLE_PASSWORD_AUTH=true
SECURITY_AUTO_UPDATES=true
SECURITY_FAIL2BAN=true

# Firewall
FIREWALL_EXTERNAL_PORTS_TCP=2222,80,443

# DNS
DNS_ZONE=example.com
DNS_RECORDS=api:A,portal:A
DNS_TTL=300
```

### 2. Provisionieren

```bash
# Trockenlauf — zeigt was passieren wuerde
make provision-dry

# Ausfuehren
make provision
```

### 3. Status pruefen

```bash
make cluster-status
```

```
Cluster: mein-server (single mode)
Region:  fsn1

Nodes (1):
  ✓ mein-server  server  cpx31  running  49.12.xx.xx

Firewalls:
  ✓ mein-server-external  Ports: 2222, 80, 443

DNS Records:
  ✓ api      A  → 49.12.xx.xx
  ✓ portal   A  → 49.12.xx.xx
```

### 4. Abbauen

```bash
make deprovision
```

---

## Komplettes Beispiel: K3S Cluster mit Hetzner

Ein Cluster mit einem Server-Node (Control Plane) und zwei Agent-Nodes (Worker), Private Network, Load Balancer, TLS-Zertifikat und DNS. Alles in einer `.env`:

### 1. ENV einrichten

```env
INFRA_PROVIDER=hetzner
DNS_PROVIDER=hetzner
PROVISION_MODE=cluster

# API — verschluesselt (siehe Secrets)
HETZNER_API_TOKEN=secret(aes:k9Xp2mV8nQ3wR6yT...)
HETZNER_REGION=fsn1
HETZNER_IMAGE=ubuntu-24.04

# SSH
SSH_KEY_PATH=~/.ssh/id_ed25519.pub

# Security
SECURITY_DEPLOY_USER=deploy
SECURITY_SSH_PORT=2222
SECURITY_DISABLE_ROOT=true
SECURITY_DISABLE_PASSWORD_AUTH=true
SECURITY_AUTO_UPDATES=true
SECURITY_FAIL2BAN=true
FIREWALL_EXTERNAL_PORTS_TCP=2222,80,443

# Cluster
CLUSTER_NAME=jardis-prod
CLUSTER_NODE_COUNT=3

CLUSTER_NODE_1_ROLE=server
CLUSTER_NODE_1_TYPE=cpx31
CLUSTER_NODE_1_NAME=jardis-prod-server-1
CLUSTER_NODE_1_VOLUME_SIZE=50

CLUSTER_NODE_2_ROLE=agent
CLUSTER_NODE_2_TYPE=cpx31
CLUSTER_NODE_2_NAME=jardis-prod-agent-1

CLUSTER_NODE_3_ROLE=agent
CLUSTER_NODE_3_TYPE=cpx31
CLUSTER_NODE_3_NAME=jardis-prod-agent-2

# Private Network
PRIVATE_NETWORK_NAME=jardis-prod-net
PRIVATE_NETWORK_SUBNET=10.0.1.0/24
PRIVATE_NETWORK_ZONE=eu-central

# Load Balancer
LOADBALANCER_ENABLED=true
LOADBALANCER_NAME=jardis-prod-lb
LOADBALANCER_TYPE=lb11
LOADBALANCER_ALGORITHM=round_robin
LOADBALANCER_HEALTH_CHECK_PROTOCOL=http
LOADBALANCER_HEALTH_CHECK_PORT=80
LOADBALANCER_HEALTH_CHECK_PATH=/health
LOADBALANCER_HEALTH_CHECK_INTERVAL=15
LOADBALANCER_HEALTH_CHECK_TIMEOUT=10
LOADBALANCER_HEALTH_CHECK_RETRIES=3

# DNS
DNS_ZONE=jardis.io
DNS_RECORDS=api:A,portal:A
DNS_TTL=300
```

### 2. Cluster provisionieren

```bash
make provision
```

Das passiert automatisch:

1. SSH-Key bei Hetzner registrieren
2. Cloud-Init-Script generieren (Deploy-User, SSH-Haertung, Fail2Ban)
3. Private Network erstellen (`10.0.1.0/24`)
4. Server-Node erstellen und ans Netzwerk haengen (`10.0.1.2`)
5. Agent-Nodes erstellen und ans Netzwerk haengen (`10.0.1.3`, `10.0.1.4`)
6. Externe Firewall erstellen (2222, 80, 443) und allen Nodes zuweisen
7. Interne Firewall erstellen (K3S-Ports: 6443, 8472, 10250, 2379, 2380) — nur Private Network
8. TLS-Zertifikat erstellen oder bestehendes wiederverwenden (Managed Let's Encrypt)
9. Load Balancer erstellen, ans Netzwerk haengen, Agent-Nodes als Targets, HTTPS mit Zertifikat
10. Volumes erstellen und an Nodes haengen (falls konfiguriert)
11. DNS-Records auf Load Balancer IP anlegen
12. State-File schreiben

### 3. Status pruefen

```bash
make cluster-status
```

```
Cluster: jardis-prod (cluster mode)
Region:  fsn1

Nodes (3):
  ✓ jardis-prod-server-1  server  cpx31  running  49.12.xx.xx  10.0.1.2
  ✓ jardis-prod-agent-1   agent   cpx31  running  49.12.xx.xx  10.0.1.3
  ✓ jardis-prod-agent-2   agent   cpx31  running  49.12.xx.xx  10.0.1.4

Private Network:
  ✓ jardis-prod-net  10.0.1.0/24  3 nodes attached

Firewalls:
  ✓ jardis-prod-external  Ports: 2222, 80, 443
  ✓ jardis-prod-internal  Ports: 6443, 8472, 10250, 2379, 2380

Load Balancer:
  ✓ jardis-prod-lb  49.12.xx.xx  2 targets

DNS Records:
  ✓ api      A  → 49.12.xx.xx
  ✓ portal   A  → 49.12.xx.xx
```

### 4. Node hinzufuegen

```bash
make node-add NAME=jardis-prod-agent-3 ROLE=agent TYPE=cpx31
```

Der neue Node wird automatisch ans Private Network, die Firewalls und den Load Balancer angeschlossen.

### 5. Node entfernen

```bash
make node-remove NAME=jardis-prod-agent-3
```

### 6. Cluster abbauen

```bash
make deprovision
```

Raeumt alle Ressourcen in der richtigen Reihenfolge ab: DNS → Load Balancer → Firewalls von Servern entfernen → Volumes detachen → Server loeschen → Firewalls → Network → SSH-Key.

---

## Secrets

Kein API-Token im Klartext, kein Passwort lesbar im Repo. `jardissupport/secret` verschluesselt sensible Werte mit AES-256-GCM oder Sodium (XSalsa20-Poly1305) — direkt in den `.env`-Dateien.

```bash
# 1. Encryption Key generieren (einmalig)
make generate-key-file
# → support/secret.key (automatisch in .gitignore)

# 2. Werte verschluesseln
make encrypt VALUE="hcloud-Xyz..."
# → secret(aes:k9Xp2mV8nQ3wR6yT...)

make encrypt-sodium VALUE="mein-passwort"
# → secret(sodium:A7bQ9c...)
```

In der `.env`:

```env
# AES-256-GCM (Standard)
HETZNER_API_TOKEN=secret(aes:k9Xp2mV8nQ3wR6yT...)

# Sodium (XSalsa20-Poly1305)
INWX_PASSWORD=secret(sodium:A7bQ9c...)
```

Die Entschluesselung passiert automatisch beim Laden via `jardissupport/dotenv` — im Code kommt der Klartext an, im Repo steht nur Ciphertext. Der Key (`support/secret.key`) wird getrennt verteilt, nie committed.

---

## Architektur

```
src/
├── Provider/
│   ├── Hetzner/                Hetzner Cloud API Implementierung + .env.example
│   └── Inwx/                   INWX DNS API Implementierung + .env.example
├── Service/
│   ├── Cli/                    CLI Application + Output
│   └── State/                  State-File Management (.provision-state.json)
├── Support/
│   ├── Contract/               Interfaces (Server, Network, Firewall, LB, DNS, Certificate, Volume)
│   ├── Data/                   Domain-Objekte (Cluster, Node, Firewall, Certificate, Volume, ...)
│   └── Factory/                ProvisionerFactory + Handler pro Provider
└── Provisioner.php             Orchestrator (provider-unabhaengig)
```

**Neuen Provider hinzufuegen:** Ordner unter `Provider/` anlegen, Contracts implementieren, `.env.example` beilegen, in `ProvisionerFactory` registrieren. Kein bestehender Code muss geaendert werden.

---

## CLI Referenz

| Make Target | CLI | Beschreibung |
|-------------|-----|-------------|
| `make provision` | `vendor/bin/provision provision` | Infrastruktur aufbauen |
| `make provision-dry` | `vendor/bin/provision provision --dry-run` | Trockenlauf |
| `make deprovision` | `vendor/bin/provision deprovision --force` | Alle Ressourcen abbauen |
| `make cluster-status` | `vendor/bin/provision status` | Status anzeigen |
| `make cluster-status-json` | `vendor/bin/provision status --json` | Status als JSON |
| `make node-add NAME=... ROLE=...` | `vendor/bin/provision node:add --name=... --role=...` | Node hinzufuegen |
| `make node-remove NAME=...` | `vendor/bin/provision node:remove --name=...` | Node entfernen |
| `make generate-key-file` | `vendor/bin/provision secret:generate-key` | Encryption Key erzeugen |
| `make encrypt VALUE=...` | `vendor/bin/provision secret:encrypt --value=...` | Wert verschluesseln (AES) |
| `make encrypt-sodium VALUE=...` | `vendor/bin/provision secret:encrypt-sodium --value=...` | Wert verschluesseln (Sodium) |

Optionale Parameter: `VOLUME=50` (node-add), `DELETE_VOLUME=1` (node-remove), `KEY_FILE=...` (encrypt), `ARGS="--env-path=..."` (alle).

---

## Development

```bash
# Setup
cp .env.example .env
make install

# Tests
make phpunit

# Quality
make phpstan
make phpcs

# Coverage
make phpunit-coverage
make phpunit-coverage-html
```

---

## Roadmap

Das Package ist von Beginn an als Multi-Provider-Plattform konzipiert. Die Interface-Architektur steht — neue Provider sind reine Implementierungsarbeit, kein Umbau.

**Geplante Infrastruktur-Provider:**
- DigitalOcean (Droplets, VPC, Firewalls, Load Balancer)
- AWS EC2 (Instances, VPC, Security Groups, ALB/NLB)
- Vultr

**Geplante DNS-Provider:**
- Cloudflare
- AWS Route53

**Neuen Provider beitragen:** Ordner unter `src/Provider/` anlegen, die Contracts implementieren, `.env.example` beilegen und in der `ProvisionerFactory` registrieren. Der Provisioner-Orchestrator, das State-Management und die CLI funktionieren sofort — ohne eine Zeile Anpassung.

---

## Was kommt danach?

Dieses Package stellt die Infrastruktur bereit. Die naechste Schicht ist `jardis/orchestration` — es uebernimmt ab SSH-Zugang:

```
jardisops/provisioning          → Server + Netzwerk + Firewall + DNS + LB
        ↓ SSH-Zugang
jardis/orchestration         → Docker / K3S installieren und konfigurieren
        ↓ Runtime ready
App-Projekt                  → Workloads deployen
```

---

## Lizenz

MIT
