<?php

/*
 * WPDB Connection
 */

namespace FluentCart\Framework\Database\Query;

use Closure;
use Exception;
use DateTimeInterface;
use FluentCart\Framework\Foundation\App;
use FluentCart\Framework\Database\Schema;
use FluentCart\Framework\Database\QueryException;
use FluentCart\Framework\Database\ConnectionInterface;
use FluentCart\Framework\Database\MultipleColumnsSelectedException;
use FluentCart\Framework\Database\Events\QueryExecuted;
use FluentCart\Framework\Database\Query\Expression;
use FluentCart\Framework\Database\Query\Processors\Processor;
use FluentCart\Framework\Database\Query\Processors\MySqlProcessor;
use FluentCart\Framework\Database\Query\Processors\SQLiteProcessor;
use FluentCart\Framework\Database\Query\Builder as QueryBuilder;
use FluentCart\Framework\Database\Query\Grammars\Grammar;
use FluentCart\Framework\Database\Query\Grammars\MySqlGrammar;
use FluentCart\Framework\Database\Query\Grammars\SQLiteGrammar;
use FluentCart\Framework\Database\Concerns\ManagesTransactions;
use FluentCart\Framework\Database\DetectsLostConnections;

use FluentCart\Framework\Database\Events\TransactionBeginning;
use FluentCart\Framework\Database\Events\TransactionCommitted;
use FluentCart\Framework\Database\Events\TransactionCommitting;
use FluentCart\Framework\Database\Events\TransactionRolledBack;

class WPDBConnection implements ConnectionInterface
{
    use DetectsLostConnections, ManagesTransactions;

    /**
     * $wpdb Global $wpdb instance
     * @var Object
     */
    protected $wpdb;

    /**
     * The name of the connected database.
     *
     * @var string
     */
    protected $database;

    /**
     * The table prefix for the connection.
     *
     * @var string
     */
    protected $tablePrefix = '';

    /**
     * The database connection configuration options.
     *
     * @var array
     */
    protected $config = [];

    /**
     * The query grammar implementation.
     *
     * @var \FluentCart\Framework\Database\Query\Grammars\Grammar
     */
    protected $queryGrammar;

    /**
     * The query post processor implementation.
     *
     * @var \FluentCart\Framework\Database\Query\Processors\Processor
     */
    protected $postProcessor;

    /**
     * The number of active transactions.
     *
     * @var int
     */
    protected $transactions = 0;

    /**
     * The transaction manager instance.
     *
     * @var \FluentCart\Framework\Database\DatabaseTransactionsManager|null
     */
    protected $transactionsManager;

    /**
     * All of the callbacks that should be invoked before a transaction is started.
     *
     * @var \Closure[]
     */
    protected $beforeStartingTransaction = [];

    /**
     * The event dispatcher.
     *
     * @var \FluentCart\Framework\Events\Dispatcher
     */
    protected $event = null;

    /**
     * Create a new database connection instance.
     *
     * @param \wpdb $wpdb The WordPress database instance.
     * @return void
     */
    public function __construct($wpdb)
    {
        $this->setupWpdbInstance($wpdb);

        $this->useDefaultQueryGrammar();

        $this->useDefaultPostProcessor();

        $this->event = App::make('events');
    }

    /**
     * Populate $wpdb instance & turn off db errors
     *
     * @param  $wpdb Global $wpdb instance
     * @return Null
     */
    protected function setupWpdbInstance($wpdb)
    {
        $this->wpdb = $wpdb;

        $this->wpdb->show_errors(
            $this->shouldShowErrors()
        );
    }

    /**
     * Determine if database errors should be shown.
     *
     * @return bool
     */
    protected function shouldShowErrors()
    {
        return strpos(App::env(), 'prod') === false;
    }

    /**
     * Set the query grammar to the default implementation.
     *
     * @return void
     */
    public function useDefaultQueryGrammar()
    {
        $this->queryGrammar = $this->getDefaultQueryGrammar();
    }

