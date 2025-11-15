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

date_default_timezone_set('Europe/Prague');

require_once '../vendor/autoload.php';
// Optional memory limit override from environment (in megabytes). If set, we will
// monitor current usage and gracefully exit before the OOM killer intervenes.
$memorySoftLimitMb = (int) \Ease\Shared::cfg('MULTIFLEXI_MEMORY_LIMIT_MB', 0);
\Ease\Shared::init(['DB_CONNECTION', 'DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD'], '../.env');
$daemonize = (bool) \Ease\Shared::cfg('MULTIFLEXI_DAEMONIZE', true);
$loggers = ['syslog', '\\MultiFlexi\\LogToSQL'];

if (\Ease\Shared::cfg('ZABBIX_SERVER') && \Ease\Shared::cfg('ZABBIX_HOST') && class_exists('\\MultiFlexi\\LogToZabbix')) {
    $loggers[] = '\\MultiFlexi\\LogToZabbix';
}

if (strtolower(\Ease\Shared::cfg('APP_DEBUG', 'false')) === 'true') {
    $loggers[] = 'console';
}

\define('EASE_LOGGER', implode('|', $loggers));
\Ease\Shared::user(new \Ease\Anonym());

$scheduler = null;

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

waitForDatabase();
$scheduler = new Scheduler();
$scheduler->logBanner('MultiFlexi Executor Daemon started');

do {
    // Proactive memory safeguard: exit if approaching limit.
    if ($memorySoftLimitMb > 0) {
        $usageBytes = memory_get_usage(true);
        $usageMb = (int) ($usageBytes / 1048576);

        if ($usageMb >= $memorySoftLimitMb) {
            error_log('Memory soft limit reached ('.$usageMb.' MB of '.$memorySoftLimitMb.' MB). Shutting down daemon gracefully.');

            break; // exits loop to allow banner + normal shutdown.
        }
    }

    try {
        $jobsToLaunch = $scheduler->getCurrentJobs();

        if (!is_iterable($jobsToLaunch)) {
            $jobsToLaunch = [];
        }
    } catch (\Throwable $e) {
        $errorMessage = $e->getMessage();
        error_log('Database error: '.$errorMessage);

        // Exit immediately on permanent errors
        if (isPermanentDatabaseError($errorMessage)) {
            exit(1);
        }

        waitForDatabase();
        $scheduler = new Scheduler();

        continue;
    }

    foreach ($jobsToLaunch as $scheduledJob) {
        try {
            $job = new Job($scheduledJob['job']);

            if (empty($job->getData()) === false) {
                $job->performJob();
            } else {
                $job->addStatusMessage(sprintf(_('Job #%d Does not exists'), $scheduledJob['job']), 'error');
            }

            $scheduler->deleteFromSQL($scheduledJob['id']);
            $job->cleanUp();
        } catch (\Throwable $e) {
            error_log('Job error: '.$e->getMessage());
        }
    }

    if ($daemonize) {
        sleep((int) \Ease\Shared::cfg('MULTIFLEXI_CYCLE_PAUSE', 10));
    }
} while ($daemonize);

$scheduler->logBanner('MultiFlexi Daemon ended');
