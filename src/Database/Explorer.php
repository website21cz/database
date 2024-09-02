<?php

/**
 * This file is part of the Nette Framework (https://nette.org)
 * Copyright (c) 2004 David Grudl (https://davidgrudl.com)
 */

declare(strict_types=1);

namespace Nette\Database;

use JetBrains\PhpStorm\Language;
use Nette;
use Nette\Caching\Cache;
use Nette\Utils\Arrays;


/**
 * The central access point to Nette Database functionality.
 */
class Explorer
{
	private const Drivers = [
		'pdo-mssql' => Drivers\PDO\MSSQL\Driver::class,
		'pdo-mysql' => Drivers\PDO\MySQL\Driver::class,
		'pdo-oci' => Drivers\PDO\OCI\Driver::class,
		'pdo-odbc' => Drivers\PDO\ODBC\Driver::class,
		'pdo-pgsql' => Drivers\PDO\PgSQL\Driver::class,
		'pdo-sqlite' => Drivers\PDO\SQLite\Driver::class,
		'pdo-sqlsrv' => Drivers\PDO\SQLSrv\Driver::class,
	];
	private const TypeConverterOptions = ['convertBoolean', 'convertDateTime', 'convertDecimal', 'newDateTime'];

	/** @var array<callable(self): void>  Occurs after connection is established */
	public array $onConnect = [];

	/** @var array<callable(self, Result|DriverException): void>  Occurs after query is executed */
	public array $onQuery = [];
	private Drivers\Driver $driver;
	private ?Drivers\Connection $connection = null;
	private Drivers\Engine $engine;
	private SqlPreprocessor $preprocessor;
	private TypeConverter $typeConverter;
	private ?SqlLiteral $lastQuery = null;
	private int $transactionDepth = 0;
	private ?Cache $cache = null;
	private ?Conventions $conventions = null;
	private ?Structure $structure = null;


	public function __construct(
		string $dsn,
		?string $username = null,
		#[\SensitiveParameter]
		?string $password = null,
		array $options = [],
	) {
		$driver = explode(':', $dsn)[0];
		$class = empty($options['driverClass'])
			? (self::Drivers['pdo-' . $driver] ?? throw new \LogicException("Unknown PDO driver '$driver'."))
			: $options['driverClass'];
		$args = compact('dsn', 'username', 'password', 'options');
		unset($options['lazy'], $options['driverClass']);
		foreach ($options as $key => $value) {
			if (!is_int($key) && $value !== null) {
				$args[$key] = $value;
				unset($args['options'][$key]);
			}
		}
		$args = array_diff_key($args, array_flip(self::TypeConverterOptions));
		$this->driver = new $class(...$args);
		$this->typeConverter = new TypeConverter;
		array_map(fn($opt) => isset($options[$opt]) && ($this->typeConverter->$opt = (bool) $options[$opt]), self::TypeConverterOptions);
	}


	public function connect(): void
	{
		if ($this->connection) {
			return;
		}

		try {
			$this->connection = $this->driver->connect();
		} catch (DriverException $e) {
			throw ConnectionException::from($e);
		}

		Arrays::invoke($this->onConnect, $this);
	}


	public function reconnect(): void
	{
		$this->disconnect();
		$this->connect();
	}


	public function disconnect(): void
	{
		$this->connection = null;
	}


	/** @deprecated */
	public function getDsn(): string
	{
		throw new Nette\DeprecatedException(__METHOD__ . '() is deprecated.');
	}


	/** @deprecated use getConnection()->getNativeConnection() */
	public function getPdo(): \PDO
	{
		trigger_error(__METHOD__ . '() is deprecated, use getConnection()->getNativeConnection()', E_USER_DEPRECATED);
		return $this->getConnection()->getNativeConnection();
	}


	public function getConnection(): Drivers\Connection
	{
		$this->connect();
		return $this->connection;
	}


