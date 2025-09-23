# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Fixed
- **Critical**: Fixed multithread database connection issues causing "MySQL server has gone away" errors
- **Critical**: Fixed "Premature end of data" and "Packets out of order" database errors in child processes
- **Critical**: Fixed database connection sharing between parent and child processes in daemon mode

### Added
- Connection validation and automatic reconnection for database connections
- Enhanced error handling with specific MySQL error codes (2006, 2013, 1040, 1205)
- Progressive backoff with randomization for database connection retries
- `MultiThreadJob` class with isolated database connections
- `MultiThreadScheduler` class with isolated database connections
- Connection alive validation with `SELECT 1` health checks

### Changed
- **Breaking**: Disabled persistent database connections by default (`DB_PERSISTENT=false`)
- Improved database connection timeouts (reduced to 10 seconds for connection, 30 seconds for read/write)
- Enhanced child process connection isolation in daemon mode
- Reduced default `MULTIFLEXI_MAX_PARALLEL` to 5 for better stability
- Added MySQL-specific connection options for better reliability

### Technical Details
- Override `getPdo()` and `getFluentPDO()` methods in multithread classes
- Force new database connections in child processes via environment variables
- Implement connection validation before each database operation
- Add comprehensive retry logic for transient database connection errors
- Improved connection cleanup in child process lifecycle

### Configuration
- Added `DB_PERSISTENT=false` to default environment configuration
- Added `MULTIFLEXI_MAX_PARALLEL=5` to default environment configuration

## Previous Releases

See git history for previous changes before this changelog was established.