<?php

declare(strict_types=1);

namespace Psalm\Internal\Fork;

use Amp\ByteStream\StreamChannel;
use Amp\Cancellation;
use Amp\Future;
use Amp\Parallel\Context\ContextException;
use Amp\Parallel\Context\Internal\AbstractContext;
use Amp\Parallel\Context\Internal\ContextChannel;
use Amp\Parallel\Context\Internal\ExitFailure;
use Amp\Parallel\Context\Internal\ExitSuccess;
use Amp\Parallel\Ipc\IpcHub;
use Amp\Serialization\SerializationException;
use Amp\TimeoutCancellation;
use Error;
use ParseError;
use Revolt\EventLoop;
use RuntimeException;
use Throwable;
use TypeError;

use function Amp\Parallel\Ipc\connect;
use function count;
use function define;
use function is_file;
use function is_string;
use function pcntl_fork;
use function posix_get_last_error;
use function posix_kill;
use function posix_strerror;
use function sprintf;
use function trigger_error;
use function var_dump;

use const E_USER_ERROR;

/**
 * @template-covariant TResult
 * @template-covariant TReceive
 * @template TSend
 * @extends AbstractContext<TResult, TReceive, TSend>
 */
final class ForkContext extends AbstractContext
{
    private const DEFAULT_START_TIMEOUT = 5;

    /**
     * @param string|non-empty-list<string> $script Path to PHP script or array with first element as path and
     *     following elements options to the PHP script (e.g.: ['bin/worker.php', 'Option1Value', 'Option2Value']).
     * @param string|null $workingDirectory Working directory.
     * @param array<string, string> $environment Array of environment variables, or use an empty array to inherit from
     *     the parent.
     * @param string|non-empty-list<string>|null $binary Path to PHP binary or array of binary path and options.
     *      Null will attempt to automatically locate the binary.
     * @param positive-int $childConnectTimeout Number of seconds the child will attempt to connect to the parent
     *      before failing.
     * @throws ContextException If starting the process fails.
     */
    public static function start(
        string|array $argv,
        IpcHub $ipcHub,
        ?Cancellation $cancellation = null,
        int $childConnectTimeout = self::DEFAULT_START_TIMEOUT,
    ): self {
        $key = $ipcHub->generateKey();

        // Fork
        if (($pid = pcntl_fork()) < 0) {
            throw new RuntimeException(posix_strerror(posix_get_last_error()));
        }

        // Parent
        if ($pid > 0) {
            try {
                $socket = $ipcHub->accept($key, $cancellation);
                $ipcChannel = new StreamChannel($socket, $socket);
        
                $socket = $ipcHub->accept($key, $cancellation);
                $resultChannel = new StreamChannel($socket, $socket);
            } catch (Throwable $exception) {
                $cancellation?->throwIfRequested();
        
                throw new ContextException("Starting the process failed", 0, $exception);
            }
        
            return new self($pid, $ipcChannel, $resultChannel);
        }

        // Child
        define("AMP_CONTEXT", "parallel");
        if (is_string($argv)) {
            $argv = [$argv];
        }

        $connectCancellation = new TimeoutCancellation((float) $childConnectTimeout);
        $uri = $ipcHub->getUri();

        try {
            $socket = connect($uri, $key, $connectCancellation);
            $ipcChannel = new StreamChannel($socket, $socket);

            $socket = connect($uri, $key, $connectCancellation);
            $resultChannel = new StreamChannel($socket, $socket);
        } catch (Throwable $exception) {
            trigger_error($exception->getMessage(), E_USER_ERROR);
        }

        try {
            if (!isset($argv[0])) {
                throw new Error("No script path given");
            }

            if (!is_file($argv[0])) {
                throw new Error(sprintf(
                    "No script found at '%s' (be sure to provide the full path to the script)",
                    $argv[0],
                ));
            }

            try {
                $argc = count($argv);
                $callable = require $argv[0];
            } catch (TypeError $exception) {
                throw new Error(sprintf(
                    "Script '%s' did not return a callable function: %s",
                    $argv[0],
                    $exception->getMessage(),
                ), 0, $exception);
            } catch (ParseError $exception) {
                throw new Error(sprintf(
                    "Script '%s' contains a parse error: %s",
                    $argv[0],
                    $exception->getMessage(),
                ), 0, $exception);
            }

            $returnValue = $callable(new ContextChannel($ipcChannel));
            $result = new ExitSuccess($returnValue instanceof Future ? $returnValue->await() : $returnValue);
        } catch (Throwable $exception) {
            $result = new ExitFailure($exception);
        }

        try {
            try {
                $resultChannel->send($result);
            } catch (SerializationException $exception) {
                // Serializing the result failed. Send the reason why.
                $resultChannel->send(new ExitFailure($exception));
            }
        } catch (Throwable $exception) {
            trigger_error(sprintf(
                "Could not send result to parent: '%s'; be sure to shutdown the child before ending the parent",
                $exception->getMessage(),
            ), E_USER_ERROR);
        }

        EventLoop::run();
    }

    private bool $exited = false;

    /**
     * @param StreamChannel<TReceive, TSend> $ipcChannel
     */
    private function __construct(
        private readonly int $pid,
        StreamChannel $ipcChannel,
        StreamChannel $resultChannel,
    ) {
        parent::__construct($ipcChannel, $resultChannel);
    }

    public function __destruct()
    {
        $this->close();
    }

    public function receive(?Cancellation $cancellation = null): mixed
    {
        if ($this->exited) {
            throw new ContextException('The thread has exited');
        }

        return parent::receive($cancellation);
    }

    public function send(mixed $data): void
    {
        if ($this->exited) {
            throw new ContextException('The thread has exited');
        }

        parent::send($data);
    }

    public function close(): void
    {
        if (!$this->exited) {
            posix_kill($this->pid, 9);
        }

        parent::close();
    }

    public function join(?Cancellation $cancellation = null): mixed
    {
        $data = $this->receiveExitResult($cancellation);

        $this->close();

        return $data->getResult();
    }
}
