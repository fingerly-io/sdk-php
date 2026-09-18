<?php

declare(strict_types=1);

namespace Fingerly;

/** One signal group that contributed to an event's suspect score. */
final class EventTrigger
{
    /**
     * @param string      $signal     the signal group, such as `vpn` or `bot`
     * @param int         $weight     the group's combined weight in this score
     * @param null|string $confidence `low`, `medium` or `high`
     */
    public function __construct(
        public readonly string $signal,
        public readonly int $weight,
        public readonly ?string $confidence = null,
    ) {}

    /**
     * @return list<self>
     *
     * @internal
     */
    public static function listFrom(mixed $rows): array
    {
        if (!is_array($rows)) {
            return [];
        }

        $triggers = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $triggers[] = new self(
                signal: Json::string($row, 'signal'),
                weight: (int) ($row['weight'] ?? 0),
                confidence: Json::nullableString($row, 'confidence'),
            );
        }

        return $triggers;
    }
}