    /**
     * Get the default query grammar instance.
     *
     * @return \FluentCart\Framework\Database\Query\Grammars\Grammar
     */
    protected function getDefaultQueryGrammar()
    {
        return $this->isSqlite() ? new SQLiteGrammar : new MySqlGrammar;
    }

    /**
     * Set the query post processor to the default implementation.
     *
     * @return void
     */
    public function useDefaultPostProcessor()
    {
        $this->postProcessor = $this->getDefaultPostProcessor();
    }

    /**
     * Get the default post processor instance.
     *
     * @return \FluentCart\Framework\Database\Query\Processors\Processor
     */
    protected function getDefaultPostProcessor()
    {
        return $this->isSqlite() ? new SQLiteProcessor : new MySqlProcessor;
    }

    /**
     * Begin a fluent query against a database table.
     *
     * @param \Closure|\FluentCart\Framework\Database\Query\Builder|string $table
     * @param string|null $as
     * @return \FluentCart\Framework\Database\Query\Builder
     */
    public function table($table, $as = null)
    {
        return $this->query()->from($table, $as);
    }

    /**
     * Get a new query builder instance.
     *
     * @return \FluentCart\Framework\Database\Query\Builder
     */
    public function query()
    {
        return new QueryBuilder(
            $this, $this->getQueryGrammar(), $this->getPostProcessor()
        );
    }

    /**
     * Run a select statement and return a single result.
     *
     * @param string $query
     * @param array $bindings
     * @return mixed
     */
    public function selectOne($query, $bindings = [])
    {
        return $this->run($query, $bindings, function ($query, $bindings) {
            $query = $this->bindParams($query, $bindings);

            $result = $this->wpdb->get_row($query);

            if ($result === false || $this->wpdb->last_error) {
                throw new QueryException(
                    $query, $bindings, new Exception($this->wpdb->last_error)
                );
            }

            return $result;
        });
    }

    /**
     * Run a select statement and return the first column of the first row.
     *
     * @param string $query
     * @param array $bindings
     * @return mixed
     *
     * @throws \FluentCart\Framework\Database\MultipleColumnsSelectedException
     */
    public function scalar($query, $bindings = [])
    {
        $record = $this->selectOne($query, $bindings);

        if (is_null($record)) {
            return null;
        }

        $record = (array)$record;

        if (count($record) > 1) {
            throw new MultipleColumnsSelectedException(
                'The query returned more than one column.'
            );
        }

        return reset($record);
    }

    /**
     * Run a select statement against the database.
     *
     * @param string $query
     * @param array $bindings
     * @return array
     */
    public function select($query, $bindings = [])
    {
        return $this->run($query, $bindings, function ($query, $bindings) {
            $query = $this->bindParams($query, $bindings);

            $result = $this->wpdb->get_results($query);

            if ($result === false || $this->wpdb->last_error) {
                throw new QueryException(
                    $query, $bindings, new Exception($this->wpdb->last_error)
                );
            }

            return $result;
        });
    }

    /**
     * Bind the parameters into SQL query
     *
     * @param $query
     * @param $bindings
     * @return string
     */
    protected function bindParams(string $query, array $bindings)
    {
        $query = str_replace('"', '`', $query);

        $bindings = $this->prepareBindings($bindings);

        if (empty($bindings)) {
            return $query;
        }

        $query = str_replace(['%', '?'], ['%%', '%s'], $query);

        if ($this->wpdb->dbh instanceof \mysqli) {
            return $this->wpdb->prepare($query, ...$bindings);
        }

        $bindings = array_map(function ($value) {
            if ($value === null) return 'NULL';
            if (is_bool($value)) return $value ? '1' : '0';
            if (is_string($value)) return "'" . esc_sql($value) . "'";
            return (string) $value;
        }, $bindings);

        return vsprintf($query, $bindings);
    }

