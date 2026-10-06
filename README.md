# marko/session-database

Database session driver — stores session data in a SQL table for shared access across multiple application servers.

## Installation

```bash
composer require marko/session-database
```

Requires `marko/database` for the database connection. Then run `marko db:migrate` to create the `sessions` table from the entity the package ships.

## Quick Example

```php
use Marko\Session\Contracts\SessionInterface;

public function __construct(
    private readonly SessionInterface $session,
) {}

public function handle(): void
{
    $this->session->set('user_id', 42);
}
```

Installing this package automatically registers the database handler, binds `SessionInterface`, and adds `SessionMiddleware` globally. No other configuration is needed.

## Documentation

Full usage, API reference, and examples: [marko/session-database](https://marko.build/docs/packages/session-database/)