	/** @deprecated use getConnection() */
	public function getSupplementalDriver(): Drivers\Connection
	{
		trigger_error(__METHOD__ . '() is deprecated, use getConnection()', E_USER_DEPRECATED);
		return $this->getConnection();
	}


	public function getDatabaseEngine(): Drivers\Engine
	{
		return $this->engine ??= $this->driver->createEngine(new Drivers\Accessory\LazyConnection($this->getConnection(...)));
	}


	public function getServerVersion(): string
	{
		return $this->getConnection()->getServerVersion();
	}


	public function getReflection(): Reflection
	{
		return new Reflection($this->getDatabaseEngine());
	}


	public function getTypeConverter(): TypeConverter
	{
		return $this->typeConverter;
	}


	/** @deprecated */
	public function setRowNormalizer(?callable $normalizer): static
	{
		throw new Nette\DeprecatedException(__METHOD__ . "() is deprecated, configure 'convert*' options instead.");
	}


	public function getInsertId(?string $sequence = null): int|string
	{
		try {
			return $this->getConnection()->getInsertId($sequence);
		} catch (DriverException $e) {
			throw $this->convertException($e);
		}
	}


	public function quote(string $string): string
	{
		return $this->getConnection()->quote($string);
	}


	public function beginTransaction(): void
	{
		if ($this->transactionDepth !== 0) {
			throw new \LogicException(__METHOD__ . '() call is forbidden inside a transaction() callback');
		}

		$this->logOperation($this->getConnection()->beginTransaction(...), new SqlLiteral('BEGIN TRANSACTION'));
	}


	public function commit(): void
	{
		if ($this->transactionDepth !== 0) {
			throw new \LogicException(__METHOD__ . '() call is forbidden inside a transaction() callback');
		}

		$this->logOperation($this->getConnection()->commit(...), new SqlLiteral('COMMIT'));
	}


	public function rollBack(): void
	{
		if ($this->transactionDepth !== 0) {
			throw new \LogicException(__METHOD__ . '() call is forbidden inside a transaction() callback');
		}

		$this->logOperation($this->getConnection()->rollBack(...), new SqlLiteral('ROLLBACK'));
	}


	public function transaction(callable $callback): mixed
	{
		if ($this->transactionDepth === 0) {
			$this->beginTransaction();
		}

		$this->transactionDepth++;
		try {
			$res = $callback($this);
		} catch (\Throwable $e) {
			$this->transactionDepth--;
			if ($this->transactionDepth === 0) {
				$this->rollback();
			}

			throw $e;
		}

		$this->transactionDepth--;
		if ($this->transactionDepth === 0) {
			$this->commit();
		}

		return $res;
	}


