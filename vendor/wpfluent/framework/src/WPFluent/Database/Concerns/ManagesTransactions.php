<?php

namespace FluentCart\Framework\Database\Concerns;

use Closure;
use Throwable;
use RuntimeException;
use FluentCart\Framework\Database\DeadlockException;

trait ManagesTransactions
{
    /**
     * @template TReturn of mixed
     *
     * Execute a Closure within a transaction.
     *
     * @param  (\Closure(static): TReturn)  $callback
     * @param  int  $attempts
     * @return TReturn
     *
     * @throws \Throwable
     */
    public function transaction(Closure $callback, $attempts = 1)
    {
        for ($currentAttempt = 1; $currentAttempt <= $attempts; $currentAttempt++) {
            $this->beginTransaction();

            try {
                $callbackResult = $callback($this);
            } catch (Throwable $e) {
                $this->handleTransactionException($e, $currentAttempt, $attempts);
                continue;
            }

            $levelBeingCommitted = $this->transactions;

            try {
                $this->commit();
            } catch (Throwable $e) {
                $this->handleCommitTransactionException($e, $currentAttempt, $attempts);
                continue;
            }

            if ($this->transactionsManager) {
                $this->transactionsManager->commit(
                    $this->getName(),
                    $levelBeingCommitted,
                    $this->transactions
                );
            }

            $this->fireConnectionEvent('committed');

            return $callbackResult;
        }
    }

    /**
     * Handle an exception encountered when running a transacted statement.
     *
     * @param  \Throwable  $e
     * @param  int  $currentAttempt
     * @param  int  $maxAttempts
     * @return void
     *
     * @throws \Throwable
     */
    protected function handleTransactionException(
        Throwable $e,
        $currentAttempt,
        $maxAttempts
    )
    {
        $this->rollBack();

        throw $e;
    }

    /**
     * Start a new database transaction.
     *
     * @return void
     *
     * @throws \Throwable
     */
    public function beginTransaction()
    {
        if ($this->inTransaction()) {
            // Nested transaction -> savepoint
            $this->transactions++;

            $this->createSavepoint();

            if ($this->transactionsManager) {
                $this->transactionsManager->begin(
                    $this->getName(), $this->transactions
                );
            }

            $this->fireConnectionEvent('beganTransaction');
            return;
        }

        foreach ($this->beforeStartingTransaction as $callback) {
            $callback($this);
        }

        $this->transactions++;

        $this->createTransaction();

        if ($this->transactionsManager) {
            $this->transactionsManager->begin(
                $this->getName(), $this->transactions
            );
        }

        $this->fireConnectionEvent('beganTransaction');
    }

    /**
     * Create a transaction within the database.
     *
     * @return void
     *
     * @throws \Throwable
     */
    protected function createTransaction()
    {
        // Only call START TRANSACTION if no transaction active
        if ($this->transactions === 1) {
            try {
                $this->unprepared("START TRANSACTION;");
            } catch (Throwable $e) {
                $this->handleBeginTransactionException($e);
            }
        }
    }

    /**
     * Create a save point within the database.
     *
     * @return void
     *
     * @throws \Throwable
     */
    protected function createSavepoint()
    {
        $savepointName = 'trans' . $this->transactions;
        $sql = $this->queryGrammar->compileSavepoint($savepointName);
        $this->unprepared($sql);
    }

    /**
     * Handle an exception from a transaction beginning.
     *
     * @param  \Throwable  $e
     * @return void
     *
     * @throws \Throwable
     */
    protected function handleBeginTransactionException(Throwable $e)
    {
        if ($this->causedByLostConnection($e)) {
            $this->reconnect();
            $this->getPdo()->beginTransaction();
        } else {
            throw $e;
        }
    }

