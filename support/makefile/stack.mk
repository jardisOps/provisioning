<---stack-------->: ## -----------------------------------------------------------------------
start: ## Start all services and wait until ready
	$(DOCKER_COMPOSE) up -d --wait
	@echo "✓ All services are ready!"
.PHONY: start

stop: ## Stop and remove all containers
	@echo "Stopping and removing all containers..."
	$(DOCKER_COMPOSE) down --volumes --remove-orphans
	@echo "All containers stopped and removed."
.PHONY: stop

restart: stop start ## Restart all containers
.PHONY: restart

status: ## Show status of all containers
	@echo "Container status:"
	@$(DOCKER_COMPOSE) ps -a
.PHONY: status

logs: ## Show logs from all containers
	$(DOCKER_COMPOSE) logs -f
.PHONY: logs

<---provision---->: ## -----------------------------------------------------------------------
provision: ## Provision infrastructure (single or cluster)
	$(DOCKER_COMPOSE) run --rm --no-deps phpcli php /app/bin/provision provision $(ARGS)
.PHONY: provision

provision-dry: ## Show what provisioning would do
	$(DOCKER_COMPOSE) run --rm --no-deps phpcli php /app/bin/provision provision --dry-run $(ARGS)
.PHONY: provision-dry

deprovision: ## Destroy all provisioned resources
	@read -p "This will destroy ALL provisioned resources. Are you sure? [y/N] " confirm && [ "$$confirm" = "y" ] || exit 1
	$(DOCKER_COMPOSE) run --rm --no-deps phpcli php /app/bin/provision deprovision --force $(ARGS)
.PHONY: deprovision

cluster-status: ## Show current infrastructure status
	$(DOCKER_COMPOSE) run --rm --no-deps phpcli php /app/bin/provision status $(ARGS)
.PHONY: cluster-status

cluster-status-json: ## Show infrastructure status as JSON
	$(DOCKER_COMPOSE) run --rm --no-deps phpcli php /app/bin/provision status --json $(ARGS)
.PHONY: cluster-status-json

node-add: ## Add a node (NAME=... ROLE=server|agent TYPE=cpx31)
	$(DOCKER_COMPOSE) run --rm --no-deps phpcli php /app/bin/provision node:add --name=$(NAME) --role=$(ROLE) --type=$(or $(TYPE),cpx31) $(ARGS)
.PHONY: node-add

node-remove: ## Remove a node (NAME=... [DELETE_VOLUME=1])
	@read -p "This will remove node '$(NAME)' and its resources. Are you sure? [y/N] " confirm && [ "$$confirm" = "y" ] || exit 1
	$(DOCKER_COMPOSE) run --rm --no-deps phpcli php /app/bin/provision node:remove --name=$(NAME) $(if $(DELETE_VOLUME),--delete-volume) $(ARGS)
.PHONY: node-remove

<---secrets------>: ## -----------------------------------------------------------------------
generate-key-file: ## Generate encryption key (support/secret.key)
	@$(DOCKER_COMPOSE) run --rm --no-deps phpcli php -r " \
		\$$key = sodium_crypto_secretbox_keygen(); \
		file_put_contents('/app/support/secret.key', base64_encode(\$$key)); \
		chmod('/app/support/secret.key', 0600); \
		echo '✓ Key generated: support/secret.key' . PHP_EOL;"
.PHONY: generate-key-file

encrypt: ## Encrypt a value (VALUE=...)
	@$(DOCKER_COMPOSE) run --rm --no-deps phpcli php -r " \
		require '/app/vendor/autoload.php'; \
		use JardisSupport\Secret\Resolver\AesSecretResolver; \
		use JardisSupport\Secret\KeyProvider\FileKeyProvider; \
		\$$key = new FileKeyProvider('/app/support/secret.key'); \
		\$$encrypted = AesSecretResolver::encrypt('$(VALUE)', \$$key); \
		echo 'secret(aes:' . \$$encrypted . ')' . PHP_EOL;"
.PHONY: encrypt

encrypt-sodium: ## Encrypt a value with Sodium (VALUE=...)
	@$(DOCKER_COMPOSE) run --rm --no-deps phpcli php -r " \
		require '/app/vendor/autoload.php'; \
		use JardisSupport\Secret\Resolver\SodiumSecretResolver; \
		use JardisSupport\Secret\KeyProvider\FileKeyProvider; \
		\$$key = new FileKeyProvider('/app/support/secret.key'); \
		\$$encrypted = SodiumSecretResolver::encrypt('$(VALUE)', \$$key); \
		echo 'secret(sodium:' . \$$encrypted . ')' . PHP_EOL;"
.PHONY: encrypt-sodium
