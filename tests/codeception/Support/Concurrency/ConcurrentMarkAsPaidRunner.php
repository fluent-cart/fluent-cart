<?php

namespace Tests\Support\Concurrency;

use FluentCart\App\App;
use FluentCart\App\Http\Controllers\OrderController;
use FluentCart\App\Models\Connection\ConnectionManager;
use FluentCart\App\Models\Order;
use FluentCart\Framework\Http\Request\Request;

final class ConcurrentMarkAsPaidRunner
{
    private const WAIT_TIMEOUT_SECONDS = 10;

    /**
     * Run two mark-as-paid handlers on independent database connections.
     *
     * A socket barrier guarantees that both route-bound Order instances are
     * loaded before either handler starts. The synchronized wpdb connections
     * then release both fresh order reads together. If the production query
     * lacks FOR UPDATE, both workers pause after reading the same due balance
     * and are released together, reproducing the race that records two succeeded charges.
     *
     * @return array<int, array{status: int, body: array}>
     */
    public static function run(int $orderId): array
    {
        self::assertProcessSupport();

        $workers = [
            self::spawnWorker($orderId),
            self::spawnWorker($orderId),
        ];

        try {
            foreach ($workers as $worker) {
                $ready = self::readPacket($worker['socket'], 'worker readiness');
                if (empty($ready['ready'])) {
                    throw new \RuntimeException(self::workerError($ready));
                }
                self::writePacket($worker['socket'], ['command' => 'start_handler']);
            }

            foreach ($workers as $worker) {
                $queryReady = self::readPacket($worker['socket'], 'order query readiness');
                if (($queryReady['event'] ?? '') !== 'order_query_ready') {
                    throw new \RuntimeException(self::workerError($queryReady));
                }
            }

            foreach ($workers as $worker) {
                self::writePacket($worker['socket'], ['command' => 'run_order_query']);
            }

            $outcomes = [];
            foreach ($workers as $index => $worker) {
                $outcomes[$index] = self::readPacket($worker['socket'], 'order query outcome');
            }

            $unlockedWorkers = array_filter($outcomes, static function (array $packet): bool {
                return ($packet['event'] ?? '') === 'unlocked_order_query_complete';
            });

            if ($unlockedWorkers) {
                if (count($unlockedWorkers) !== count($workers)) {
                    throw new \RuntimeException('Concurrent workers observed inconsistent order-lock queries');
                }

                foreach ($workers as $worker) {
                    self::writePacket($worker['socket'], ['command' => 'continue_after_order_query']);
                }

                foreach ($workers as $index => $worker) {
                    $outcomes[$index] = self::readPacket($worker['socket'], 'worker result');
                }
            }

            $results = [];
            foreach ($outcomes as $outcome) {
                if (isset($outcome['error'])) {
                    throw new \RuntimeException(self::workerError($outcome));
                }
                if (!isset($outcome['status'], $outcome['body'])) {
                    throw new \RuntimeException(self::workerError($outcome));
                }
                $results[] = [
                    'status' => (int) $outcome['status'],
                    'body'   => (array) $outcome['body'],
                ];
            }

            return $results;
        } finally {
            foreach ($workers as $worker) {
                fclose($worker['socket']);
                pcntl_waitpid($worker['pid'], $status);
            }
        }
    }

    private static function spawnWorker(int $orderId): array
    {
        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        if ($sockets === false) {
            throw new \RuntimeException('Unable to create the worker synchronization socket');
        }

        $pid = pcntl_fork();
        if ($pid === -1) {
            fclose($sockets[0]);
            fclose($sockets[1]);
            throw new \RuntimeException('Unable to fork the mark-as-paid worker');
        }

        if ($pid === 0) {
            fclose($sockets[0]);
            self::runWorker($sockets[1], $orderId);
        }

        fclose($sockets[1]);
        stream_set_timeout($sockets[0], self::WAIT_TIMEOUT_SECONDS);

        return [
            'pid'    => $pid,
            'socket' => $sockets[0],
        ];
    }

    private static function runWorker($socket, int $orderId): void
    {
        try {
            self::reconnectWordPressDatabase($socket);
            $routeBoundOrder = Order::query()->find($orderId);
            if (!$routeBoundOrder) {
                throw new \RuntimeException('Worker could not load the order fixture');
            }

            $GLOBALS['wpdb']->armOrderQueryBarrier();
            self::writePacket($socket, ['ready' => true]);

            $command = self::readPacket($socket, 'parent start command');
            if (($command['command'] ?? '') !== 'start_handler') {
                throw new \RuntimeException('Worker received an invalid start command');
            }

            $request = new Request(App::getInstance(), [], [
                'payment_method'   => 'offline_payment',
                'transaction_type' => 'charge',
            ]);
            $response = (new OrderController())->markAsPaid($request, $routeBoundOrder);

            self::writePacket($socket, [
                'status' => (int) $response->get_status(),
                'body'   => (array) $response->get_data(),
            ]);
            fclose($socket);
            self::terminateWorker();
        } catch (\Throwable $e) {
            try {
                self::writePacket($socket, [
                    'error' => get_class($e) . ': ' . $e->getMessage(),
                ]);
            } catch (\Throwable $ignored) {
                // The parent may already have closed the synchronization socket.
            }
            fclose($socket);
            self::terminateWorker();
        }
    }

    private static function reconnectWordPressDatabase($socket): void
    {
        global $wpdb;

        $currentDatabase = $wpdb;
        $wpdb = new SynchronizedWpdb(
            DB_USER,
            DB_PASSWORD,
            DB_NAME,
            DB_HOST,
            $socket
        );
        $wpdb->set_prefix($currentDatabase->prefix);

        foreach ([
            'actionscheduler_actions',
            'actionscheduler_claims',
            'actionscheduler_groups',
            'actionscheduler_logs',
        ] as $tableProperty) {
            if (isset($currentDatabase->{$tableProperty})) {
                $wpdb->{$tableProperty} = $currentDatabase->{$tableProperty};
            }
        }

        $app = App::getInstance();
        $app->forgetInstance('db');
        ConnectionManager::connect($app);
    }

    private static function writePacket($socket, array $packet): void
    {
        $json = wp_json_encode($packet);
        if (!is_string($json) || @fwrite($socket, $json . "\n") === false) {
            throw new \RuntimeException('Unable to write a worker synchronization packet');
        }
    }

    private static function readPacket($socket, string $phase): array
    {
        $line = fgets($socket);
        if ($line === false) {
            $metadata = stream_get_meta_data($socket);
            $reason = !empty($metadata['timed_out']) ? 'timed out' : 'closed';
            throw new \RuntimeException("Worker socket {$reason} during {$phase}");
        }

        $packet = json_decode($line, true);
        if (!is_array($packet)) {
            throw new \RuntimeException("Worker sent invalid JSON during {$phase}");
        }

        return $packet;
    }

    private static function workerError(array $packet): string
    {
        return 'Concurrent mark-as-paid worker failed: ' . ($packet['error'] ?? wp_json_encode($packet));
    }

    private static function terminateWorker(): void
    {
        posix_kill(getmypid(), SIGKILL);
        exit(1);
    }

    private static function assertProcessSupport(): void
    {
        foreach (['pcntl_fork', 'pcntl_waitpid', 'posix_kill', 'stream_socket_pair'] as $function) {
            if (!function_exists($function)) {
                throw new \RuntimeException("Concurrent mark-as-paid test requires {$function}()");
            }
        }
    }
}