    /**
     * Run a select statement against the database and returns a generator.
     *
     * @param string $query
     * @param array $bindings
     * @return \Generator
     * @throws \FluentAccount\Framework\Database\QueryException
     */
    public function cursor($query, $bindings = [])
    {
        // If not mysqli (e.g., SQLite), fallback to standard select
        if (!$this->wpdb->dbh instanceof \mysqli) {
            foreach ($this->select($query, $bindings) as $row) {
                yield $row;
            }
            return;
        }

        $preparedQuery = str_replace('"', '`', $query);

        $preparedQuery = str_replace('%', '%%', $preparedQuery);

        $bindings = $this->prepareBindings($bindings);

        $this->wpdb->flush();
        $this->wpdb->insert_id = 0;
        $this->wpdb->check_current_query = true;

        if (!$this->wpdb->check_connection()) {
            throw new QueryException(
                $query, $bindings, new Exception(
                    $this->wpdb->last_error ?: 'Error reconnecting to database.'
                )
            );
        }

        if (defined('SAVEQUERIES') && SAVEQUERIES) {
            $this->wpdb->timer_start();
        }

        $statement = $this->wpdb->dbh->prepare($preparedQuery);

        if ($statement === false) {
            throw new QueryException(
                $query, $bindings, new Exception(
                    'Failed to prepare statement: ' . $this->wpdb->dbh->error
                )
            );
        }

        if (!empty($bindings)) {
            $types = '';
            foreach ($bindings as $binding) {
                if (is_int($binding)) {
                    $types .= 'i';
                } elseif (is_double($binding)) {
                    $types .= 'd';
                } else {
                    $types .= 's';
                }
            }

            $statement->bind_param($types, ...$bindings);
        }

        if ($statement->execute()) {
            $result = $statement->get_result();
            
            if ($result) {
                while ($row = $result->fetch_assoc()) {
                    yield (object) $row;
                }
                $result->free();
            } else {
                if ($statement->errno) {
                    throw new QueryException(
                        $query, $bindings, new Exception($statement->error)
                    );
                }
            }
            $statement->close();
            return;
        }

        // Error handling if statement execution fails
        if ($statement->error || $statement->errno) {
            $err = $statement->error
                ? $statement->error
                : 'Mysqli Error No: ' . $statement->errno;

            $this->wpdb->last_error = $err;

            throw new QueryException(
                $query, $bindings, new Exception($err)
            );
        }
    }

    /**
     * Raw cursor query for MySQLi (non-prepared).
     * 
     * @param  string $query
     * @param  array  $bindings
     * @return \Generator
     * @throws \FluentAccount\Framework\Database\QueryException
     */
    public function rawCursor($query, $bindings = [])
    {
        if (!empty($bindings)) {
            $query = str_replace(['%', '?'], ['%%', '%s'], $query);
            $query = $this->wpdb->prepare($query, ...$bindings);
        }

        if (!$this->wpdb->dbh instanceof \mysqli) {
            foreach ($this->select($query) as $row) { yield $row; }
            return;
        }

        $stmt = $this->wpdb->dbh->query($query, MYSQLI_USE_RESULT);

        if ($stmt instanceof \mysqli_result) {
            try {
                while ($row = $stmt->fetch_assoc()) {
                    yield (object) $row;
                }
            } finally {
                $stmt->free();
            }
        } elseif ($this->wpdb->dbh->error) {
            throw new QueryException(
                $query, $bindings, new Exception($this->wpdb->dbh->error)
            );
        }
    }

    /**
     * Run an insert statement against the database.
     *
     * @param string $query
     * @param array $bindings
     * @return bool
     */
    public function insert($query, $bindings = [])
    {
        return $this->statement($query, $bindings);
    }

    /**
     * Run an update statement against the database.
     *
     * @param string $query
     * @param array $bindings
     * @return int
     */
    public function update($query, $bindings = [])
    {
        return $this->affectingStatement($query, $bindings);
    }

