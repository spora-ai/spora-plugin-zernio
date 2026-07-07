<?php

declare(strict_types=1);

namespace Spora\Plugins\Zernio\Support;

use Spora\Tools\ValueObjects\ToolResult;

/**
 * Stateless helpers for building Zernio queue slot payloads and query maps.
 *
 * Kept out of {@see \Spora\Plugins\Zernio\Tools\ZernioQueueTool} so the
 * Tool class can stay under SonarQube's 20-method-per-class threshold.
 * All methods are pure: they take `$arguments` (and any other required
 * inputs) as parameters and never touch the HTTP client.
 *
 * The day-name → day-of-week-number map mirrors the Zernio spec:
 * 0 = Sunday, 6 = Saturday.
 */
final class QueuePayloadBuilder
{
    private const DAY_NAMES = [
        'sunday' => 0, 'sun' => 0,
        'monday' => 1, 'mon' => 1,
        'tuesday' => 2, 'tue' => 2, 'tues' => 2,
        'wednesday' => 3, 'wed' => 3,
        'thursday' => 4, 'thu' => 4, 'thurs' => 4,
        'friday' => 5, 'fri' => 5,
        'saturday' => 6, 'sat' => 6,
    ];

    /**
     * Build the `?profileId&[queueId]&[count]` query used by every queue read
     * (list/preview/next). Returns the failed ToolResult directly when the
     * required `profile_id` argument is missing so callers can propagate.
     *
     * @param  array<string, mixed>   $arguments
     * @param  string                 $operation Name of the calling operation for the error message.
     * @return array<string, scalar|null>|ToolResult
     */
    public static function queueReadQuery(array $arguments, string $operation): array|ToolResult
    {
        $profileId = self::trimmedArg($arguments, 'profile_id');
        if ($profileId === '') {
            return new ToolResult(false, "{$operation} requires a profile_id.");
        }
        $query = ['profileId' => $profileId];
        $queueId = self::trimmedArg($arguments, 'queue_id');
        if ($queueId !== '') {
            $query['queueId'] = $queueId;
        }
        if (isset($arguments['count'])) {
            $query['count'] = max(1, min(100, (int) $arguments['count']));
        }
        return $query;
    }

    /**
     * Assemble the create_queue body. Requires `name` plus at least one slot.
     *
     * @param  array<string, mixed> $arguments
     * @return array<string, mixed>|ToolResult
     */
    public static function createQueuePayload(array $arguments): array|ToolResult
    {
        $name = self::trimmedArg($arguments, 'name');
        if ($name === '') {
            return new ToolResult(false, 'create_slot requires a queue `name` (e.g. "Evening Posts").');
        }
        return self::resolveQueuePayload($arguments, $name);
    }

    /**
     * Assemble the update_queue body. Does not require `name` (omitted on
     * partial updates) but still requires at least one slot.
     *
     * @param  array<string, mixed> $arguments
     * @return array<string, mixed>|ToolResult
     */
    public static function updateQueuePayload(array $arguments): array|ToolResult
    {
        return self::resolveQueuePayload($arguments, self::trimmedArg($arguments, 'name'));
    }

    /**
     * @param  array<string, mixed> $arguments
     * @return array<string, mixed>|ToolResult
     */
    private static function resolveQueuePayload(array $arguments, string $name): array|ToolResult
    {
        $profileId = self::trimmedArg($arguments, 'profile_id');
        if ($profileId === '') {
            return new ToolResult(false, 'A queue requires a profile_id.');
        }
        $slots = self::buildSlots($arguments);
        if ($slots instanceof ToolResult || $slots === []) {
            return self::slotsError($slots);
        }
        return self::buildQueuePayload($arguments, $profileId, $name, $slots);
    }

    /**
     * @param list<array{dayOfWeek: int, time: string}>|ToolResult $slots
     */
    private static function slotsError(array|ToolResult $slots): ToolResult
    {
        if ($slots instanceof ToolResult) {
            return $slots;
        }
        return new ToolResult(false, 'A queue requires at least one slot. Pass `slots: [{dayOfWeek, time}, …]` or both `day` and `time`.');
    }

    /**
     * @param  array<string, mixed>                     $arguments
     * @param  list<array{dayOfWeek: int, time: string}> $slots
     * @return array<string, mixed>
     */
    private static function buildQueuePayload(array $arguments, string $profileId, string $name, array $slots): array
    {
        $payload = ['profileId' => $profileId, 'slots' => $slots];
        if ($name !== '') {
            $payload['name'] = $name;
        }
        $timezone = self::trimmedArg($arguments, 'timezone');
        if ($timezone !== '') {
            $payload['timezone'] = $timezone;
        }
        $payload['active']            = (bool) ($arguments['active']              ?? true);
        $payload['setAsDefault']      = (bool) ($arguments['set_as_default']      ?? false);
        $payload['reshuffleExisting'] = (bool) ($arguments['reshuffle_existing'] ?? false);
        return $payload;
    }

