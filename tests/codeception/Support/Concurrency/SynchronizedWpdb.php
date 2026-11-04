<?php

namespace Tests\Support\Concurrency;

final class SynchronizedWpdb extends \wpdb
{
    private $socket;
    private bool $orderQueryBarrierArmed = false;

    public function __construct(
        string $dbUser,
        string $dbPassword,
        string $dbName,
        string $dbHost,
        $socket
    ) {
        $this->socket = $socket;

        parent::__construct($dbUser, $dbPassword, $dbName, $dbHost);
    }

    public function armOrderQueryBarrier(): void
    {
        $this->orderQueryBarrierArmed = true;
    }

    public function query($query)
    {
        if (!$this->isTargetOrderRead($query)) {
            return parent::query($query);
        }

        $this->orderQueryBarrierArmed = false;
        $hasRowLock = stripos($query, 'for update') !== false;
        $this->writePacket([
            'event'        => 'order_query_ready',
            'has_row_lock' => $hasRowLock,
        ]);
        $this->expectCommand('run_order_query');

        $result = parent::query($query);

        if (!$hasRowLock) {
            $this->writePacket(['event' => 'unlocked_order_query_complete']);
            $this->expectCommand('continue_after_order_query');
        }

        return $result;
    }

    private function isTargetOrderRead(string $query): bool
    {
        if (!$this->orderQueryBarrierArmed || stripos($query, 'select') === false) {
            return false;
        }

        $normalized = strtolower(str_replace('`', '', $query));

        return strpos($normalized, 'fct_orders') !== false;
    }

    private function writePacket(array $packet): void
    {
        $json = wp_json_encode($packet);
        if (!is_string($json) || @fwrite($this->socket, $json . "\n") === false) {
            throw new \RuntimeException('Unable to write a synchronized database packet');
        }
    }

    private function expectCommand(string $expected): void
    {
        $line = fgets($this->socket);
        $packet = is_string($line) ? json_decode($line, true) : null;

        if (!is_array($packet) || ($packet['command'] ?? '') !== $expected) {
            throw new \RuntimeException("Synchronized database expected {$expected}");
        }
    }
}
