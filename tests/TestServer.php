<?php

declare(strict_types=1);

namespace Fingerly\Tests;

/**
 * A recording HTTP server the tests point the SDK at.
 *
 * It is PHP's own built-in server on a real socket, so the tests exercise the
 * transport rather than a stub of it: headers, status codes, redirects and
 * stalled responses all behave as they would against the API. Requests and
 * canned answers pass through a temporary directory, because the server runs
 * in another process.
 */
final class TestServer
{
    /** A secret key of the right shape. It is not a credential for anything. */
    public const SECRET_KEY = 'fly_sk_production_testonlysecret';
    public const PUBLIC_KEY = 'fly_pk_production_testonlypublic';
    public const PROXY_KEY = 'fly_px_production_testonlyproxy';
    public const WEBHOOK_SECRET = 'whsec_testonly';

    private readonly string $dir;
    private readonly string $host;

    /** @var null|resource */
    private mixed $process = null;

    private function __construct()
    {
        $dir = sys_get_temp_dir().'/fingerly-test-'.bin2hex(random_bytes(6));
        mkdir($dir, 0o777, true);
        $this->dir = $dir;
        $this->host = '127.0.0.1:'.self::freePort();
    }

    /**
     * Starts a server that answers with each canned response in order,
     * repeating the last one once the queue runs dry.
     *
     * @param list<array{status?: int, body?: string, headers?: array<string, string>, delay?: float}> $responses
     */
    public static function start(array $responses): self
    {
        $server = new self();
        file_put_contents($server->dir.'/responses.json', json_encode($responses, \JSON_THROW_ON_ERROR));
        file_put_contents($server->dir.'/router.php', self::router());

        $descriptors = [1 => ['file', $server->dir.'/out.log', 'a'], 2 => ['file', $server->dir.'/err.log', 'a']];
        $process = proc_open(
            [\PHP_BINARY, '-d', 'variables_order=EGPCS', '-S', $server->host, $server->dir.'/router.php'],
            $descriptors,
            $pipes,
            null,
            ['FINGERLY_TEST_DIR' => $server->dir] + $_ENV,
        );
        if (!is_resource($process)) {
            throw new \RuntimeException('could not start the test server');
        }
        $server->process = $process;
        $server->waitUntilListening();

        return $server;
    }

    /**
     * Starts a server that answers every request the same way.
     *
     * @param array<string, string> $headers
     */
    public static function always(int $status, string $body, array $headers = [], float $delay = 0.0): self
    {
        return self::start([['status' => $status, 'body' => $body, 'headers' => $headers, 'delay' => $delay]]);
    }

    public function baseUrl(): string
    {
        return 'http://'.$this->host;
    }

    /**
     * Every request the server has seen, in order.
     *
     * @return list<array{method: string, path: string, query: string, headers: array<string, string>, body: string}>
     */
    public function requests(): array
    {
        $log = $this->dir.'/requests.jsonl';
        if (!is_file($log)) {
            return [];
        }

        $requests = [];
        foreach (file($log, \FILE_IGNORE_NEW_LINES | \FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $requests[] = json_decode($line, true, 32, \JSON_THROW_ON_ERROR);
        }

        return $requests;
    }

    /**
     * The first request, which is all most tests need.
     *
     * @return array{method: string, path: string, query: string, headers: array<string, string>, body: string}
     */
    public function first(): array
    {
        $requests = $this->requests();
        if ([] === $requests) {
            throw new \RuntimeException('no request arrived');
        }

        return $requests[0];
    }

    public function requestCount(): int
    {
        return count($this->requests());
    }

    /**
     * One header of a recorded request, or `''` when it was not sent.
     *
     * @param array{headers: array<string, string>} $request
     */
    public static function header(array $request, string $name): string
    {
        return $request['headers'][strtolower($name)] ?? '';
    }

    public function stop(): void
    {
        if (is_resource($this->process)) {
            proc_terminate($this->process, \SIGTERM);
            proc_close($this->process);
            $this->process = null;
        }
        foreach (glob($this->dir.'/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->dir);
    }

    private function waitUntilListening(): void
    {
        [$host, $port] = explode(':', $this->host);
        for ($attempt = 0; $attempt < 200; ++$attempt) {
            $socket = @fsockopen($host, (int) $port, $errno, $error, 0.1);
            if (is_resource($socket)) {
                fclose($socket);

                return;
            }
            usleep(25_000);
        }

        throw new \RuntimeException('the test server never started listening');
    }

    private static function freePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        if (!is_resource($socket)) {
            throw new \RuntimeException('could not reserve a port');
        }
        $name = stream_socket_get_name($socket, false);
        fclose($socket);

        return (int) explode(':', (string) $name)[1];
    }

    /**
     * The router the built-in server runs, as source.
     *
     * It records what it received and replies with the next canned answer,
     * keeping the last one in place so a test that makes several requests can
     * stop queueing once it has said what it wants to say.
     */
    private static function router(): string
    {
        return <<<'PHP'
            <?php

            $dir = getenv('FINGERLY_TEST_DIR');

            $headers = [];
            foreach ($_SERVER as $name => $value) {
                if (str_starts_with((string) $name, 'HTTP_')) {
                    $headers[str_replace('_', '-', strtolower(substr((string) $name, 5)))] = (string) $value;
                }
            }
            foreach (['CONTENT_TYPE' => 'content-type', 'CONTENT_LENGTH' => 'content-length'] as $key => $header) {
                if (isset($_SERVER[$key])) {
                    $headers[$header] = (string) $_SERVER[$key];
                }
            }

            file_put_contents($dir.'/requests.jsonl', json_encode([
                'method' => $_SERVER['REQUEST_METHOD'] ?? '',
                'path' => parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH),
                'query' => $_SERVER['QUERY_STRING'] ?? '',
                'headers' => $headers,
                'body' => (string) file_get_contents('php://input'),
            ]).PHP_EOL, FILE_APPEND | LOCK_EX);

            $queue = json_decode((string) file_get_contents($dir.'/responses.json'), true) ?: [];
            $canned = count($queue) > 1 ? array_shift($queue) : ($queue[0] ?? null);
            if (count($queue) >= 1) {
                file_put_contents($dir.'/responses.json', json_encode($queue), LOCK_EX);
            }
            if (null === $canned) {
                http_response_code(500);
                echo 'no canned response';

                return;
            }

            if (($canned['delay'] ?? 0) > 0) {
                usleep((int) ($canned['delay'] * 1_000_000));
            }
            http_response_code($canned['status'] ?? 200);
            header('content-type: application/json');
            foreach ($canned['headers'] ?? [] as $name => $value) {
                header($name.': '.$value, true);
            }
            echo $canned['body'] ?? '';
            PHP;
    }
}
