<---qa tools----->: ## -----------------------------------------------------------------------
phpunit: ## Run unit tests
	$(DOCKER_COMPOSE) run --rm phpcli vendor/bin/phpunit --bootstrap ./tests/bootstrap.php /app/tests/Unit
.PHONY: phpunit

phpunit-reports: ## Run unit tests with reports
	$(DOCKER_COMPOSE) run --rm -e PCOV_ENABLED=1 phpcli vendor/bin/phpunit --bootstrap ./tests/bootstrap.php /app/tests/Unit --coverage-clover tests/reports/clover.xml --coverage-xml tests/reports/coverage-xml
.PHONY: phpunit-reports

phpunit-coverage: ## Run unit tests with coverage text
	$(DOCKER_COMPOSE) run --rm -e PCOV_ENABLED=1 phpcli vendor/bin/phpunit --bootstrap ./tests/bootstrap.php /app/tests/Unit --coverage-text
.PHONY: phpunit-coverage

phpunit-coverage-html: ## Run unit tests with HTML coverage
	$(DOCKER_COMPOSE) run --rm -e PCOV_ENABLED=1 phpcli vendor/bin/phpunit --bootstrap ./tests/bootstrap.php /app/tests/Unit --coverage-html tests/reports/coverage-html
.PHONY: phpunit-coverage-html

integration-test: ## Run integration tests (credentials in tests/fixtures/<provider>/.env.local)
	$(DOCKER_COMPOSE) run --rm phpcli vendor/bin/phpunit --bootstrap ./tests/bootstrap.php /app/tests/Integration
.PHONY: integration-test

phpstan: ## Run PHPStan analysis
	$(DOCKER_COMPOSE) run --rm --no-deps phpcli vendor/bin/phpstan analyse /app/src -c phpstan.neon
.PHONY: phpstan

phpcs: ## Run coding standards
	$(DOCKER_COMPOSE) run --rm --no-deps phpcli vendor/bin/phpcs /app/src
.PHONY: phpcs
