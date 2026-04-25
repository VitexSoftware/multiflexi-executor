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

use MultiFlexi\DaemonHelper;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * Unit tests for the parallel daemon scheduling helpers (DaemonHelper).
 *
 * All tests are pure / offline — no database or external process is required.
 */
class ParallelDaemonTest extends TestCase
{
    // ------------------------------------------------------------------ //
    // isPermanentDatabaseError                                             //
    // ------------------------------------------------------------------ //

    public function testAccessDeniedIsPermanent(): void
    {
        $this->assertTrue(
            DaemonHelper::isPermanentDatabaseError("SQLSTATE[HY000] [1045] Access denied for user 'root'@'localhost'"),
        );
    }

    public function testErrorCode1045IsPermanent(): void
    {
        $this->assertTrue(
            DaemonHelper::isPermanentDatabaseError('error 1045: authentication failure'),
        );
    }

    public function testAuthenticationKeywordIsPermanent(): void
    {
        $this->assertTrue(
            DaemonHelper::isPermanentDatabaseError('authentication failed: bad password'),
        );
    }

    public function testUnknownDatabaseIsPermanent(): void
    {
        $this->assertTrue(
            DaemonHelper::isPermanentDatabaseError("Unknown database 'multiflexi'"),
        );
    }

    public function testErrorCode1049IsPermanent(): void
    {
        $this->assertTrue(
            DaemonHelper::isPermanentDatabaseError('SQLSTATE[HY000] [1049] error 1049'),
        );
    }

    public function testConnectionRefusedIsTransient(): void
    {
        $this->assertFalse(
            DaemonHelper::isPermanentDatabaseError('Connection refused'),
        );
    }

    public function testNetworkTimeoutIsTransient(): void
    {
        $this->assertFalse(
            DaemonHelper::isPermanentDatabaseError('SQLSTATE[HY000] [2002] Connection timed out'),
        );
    }

    public function testGenericPdoErrorIsTransient(): void
    {
        $this->assertFalse(
            DaemonHelper::isPermanentDatabaseError('General error: 2006 MySQL server has gone away'),
        );
    }

    // ------------------------------------------------------------------ //
    // availableSlots                                                       //
    // ------------------------------------------------------------------ //

    public function testUnlimitedSlotsWhenMaxParallelIsZero(): void
    {
        $this->assertSame(\PHP_INT_MAX, DaemonHelper::availableSlots(0, 0));
        $this->assertSame(\PHP_INT_MAX, DaemonHelper::availableSlots(0, 99));
    }

    public function testCorrectSlotsCalculated(): void
    {
        $this->assertSame(3, DaemonHelper::availableSlots(5, 2));
        $this->assertSame(1, DaemonHelper::availableSlots(4, 3));
    }

    public function testZeroSlotsAtCapacity(): void
    {
        $this->assertSame(0, DaemonHelper::availableSlots(4, 4));
    }

    public function testZeroSlotsWhenOverCapacity(): void
    {
        // Should never go negative even if running count exceeds configured limit.
        $this->assertSame(0, DaemonHelper::availableSlots(3, 5));
    }

    public function testSingleSlotLimit(): void
    {
        $this->assertSame(1, DaemonHelper::availableSlots(1, 0));
        $this->assertSame(0, DaemonHelper::availableSlots(1, 1));
    }

    // ------------------------------------------------------------------ //
    // reapCompletedJobs                                                    //
    // ------------------------------------------------------------------ //

    public function testCompletedJobWithExitZeroIsReaped(): void
    {
        $process = $this->mockProcess(running: false, exitCode: 0);

        $jobs = [42 => ['process' => $process, 'jobId' => 100]];

        DaemonHelper::reapCompletedJobs($jobs);

        $this->assertEmpty($jobs, 'Completed job should be removed from the tracking array');
    }

    public function testRunningJobIsKept(): void
    {
        $process = $this->mockProcess(running: true);

        $jobs = [42 => ['process' => $process, 'jobId' => 100]];

        DaemonHelper::reapCompletedJobs($jobs);

        $this->assertCount(1, $jobs, 'Running job must not be removed');
    }

    public function testFailedJobIsReapedAndDoesNotThrow(): void
    {
        $process = $this->mockProcess(running: false, exitCode: 1, stderr: 'something went wrong');

        $jobs = [7 => ['process' => $process, 'jobId' => 99]];

        // Must not throw; error_log is the side-effect, not an exception.
        DaemonHelper::reapCompletedJobs($jobs);

        $this->assertEmpty($jobs, 'Failed job should still be reaped');
    }

    public function testMixedJobsReapCorrectly(): void
    {
        $runningProcess = $this->mockProcess(running: true);
        $doneProcess = $this->mockProcess(running: false, exitCode: 0);

        $jobs = [
            1 => ['process' => $runningProcess, 'jobId' => 10],
            2 => ['process' => $doneProcess, 'jobId' => 20],
        ];

        DaemonHelper::reapCompletedJobs($jobs);

        $this->assertCount(1, $jobs);
        $this->assertArrayHasKey(1, $jobs, 'Running job must remain');
        $this->assertArrayNotHasKey(2, $jobs, 'Completed job must be removed');
    }

    public function testMultipleCompletedJobsAllReaped(): void
    {
        $jobs = [];

        for ($i = 0; $i < 5; ++$i) {
            $jobs[$i] = ['process' => $this->mockProcess(running: false, exitCode: 0), 'jobId' => $i + 1];
        }

        DaemonHelper::reapCompletedJobs($jobs);

        $this->assertEmpty($jobs, 'All completed jobs should be reaped in a single pass');
    }

    public function testEmptyArrayHandledGracefully(): void
    {
        $jobs = [];
        DaemonHelper::reapCompletedJobs($jobs);
        $this->assertEmpty($jobs);
    }

    // ------------------------------------------------------------------ //
    // Helpers                                                              //
    // ------------------------------------------------------------------ //

    private function mockProcess(bool $running, int $exitCode = 0, string $stderr = ''): Process
    {
        $process = $this->createMock(Process::class);
        $process->method('isRunning')->willReturn($running);

        if (!$running) {
            $process->method('getExitCode')->willReturn($exitCode);
            $process->method('getErrorOutput')->willReturn($stderr);
        }

        return $process;
    }
}
