<?php

declare(strict_types=1);

/**
 * This file is part of the MultiFlexi package
 *
 * https://multiflexi.eu/
 *
 * (c) Vítězslav Dvořák <http://vitexsoftware.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace MultiFlexi;

use Ease\Shared;
use Symfony\Component\Process\Process;

date_default_timezone_set('Europe/Prague');

require_once '../vendor/autoload.php';

// Optional memory limit override from environment (in megabytes). If set, we will
// monitor current usage and gracefully exit before the OOM killer intervenes.
$memorySoftLimitMb = (int) Shared::cfg('MULTIFLEXI_MEMORY_LIMIT_MB', 0);
Shared::init(['DB_CONNECTION', 'DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD'], '../.env');
$daemonize = (bool) Shared::cfg('MULTIFLEXI_DAEMONIZE', true);

// Maximum number of concurrently running jobs. 0 means unlimited.
// Set via MULTIFLEXI_MAX_PARALLEL environment variable.
// Requires the pcntl extension for signal-based graceful shutdown.
$maxParallel = (int) Shared::cfg('MULTIFLEXI_MAX_PARALLEL', 0);

// Resolve the .env path to an absolute path so subprocesses can find it
// regardless of their working directory.
$envFile = realpath(__DIR__.'/../.env') ?: __DIR__.'/../.env';

$loggers = ['syslog', '\\MultiFlexi\\LogToSQL'];

if (Shared::cfg('ZABBIX_SERVER') && Shared::cfg('ZABBIX_HOST') && class_exists('\\MultiFlexi\\LogToZabbix')) {
    $loggers[] = '\\MultiFlexi\\LogToZabbix';
}

if (strtolower(Shared::cfg('APP_DEBUG', 'false')) === 'true') {
    $loggers[] = 'console';
}

\define('APP_NAME', 'MultiFlexi Executor');
\define('EASE_LOGGER', implode('|', $loggers));

new \MultiFlexi\Defaults();
Shared::user(new \MultiFlexi\UnixUser());

/**
 * Check if database error is a permanent failure that should not be retried.
 *
 * @param string $errorMessage Error message from database exception
 *
 * @return bool True if error is permanent and daemon should exit
 */
function isPermanentDatabaseError(string $errorMessage): bool
{
    // Authentication/credential errors that won't resolve with retries
    if (str_contains($errorMessage, 'Access denied')
        || str_contains($errorMessage, '1045')
        || str_contains($errorMessage, 'authentication')) {
        error_log(_('Database authentication failed. Check credentials in configuration. Exiting.'));

        return true;
    }

    // Database doesn't exist
    if (str_contains($errorMessage, 'Unknown database')
        || str_contains($errorMessage, '1049')) {
        error_log(_('Database does not exist. Check database name in configuration. Exiting.'));

        return true;
    }

    return false;
}

function waitForDatabase(): void
{
    $maxRetries = 10; // Maximum retry attempts before giving up
    $retryCount = 0;

    while (true) {
        try {
            $testScheduler = new Scheduler();
            $testScheduler->getCurrentJobs(); // Try a simple query
            unset($testScheduler);

            break;
        } catch (\Throwable $e) {
            $errorMessage = $e->getMessage();
            error_log('Database unavailable: '.$errorMessage);

            // Exit immediately on permanent errors
            if (isPermanentDatabaseError($errorMessage)) {
                exit(1);
            }

            ++$retryCount;

            if ($retryCount >= $maxRetries) {
                error_log(_('Maximum database connection retries exceeded. Exiting.'));

                exit(1);
            }

            error_log(sprintf(_('Retrying database connection in 30 seconds (%d/%d)...'), $retryCount, $maxRetries));
            sleep(30);
        }
    }
}

/**
 * Walk the running-jobs table and remove entries whose subprocesses have exited.
 * Logs non-zero exit codes and any stderr output for visibility.
 *
 * @param array<int, array{process: Process, jobId: int}> $runningJobs Passed by reference
 */
function reapCompletedJobs(array &$runningJobs): void
{
    foreach ($runningJobs as $key => $jobInfo) {
        if (!$jobInfo['process']->isRunning()) {
            $exitCode = (int) $jobInfo['process']->getExitCode();

            if ($exitCode !== 0) {
                error_log(sprintf('Job #%d subprocess exited with code %d', $jobInfo['jobId'], $exitCode));
                $stderr = trim($jobInfo['process']->getErrorOutput());

                if ($stderr !== '') {
                    error_log('Job #'.$jobInfo['jobId'].' stderr: '.$stderr);
                }
            }

            unset($runningJobs[$key]);
        }
    }
}

