<?php
namespace MultiFlexi;
/**
 * Description of MultiThreadJob
 *
 * @author Vitex <info@vitexsoftware.cz> 
 */
class MultiThreadJob extends \MultiFlexi\Job {
	/** @var null|\PDO */
	public null|\PDO $pdo = null;
	/** @var null|\Envms\FluentPDO\Query */
	public null|\Envms\FluentPDO\Query $fluent = null;

	public function __construct($jobId)
	{
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
			$dsn = "sqlite:" . ($dbName ?: ':memory:');
		} else {
			throw new \Exception("Unsupported DB_CONNECTION type: {$dbType}");
		}
		$pdoOptions = [
			\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION
		];
		if ($dbType === 'sqlite') {
			$this->pdo = new \PDO($dsn, null, null, $pdoOptions);
		} else {
			$this->pdo = new \PDO($dsn, $dbUser, $dbPass, $pdoOptions);
		}
		$this->fluent = new \Envms\FluentPDO\Query($this->pdo);
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
	 * Vlastní metoda pro DB operace přes vlastní FluentPDO
	 */
	public function getJobDataMultiThread()
	{
		// Příklad: použijte $this->fluent místo parent
		// return $this->fluent->from('jobs')->where(...)->fetch();
		// ...implementace dle potřeby...
	}
}
