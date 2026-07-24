# Copilot Instructions for AI Agents

## Project Overview

- Symfony-based PHP web application for kaiserlan.at
- Modular structure: `src/` (core logic), `templates/` (Twig views), `assets/` (JS/CSS), `public/` (web root)
- Major domains: User management, payments, shop, catering, tournaments
- Unified payment system: see `PAYMENT_SYSTEM_IMPLEMENTATION.md` and `src/Entity/UserTransaction.php`

## Key Architectural Patterns

- Service-oriented: Business logic in `src/Service/`, entities in `src/Entity/`
- ID management via UUIDs (Ramsey UUID)
- Data fixtures for test/demo data: `src/DataFixtures/`
- Custom Unit of Work pattern in `src/Idm/`
- Integration with external services (IMAP, Sentry, VichUploader, etc.)

## Developer Workflows

- **Build assets:** `yarn encore dev` or `yarn encore production` (see `webpack.config.js`)
- **Run tests:** `php bin/phpunit` (unit/integration tests in `tests/`)
- **Database fixtures:** Use `LiipTestFixturesBundle` in integration tests
- **Clear cache:** `php bin/console cache:clear`
- **Migrate DB:** `php bin/console doctrine:migrations:migrate`
- **Debug:** Use Symfony console commands (see `README.md` for examples)
- **Payment system migration:** `php bin/console app:migrate-payment-system` (see `PAYMENT_SYSTEM_IMPLEMENTATION.md`)

## Project-Specific Conventions

- All user/entity IDs are UUIDs, not auto-increment ints
- Payment logic is consolidated in `UserTransaction`/`UserBalance` entities and related services
- Tests use custom `DatabaseTestCase` for DB setup/teardown
- Asset entrypoints are defined in `webpack.config.js` and referenced in Twig
- Email/IMAP integration: configure via `.env` (see `PAYMENT_EMAIL_SYSTEM.md`)
- Uploaded files managed via VichUploaderBundle (see `config/packages/vich_uploader.yaml`)

## Integration Points

- Sentry for error logging (see `config/packages/sentry.yaml`)
- VichUploader for file uploads
- LiipTestFixturesBundle for test DB setup
- Custom console commands in `src/Command/`

## Examples

- Payment processing: `src/Service/TransactionService.php`, `src/Command/ProcessPaymentsCommand.php`
- User management: `src/Service/UserService.php`, `src/Idm/IdmManager.php`
- Shop/catering: `src/Service/ShopService.php`, `src/Service/CateringService.php`
- Tests: `tests/Integration/Service/*IntegrationTest.php`, `tests/Unit/Idm/UnitOfWorkTest.php`

## Tips

- Always check for project-specific commands in `src/Command/` and documentation in `*.md` files
- For new features, follow the service/entity/test separation pattern
- Use fixtures for reproducible integration tests

- Don't create tests and any test files if not asked for
- Don't include unnecessary comments or documentation
- Don't create README files
- The execution of code happens always on the docker-compose environment
- The execution of unit tests always happens on the docker-compose environment
