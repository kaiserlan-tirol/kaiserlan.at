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
