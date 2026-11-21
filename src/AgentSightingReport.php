<?php

declare(strict_types=1);

namespace Fingerly;

/**
 * One request your server served, as raw input for
 * {@see Agents::reportSightings()}.
 *
 * Only these fields are ever sent: never the path, the query string, cookies,
 * `Authorization`, any other header, the body or the response.
 */
final class AgentSightingReport
{
    /**
     * @param string      $userAgent      the `User-Agent` header as received, `''` when there was none; at
     *                                    most 4096 bytes
     * @param string      $clientIp       one IPv4 or IPv6 literal from a trusted source, never a forwarded list
     * @param null|string $host           the `Host` the request was sent to
     * @param null|string $method         the request method; the API reads `GET` when it is left out
     * @param null|string $signature      the `Signature` header, when the request had one
     * @param null|string $signatureInput the `Signature-Input` header, when the request had one
     * @param null|string $signatureAgent the `Signature-Agent` header, when the request had one
     * @param int         $count          how many times this exact request was served since it was last
     *                                    reported, this one included; at most 1,000,000
     */
    public function __construct(
        public readonly string $userAgent,
        public readonly string $clientIp,
        public readonly ?string $host = null,
        public readonly ?string $method = null,
        public readonly ?string $signature = null,
        public readonly ?string $signatureInput = null,
        public readonly ?string $signatureAgent = null,
        public readonly int $count = 1,
    ) {}

    /**
     * The item as the API reads it, with absent fields left out.
     *
     * @return array<string, int|string>
     *
     * @internal
     */
    public function toArray(): array
    {
        $item = ['user_agent' => $this->userAgent, 'client_ip' => $this->clientIp];
        foreach ([
            'host' => $this->host,
            'method' => $this->method,
            'signature' => $this->signature,
            'signature_input' => $this->signatureInput,
            'signature_agent' => $this->signatureAgent,
        ] as $name => $value) {
            if (null !== $value) {
                $item[$name] = $value;
            }
        }
        $item['count'] = $this->count;

        return $item;
    }
}
