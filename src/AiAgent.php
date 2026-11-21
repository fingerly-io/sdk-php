<?php

declare(strict_types=1);

namespace Fingerly;

/**
 * The AI agent behind an event whose `visitorKind` is {@see VisitorKind::AI_BOT}.
 *
 * The identity comes from the agent's user agent, which anyone can copy. It is
 * not verified yet: {@see self::$verification} says how far the claim was
 * checked, so read it as a label, not as proof. `purpose`, `runtime` and the
 * verification values are plain strings, so a value the API adds later still
 * reads.
 */
final class AiAgent
{
    /**
     * @param string         $name         the agent as its operator names it, such as `ChatGPT-User` or `Claude-User`
     * @param string         $provider     the company that operates it, such as `OpenAI`, `Anthropic` or `Google`
     * @param string         $purpose      `training`, `search`, `user_fetch` or `agent`; see {@see AiPurpose}
     * @param string         $runtime      `provider_cloud`, `hosted_browser` or `user_device`; see {@see AiRuntime}
     * @param bool           $declared     true when the agent named itself, in its user agent or a signature
     * @param AiVerification $verification how the claimed identity was checked, and what that found
     */
    public function __construct(
        public readonly string $name,
        public readonly string $provider,
        public readonly string $purpose,
        public readonly string $runtime,
        public readonly bool $declared,
        public readonly AiVerification $verification,
    ) {}

    /**
     * Builds the agent from an event's `ai` member, or null when there is none.
     *
     * @throws ApiException if the member is not an agent this SDK can read
     *
     * @internal
     */
    public static function fromMember(mixed $member): ?self
    {
        if (!is_array($member)) {
            return null;
        }

        /** @var array<string, mixed> $member */
        $verification = $member['verification'] ?? null;
        if (!is_array($verification)) {
            throw new ApiException(200, 'invalid_response', ['field' => 'ai.verification']);
        }

        /** @var array<string, mixed> $verification */
        return new self(
            name: Json::string($member, 'name'),
            provider: Json::string($member, 'provider'),
            purpose: Json::string($member, 'purpose'),
            runtime: Json::string($member, 'runtime'),
            declared: true === ($member['declared'] ?? false),
            verification: new AiVerification(
                method: Json::string($verification, 'method'),
                result: Json::string($verification, 'result'),
            ),
        );
    }
}
