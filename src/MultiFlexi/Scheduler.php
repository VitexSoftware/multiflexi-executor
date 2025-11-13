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

class Scheduler extends \MultiFlexi\Engine
{
    /**
     * Mapping of interval codes to cron expressions.
     *
     * @var array<string, string>
     */
    public static array $intervCron = [
        'y' => '0 0 1 1 *',    // yearly
        'm' => '0 0 1 * *',    // monthly
        'w' => '0 0 * * 0',    // weekly (Sunday)
        'd' => '0 0 * * *',    // daily
        'h' => '0 * * * *',    // hourly
        'i' => '* * * * *',    // minutely
        'n' => '',             // disabled
    ];

    /**
     * @param null|int|string $identifier
     */
    public function __construct($identifier = null, array $options = [])
    {
        $this->myTable = 'schedule';
        $this->nameColumn = '';
        parent::__construct($identifier, $options);
    }

    /**
     * Add a job to be executed after given timestamp.
     *
     * @return int Inserted schedule row ID
     */
    public function addJob(Job $job, \DateTime $when): int
    {
        $job->getRuntemplate()->updateToSQL([
            'last_schedule' => $when->format('Y-m-d H:i:s'),
        ], ['id' => $job->getRuntemplate()->getMyKey()]);

        return $this->insertToSQL([
            'after' => $when->format('Y-m-d H:i:s'),
            'job' => $job->getMyKey(),
        ]);
    }

    /**
     * Retrieve jobs scheduled for execution (their 'after' time already passed).
     * Adds a schema verification step to avoid referencing non-existent columns.
     *
     * @return \Envms\FluentPDO\Queries\Select FluentPDO select query
     */
    public function getCurrentJobs()
    {
        $this->verifySchema();
        $databaseType = $this->getPdo()->getAttribute(\PDO::ATTR_DRIVER_NAME);

        switch ($databaseType) {
            case 'mysql':
                $condition = 'UNIX_TIMESTAMP(after) < UNIX_TIMESTAMP(NOW())';

                break;
            case 'sqlite':
                $condition = "strftime('%s', after) < strftime('%s', 'now')";

                break;
            case 'pgsql':
                $condition = 'EXTRACT(EPOCH FROM after) < EXTRACT(EPOCH FROM NOW())';

                break;
            case 'sqlsrv':
                $condition = "DATEDIFF(second, '1970-01-01', after) < DATEDIFF(second, '1970-01-01', GETDATE())";

                break;

            default:
                throw new \Exception(_('Unsupported database type ').$databaseType);
        }

        return $this->listingQuery()->orderBy('after')->where($condition);
    }

    /**
     * Check schedule table schema to detect accidental references to a missing 'type' column.
     * Logs a warning if 'type' does not exist but appears to be required by external code.
     * Does not modify schema; only diagnostic.
     */
    private function verifySchema(): void
    {
        try {
            $driver = $this->getPdo()->getAttribute(\PDO::ATTR_DRIVER_NAME);
            $columns = [];

            switch ($driver) {
                case 'mysql':
                    foreach ($this->getPdo()->query('SHOW COLUMNS FROM `schedule`')->fetchAll(\PDO::FETCH_ASSOC) as $col) {
                        $columns[] = $col['Field'] ?? '';
                    }

                    break;
                case 'sqlite':
                    foreach ($this->getPdo()->query('PRAGMA table_info(schedule)')->fetchAll(\PDO::FETCH_ASSOC) as $col) {
                        $columns[] = $col['name'] ?? '';
                    }

                    break;
                case 'pgsql':
                    foreach ($this->getPdo()->query("SELECT column_name FROM information_schema.columns WHERE table_name = 'schedule'")->fetchAll(\PDO::FETCH_ASSOC) as $col) {
                        $columns[] = $col['column_name'] ?? '';
                    }

                    break;
                case 'sqlsrv':
                    foreach ($this->getPdo()->query("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = 'schedule'")->fetchAll(\PDO::FETCH_ASSOC) as $col) {
                        $columns[] = $col['COLUMN_NAME'] ?? '';
                    }

                    break;
            }

            if (!\in_array('type', $columns, true)) {
                // Provide one-time warning (env guard to silence if needed)
                static $warned = false;

                if ($warned === false && getenv('MULTIFLEXI_SUPPRESS_TYPE_WARNING') !== '1') {
                    error_log(_('Schedule table has no "type" column; code will avoid its usage.'));
                    $warned = true;
                }
            }
        } catch (\Throwable $e) {
            $errorMessage = $e->getMessage();

            // Check for authentication errors and re-throw to trigger daemon exit
            if (str_contains($errorMessage, 'Access denied')
                || str_contains($errorMessage, '1045')
                || str_contains($errorMessage, 'authentication')) {
                throw $e; // Re-throw authentication errors to trigger daemon exit
            }

            // Non-fatal; just log other errors
            error_log(_('Schema verification failed: ').$errorMessage);
        }
    }
}