    /**
     * Commit the active database transaction.
     *
     * @return void
     *
     * @throws \Throwable
     */
    public function commit()
    {
        if ($this->transactions === 0) {
            return;
        }

        if ($this->transactions === 1) {
            $this->fireConnectionEvent('committing');
            $this->unprepared("COMMIT;");
        } elseif ($this->queryGrammar->supportsSavepoints()) {
            $savepointName = 'trans' . $this->transactions;
            $sql = $this->queryGrammar->compileSavepointRelease($savepointName);
            $this->unprepared($sql);
        }

        [$levelBeingCommitted, $this->transactions] = [
            $this->transactions,
            max(0, $this->transactions - 1),
        ];

        if ($this->transactionsManager) {
            $this->transactionsManager->commit(
                $this->getName(),
                $levelBeingCommitted,
                $this->transactions
            );
        }

        $this->fireConnectionEvent('committed');
    }

    /**
     * Handle an exception encountered when committing a transaction.
     *
     * @param  \Throwable  $e
     * @param  int  $currentAttempt
     * @param  int  $maxAttempts
     * @return void
     *
     * @throws \Throwable
     */
    protected function handleCommitTransactionException(Throwable $e, $currentAttempt, $maxAttempts)
    {
        $this->transactions = max(0, $this->transactions - 1);

        if ($this->causedByLostConnection($e)) {
            $this->transactions = 0;
        }

        throw $e;
    }

    /**
     * Rollback the active database transaction.
     *
     * @param  int|null  $toLevel
     * @return void
     *
     * @throws \Throwable
     */
    public function rollBack($toLevel = null)
    {
        $toLevel = is_null($toLevel)
            ? $this->transactions - 1
            : $toLevel;

        if ($toLevel < 0 || $toLevel >= $this->transactions) {
            return;
        }

        try {
            $this->performRollBack($toLevel);
        } catch (Throwable $e) {
            $this->handleRollBackException($e);
        }

        $this->transactions = $toLevel;

        if ($this->transactionsManager) {
            $this->transactionsManager->rollback(
                $this->getName(),
                $this->transactions
            );
        }

        $this->fireConnectionEvent('rollingBack');
    }

    /**
     * Perform a rollback within the database.
     *
     * @param  int  $toLevel
     * @return void
     *
     * @throws \Throwable
     */
    protected function performRollBack($toLevel)
    {
        if ($toLevel === 0) {
            if ($this->inTransaction()) {
                $transaction = $this->unprepared("ROLLBACK;");
                if ($transaction !== false) {
                    $this->transactions--;
                }
            }
        } elseif ($this->queryGrammar->supportsSavepoints()) {
            // Rollback to the savepoint created at transaction level $toLevel + 1
            $savepointName = 'trans' . ($toLevel + 1);
            $sql = $this->queryGrammar->compileSavepointRollBack($savepointName);
            $this->unprepared($sql);
        }
    }

    /**
     * Handle an exception from a rollback.
     *
     * @param  \Throwable  $e
     * @return void
     *
     * @throws \Throwable
     */
    protected function handleRollBackException(Throwable $e)
    {
        if ($this->causedByLostConnection($e)) {
            $this->transactions = 0;

            if ($this->transactionsManager) {
                $this->transactionsManager->rollback(
                    $this->getName(),
                    $this->transactions
                );
            }
        }

        throw $e;
    }

    /**
     * Get the number of active transactions.
     *
     * @return int
     */
    public function transactionLevel()
    {
        return $this->transactions;
    }

    /**
     * Execute the callback after a transaction commits.
     *
     * @param  callable  $callback
     * @return void
     *
     * @throws \RuntimeException
     */
    public function afterCommit($callback)
    {
        if ($this->transactionsManager) {
            // @phpstan-ignore-next-line
            return $this->transactionsManager->addCallback($callback);
        }

        throw new RuntimeException('Transactions Manager has not been set.');
    }

    /**
     * Determine if the connection is in a "transaction".
     * 
     * @return bool
     */
    public function inTransaction()
    {
        return $this->transactions > 0;
    }

    /**
     * Set the transaction manager instance on the connection.
     *
     * @param  \FluentCart\Framework\Database\DatabaseTransactionsManager  $manager
     * @return $this
     */
    public function setTransactionManager($manager)
    {
        $this->transactionsManager = $manager;

        return $this;
    }

    /**
     * Unset the transaction manager for this connection.
     *
     * @return void
     */
    public function unsetTransactionManager()
    {
        $this->transactionsManager = null;
    }
}
