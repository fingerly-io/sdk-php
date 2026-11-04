<?php

declare(strict_types=1);

namespace Fingerly\Tests;

/**
 * A minimal HTTP/1.1 server that keeps connections open.
 *
 * PHP's built-in server closes every connection after one response, so it
 * cannot show whether the transport reuses one. This one answers every request
 * with the same body over a persistent connection and records which client
 * port each request arrived from.
 *
 * @internal
 */
final class KeepAliveServer
{
    private readonly string $dir;
    private readonly int $port;
    private mixed $process = null;

    private function __construct()
    {
        $dir = sys_get_temp_dir().'/fingerly-keepalive-'.bin2hex(random_bytes(6));
        mkdir($dir, 0o777, true);
        $this->dir = $dir;

        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        if (!is_resource($socket)) {
            throw new \RuntimeException('could not reserve a port');
        }
        $this->port = (int) explode(':', (string) stream_socket_get_name($socket, false))[1];
        fclose($socket);
    }

    public static function start(int $status, string $body): self
    {
        $server = new self();
        file_put_contents($server->dir.'/server.php', self::script());
        $process = proc_open(
            [\PHP_BINARY, $server->dir.'/server.php', (string) $server->port, $server->dir.'/requests.jsonl', (string) $status, $body],
            [1 => ['file', $server->dir.'/out.log', 'a'], 2 => ['file', $server->dir.'/err.log', 'a']],
            $pipes,
        );
        if (!is_resource($process)) {
            throw new \RuntimeException('could not start the keep-alive server');
        }
        $server->process = $process;
        for ($attempt = 0; $attempt < 200; ++$attempt) {
            $probe = @fsockopen('127.0.0.1', $server->port, $errno, $error, 0.1);
            if (is_resource($probe)) {
                fclose($probe);

                return $server;
            }
            usleep(25_000);
        }

        throw new \RuntimeException('the keep-alive server did not start');
    }

    public function baseUrl(): string
    {
        return 'http://127.0.0.1:'.$this->port;
    }

    /**
     * Every request seen, in order.
     *
     * @return list<array{peer: string, method: string, path: string, headers: array<string, string>}>
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
     * The client ports requests arrived from; one per connection used.
     *
     * The probe connection made while starting carries no request, so it is
     * not among them.
     *
     * @return list<string>
     */
    public function connections(): array
    {
        return array_values(array_unique(array_column($this->requests(), 'peer')));
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

    private static function script(): string
    {
        return <<<'PHP'
            <?php
            [$_, $port, $log, $status, $body] = $argv;
            $server = stream_socket_server('tcp://127.0.0.1:'.$port, $errno, $error);
            $clients = [];
            $buffers = [];
            while (true) {
                $read = array_merge([$server], $clients);
                $write = $except = null;
                if (false === stream_select($read, $write, $except, 5)) {
                    break;
                }
                foreach ($read as $socket) {
                    if ($socket === $server) {
                        $client = stream_socket_accept($server, 0, $peer);
                        if (false !== $client) {
                            $clients[(int) $client] = $client;
                            $buffers[(int) $client] = '';
                        }
                        continue;
                    }
                    $chunk = fread($socket, 65536);
                    if ('' === $chunk || false === $chunk) {
                        unset($clients[(int) $socket], $buffers[(int) $socket]);
                        fclose($socket);
                        continue;
                    }
                    $buffers[(int) $socket] .= $chunk;
                    while (false !== ($end = strpos($buffers[(int) $socket], "\r\n\r\n"))) {
                        $head = substr($buffers[(int) $socket], 0, $end);
                        $lines = explode("\r\n", $head);
                        [$method, $path] = explode(' ', array_shift($lines));
                        $headers = [];
                        foreach ($lines as $line) {
                            [$name, $value] = array_map('trim', explode(':', $line, 2)) + [1 => ''];
                            $headers[strtolower($name)] = $value;
                        }
                        $length = (int) ($headers['content-length'] ?? 0);
                        if (strlen($buffers[(int) $socket]) < $end + 4 + $length) {
                            break;
                        }
                        $buffers[(int) $socket] = substr($buffers[(int) $socket], $end + 4 + $length);
                        $peer = stream_socket_get_name($socket, true);
                        file_put_contents($log, json_encode(['peer' => $peer, 'method' => $method, 'path' => $path, 'headers' => $headers])."\n", FILE_APPEND);
                        fwrite($socket, "HTTP/1.1 {$status} OK\r\ncontent-type: application/json\r\ncontent-length: ".strlen($body)."\r\nconnection: keep-alive\r\n\r\n".$body);
                    }
                }
            }
            PHP;
    }
}
