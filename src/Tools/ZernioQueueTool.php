<?php

declare(strict_types=1);

namespace Spora\Plugins\Zernio\Tools;

use Spora\Plugins\Zernio\Support\ZernioConfig;
use Spora\Tools\Attributes\Tool;
use Spora\Tools\Attributes\ToolOperation;
use Spora\Tools\Attributes\ToolParameter;
use Spora\Tools\Attributes\ToolSetting;
use Spora\Tools\ValueObjects\ToolResult;

/**
 * Manage Zernio's recurring posting queue — the weekly time slots that
 * auto-scheduled posts drop into. Reads (list/preview/next) need no approval;
 * slot mutations (create/update/delete) require approval by default.
 */
#[Tool(
    name: 'zernio_queue',
    description: 'Inspect and manage the Zernio posting queue: list recurring time slots, preview upcoming scheduled times, find the next open slot, and create, update, or delete slots.',
    displayName: 'Zernio Queue',
    category: 'social-media',
)]
#[ToolOperation(name: 'list_slots', description: 'List recurring queue time slots', enabledByDefault: true, requiresApprovalByDefault: false)]
#[ToolOperation(name: 'preview_queue', description: 'Preview upcoming scheduled slot times', enabledByDefault: true, requiresApprovalByDefault: false)]
#[ToolOperation(name: 'next_slot', description: 'Get the next available queue slot', enabledByDefault: true, requiresApprovalByDefault: false)]
#[ToolOperation(name: 'create_slot', description: 'Create a recurring queue time slot', enabledByDefault: true, requiresApprovalByDefault: true)]
#[ToolOperation(name: 'update_slot', description: 'Update a recurring queue time slot', enabledByDefault: true, requiresApprovalByDefault: true)]
#[ToolOperation(name: 'delete_slot', description: 'Delete a recurring queue time slot', enabledByDefault: true, requiresApprovalByDefault: true)]
#[ToolSetting(key: 'api_key', label: 'Zernio API Key', type: 'password', description: 'Bearer token for the Zernio API. Falls back to the ZERNIO_API_KEY environment variable.', required: false)]
#[ToolSetting(key: 'base_url', label: 'Base URL', type: 'text', description: 'Zernio API base URL (default: https://zernio.com/api/v1).', default: 'https://zernio.com/api/v1')]
#[ToolSetting(key: 'http_timeout', label: 'HTTP Timeout', type: 'text', description: 'Seconds before an HTTP request fails (default: 30).')]
#[ToolParameter(name: 'profile_id', type: 'string', description: 'Profile ID the queue belongs to. Recommended for all operations.', required: false)]
#[ToolParameter(name: 'slot_id', type: 'string', description: 'The slot ID. Required for update_slot and delete_slot.', required: false)]
#[ToolParameter(name: 'day', type: 'string', description: 'Day of week for the slot, e.g. "monday". Used by create_slot/update_slot.', required: false)]
#[ToolParameter(name: 'time', type: 'string', description: 'Time of day for the slot in HH:MM (24h). Used by create_slot/update_slot.', required: false)]
#[ToolParameter(name: 'timezone', type: 'string', description: 'IANA timezone for the slot, e.g. "Europe/Berlin". Used by create_slot/update_slot.', required: false)]
final class ZernioQueueTool extends AbstractZernioTool
{
    private const SLOTS_PATH = '/queue/slots';

    public function execute(array $arguments, int $agentId, ?int $userId = null, ?int $taskId = null): ToolResult
    {
        $config = $this->resolveConfig($agentId, $userId);
        if ($config === null) {
            return $this->missingCredentialResult();
        }

        return $this->guard(fn(): ToolResult => match ($this->getOperationName($arguments)) {
            'preview_queue' => $this->read('/queue/preview', 'Queue preview', $arguments, $config),
            'next_slot'     => $this->read('/queue/next-slot', 'Next slot', $arguments, $config),
            'create_slot'   => $this->createSlot($arguments, $config),
            'update_slot'   => $this->updateSlot($arguments, $config),
            'delete_slot'   => $this->deleteSlot($arguments, $config),
            default         => $this->read(self::SLOTS_PATH, 'Queue slots', $arguments, $config),
        });
    }

    public function describeAction(array $arguments): string
    {
        return match ($this->getOperationName($arguments)) {
            'preview_queue' => 'Preview the Zernio queue',
            'next_slot'     => 'Get the next Zernio queue slot',
            'create_slot'   => 'Create a Zernio queue slot',
            'update_slot'   => 'Update Zernio queue slot ' . trim((string) ($arguments['slot_id'] ?? '')),
            'delete_slot'   => 'Delete Zernio queue slot ' . trim((string) ($arguments['slot_id'] ?? '')),
            default         => 'List Zernio queue slots',
        };
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function read(string $path, string $label, array $arguments, ZernioConfig $config): ToolResult
    {
        $profileId = trim((string) ($arguments['profile_id'] ?? ''));
        $query = $profileId !== '' ? ['profileId' => $profileId] : [];

        $response = $this->client->get($path, $query, $config);

        return new ToolResult(true, "{$label}:\n" . json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function createSlot(array $arguments, ZernioConfig $config): ToolResult
    {
        $payload = $this->slotPayload($arguments);
        if ($payload instanceof ToolResult) {
            return $payload;
        }

        $response = $this->client->post(self::SLOTS_PATH, $payload, $config);

        return new ToolResult(true, "Created queue slot:\n" . json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function updateSlot(array $arguments, ZernioConfig $config): ToolResult
    {
        $slotId = trim((string) ($arguments['slot_id'] ?? ''));
        if ($slotId === '') {
            return new ToolResult(false, 'update_slot requires a slot_id.');
        }

        $payload = $this->slotPayload($arguments);
        if ($payload instanceof ToolResult) {
            return $payload;
        }
        $payload['id'] = $slotId;

        $response = $this->client->put(self::SLOTS_PATH, $payload, $config);

        return new ToolResult(true, "Updated queue slot:\n" . json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function deleteSlot(array $arguments, ZernioConfig $config): ToolResult
    {
        $slotId = trim((string) ($arguments['slot_id'] ?? ''));
        if ($slotId === '') {
            return new ToolResult(false, 'delete_slot requires a slot_id.');
        }

        $this->client->delete(self::SLOTS_PATH, ['id' => $slotId], $config);

        return new ToolResult(true, "Deleted queue slot {$slotId}.", ['slot_id' => $slotId]);
    }

    /**
     * Build the day/time/timezone payload shared by create_slot and update_slot,
     * or a failed ToolResult when the required fields are missing.
     *
     * @param  array<string, mixed> $arguments
     * @return array<string, mixed>|ToolResult
     */
    private function slotPayload(array $arguments): array|ToolResult
    {
        $day  = trim((string) ($arguments['day'] ?? ''));
        $time = trim((string) ($arguments['time'] ?? ''));
        if ($day === '' || $time === '') {
            return new ToolResult(false, 'A queue slot requires both day and time.');
        }

        $payload = ['day' => $day, 'time' => $time];
        foreach (['timezone' => 'timezone', 'profile_id' => 'profileId'] as $arg => $param) {
            $value = trim((string) ($arguments[$arg] ?? ''));
            if ($value !== '') {
                $payload[$param] = $value;
            }
        }

        return $payload;
    }
}
