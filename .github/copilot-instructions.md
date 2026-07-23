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
- Always use the existing logging service for logging and use PREFIXES for log messages, e.g. [WFI-LISTENER] for workflow-instance listeners

## Error Handling

- Throw typed NestJS HTTP exceptions (e.g. `NotFoundException`, `BadRequestException`) — never raw `Error`
- Always log errors via the logging service with the relevant prefix before re-throwing
- Never swallow exceptions silently

## Type Safety

- No `any` types — use proper interfaces, DTOs, or generics
- Follow NestJS naming conventions: PascalCase for classes/decorators, camelCase for methods/properties
- No dead code — remove unused imports, variables, and unreachable branches

## Input Validation & Security

- All incoming payloads must use class-validator decorated DTOs
- Use NestJS Guards for authentication/authorization — never inline auth checks in services or controllers
- Never log sensitive data (tokens, passwords, PII)
- Never hardcode credentials, secrets, or API keys — use environment variables

## When Tests Are Requested

- Co-locate unit tests next to the file under test (`*.spec.ts`)
- Use the NestJS `Test.createTestingModule()` testing module
- Mock all external dependencies (repositories, HTTP clients, pub/sub)