	/**
	 * Generates and executes SQL query.
	 * @param  literal-string  $sql
	 */
	public function query(#[Language('SQL')] string $sql, #[Language('GenericSQL')] ...$params): Result
	{
		[$sql, $params] = $this->preprocess($sql, ...$params);
		return $this->logOperation(
			fn() => $this->connection->query($sql, $params),
			$this->lastQuery = new SqlLiteral($sql, $params),
		);
	}


	/** @deprecated  use query() */
	public function queryArgs(string $sql, array $params): Result
	{
		trigger_error(__METHOD__ . '() is deprecated, use query()', E_USER_DEPRECATED);
		return $this->query($sql, ...$params);
	}


	/**
	 * @param  literal-string  $sql
	 * @return array{string, array}
	 */
	public function preprocess(string $sql, ...$params): array
	{
		$this->connect();
		$this->preprocessor ??= new SqlPreprocessor($this);
		return $params
			? $this->preprocessor->process(func_get_args())
			: [$sql, []];
	}


	private function logOperation(\Closure $callback, SqlLiteral $query): Result
	{
		try {
			$time = microtime(true);
			$result = $callback();
			$time = microtime(true) - $time;
		} catch (DriverException $e) {
			$e = $this->convertException($e);
			Arrays::invoke($this->onQuery, $this, $e);
			throw $e;
		}

		$result = new Result($this, $query, $result, $time);
		Arrays::invoke($this->onQuery, $this, $result);
		return $result;
	}


	public function getLastQuery(): ?SqlLiteral
	{
		return $this->lastQuery;
	}


	/** @deprecated use getLastQuery()->getSql() */
	public function getLastQueryString(): ?string
	{
		trigger_error(__METHOD__ . '() is deprecated, use getLastQuery()->getSql()', E_USER_DEPRECATED);
		return $this->lastQuery?->getSql();
	}


	private function convertException(DriverException $e): DriverException
	{
		$class = $this->getDatabaseEngine()->classifyException($e);
		return $class ? $class::from($e) : $e;
	}


	/********************* shortcuts ****************d*g**/


	/**
	 * Shortcut for query()->fetch()
	 * @param  literal-string  $sql
	 */
	public function fetch(#[Language('SQL')] string $sql, #[Language('GenericSQL')] ...$params): ?Row
	{
		return $this->query($sql, ...$params)->fetch();
	}


	/**
	 * Shortcut for query()->fetchAssoc()
	 * @param  literal-string  $sql
	 */
	public function fetchAssoc(#[Language('SQL')] string $sql, #[Language('GenericSQL')] ...$params): ?array
	{
		return $this->query($sql, ...$params)->fetchAssoc();
	}


	/**
	 * Shortcut for query()->fetchField()
	 * @param  literal-string  $sql
	 */
	public function fetchField(#[Language('SQL')] string $sql, #[Language('GenericSQL')] ...$params): mixed
	{
		return $this->query($sql, ...$params)->fetchField();
	}


	/**
	 * Shortcut for query()->fetchList()
	 * @param  literal-string  $sql
	 */
	public function fetchList(#[Language('SQL')] string $sql, #[Language('GenericSQL')] ...$params): ?array
	{
		return $this->query($sql, ...$params)->fetchList();
	}


	/**
	 * Shortcut for query()->fetchList()
	 * @param  literal-string  $sql
	 */
	public function fetchFields(#[Language('SQL')] string $sql, #[Language('GenericSQL')] ...$params): ?array
	{
		return $this->query($sql, ...$params)->fetchList();
	}


	/**
	 * Shortcut for query()->fetchPairs()
	 * @param  literal-string  $sql
	 */
	public function fetchPairs(#[Language('SQL')] string $sql, #[Language('GenericSQL')] ...$params): array
	{
		return $this->query($sql, ...$params)->fetchPairs();
	}


	/**
	 * Shortcut for query()->fetchAll()
	 * @param  literal-string  $sql
	 */
	public function fetchAll(#[Language('SQL')] string $sql, #[Language('GenericSQL')] ...$params): array
	{
		return $this->query($sql, ...$params)->fetchAll();
	}


	public static function literal(string $value, ...$params): SqlLiteral
	{
		return new SqlLiteral($value, $params);
	}


	/********************* active row ****************d*g**/


	public function table(string $table): Table\Selection
	{
		return new Table\Selection($this, $table);
	}


	public function setCache(Cache $cache): static
	{
		if (isset($this->structure)) {
			throw new \LogicException('Cannot set cache after structure is created.');
		}
		$this->cache = $cache;
		return $this;
	}


	/** @internal */
	public function getCache(): ?Cache
	{
		return $this->cache;
	}


	public function setConventions(Conventions $conventions): static
	{
		if (isset($this->conventions)) {
			throw new \LogicException('Conventions are already set.');
		}
		$this->conventions = $conventions;
		return $this;
	}


	/** @internal */
	public function getConventions(): Conventions
	{
		return $this->conventions ??= new Conventions\DiscoveredConventions($this->getStructure());
	}


	/** @internal */
	public function getStructure(): Structure
	{
		return $this->structure ??= new Structure($this->getDatabaseEngine(), $this->getCache());
	}
}


class_exists(Connection::class);
