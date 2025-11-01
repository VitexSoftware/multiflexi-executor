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
$memorySoftLimitMb = (int) (\Ease\Shared::cfg('MULTIFLEXI_MEMORY_LIMIT_MB', 0));
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

function waitForDatabase(): void
{
    while (true) {
        try {
            $testScheduler = new Scheduler();
            $testScheduler->getCurrentJobs(); // Try a simple query
            unset($testScheduler);

            break;
        } catch (\Throwable $e) {
            error_log('Database unavailable: '.$e->getMessage());
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
        $usageMb    = (int) ($usageBytes / 1048576);
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
        error_log('Database error: '.$e->getMessage());
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
        sleep(\Ease\Shared::cfg('MULTIFLEXI_CYCLE_PAUSE', 10));
    }
} while ($daemonize);

$scheduler->logBanner('MultiFlexi Daemon ended');
