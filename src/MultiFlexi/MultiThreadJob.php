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

/**
 * Job variant that owns its own PDO/FluentPDO handles for execution in forked children.
 */
class MultiThreadJob extends \MultiFlexi\Job
{
    public ?\PDO $pdo = null;

    public ?\Envms\FluentPDO\Query $fluent = null;

    public function __construct($jobId)
    {
        // CRITICAL: Disable persistent connections in multithread environment
        putenv('DB_PERSISTENT=false');

        // CRITICAL: Must create database connection BEFORE calling parent constructor
        // to prevent using inherited connections
        $this->createFreshConnection();

        // Call parent constructor - it should detect and use our fresh connection
        parent::__construct($jobId);
    }

    public function __destruct()
    {
        // Korektní uzavření PDO spojení
        if ($this->pdo instanceof \PDO) {
            $this->pdo = null;
        }

        $this->fluent = null;
    }

    /**
     * Override parent getPdo() to use our connection with validation.
     *
     * @param mixed $properties
     */
    public function getPdo($properties = [])
    {
        if (!$this->isConnectionAlive()) {
            error_log('MultiThreadJob: Reconnecting to database...');
            $this->pdo = null;
            $this->fluent = null;
            $this->createFreshConnection();
        }

        return $this->pdo;
    }

    /**
     * Override parent getFluentPDO() to use our connection.
     */
    public function getFluentPDO(bool $read = false, bool $write = false)
    {
        if (!$this->fluent instanceof \Envms\FluentPDO\Query) {
            if (!$this->pdo instanceof \PDO) {
                $this->createFreshConnection();
            }

            $this->fluent = new \Envms\FluentPDO\Query($this->pdo);
            $this->fluent->exceptionOnError = true;
        }

        return $this->fluent;
    }

    /**
     * Create a fresh database connection for this instance.
     */
    private function createFreshConnection(): void
    {
        // Force a completely new connection by adding a unique identifier
        $connectionId = uniqid('mt_', true);

        $dbType = strtolower(\Ease\Shared::cfg('DB_CONNECTION', 'mysql'));
        $dbHost = \Ease\Shared::cfg('DB_HOST', 'localhost');
        $dbPort = \Ease\Shared::cfg('DB_PORT', null);
        $dbName = \Ease\Shared::cfg('DB_DATABASE', null);
        $dbUser = \Ease\Shared::cfg('DB_USERNAME', null);
        $dbPass = \Ease\Shared::cfg('DB_PASSWORD', null);
        $dsn = '';

        if ($dbType === 'mysql' || $dbType === 'mariadb') {
            $dsn = "mysql:host={$dbHost};";

            if ($dbPort) {
                $dsn .= "port={$dbPort};";
            }

            $dsn .= "dbname={$dbName}";
        } elseif ($dbType === 'pgsql' || $dbType === 'postgresql') {
            $dsn = "pgsql:host={$dbHost};";

            if ($dbPort) {
                $dsn .= "port={$dbPort};";
            }

            $dsn .= "dbname={$dbName}";
        } elseif ($dbType === 'sqlite') {
            $dsn = 'sqlite:'.($dbName ?: ':memory:');
        } else {
            throw new \Exception("Unsupported DB_CONNECTION type: {$dbType}");
        }

        $pdoOptions = [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_TIMEOUT => 10,
            \PDO::ATTR_PERSISTENT => false,  // Never use persistent connections in multi-process environment
            \PDO::ATTR_EMULATE_PREPARES => false,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
        ];

        // Add MySQL-specific options only for MySQL databases
        if ($dbType === 'mysql' || $dbType === 'mariadb') {
            $pdoOptions[\PDO::MYSQL_ATTR_INIT_COMMAND] = 'SET NAMES utf8mb4';
            $pdoOptions[\PDO::MYSQL_ATTR_CONNECT_TIMEOUT] = 10;
            $pdoOptions[\PDO::MYSQL_ATTR_READ_TIMEOUT] = 30;
            $pdoOptions[\PDO::MYSQL_ATTR_WRITE_TIMEOUT] = 30;
        }

        try {
            if ($dbType === 'sqlite') {
                $this->pdo = new \PDO($dsn, null, null, $pdoOptions);
            } else {
                $this->pdo = new \PDO($dsn, $dbUser, $dbPass, $pdoOptions);
            }

            $this->fluent = new \Envms\FluentPDO\Query($this->pdo);
        } catch (\PDOException $e) {
            error_log('MultiThreadJob: Failed to create database connection: '.$e->getMessage());

            throw $e;
        }
    }

    /**
     * Validate if PDO connection is still alive.
     */
    private function isConnectionAlive(): bool
    {
        if (!$this->pdo instanceof \PDO) {
            return false;
        }

        try {
            // Try a simple query to test connection
            $this->pdo->query('SELECT 1')->fetchColumn();

            return true;
        } catch (\PDOException $e) {
            error_log('MultiThreadJob: Connection validation failed: '.$e->getMessage());

            return false;
        }
    }
}