    /**
     * Resolve the `slots` argument either from an explicit array or from a
     * convenience `day` + `time` pair.
     *
     * @param  array<string, mixed> $arguments
     * @return list<array{dayOfWeek: int, time: string}>|ToolResult
     */
    public static function buildSlots(array $arguments): array|ToolResult
    {
        if (isset($arguments['slots']) && is_array($arguments['slots']) && $arguments['slots'] !== []) {
            return self::parseSlotArray($arguments['slots']);
        }
        return self::resolveSingleSlot(
            self::trimmedArg($arguments, 'day'),
            self::trimmedArg($arguments, 'time'),
        );
    }

    /**
     * @return list<array{dayOfWeek: int, time: string}>|ToolResult
     */
    private static function resolveSingleSlot(string $day, string $time): array|ToolResult
    {
        $missing = self::singleSlotMissing($day, $time);
        if ($missing !== null) {
            return $missing;
        }
        $normalised = self::normaliseSlot($day, $time);
        if ($normalised instanceof ToolResult) {
            return $normalised;
        }
        return [$normalised];
    }

    /**
     * @return null|list<array{dayOfWeek: int, time: string}>|ToolResult
     */
    private static function singleSlotMissing(string $day, string $time): null|array|ToolResult
    {
        if ($day === '' && $time === '') {
            return [];
        }
        if ($day === '' || $time === '') {
            return new ToolResult(false, '`day` and `time` must both be provided (or pass `slots` directly).');
        }
        return null;
    }

    /**
     * @param  list<mixed> $entries
     * @return list<array{dayOfWeek: int, time: string}>|ToolResult
     */
    private static function parseSlotArray(array $entries): array|ToolResult
    {
        $out = [];
        foreach ($entries as $entry) {
            $normalised = self::normaliseSlotEntry($entry);
            if ($normalised instanceof ToolResult) {
                return $normalised;
            }
            $out[] = $normalised;
        }
        return $out;
    }

    /**
     * @return array{dayOfWeek: int, time: string}|ToolResult
     */
    private static function normaliseSlotEntry(mixed $entry): array|ToolResult
    {
        if (!is_array($entry)) {
            return new ToolResult(false, 'Each entry in `slots` must be an object {dayOfWeek, time}.');
        }
        $day  = $entry['dayOfWeek'] ?? $entry['day_of_week'] ?? null;
        $time = (string) ($entry['time'] ?? '');
        if ($day === null || $time === '') {
            return new ToolResult(false, 'Each slot must have both dayOfWeek (0-6) and time (HH:MM).');
        }
        return self::normaliseSlot($day, $time);
    }

    /**
     * @return array{dayOfWeek: int, time: string}|ToolResult
     */
    private static function normaliseSlot(mixed $day, string $time): array|ToolResult
    {
        $dayOfWeek = self::resolveDayOfWeek($day);
        if ($dayOfWeek instanceof ToolResult) {
            return $dayOfWeek;
        }
        if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time)) {
            return new ToolResult(false, '`time` must be in "HH:MM" 24h format.');
        }
        return ['dayOfWeek' => $dayOfWeek, 'time' => $time];
    }

    /**
     * @return int|ToolResult
     */
    private static function resolveDayOfWeek(mixed $day): int|ToolResult
    {
        if (is_int($day) || (is_string($day) && ctype_digit($day))) {
            return self::normaliseNumericDay((int) $day);
        }
        if (is_string($day)) {
            return self::normaliseNamedDay($day);
        }
        return new ToolResult(false, '`day` must be a string or integer.');
    }

    /**
     * @return int|ToolResult
     */
    private static function normaliseNumericDay(int $numeric): int|ToolResult
    {
        if ($numeric < 0 || $numeric > 6) {
            return new ToolResult(false, '`dayOfWeek` must be between 0 (Sunday) and 6 (Saturday).');
        }
        return $numeric;
    }

    /**
     * @return int|ToolResult
     */
    private static function normaliseNamedDay(string $day): int|ToolResult
    {
        $key = strtolower(trim($day));
        if (!isset(self::DAY_NAMES[$key])) {
            return new ToolResult(false, '`day` must be a name ("monday".."sunday") or a number 0-6.');
        }
        return self::DAY_NAMES[$key];
    }

    /** @param array<string, mixed> $arguments */
    private static function trimmedArg(array $arguments, string $key): string
    {
        return trim((string) ($arguments[$key] ?? ''));
    }
}
