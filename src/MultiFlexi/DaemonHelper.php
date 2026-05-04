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

use Symfony\Component\Process\Process;

/**
 * Pure helper methods for the daemon's parallel scheduling logic.
 *
 * Extracted from daemon.php so they can be unit-tested independently.
 */
class DaemonHelper
{
    /**
     * Returns true for database errors that are permanent and should not be retried.
     * Authentication failures and missing databases belong here; network timeouts do not.
     */
    public static function isPermanentDatabaseError(string $message): bool
    {
        if (str_contains($message, 'Access denied')
            || str_contains($message, '1045')
            || str_contains($message, 'authentication')) {
            return true;
        }

        if (str_contains($message, 'Unknown database')
            || str_contains($message, '1049')) {
            return true;
        }

        return false;
    }

    /**
     * Calculate how many new job subprocesses may be started.
     *
     * @param int $maxParallel Configured limit (0 = unlimited)
     * @param int $currentRunning Number of currently running subprocesses
     *
     * @return int Available slots; PHP_INT_MAX when limit is 0 (unlimited)
     */
    public static function availableSlots(int $maxParallel, int $currentRunning): int
    {
        if ($maxParallel === 0) {
            return \PHP_INT_MAX;
        }

        return max(0, $maxParallel - $currentRunning);
    }

    /**
     * Remove completed job subprocesses from the tracking array.
     * Logs non-zero exit codes and any stderr output.
     *
     * @param array<int, array{process: Process, jobId: int}> $runningJobs Passed by reference
     */
    public static function reapCompletedJobs(array &$runningJobs): void
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
}