waitForDatabase();
$scheduler = new Scheduler();
$scheduler->logBanner('MultiFlexi Executor Daemon started');

// Announce parallelism mode
if ($maxParallel > 0) {
    error_log(sprintf('Parallel mode: up to %d concurrent jobs (MULTIFLEXI_MAX_PARALLEL=%d)', $maxParallel, $maxParallel));
} else {
    error_log('Parallel mode: unlimited concurrent jobs');
}

/** @var array<int, array{process: Process, jobId: int}> $runningJobs  keyed by schedule row id */
$runningJobs = [];
$shutdown = false;

// Graceful shutdown on SIGTERM / SIGINT: stop launching new jobs and let
// running ones complete.
if (\function_exists('pcntl_signal')) {
    pcntl_async_signals(true);
    $signalHandler = static function (int $signal) use (&$shutdown): void {
        error_log(sprintf('Received signal %d — stopping new job launches, waiting for running jobs to finish.', $signal));
        $shutdown = true;
    };
    pcntl_signal(\SIGTERM, $signalHandler);
    pcntl_signal(\SIGINT, $signalHandler);
}

do {
    // Proactive memory safeguard: trigger graceful shutdown if approaching limit.
    if ($memorySoftLimitMb > 0) {
        $usageBytes = memory_get_usage(true);
        $usageMb = (int) ($usageBytes / 1048576);

        if ($usageMb >= $memorySoftLimitMb) {
            error_log('Memory soft limit reached ('.$usageMb.' MB of '.$memorySoftLimitMb.' MB). Shutting down daemon gracefully.');
            $shutdown = true;
        }
    }

    // Collect finished subprocesses before trying to launch more.
    reapCompletedJobs($runningJobs);

    if (!$shutdown) {
        // How many new jobs may we start this cycle?
        $currentCount = count($runningJobs);
        $slotsAvailable = $maxParallel > 0
            ? max(0, $maxParallel - $currentCount)
            : \PHP_INT_MAX;

        if ($slotsAvailable > 0) {
            try {
                $jobsToLaunch = $scheduler->getCurrentJobs();

                if (!is_iterable($jobsToLaunch)) {
                    $jobsToLaunch = [];
                }
            } catch (\Throwable $e) {
                $errorMessage = $e->getMessage();
                error_log('Database error: '.$errorMessage);

                if (isPermanentDatabaseError($errorMessage)) {
                    exit(1);
                }

                waitForDatabase();
                $scheduler = new Scheduler();
                $jobsToLaunch = [];
            }

            $launched = 0;

            foreach ($jobsToLaunch as $scheduledJob) {
                if ($maxParallel > 0 && $launched >= $slotsAvailable) {
                    // Reached capacity for this cycle; remaining jobs will be
                    // picked up in a future cycle once slots free up.
                    break;
                }

                $scheduleId = (int) $scheduledJob['id'];
                $jobId = (int) $scheduledJob['job'];

                try {
                    // Remove from schedule immediately to claim the job and
                    // prevent another daemon instance from picking it up.
                    $scheduler->deleteFromSQL($scheduleId);

                    // Spawn executor.php as an isolated subprocess so that
                    // each job gets its own DB connection, memory space, and
                    // log context. No timeout — jobs may run arbitrarily long.
                    $process = new Process(
                        [PHP_BINARY, __DIR__.'/executor.php', '-j', (string) $jobId, '-e', $envFile],
                    );
                    $process->setTimeout(null);
                    $process->start();

                    $runningJobs[$scheduleId] = ['process' => $process, 'jobId' => $jobId];
                    ++$launched;
                } catch (\Throwable $e) {
                    error_log(sprintf('Failed to launch job #%d: %s', $jobId, $e->getMessage()));
                }
            }
        }
    }

    if ($daemonize && !$shutdown) {
        sleep((int) Shared::cfg('MULTIFLEXI_CYCLE_PAUSE', 10));
    }

} while ($daemonize && !$shutdown);

// Drain: wait for all in-flight jobs to finish before exiting.
if (!empty($runningJobs)) {
    error_log(sprintf('Shutdown: waiting for %d running job(s) to complete…', count($runningJobs)));

    while (!empty($runningJobs)) {
        reapCompletedJobs($runningJobs);
        sleep(1);
    }
}

$scheduler->logBanner('MultiFlexi Daemon ended');