    /**
     * Run a delete statement against the database.
     *
     * @param string $query
     * @param array $bindings
     * @return int
     */
    public function delete($query, $bindings = [])
    {
        return $this->affectingStatement($query, $bindings);
    }

    /**
     * Execute an SQL statement and return the boolean result.
     *
     * @param string $query
     * @param array $bindings
     * @return bool
     */
    public function statement($query, $bindings = [])
    {
        return $this->run($query, $bindings, function ($query, $bindings) {
            $query = $this->bindParams($query, $bindings, true);

            $result = $this->unprepared($query);

            if ($result === false || $this->wpdb->last_error) {
                throw new QueryException(
                    $query, $bindings, new Exception($this->wpdb->last_error)
                );
            }

            return $result;
        });
    }

    /**
     * Run an SQL statement and get the number of rows affected.
     *
     * @param string $query
     * @param array $bindings
     * @return int
     */
    public function affectingStatement($query, $bindings = [])
    {
        return $this->run($query, $bindings, function ($query, $bindings) {
            $query = $this->bindParams($query, $bindings, true);

            $result = $this->wpdb->query($query);

            if ($result === false || $this->wpdb->last_error) {
                throw new QueryException(
                    $query, $bindings, new Exception($this->wpdb->last_error)
                );
            }

            return intval($result);
        });
    }

    /**
     * Run a raw, unprepared query against the PDO connection.
     *
     * @param string $query
     * @return bool
     */
    public function unprepared($query)
    {
        return $this->wpdb->query($query);
    }

    /**
     * Execute the given callback in "dry run" mode.
     *
     * @param \Closure $callback
     * @return array
     */
    public function pretend(Closure $callback)
    {
        // ...
    }

    /**
     * Prepare the query bindings for execution.
     *
     * @param array $bindings
     * @return array
     */
    public function prepareBindings(array $bindings)
    {
        $grammar = $this->getQueryGrammar();

        foreach ($bindings as $key => $value) {
            // We need to transform all instances of DateTimeInterface into
            // the actual date string. Each query grammar maintains its
            // own date string format so we'll just ask the grammar
            // for the format to get from the date.
            if ($value instanceof DateTimeInterface) {
                $bindings[$key] = $value->format($grammar->getDateFormat());
            } elseif (is_bool($value)) {
                $bindings[$key] = (int)$value;
            }
        }

        return $bindings;
    }

    /**
     * Run a SQL statement and log its execution context.
     * 
     * @param  string $query
     * @param  array $bindings
     * @param  \Closure $callback
     * @return mixed
     */
    public function run($query, $bindings, $callback)
    {
        $start = microtime(true);

        try {
            return $callback($query, $bindings);
        } finally {
            $time = $this->getElapsedTime($start);
            $this->event->dispatch(
                new QueryExecuted($query, $bindings, $time, $this)
            );
        }
    }

    /**
     * Get a new raw query expression.
     *
     * @param mixed $value
     * @return \FluentCart\Framework\Database\Query\Expression
     */
    public function raw($value)
    {
        return new Expression($value);
    }

    /**
     * Get the query grammar used by the connection.
     *
     * @return \FluentCart\Framework\Database\Query\Grammars\Grammar
     */
    public function getQueryGrammar()
    {
        $this->queryGrammar->setTablePrefix($this->wpdb);

        return $this->queryGrammar;
    }

    /**
     * Set the query grammar used by the connection.
     *
     * @param \FluentCart\Framework\Database\Query\Grammars\Grammar $grammar
     * @return $this
     */
    public function setQueryGrammar(Grammar $grammar)
    {
        $this->queryGrammar = $grammar;

        return $this;
    }

    /**
     * Get the query post processor used by the connection.
     *
     * @return \FluentCart\Framework\Database\Query\Processors\Processor
     */
    public function getPostProcessor()
    {
        return $this->postProcessor;
    }

