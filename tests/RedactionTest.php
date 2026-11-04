<?php

declare(strict_types=1);

namespace Fingerly\Tests;

use Fingerly\Client;
use Fingerly\ConfigurationException;
use Fingerly\Proxy;
use Fingerly\Proxy\ProxyRequest;
use Fingerly\TimeoutException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\VarDumper\Cloner\VarCloner;
use Symfony\Component\VarDumper\Dumper\CliDumper;

/**
 * No credential ever appears in anything a developer prints, logs or ships.
 *
 * @internal
 */
final class RedactionTest extends TestCase
{
    private ?TestServer $server = null;

    protected function tearDown(): void
    {
        $this->server?->stop();
        $this->server = null;
    }

    public function testNoDumpOfAnSdkObjectShowsAKey(): void
    {
        foreach ($this->objects() as $label => $object) {
            ob_start();
            var_dump($object);
            $dumps = [
                'var_dump' => (string) ob_get_clean(),
                'print_r' => print_r($object, true),
                'var_export' => var_export($object, true),
                'json_encode' => (string) json_encode($object),
                'VarDumper' => (string) (new CliDumper())->dump((new VarCloner())->cloneVar($object), true),
            ];
            foreach ($dumps as $how => $output) {
                $this->assertNoKey($output, $label.' through '.$how);
            }
            self::assertStringContainsString('[redacted]', $dumps['var_dump'], $label);
            self::assertStringContainsString('[redacted]', $dumps['VarDumper'], $label);
        }
    }

    public function testAnSdkObjectHoldingAKeyCannotBeSerialized(): void
    {
        foreach ($this->objects() as $label => $object) {
            try {
                $serialized = serialize($object);
                $this->assertNoKey($serialized, $label);
                self::fail($label.' was serialized');
            } catch (\Exception $e) {
                $this->assertNoKey($e->getMessage(), $label);
            }
        }
    }

    /**
     * Error trackers record each frame's arguments; marking them sensitive
     * is what keeps the key out (PHP 8.2 and later honour the attribute).
     */
    public function testNoStackFrameOfAFailedReadCarriesTheKey(): void
    {
        if (\PHP_VERSION_ID < 80200) {
            self::markTestSkipped('#[\SensitiveParameter] takes effect from PHP 8.2');
        }
        $previous = ini_set('zend.exception_ignore_args', '0');

        try {
            $this->server = TestServer::always(200, '{}', [], 2.0);
            $fingerly = new Client(TestServer::SECRET_KEY, ['endpoint' => $this->server->baseUrl(), 'timeout' => 0.2]);

            try {
                $fingerly->events->get('0199a963-e400-7000-8000-000000000001');
                self::fail('the read should have timed out');
            } catch (TimeoutException $e) {
                $this->assertNoKey(print_r($e->getTrace(), true), 'the timeout trace');
                $this->assertNoKey($e->getTraceAsString(), 'the timeout trace string');
            }

            try {
                new Client(TestServer::SECRET_KEY."\n");
                self::fail('a key with a newline should have been refused');
            } catch (ConfigurationException $e) {
                $this->assertNoKey(print_r($e->getTrace(), true), 'the configuration trace');
                $this->assertNoKey($e->getMessage(), 'the configuration message');
            }
        } finally {
            ini_set('zend.exception_ignore_args', false === $previous ? '1' : $previous);
        }
    }

    /** @return array<string, object> */
    private function objects(): array
    {
        $client = new Client(TestServer::SECRET_KEY);

        return [
            'Client' => $client,
            'Events' => $client->events,
            'Proxy' => new Proxy([
                'proxy_key' => TestServer::PROXY_KEY,
                'path' => '/k7x2qm4a',
                'client_ip' => fn (ProxyRequest $request): string => '203.0.113.7',
            ]),
            'Proxy (secret key)' => new Proxy([
                'secret_key' => TestServer::SECRET_KEY,
                'path' => '/k7x2qm4a',
                'client_ip' => fn (ProxyRequest $request): string => '203.0.113.7',
            ]),
        ];
    }

    private function assertNoKey(string $output, string $where): void
    {
        foreach ([TestServer::SECRET_KEY, TestServer::PROXY_KEY] as $key) {
            // The distinctive tail too, in case a dumper splits the string.
            self::assertStringNotContainsString($key, $output, $where.' shows a key');
            self::assertStringNotContainsString(substr($key, -12), $output, $where.' shows part of a key');
        }
    }
}
