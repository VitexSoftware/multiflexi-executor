# WARP.md

This file provides guidance to WARP (warp.dev) when working with code in this repository.

Repository: multiflexi-executor (part of the MultiFlexi suite)

Development commands
- Install dependencies
  - composer install
  - Or via Makefile target: make vendor
- Static analysis (PHPStan)
  - make static-code-analysis
  - Generate/refresh baseline: make static-code-analysis-baseline
- Coding standards (php-cs-fixer)
  - make cs
- Update autoload (composer lockfile changes, classmaps)
  - make autoload
- Tests (PHPUnit)
  - Run all: make tests
  - Run a single test file: vendor/bin/phpunit path/to/TestFile.php
  - Run tests by name/pattern: vendor/bin/phpunit --filter "TestNameOrRegex"
- Container images (Containerfile)
  - Build for current arch: make buildimage
  - Build multi-arch and push: make buildx
  - Run image locally (requires .env): make drun

Running the executor locally
Note: src/executor.php and src/daemon.php expect vendor/autoload.php and a .env file one directory above their own location. Run them from the src/ directory so relative paths resolve correctly.
- Single runtemplate execution (run one job):
  - From repo root:
    - cd src && php -q -f executor.php -- -r <RUNTEMPLATE_ID> [-o <output_path>] [-e <env_file>]
  - Flags/behavior:
    - -r or --runtemplate sets the RunTemplate ID to execute
    - -o or --output sets destination for captured output (defaults to stdout or RESULT_FILE from .env)
    - -e or --environment sets path to .env (defaults to ../.env)
- Long-running executor daemon (polls DB and executes due jobs):
  - From repo root:
    - cd src && php -q -f daemon.php
  - Behavior is controlled via .env. MULTIFLEXI_DAEMONIZE=true runs continuously; MULTIFLEXI_CYCLE_PAUSE controls poll interval (seconds).

Configuration and environment
- Place a .env file at the repository root. The executor reads DB and app settings via Ease\Shared from that file.
- Expected keys include (driven by MultiFlexi core): DB_CONNECTION, DB_HOST, DB_PORT, DB_DATABASE, DB_USERNAME, DB_PASSWORD, APP_DEBUG, MULTIFLEXI_DAEMONIZE, MULTIFLEXI_CYCLE_PAUSE, RESULT_FILE, ZABBIX_SERVER, ZABBIX_HOST.
- Logging is configured via EASE_LOGGER, automatically assembled based on env and available classes: syslog | \MultiFlexi\LogToSQL | optional \MultiFlexi\LogToZabbix | console (when APP_DEBUG=true).

High-level architecture and flow
- Purpose: multiflexi-executor runs MultiFlexi jobs either as a one-shot execution for a given RunTemplate or as a daemon that continuously dispatches scheduled jobs.
- Core dependency: vitexsoftware/multiflexi-core provides domain classes used here (e.g., Scheduler, Job, RunTemplate, UnixUser/Anonym, LogToSQL, optional LogToZabbix). This repo focuses on orchestration and process lifecycle; business logic and persistence live in the core.
- Entrypoints (src/):
  - executor.php (one-shot):
    - Initializes config from .env; builds logger set; sets APP_NAME based on an optional interval code.
    - Resolves a RunTemplate by ID, prepares a Job (prepareJob), executes it (performJob), prints/stdout and stderr streams, and exits with the underlying process exit code.
  - daemon.php (long-running):
    - Initializes config; waits for DB availability (retry loop) before starting.
    - Main loop:
      - Scheduler::getCurrentJobs() fetches due jobs; for each, instantiate Job, performJob, then clean up and delete the schedule record.
      - On DB errors, re-enter wait-for-DB and recreate Scheduler.
      - Sleep between cycles when MULTIFLEXI_DAEMONIZE=true.
- Binaries (bin/): installation targets provide shell wrappers that call the installed PHP entrypoint under /usr/lib/multiflexi-executor; for development, use the src/*.php scripts as shown above.
- Tests (tests/): contains an integration-oriented test script (tests/test.sh) that exercises RunTemplate scheduling via multiflexi-cli. PHPUnit is available for unit tests via vendor/bin/phpunit.

Cross-repo context (MultiFlexi suite)
- This executor expects the shared MultiFlexi database schema and core library. It operates alongside other components (e.g., scheduler/UI) but only interacts through the DB and core APIs. See the top-level MultiFlexi project README for platform-level docs.

Notes for future modifications
- If you change class locations or add namespaces, ensure composer.json autoload sections are updated and run composer dump-autoload or make autoload.
- PHPStan config lives in phpstan-default.neon.dist; use the baseline target to manage legacy issues when raising strictness.

