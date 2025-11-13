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

use MultiFlexi\Scheduler;
use PHPUnit\Framework\TestCase;

/**
 * SchedulerTest.
 *
 * Validates that the overridden Scheduler operates without fatal error
 * when the underlying `schedule` table has no `type` column. Uses an
 * in-memory SQLite database to emulate schema.
 */
class SchedulerTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('CREATE TABLE schedule (id INTEGER PRIMARY KEY AUTOINCREMENT, after TEXT NOT NULL, job INTEGER NOT NULL)');
        // Insert one job due now
        $this->pdo->exec("INSERT INTO schedule (after, job) VALUES (datetime('now','-1 minute'), 42)");
    }

    /**
     * Test that getCurrentJobs returns a traversable query and does not throw when 'type' column is absent.
     */
    public function testGetCurrentJobsWithoutTypeColumn(): void
    {
        $scheduler = $this->makeScheduler();
        $jobsQuery = $scheduler->getCurrentJobs();
        $this->assertIsIterable($jobsQuery, 'Returned value should be iterable');
        $rows = [];

        foreach ($jobsQuery as $row) {
            $rows[] = $row;
        }

        $this->assertNotEmpty($rows, 'Should fetch at least one scheduled job');
        $this->assertSame(42, $rows[0]['job']);
    }

    /**
     * Provide a minimal Scheduler bound to our test PDO.
     */
    private function makeScheduler(): Scheduler
    {
        $scheduler = new Scheduler();
        // Inject PDO by reflection since parent Engine holds it internally.
        $ref = new ReflectionClass($scheduler);
        $prop = $ref->getProperty('pdo');
        $prop->setAccessible(true);
        $prop->setValue($scheduler, $this->pdo);

        return $scheduler;
    }
}
