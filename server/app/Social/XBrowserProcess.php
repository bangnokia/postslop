<?php

declare(strict_types=1);

namespace App\Social;

use Crab\Engine\Async\Async;
use Crab\Engine\EventLoop;

// Keep pipe ownership, deadlines, and process cleanup together so every failure closes the same resources.
// @mago-expect lint:cyclomatic-complexity,kan-defect
final readonly class XBrowserProcess
{
    private const MAX_INPUT_BYTES = 7 * 1024 * 1024;
    private const MAX_OUTPUT_BYTES = 64 * 1024;

    public function __construct(
        private string $nodeBinary,
        private string $workerPath,
        private float $timeoutSeconds = 95,
    ) {
        if (!is_finite($timeoutSeconds) || $timeoutSeconds <= 0 || $timeoutSeconds > 95) {
            throw new \InvalidArgumentException('The X browser worker deadline must be between 0 and 95 seconds.');
        }
    }

    public function request(array $input): array
    {
        $payload = json_encode($input, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        if (strlen($payload) > self::MAX_INPUT_BYTES) {
            throw new \RuntimeException('The X browser request is too large.');
        }
        $pipes = [];
        // Process warnings can include private local paths; only the safe failure below leaves this boundary.
        // @mago-expect lint:no-error-control-operator
        $process = @proc_open(
            [$this->nodeBinary, $this->workerPath],
            [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
            $pipes,
            dirname($this->workerPath),
        );
        if ($process === false) {
            throw new \RuntimeException('The X browser worker could not start.');
        }
        try {
            foreach ($pipes as $pipe) {
                stream_set_blocking($pipe, false);
            }
            $output = $this->exchange($process, $pipes, $payload);
            $result = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
            if (!is_array($result) || array_is_list($result)) {
                throw new \RuntimeException('The X browser worker returned an invalid result.');
            }
            return $result;
        } finally {
            $this->close($process, $pipes);
        }
    }

    /** @param resource $process @param array<int, resource> $pipes */
    private function exchange(mixed $process, array &$pipes, string $payload): string
    {
        $deadline = (hrtime(true) / 1e9) + $this->timeoutSeconds;
        $offset = 0;
        $output = '';
        $errorBytes = 0;
        $loop = EventLoop::current();
        while ((hrtime(true) / 1e9) < $deadline) {
            if (array_key_exists(0, $pipes)) {
                // A worker can exit before receiving its full request; that outcome is handled below.
                // @mago-expect lint:no-error-control-operator
                $written = @fwrite($pipes[0], substr($payload, $offset, 65_536));
                if ($written === false) {
                    throw new \RuntimeException('The X browser worker stopped receiving its request.');
                }
                $offset += $written;
                if ($offset === strlen($payload)) {
                    fclose($pipes[0]);
                    unset($pipes[0]);
                }
            }
            $output .= $this->read($pipes[1]);
            $errorBytes += strlen($this->read($pipes[2]));
            if (strlen($output) > self::MAX_OUTPUT_BYTES || $errorBytes > self::MAX_OUTPUT_BYTES) {
                throw new \RuntimeException('The X browser worker exceeded its output limit.');
            }
            $state = proc_get_status($process);
            if (!$state['running'] && feof($pipes[1]) && feof($pipes[2])) {
                if ($state['exitcode'] !== 0 || $offset !== strlen($payload)) {
                    throw new \RuntimeException('The X browser worker exited without confirming its result.');
                }
                return $output;
            }
            // Yield the HTTP worker's existing loop while the browser is busy, including while writing large images.
            Async::sleep($loop, 0.01);
        }
        throw new \RuntimeException('The X browser worker did not finish before its deadline.');
    }

    /** @param resource $pipe */
    private function read(mixed $pipe): string
    {
        $chunk = fread($pipe, 65_536);
        if ($chunk === false) {
            throw new \RuntimeException('The X browser worker output could not be read.');
        }
        return $chunk;
    }

    /** @param resource $process @param array<int, resource> $pipes */
    private function close(mixed $process, array $pipes): void
    {
        if (proc_get_status($process)['running']) {
            // The worker handles SIGTERM by closing its own browser context; never touch a personal browser.
            proc_terminate($process);
            $deadline = (hrtime(true) / 1e9) + 2;
            while (proc_get_status($process)['running'] && (hrtime(true) / 1e9) < $deadline) {
                Async::sleep(EventLoop::current(), 0.01);
            }
            if (proc_get_status($process)['running']) {
                proc_terminate($process, 9);
            }
        }
        foreach ($pipes as $pipe) {
            fclose($pipe);
        }
        proc_close($process);
    }
}
