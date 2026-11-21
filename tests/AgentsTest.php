<?php

declare(strict_types=1);

namespace Fingerly\Tests;

use Fingerly\AgentSighting;
use Fingerly\AgentSightingReport;
use Fingerly\AiPurpose;
use Fingerly\AiVerificationMethod;
use Fingerly\AiVerificationResult;
use Fingerly\ApiException;
use Fingerly\Client;
use Fingerly\ConfigurationException;
use Fingerly\VisitorKind;
use PHPUnit\Framework\TestCase;

/**
 * The low-level `POST /api/v1/agents/sightings` call.
 *
 * @internal
 */
final class AgentsTest extends TestCase
{
    private ?TestServer $server = null;

    protected function tearDown(): void
    {
        $this->server?->stop();
        $this->server = null;
    }

    public function testPostsTheItemsWithTheSecretKey(): void
    {
        $this->server = TestServer::always(200, (string) json_encode([
            'rules_version' => 'r1',
            'items' => [['ttl' => 60, 'visitor_kind' => 'human'], ['ttl' => 0, 'visitor_kind' => 'ai_bot', 'agent' => 'X']],
        ]));

        $answer = $this->client()->agents->reportSightings([
            new AgentSightingReport('curl/8.7.1', '203.0.113.7', 'example.com', 'GET', count: 3),
            new AgentSightingReport('', '2001:db8::1', signature: 's', signatureInput: 'i', signatureAgent: '"https://a"'),
        ]);

        $request = $this->server->first();
        self::assertSame('POST', $request['method']);
        self::assertSame('/api/v1/agents/sightings', $request['path']);
        self::assertSame(TestServer::SECRET_KEY, TestServer::header($request, 'x-api-key'));
        self::assertSame('application/json', TestServer::header($request, 'content-type'));
        self::assertSame([
            'items' => [
                ['user_agent' => 'curl/8.7.1', 'client_ip' => '203.0.113.7', 'host' => 'example.com', 'method' => 'GET', 'count' => 3],
                ['user_agent' => '', 'client_ip' => '2001:db8::1', 'signature' => 's', 'signature_input' => 'i', 'signature_agent' => '"https://a"', 'count' => 1],
            ],
        ], json_decode($request['body'], true));

        self::assertSame('r1', $answer->rulesVersion);
        self::assertCount(2, $answer->items);
        self::assertSame(60, $answer->items[0]->ttl);
        self::assertSame(VisitorKind::HUMAN, $answer->items[0]->visitorKind);
        self::assertNull($answer->items[0]->agent);
        self::assertSame('X', $answer->items[1]->agent);
    }

    public function testDecodesTheAgentSightingsFixture(): void
    {
        $fixture = self::fixture('agent_sightings');
        $this->server = TestServer::always($fixture['status'], (string) json_encode($fixture['body']));
        $items = array_fill(0, count($fixture['body']['items']), new AgentSightingReport('ua', '198.51.100.1'));

        $answer = $this->client()->agents->reportSightings($items);

        self::assertSame($fixture['body']['rules_version'], $answer->rulesVersion);
        self::assertCount(count($fixture['body']['items']), $answer->items);
        foreach ($fixture['body']['items'] as $index => $expected) {
            $item = $answer->items[$index];
            self::assertSame($expected['ttl'], $item->ttl);
            self::assertSame($expected['visitor_kind'], $item->visitorKind);
            self::assertSame($expected['agent'] ?? null, $item->agent);
            self::assertSame(isset($expected['ai']), null !== $item->ai);
            if (isset($expected['ai'])) {
                self::assertSame($expected['ai']['name'], $item->ai?->name);
                self::assertSame($expected['ai']['provider'], $item->ai?->provider);
                self::assertSame($expected['ai']['verification']['method'], $item->ai?->verification->method);
            }
            self::assertFalse($item->isUnknown());
        }
        $gptBot = $answer->items[1];
        self::assertSame(VisitorKind::AI_BOT, $gptBot->visitorKind);
        self::assertNotNull($gptBot->ai);
        self::assertSame(AiPurpose::TRAINING, $gptBot->ai->purpose);
        self::assertSame(AiVerificationMethod::IP_LIST, $gptBot->ai->verification->method);
        self::assertSame(AiVerificationResult::PASS, $gptBot->ai->verification->result);
        $signed = $answer->items[3];
        self::assertSame(0, $signed->ttl);
        self::assertNotNull($signed->ai);
        self::assertSame(AiVerificationMethod::SIGNATURE, $signed->ai->verification->method);
        self::assertSame(VisitorKind::SEARCH_CRAWLER, $answer->items[4]->visitorKind);
    }

    public function testReadsTheRateLimitScopeAndRetryAfter(): void
    {
        $this->server = TestServer::always(429, '{"error":{"code":"rate_limited","message":"x","status":429}}', [
            'retry-after' => '120',
            'fingerly-ratelimit-scope' => 'daily',
        ]);

        try {
            $this->client()->agents->reportSightings([new AgentSightingReport('ua', '198.51.100.1')]);
            self::fail('a 429 must throw');
        } catch (ApiException $e) {
            self::assertTrue($e->isRateLimited());
            self::assertSame(120, $e->getRetryAfter());
            self::assertSame(ApiException::RATE_LIMIT_SCOPE_DAILY, $e->getRateLimitScope());
            self::assertSame('rate_limited', $e->getErrorCode());
        }
    }

    public function testMapsAnInvalidRequest(): void
    {
        $this->server = TestServer::always(422, '{"error":{"code":"invalid_request","message":"x","status":422}}');

        try {
            $this->client()->agents->reportSightings([new AgentSightingReport('ua', '198.51.100.1')]);
            self::fail('a 422 must throw');
        } catch (ApiException $e) {
            self::assertSame(422, $e->getStatus());
            self::assertSame('invalid_request', $e->getErrorCode());
            self::assertNull($e->getRateLimitScope());
        }
    }

    public function testRefusesAnEmptyOrOversizedBatchBeforeSending(): void
    {
        foreach ([[], array_fill(0, 201, new AgentSightingReport('ua', '198.51.100.1'))] as $items) {
            try {
                (new Client(TestServer::SECRET_KEY))->agents->reportSightings($items);
                self::fail('the batch must be refused');
            } catch (ConfigurationException $e) {
                self::assertStringContainsString('between 1 and 200', $e->getMessage());
            }
        }
    }

    public function testTheUnknownAnswerIsOneNamedValue(): void
    {
        self::assertSame(AgentSighting::unknown(), AgentSighting::unknown());
        self::assertSame(AgentSighting::UNKNOWN, AgentSighting::unknown()->visitorKind);
        self::assertTrue(AgentSighting::unknown()->isUnknown());
        self::assertNotContains(AgentSighting::UNKNOWN, VisitorKind::ALL);
        self::assertSame(0, AgentSighting::unknown()->ttl);
        self::assertNull(AgentSighting::unknown()->ai);
    }

    /**
     * @return array{name: string, status: int, body: array<string, mixed>}
     */
    public static function fixture(string $name): array
    {
        $fixtures = json_decode((string) file_get_contents(\dirname(__DIR__).'/contract/fixtures.json'), true, 64, \JSON_THROW_ON_ERROR);
        foreach ($fixtures['responses'] as $fixture) {
            if ($name === $fixture['name']) {
                return $fixture;
            }
        }

        throw new \RuntimeException('no fixture named '.$name);
    }

    private function client(): Client
    {
        self::assertNotNull($this->server);

        return new Client(TestServer::SECRET_KEY, ['endpoint' => $this->server->baseUrl(), 'allow_insecure_loopback' => true]);
    }
}
