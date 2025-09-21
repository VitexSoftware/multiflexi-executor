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

use PhpParser\Node\Expr\AssignOp\Mul;

date_default_timezone_set('Europe/Prague');

require_once '../vendor/autoload.php';
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

// Optional async signals; we also explicitly reap to maintain our active children list
if (function_exists('pcntl_async_signals')) {
    pcntl_async_signals(true);
}

/**
 * Reap finished child processes and update the active children map.
 * @param array<int,int> $activeChildren pid => jobId
 */
function reapChildrenList(array &$activeChildren): void
{
    if (!function_exists('pcntl_waitpid')) {
        return;
    }
    while (($pid = pcntl_waitpid(-1, $status, WNOHANG)) > 0) {
        if (isset($activeChildren[$pid])) {
            unset($activeChildren[$pid]);
        }
    }
}

function waitForDatabase(): void
{
    while (true) {
        try {
            $testScheduler = new MultiThreadScheduler();
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
$scheduler = new MultiThreadScheduler();
$scheduler->logBanner('MultiFlexi Executor Daemon started');

$maxParallel = (int) \Ease\Shared::cfg('MULTIFLEXI_MAX_PARALLEL', 10); // 0 or <1 means unlimited
$activeChildren = [];

do {
    // Monitoring: print current MySQL connections
    try {
        $dbType = strtolower(\Ease\Shared::cfg('DB_CONNECTION', 'mysql'));
        if ($dbType === 'mysql' || $dbType === 'mariadb') {
            $dbHost = \Ease\Shared::cfg('DB_HOST', 'localhost');
            $dbPort = \Ease\Shared::cfg('DB_PORT', null);
            $dbName = \Ease\Shared::cfg('DB_DATABASE', null);
            $dbUser = \Ease\Shared::cfg('DB_USERNAME', null);
            $dbPass = \Ease\Shared::cfg('DB_PASSWORD', null);
            $dsn = "mysql:host={$dbHost};";
            if ($dbPort) {
                $dsn .= "port={$dbPort};";
            }
            $dsn .= "dbname={$dbName}";
            $pdoOptions = [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION
            ];
            $pdo = new \PDO($dsn, $dbUser, $dbPass, $pdoOptions);
            $stmt = $pdo->query("SHOW STATUS LIKE 'Threads_connected'");
            $row = $stmt->fetch();
            $activeConnections = $row['Value'] ?? 'unknown';
            error_log('Current MySQL connections: ' . $activeConnections);
            $pdo = null;
        }
    } catch (\Throwable $e) {
        error_log('MySQL connection monitor error: ' . $e->getMessage());
    }
    try {
        $jobsToLaunch = $scheduler->getCurrentJobs();

        if (!is_iterable($jobsToLaunch)) {
            $jobsToLaunch = [];
        }
    } catch (\Throwable $e) {
        error_log('Database error: '.$e->getMessage());
        waitForDatabase();
        $scheduler = new MultiThreadScheduler();

        continue;
    }

    foreach ($jobsToLaunch as $scheduledJob) {
        // If pcntl is available, fork a child per job to execute in parallel
        if (function_exists('pcntl_fork')) {
            // Respect concurrency limit if configured
            if ($maxParallel > 0) {
                // Try to reap finished children first
                reapChildrenList($activeChildren);
                while (count($activeChildren) >= $maxParallel) {
                    // Wait briefly and keep reaping until a slot frees up
                    reapChildrenList($activeChildren);
                    usleep(100000); // 100ms
                }
            }

            $pid = pcntl_fork();

            if ($pid === -1) {
                error_log('Failed to fork for job #'.$scheduledJob['job'].'; running synchronously.');
                // Fallback to synchronous execution
                try {
                    $job = new MultiThreadJob($scheduledJob['job']);
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
            } elseif ($pid === 0) {
                    // Child process: run the job and exit
                    // Disconnect from parent's database connection
                    $scheduler = null;

                    $maxAttempts = 2;
                        $attempt = 0;
                        while ($attempt < $maxAttempts) {
                            try {
                                // Always create a fresh MultiThreadJob for each attempt
                                $job = new MultiThreadJob($scheduledJob['job']);
                                if (empty($job->getData()) === false) {
                                    $job->performJob();
                                } else {
                                    $job->addStatusMessage(sprintf(_('Job #%d Does not exists'), $scheduledJob['job']), 'error');
                                }
                                $childScheduler = new MultiThreadScheduler();
                                $childScheduler->deleteFromSQL($scheduledJob['id']);
                                $job->cleanUp();
                                break; // success
                            } catch (\PDOException $e) {
                                if (strpos($e->getMessage(), 'MySQL server has gone away') !== false) {
                                    error_log('Job error (child): MySQL server has gone away, reconnecting...');
                                    sleep(2); // short pause before retry
                                    $attempt++;
                                    continue;
                                } else {
                                    error_log('Job error (child): ' . $e->getMessage());
                                    break;
                                }
                            } catch (\Throwable $e) {
                                error_log('Job error (child): ' . $e->getMessage());
                                break;
                            }
                        }
                    // Ensure child terminates
                    exit(0);
            } else {
                // Parent: record active child and continue to next scheduled job (non-blocking)
                $activeChildren[$pid] = (int) $scheduledJob['job'];
                continue;
            }
        } else {
            // pcntl not available: execute sequentially (original behavior)
            try {
                $job = new MultiThreadJob($scheduledJob['job']);

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
    }

    // Reap any finished children since last iteration to keep the active set accurate
    reapChildrenList($activeChildren);

    if ($daemonize) {
        sleep(\Ease\Shared::cfg('MULTIFLEXI_CYCLE_PAUSE', 10));
    }
} while ($daemonize);

$scheduler->logBanner('MultiFlexi Daemon ended');
