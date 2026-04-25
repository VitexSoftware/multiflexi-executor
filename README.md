# multiflexi-executor
Execute MultiFlexi jobs (one-shot or as a long-running daemon).

![Executor Logo](multiflexi-executor.svg?raw=true)

MultiFlexi
----------

multiflexi-executor is part of the [MultiFlexi](https://multiflexi.eu) suite.
See the full list of ready-to-run applications within the MultiFlexi platform on the [application list page](https://www.multiflexi.eu/apps.php).

[![MultiFlexi App](https://github.com/VitexSoftware/MultiFlexi/blob/main/doc/multiflexi-app.svg)](https://www.multiflexi.eu/)

## Requirements
- PHP 8.2+
- Composer
- A configured MultiFlexi database (shared across the suite)
- Optional: the `pcntl` extension for SIGTERM/SIGINT graceful-shutdown support in the daemon

## Installation (development)
```
composer install
```

## Configuration (.env)
Place a .env file at the repository root. The executor reads configuration via Ease\Shared.

Common keys:
| Variable | Default | Description |
|---|---|---|
| `DB_CONNECTION` | `mysql` | Database driver (`mysql`, `pgsql`, `sqlite`) |
| `DB_HOST` | `localhost` | Database host |
| `DB_PORT` | driver default | Database port |
| `DB_DATABASE` | — | Database name |
| `DB_USERNAME` | — | Database user |
| `DB_PASSWORD` | — | Database password |
| `APP_DEBUG` | `false` | Log to console when `true` |
| `MULTIFLEXI_DAEMONIZE` | `true` | Run the daemon continuously |
| `MULTIFLEXI_CYCLE_PAUSE` | `10` | Seconds between polling cycles |
| `MULTIFLEXI_MAX_PARALLEL` | `0` | Max concurrent jobs; `0` = unlimited |
| `MULTIFLEXI_MEMORY_LIMIT_MB` | `0` | Soft memory limit in MB (`0` = disabled) |
| `RESULT_FILE` | `php://stdout` | Default output destination for one-shot runs |
| `ZABBIX_SERVER` / `ZABBIX_HOST` | — | Enable `LogToZabbix` when both are set |

Notes:
- `src/executor.php` and `src/daemon.php` expect `vendor/autoload.php` and `.env` one directory above. Run them from `src/` so relative paths resolve.
- The daemon passes the absolute `.env` path to each job subprocess automatically.

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
- Controlled by `.env`. When `MULTIFLEXI_DAEMONIZE=true` the process runs continuously, sleeping `MULTIFLEXI_CYCLE_PAUSE` seconds between polling cycles.
- **Parallel execution**: each due job is launched as an isolated `executor.php` subprocess. Jobs run concurrently without blocking one another, so a slow job never delays the next timeslot. Each subprocess gets its own PHP process, database connection, and memory — no shared state between jobs.
- `MULTIFLEXI_MAX_PARALLEL` caps the number of concurrently running subprocesses (`0` = unlimited). When all slots are occupied the daemon waits until a slot frees before launching the next job.
- On `SIGTERM` or `SIGINT` (requires the `pcntl` extension) the daemon stops accepting new jobs and waits for all in-flight subprocesses to finish before exiting.

Examples:
```bash
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

## Scheduling example (via CLI)
The repo includes an integration-oriented script under tests/ that schedules a RunTemplate using multiflexi-cli:
```
multiflexi-cli runtemplate schedule --id 1 --format json
```

## Kubernetes Executor

The Kubernetes executor (`MultiFlexi\Executor\Kubernetes`) runs jobs as one-shot pods in a Kubernetes cluster using `kubectl run --attach`.

### How it works
1. **Config derivation**: The executor reconstructs Kubernetes config from existing DB fields — no dedicated kubernetes JSON column is needed:
   - `helmchart` column → Helm chart URI (OCI or repo reference)
   - `name` column → Helm release name (lowercased, DNS-1123 safe)
   - `artifacts` column → artifact output paths for `kubectl cp`
   - Sensible defaults for namespace (`multiflexi`), timeout (300s), etc.
2. **Helm pre-deploy**: On first run, if the application is not yet deployed, the executor runs `helm upgrade --install` using the chart from the `helmchart` DB field.
3. **Job execution**: Creates an ephemeral pod via `kubectl run` with `--restart=Never --attach`, passing environment variables via `--env` flags.
4. **Artifact collection**: After pod completion, copies artifacts from the pod via `kubectl cp` and stores them in the MultiFlexi FileStore.
5. **Log capture**: `storeLogs()` fetches pod output via `kubectl logs` after job completion.

### Requirements
- `kubectl` binary in PATH
- `helm` binary in PATH (for Helm pre-deployment)
- Valid kubeconfig at `~/.kube/config` or `$KUBECONFIG`
- Applications must have `ociimage` set (container image reference)
- Applications should have `helmchart` set to a valid Helm chart URI for first-time deployment

### File-path environment variables
File-path config fields from the executor host are skipped for Kubernetes jobs — host paths are not available inside the pod. A warning is logged for each skipped field.

## Azure Container Instances Executor

The Azure executor (`MultiFlexi\Executor\Azure`) runs jobs as one-shot containers in Azure Container Instances (ACI).

### How it works
1. **Container creation**: Creates a container group via `az container create --restart-policy Never` (one-shot).
2. **Environment variables**: Passes env vars via `--environment-variables`. Sensitive vars (containing PASSWORD, SECRET, TOKEN, KEY) are routed to `--secure-environment-variables`.
3. **Status polling**: Polls `az container show` every 10 seconds until the container reaches a terminal state (up to 1 hour).
4. **Log collection**: Fetches output via `az container logs` after completion.
5. **Cleanup**: Deletes the container group via `az container delete`.

### Requirements
- Azure CLI (`az`) in PATH, authenticated (`az login`)
- `AZURE_RESOURCE_GROUP` environment variable set (required)
- Applications must have `ociimage` set

### Configuration (environment variables)
- `AZURE_RESOURCE_GROUP` — Azure resource group (required)
- `AZURE_LOCATION` — Azure region (default: `westeurope`)
- `AZURE_CPU` — CPU cores per container (default: `1`)
- `AZURE_MEMORY` — Memory in GB per container (default: `1.5`)