    /**
     * Set the query post processor used by the connection.
     *
     * @param \FluentCart\Framework\Database\Query\Processors\Processor $processor
     * @return $this
     */
    public function setPostProcessor(Processor $processor)
    {
        $this->postProcessor = $processor;

        return $this;
    }

    /**
     * Return the last insert id
     *
     * @param string $args
     *
     * @return int
     */
    public function lastInsertId($args)
    {
        return $this->wpdb->insert_id;
    }

    /**
     * Return self as PDO, the Processor instance uses it.
     *
     * @return \FluentCart\Framework\Database\Query\WPDBConnection
     */
    public function getPdo()
    {
        return $this;
    }

    /**
     * Returns the $wpdb object.
     *
     * @return Object $wpdb
     */
    public function getWPDB()
    {
        return $this->wpdb;
    }

    /**
     * Get the database connection name.
     *
     * @return string|null
     */
    public function getName()
    {
        return $this->isSqlite() ? 'sqlite' : 'mysql';
    }

    /**
     * Get the name of the connected database.
     *
     * @return string
     */
    public function getDatabaseName()
    {
        return $this->wpdb->dbname;
    }

    /**
     * Get the server version for the connection.
     *
     * @return string
     */
    public function getServerVersion(): string
    {
        return $this->getWPDB()->db_version();
    }

    /**
     * Get the column listing for a given table.
     *
     * @param string $table
     * @return array
     */
    public function getColumnListing($table)
    {
        return Schema::getColumns($table);
    }

    /**
     * Alias for getColumnListing.
     *
     * @param  string  $t
     * @return array
     */
    public function getColumns($t)
    {
        return $this->getColumnListing($t);
    }

    /**
     * Determine if the connected database is a sqlite database.
     *
     * @return bool
     */
    public function isSqlite()
    {
        return Schema::isSqlite();
    }

    /**
     * Determine if the connected database is a mariadb database.
     *
     * @return bool
     */
    public function isMaria()
    {
        return Schema::isMaria();
    }

    /**
     * Register a hook to be run just before a database transaction is started.
     *
     * @param  \Closure  $callback
     * @return $this
     */
    public function beforeStartingTransaction(Closure $callback)
    {
        $this->beforeStartingTransaction[] = $callback;

        return $this;
    }

    /**
     * Register a database query listener with the connection.
     *
     * @param \Closure $callback
     * @return void
     */
    public function listen(Closure $callback)
    {
        $this->event->listen(QueryExecuted::class, $callback);
    }

    /**
     * Fire an event for this connection.
     *
     * @param  string  $event
     * @return array|null
     */
    protected function fireConnectionEvent($event)
    {
        if (!$this->event) {
            return;
        }

        switch ($event) {
            case 'beganTransaction':
                $payload = new TransactionBeginning($this);
                break;
            case 'committed':
                $payload = new TransactionCommitted($this);
                break;
            case 'committing':
                $payload = new TransactionCommitting($this);
                break;
            case 'rollingBack':
                $payload = new TransactionRolledBack($this);
                break;
            default:
                $payload = null;
                break;
        }

        if ($payload !== null) {
            return $this->event->dispatch($payload);
        }
    }

    /**
     * Get the elapsed time since a given starting point.
     *
     * @param int $start
     * @return float
     */
    protected function getElapsedTime($start)
    {
        return round((microtime(true) - $start) * 1000, 2);
    }

    /**
     * Get the table prefix for the connection.
     * 
     * @return [type] [description]
     */
    public function getTablePrefix()
    {
        if (!$this->tablePrefix) {
            $this->tablePrefix = $this->queryGrammar->getTablePrefix();
        }

        return $this->tablePrefix;
    }

    /**
     * Get the table name with the table prefix.
     * 
     * @param  string $table
     * @return string       
     */
    public function getTableName($table)
    {
        return $this->getTablePrefix() . $table;
    }
}
