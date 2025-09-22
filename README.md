# multiflexi-executor
Execute MultiFlexi jobs (one-shot or as a long-running daemon).

![Executor Logo](multiflexi-executor.svg?raw=true)

MultiFlexi
----------

multiflexi-executor is part of the [MultiFlexi](https://multiflexi.eu) suite.
See the full list of ready-to-run applications within the MultiFlexi platform on the [application list page](https://www.multiflexi.eu/apps.php).

[![MultiFlexi App](https://github.com/VitexSoftware/MultiFlexi/blob/main/doc/multiflexi-app.svg)](https://www.multiflexi.eu/)

## Requirements
- PHP 8.2+ (daemon parallel mode requires the pcntl extension)
- Composer
- A configured MultiFlexi database (shared across the suite)

## Installation (development)
```
composer install
```

## Configuration (.env)
Place a .env file at the repository root. The executor reads configuration via Ease\Shared.

Common keys:
- DB_CONNECTION, DB_HOST, DB_PORT, DB_DATABASE, DB_USERNAME, DB_PASSWORD
- DB_PERSISTENT=false                  # disable persistent connections for multithread safety (recommended)
- APP_DEBUG=true|false
- MULTIFLEXI_DAEMONIZE=true|false
- MULTIFLEXI_CYCLE_PAUSE=10            # seconds between polling cycles when daemonized
- RESULT_FILE=php://stdout             # default output destination for one-shot runs
- ZABBIX_SERVER, ZABBIX_HOST           # enable LogToZabbix if available
- MULTIFLEXI_MAX_PARALLEL=5            # max concurrent jobs in daemon (0 or <1 = unlimited, recommended: 5-10)

Notes:
- src/executor.php and src/daemon.php expect vendor/autoload.php and .env one directory above. Run them from src/ so relative paths resolve.
- Parallel execution requires the pcntl extension. On Debian-based systems install the package matching your PHP version (e.g., php8.3-pcntl).
- **Database Connection Safety**: Set `DB_PERSISTENT=false` to prevent connection sharing issues in multithread mode. The executor uses isolated connections for each child process to avoid "MySQL server has gone away" errors.

## Usage
### One-shot execution (run a single RunTemplate)
From repo root:
```
cd src && php -q -f executor.php -- -r <RUNTEMPLATE_ID> [-o <output_path>] [-e <env_file>]
```
Flags:
- -r, --runtemplate: RunTemplate ID to execute
- -o, --output: destination for captured output (defaults to stdout or RESULT_FILE)
- -e, --environment: path to .env (defaults to ../.env)

### Daemon (polls DB and executes due jobs)
From repo root:
```
cd src && php -q -f daemon.php
```
Behavior:
- Controlled by .env. When MULTIFLEXI_DAEMONIZE=true, the process runs continuously and sleeps MULTIFLEXI_CYCLE_PAUSE seconds between cycles.
- Parallel execution: if pcntl is available, due jobs are executed in parallel using forks. Limit concurrency with MULTIFLEXI_MAX_PARALLEL (set 0 for unlimited).

Examples:
```
# Run daemon with up to 4 concurrent jobs
MULTIFLEXI_MAX_PARALLEL=4 php -q -f src/daemon.php

# Unlimited parallelism (default when 0 or unset)
php -q -f src/daemon.php
```

## Useful Make targets
```
make vendor                     # install dependencies
make static-code-analysis       # phpstan
make static-code-analysis-baseline
make cs                         # coding standards (php-cs-fixer)
make autoload                   # composer update
make tests                      # run PHPUnit tests
make buildimage                 # build container image
make buildx                     # multi-arch build & push
make drun                       # run container (requires .env)
```

## Testing
Run all tests:
```
make tests
```
Run a single test file or filtered tests:
```
vendor/bin/phpunit path/to/TestFile.php
vendor/bin/phpunit --filter "TestNameOrRegex"
```

## Troubleshooting

### Database Connection Issues
If you encounter database connection errors like "MySQL server has gone away", "Premature end of data", or "Packets out of order":

1. **Ensure `DB_PERSISTENT=false`** in your .env file (required for multithread safety)
2. **Limit concurrent jobs** with `MULTIFLEXI_MAX_PARALLEL=5` (or lower if you have limited database connections)
3. **Check MySQL configuration**:
   ```sql
   SHOW VARIABLES LIKE 'max_connections';
   SHOW VARIABLES LIKE 'wait_timeout';
   SHOW STATUS LIKE 'Threads_connected';
   ```
4. **Monitor connection usage**: The daemon logs current MySQL connections during operation
5. **Increase MySQL limits** if needed:
   ```ini
   # /etc/mysql/mysql.conf.d/mysqld.cnf
   max_connections = 200
   wait_timeout = 300
   ```

The executor automatically:
- Validates connections before each database operation
- Reconnects on connection failures
- Uses progressive backoff for retry attempts
- Isolates database connections in child processes

## Scheduling example (via CLI)
The repo includes an integration-oriented script under tests/ that schedules a RunTemplate using multiflexi-cli:
```
multiflexi-cli runtemplate schedule --id 1 --format json
```
