<?php

namespace FluentCart\Framework\Database\Concerns;

use Closure;
use Throwable;
use RuntimeException;

trait ManagesTransactions
{
    /**
     * Execute a Closure within a transaction.
     *
     * @template TReturn
     * @param  \Closure(static): TReturn  $callback
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
                $result = $callback($this);
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

            return $result;
        }
    }

    /**
     * Begin transaction or create savepoint.
     */
    public function beginTransaction()
    {
        if ($this->inTransaction()) {

            $this->transactions++;

            if ($this->supportsSavepoints()) {
                $this->createSavepoint();
            }

            if ($this->transactionsManager) {
                $this->transactionsManager->begin(
                    $this->getName(),
                    $this->transactions
                );
            }

            return;
        }

        $this->transactions++;

        $this->createTransaction();

        if ($this->transactionsManager) {
            $this->transactionsManager->begin(
                $this->getName(),
                $this->transactions
            );
        }
    }

    /**
     * Create root transaction.
     */
    protected function createTransaction()
    {
        try {
            if ($this->isSqlite()) {
                $this->unprepared('BEGIN;');
            } else {
                $this->unprepared('START TRANSACTION;');
            }
        } catch (Throwable $e) {
            $this->handleBeginTransactionException($e);
        }
    }

    /**
     * Create savepoint.
     */
    protected function createSavepoint()
    {
        $name = $this->getSavepointName($this->transactions);
        $this->unprepared("SAVEPOINT {$name};");
    }

    /**
     * Commit transaction or release savepoint.
     */
    public function commit()
    {
        if ($this->transactions === 0) {
            return;
        }

        if ($this->transactions === 1) {

            $this->unprepared('COMMIT;');

        } elseif ($this->supportsSavepoints()) {

            $name = $this->getSavepointName($this->transactions);
            $this->unprepared("RELEASE SAVEPOINT {$name};");
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
    }

    /**
     * Rollback transaction.
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
    }

    /**
     * Perform rollback.
     */
    protected function performRollBack($toLevel)
    {
        if ($toLevel === 0) {

            $this->unprepared('ROLLBACK;');

        } elseif ($this->supportsSavepoints()) {

            $name = $this->getSavepointName($toLevel + 1);
            $this->unprepared("ROLLBACK TO SAVEPOINT {$name};");
        }
    }

    /**
     * Determine if savepoints are supported.
     */
    protected function supportsSavepoints()
    {
        // SQLite under WordPress does NOT support savepoints
        if ($this->isSqlite()) {
            return false;
        }

        return true;
    }

    /**
     * Handle transaction exception.
     */
    protected function handleTransactionException(Throwable $e, $currentAttempt, $maxAttempts)
    {
        $this->rollBack();
        throw $e;
    }

    /**
     * Handle commit exception.
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
     * Handle begin exception.
     */
    protected function handleBeginTransactionException(Throwable $e)
    {
        if ($this->causedByLostConnection($e)) {
            $this->reconnect();
        } else {
            throw $e;
        }
    }

    /**
     * Handle rollback exception.
     */
    protected function handleRollBackException(Throwable $e)
    {
        if ($this->causedByLostConnection($e)) {
            $this->transactions = 0;
        }

        throw $e;
    }

    /**
     * Get savepoint name.
     */
    protected function getSavepointName($level)
    {
        return 'trans' . $level;
    }

    /**
     * Transaction nesting level.
     */
    public function transactionLevel()
    {
        return $this->transactions;
    }

    /**
     * After commit callback.
     */
    public function afterCommit($callback)
    {
        if ($this->transactionsManager) {
            return $this->transactionsManager->addCallback($callback);
        }

        throw new RuntimeException('Transactions Manager has not been set.');
    }

    /**
     * In transaction?
     */
    public function inTransaction()
    {
        return $this->transactions > 0;
    }

    /**
     * Set transaction manager.
     */
    public function setTransactionManager($manager)
    {
        $this->transactionsManager = $manager;
        return $this;
    }

    /**
     * Unset transaction manager.
     */
    public function unsetTransactionManager()
    {
        $this->transactionsManager = null;
    }
}
