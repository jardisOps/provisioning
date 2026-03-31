PROVISION_BIN ?= vendor/bin/provision

<---provision---->: ## -----------------------------------------------------------------------
provision: ## Provision infrastructure (single or cluster)
	$(PROVISION_BIN) provision $(ARGS)
.PHONY: provision

provision-dry: ## Show what provisioning would do
	$(PROVISION_BIN) provision --dry-run $(ARGS)
.PHONY: provision-dry

deprovision: ## Destroy all provisioned resources
	@read -p "This will destroy ALL provisioned resources. Are you sure? [y/N] " confirm && [ "$$confirm" = "y" ] || exit 1
	$(PROVISION_BIN) deprovision --force $(ARGS)
.PHONY: deprovision

cluster-status: ## Show current infrastructure status
	$(PROVISION_BIN) status $(ARGS)
.PHONY: cluster-status

cluster-status-json: ## Show infrastructure status as JSON
	$(PROVISION_BIN) status --json $(ARGS)
.PHONY: cluster-status-json

node-add: ## Add a node (NAME=... ROLE=server|agent [TYPE=cpx31] [VOLUME=10])
	$(PROVISION_BIN) node:add --name=$(NAME) --role=$(ROLE) --type=$(or $(TYPE),cpx31) $(if $(VOLUME),--volume=$(VOLUME)) $(ARGS)
.PHONY: node-add

node-remove: ## Remove a node (NAME=... [DELETE_VOLUME=1])
	@read -p "This will remove node '$(NAME)' and its resources. Are you sure? [y/N] " confirm && [ "$$confirm" = "y" ] || exit 1
	$(PROVISION_BIN) node:remove --name=$(NAME) $(if $(DELETE_VOLUME),--delete-volume) $(ARGS)
.PHONY: node-remove

<---secrets------>: ## -----------------------------------------------------------------------
generate-key-file: ## Generate encryption key (support/secret.key)
	$(PROVISION_BIN) secret:generate-key $(ARGS)
.PHONY: generate-key-file

encrypt: ## Encrypt a value (VALUE=... [KEY_FILE=...])
	@$(PROVISION_BIN) secret:encrypt --value="$(VALUE)" $(if $(KEY_FILE),--key-file=$(KEY_FILE)) $(ARGS)
.PHONY: encrypt

encrypt-sodium: ## Encrypt a value with Sodium (VALUE=... [KEY_FILE=...])
	@$(PROVISION_BIN) secret:encrypt-sodium --value="$(VALUE)" $(if $(KEY_FILE),--key-file=$(KEY_FILE)) $(ARGS)
.PHONY: encrypt-sodium
