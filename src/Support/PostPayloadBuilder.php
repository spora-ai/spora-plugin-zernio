<?php

declare(strict_types=1);

namespace Spora\Plugins\Zernio\Support;

use Spora\Tools\ValueObjects\ToolResult;

/**
 * Stateless helpers for building Zernio post create/update payloads.
 *
 * Kept out of {@see \Spora\Plugins\Zernio\Tools\ZernioPostTool} so the
 * Tool class can stay under SonarQube's 20-method-per-class threshold.
 * All methods are pure: they take `$arguments` and any other required
 * inputs as parameters and never touch the HTTP client.
 */
final class PostPayloadBuilder
{
    /**
     * Build the list of `platforms` for create_post. Accepts the explicit
     * `platforms` array, or `account_ids` + a single `platform` for convenience.
     *
     * @param  array<string, mixed>          $arguments
     * @return list<array<string, mixed>>|ToolResult
     */
    public static function buildPlatforms(array $arguments): array|ToolResult
    {
        if (isset($arguments['platforms']) && is_array($arguments['platforms']) && $arguments['platforms'] !== []) {
            return self::parseExplicitPlatforms($arguments['platforms']);
        }
        $ids = $arguments['account_ids'] ?? null;
        if (!is_array($ids) || $ids === []) {
            return [];
        }
        $platform = self::trimmedArg($arguments, 'platform');
        if ($platform === '') {
            return new ToolResult(false, 'create_post with account_ids requires a `platform` name (e.g. "twitter"). Use `platforms` instead for per-platform targets.');
        }
        return array_map(
            static fn(string $id): array => ['platform' => $platform, 'accountId' => $id],
            self::stringList($ids),
        );
    }

    /**
     * @param  list<mixed> $entries
     * @return list<array<string, mixed>>|ToolResult
     */
    public static function parseExplicitPlatforms(array $entries): array|ToolResult
    {
        $out = [];
        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                return new ToolResult(false, 'Each entry in `platforms` must be an object with at least {platform, accountId}.');
            }
            $platform  = self::trimmedArg($entry, 'platform');
            $accountId = self::trimmedArg($entry, 'accountId');
            if ($platform === '' || $accountId === '') {
                return new ToolResult(false, 'Each entry in `platforms` must have both `platform` and `accountId`.');
            }
            $row    = ['platform' => $platform, 'accountId' => $accountId];
            $known  = ['customContent', 'customMedia', 'scheduledFor', 'platformSpecificData'];
            $extras = array_intersect_key($entry, array_flip($known));
            $out[]  = array_merge($row, $extras);
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $arguments
     * @param array<string, string> $map
     * @return array<string, mixed>
     */
    public static function stringListPayload(array $arguments, array $map): array
    {
        $out = [];
        foreach ($map as $arg => $field) {
            $value = $arguments[$arg] ?? null;
            if (is_array($value) && $value !== []) {
                $out[$field] = array_values($value);
            } elseif (is_string($value) && trim($value) !== '') {
                $out[$field] = $value;
            }
        }
        return $out;
    }

    /**
     * Pass-through for nested object payloads (recycling, tiktokSettings, …)
     * only when the agent actually passed them.
     *
     * @param  array<string, mixed> $arguments
     * @param  array<string, string> $map
     * @return array<string, mixed>
     */
    public static function nestedPayload(array $arguments, array $map): array
    {
        $out = [];
        foreach ($map as $arg => $field) {
            if (isset($arguments[$arg]) && is_array($arguments[$arg])) {
                $out[$field] = $arguments[$arg];
            }
        }
        return $out;
    }

    /** @param array<string, mixed> $arguments */
    public static function hasMedia(array $arguments): bool
    {
        return isset($arguments['media_items']) && is_array($arguments['media_items']) && $arguments['media_items'] !== [];
    }

    /**
     * @param list<array<string, mixed>> $platforms
     */
    public static function everyPlatformHasCustomContent(array $platforms): bool
    {
        return $platforms !== [] && array_all($platforms, static fn(array $row): bool => !empty($row['customContent']));
    }

    /**
     * Coerce a tool argument into a clean list of non-empty strings.
     *
     * @return list<string>
     */
    public static function stringList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $item) {
            if (is_string($item) && trim($item) !== '') {
                $out[] = trim($item);
            }
        }
        return $out;
    }

    /**
     * Resolve the publish/schedule/queue/draft fields for a post, returning a
     * failed ToolResult when scheduling is requested without a timezone.
     *
     * @param  array<string, mixed> $arguments
     * @return array<string, mixed>|ToolResult
     */
    public static function schedulingPayload(array $arguments): array|ToolResult
    {
        if ((bool) ($arguments['publish_now'] ?? false)) {
            return ['publishNow' => true];
        }
        $queued = self::trimmedArg($arguments, 'queued_from_profile');
        if ($queued !== '') {
            return self::queueSchedulePayload($arguments, $queued);
        }
        return self::scheduledOrDefaultPayload($arguments);
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>|ToolResult
     */
    private static function scheduledOrDefaultPayload(array $arguments): array|ToolResult
    {
        $scheduledFor = self::trimmedArg($arguments, 'scheduled_for');
        if ($scheduledFor === '') {
            return self::defaultSchedulePayload($arguments);
        }
        return self::explicitSchedulePayload($arguments, $scheduledFor);
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    public static function queueSchedulePayload(array $arguments, string $queued): array
    {
        $payload = ['queuedFromProfile' => $queued];
        $queueId = self::trimmedArg($arguments, 'queue_id');
        if ($queueId !== '') {
            $payload['queueId'] = $queueId;
        }
        return $payload;
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    public static function defaultSchedulePayload(array $arguments): array
    {
        if (array_key_exists('is_draft', $arguments) && (bool) $arguments['is_draft']) {
            return ['isDraft' => true];
        }
        return [];
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>|ToolResult
     */
    public static function explicitSchedulePayload(array $arguments, string $scheduledFor): array|ToolResult
    {
        $timezone = self::trimmedArg($arguments, 'timezone');
        if ($timezone === '') {
            return new ToolResult(false, 'Scheduling a post requires a timezone alongside scheduled_for.');
        }
        return ['scheduledFor' => $scheduledFor, 'timezone' => $timezone];
    }

    /** @param array<string, mixed> $arguments */
    public static function modeLabel(array $arguments): string
    {
        return match (true) {
            (bool) ($arguments['publish_now'] ?? false)         => 'published',
            self::trimmedArg($arguments, 'queued_from_profile') !== '' => 'queue-scheduled',
            self::trimmedArg($arguments, 'scheduled_for')       !== '' => 'scheduled',
            default                                                  => 'drafted',
        };
    }

    /** @param array<string, mixed> $arguments */
    public static function describeCreate(array $arguments): string
    {
        $platforms = self::buildPlatforms($arguments);
        $count = is_array($platforms) ? count($platforms) : 0;
        return ucfirst(self::modeLabel($arguments)) . " a post to {$count} account(s)";
    }

    /** @param array<string, mixed> $arguments */
    private static function trimmedArg(array $arguments, string $key): string
    {
        return trim((string) ($arguments[$key] ?? ''));
    }
}
