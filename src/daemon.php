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
                    // CRITICAL: Completely isolate database connections in child process
                    
                    // 1. Close parent scheduler connections
                    if ($scheduler && $scheduler->pdo) {
                        $scheduler->pdo = null;
                    }
                    if ($scheduler && $scheduler->fluent) {
                        $scheduler->fluent = null;
                    }
                    $scheduler = null;
                    
                    // 2. Set environment variables to force new connections in child process
                    putenv('MULTIFLEXI_FORCE_NEW_DB_CONNECTION=1');
                    putenv('MULTIFLEXI_CHILD_PROCESS=' . getmypid());
                    putenv('DB_PERSISTENT=false');  // Disable persistent connections in child processes
                    
                    // 3. Force garbage collection to close connections
                    gc_collect_cycles();
                    
                    // Small delay to ensure complete connection cleanup
                    usleep(50000); // 50ms

                    $maxAttempts = 2;
                        $attempt = 0;
                        while ($attempt < $maxAttempts) {
                            try {
                                // Create fresh instances with new database connections
                                $job = new MultiThreadJob($scheduledJob['job']);
                                if (empty($job->getData()) === false) {
                                    $job->performJob();
                                } else {
                                    $job->addStatusMessage(sprintf(_('Job #%d Does not exists'), $scheduledJob['job']), 'error');
                                }
                                $childScheduler = new MultiThreadScheduler();
                                $childScheduler->deleteFromSQL($scheduledJob['id']);
                                $job->cleanUp();
                                
                                // Clean up child connections before exit
                                $childScheduler->pdo = null;
                                $childScheduler->fluent = null;
                                $job->pdo = null;
                                $job->fluent = null;
                                
                                break; // success
                            } catch (\PDOException $e) {
                                $errorCode = $e->getCode();
                                $errorMessage = $e->getMessage();
                                
                                // MySQL connection errors that warrant a retry
                                $retryableErrors = [
                                    'MySQL server has gone away',
                                    'Premature end of data',
                                    'Packets out of order',
                                    'Lost connection to MySQL server',
                                    'Connection refused',
                                    'Can\'t connect to MySQL server',
                                    'Too many connections'
                                ];
                                
                                // Check for retryable error codes (MySQL specific)
                                $retryableErrorCodes = [2006, 2013, 1040, 1205]; // CR_SERVER_GONE_ERROR, CR_SERVER_LOST, ER_CON_COUNT_ERROR, ER_LOCK_WAIT_TIMEOUT
                                
                                $shouldRetry = in_array($errorCode, $retryableErrorCodes) || 
                                               array_reduce($retryableErrors, function($carry, $errorPattern) use ($errorMessage) {
                                                   return $carry || strpos($errorMessage, $errorPattern) !== false;
                                               }, false);
                                
                                if ($shouldRetry && $attempt < $maxAttempts - 1) {
                                    error_log('Job error (child): Database connection issue (Code: ' . $errorCode . '), attempt ' . ($attempt + 1) . ': ' . $errorMessage);
                                    // Progressive backoff with some randomness to avoid thundering herd
                                    $backoffTime = (1 + $attempt) + (random_int(0, 1000) / 1000);
                                    usleep((int)($backoffTime * 1000000)); // Convert to microseconds
                                    $attempt++;
                                    continue;
                                } else {
                                    error_log('Job error (child): ' . $errorMessage . ' (Code: ' . $errorCode . ')');
                                    break;
                                }
                            }
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

    if ($daemonize) {
        sleep((int) \Ease\Shared::cfg('MULTIFLEXI_CYCLE_PAUSE', 10));
    }
} while ($daemonize);

$scheduler->logBanner('MultiFlexi Daemon ended');
