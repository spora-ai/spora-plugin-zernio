<?php

declare(strict_types=1);

namespace Spora\Plugins\Zernio\Tools;

use Spora\Plugins\Zernio\Support\QueuePayloadBuilder;
use Spora\Plugins\Zernio\Support\ZernioConfig;
use Spora\Services\PrincipalContext;
use Spora\Tools\Attributes\Tool;
use Spora\Tools\Attributes\ToolOperation;
use Spora\Tools\Attributes\ToolParameter;
use Spora\Tools\Attributes\ToolSetting;
use Spora\Tools\ValueObjects\ToolResult;

/**
 * Manage Zernio's recurring posting queues — the named weekly time slots
 * that auto-scheduled posts drop into. The Zernio model is **queue → many
 * slots** (not many independent slots), so the operations below act on
 * queues. `day`/`time` are convenience parameters that get folded into the
 * required `slots[]` array. `queueId` is the identifier returned from
 * `list_slots` and is required to update or delete a non-default queue.
 *
 * Read operations (`list_slots`, `preview_queue`, `next_slot`) need no
 * approval; queue mutations (`create_slot`, `update_slot`, `delete_slot`)
 * require approval. The slot/payload assembly lives in
 * {@see QueuePayloadBuilder} so this class
 * stays under the SonarQube 20-method limit.
 */
#[Tool(
    name: 'zernio_queue',
    description: 'Inspect and manage Zernio posting queues: list named queues, preview upcoming slots, find the next open slot, and create/update/delete a queue. The simplest input is day ("monday".."sunday" or 0-6) plus time ("HH:MM") plus timezone plus name; for full control pass slots[] directly.',
    displayName: 'Zernio Queue',
    category: 'social-media',
)]
#[ToolOperation(name: 'list_slots', description: 'List queue schedules for a profile (use all=true to list every queue)', enabledByDefault: true, requiresApprovalByDefault: false)]
#[ToolOperation(name: 'preview_queue', description: 'Preview upcoming scheduled slot times for a queue', enabledByDefault: true, requiresApprovalByDefault: false)]
#[ToolOperation(name: 'next_slot', description: 'Get the next available queue slot', enabledByDefault: true, requiresApprovalByDefault: false)]
#[ToolOperation(name: 'create_slot', description: 'Create a new queue schedule (the first call creates the profile default)', enabledByDefault: true, requiresApprovalByDefault: true)]
#[ToolOperation(name: 'update_slot', description: 'Update an existing queue (or the default one if queue_id is omitted)', enabledByDefault: true, requiresApprovalByDefault: true)]
#[ToolOperation(name: 'delete_slot', description: 'Delete a queue schedule', enabledByDefault: true, requiresApprovalByDefault: true)]
#[ToolSetting(key: 'api_key', label: 'Zernio API Key', type: 'password', description: 'Bearer token for the Zernio API. Falls back to the ZERNIO_API_KEY environment variable.', required: true)]
#[ToolSetting(key: 'base_url', label: 'Base URL', type: 'text', description: 'Zernio API base URL (default: https://zernio.com/api/v1).', default: 'https://zernio.com/api/v1')]
#[ToolSetting(key: 'http_timeout', label: 'HTTP Timeout', type: 'text', description: 'Seconds before an HTTP request fails (default: 30).')]
#[ToolParameter(name: 'profile_id', type: 'string', description: 'Profile ID the queue belongs to. Required for every operation.', required: ['list_slots', 'preview_queue', 'next_slot', 'create_slot', 'update_slot', 'delete_slot'])]
#[ToolParameter(name: 'queue_id', type: 'string', description: 'Queue ID (from list_slots) to filter or target. Required for delete_slot; optional for list_slots/update_slot (omit to update the default).', required: ['delete_slot'])]
#[ToolParameter(name: 'all', type: 'boolean', description: 'Set true on list_slots to return every queue for the profile.', required: false, default: false)]
#[ToolParameter(name: 'count', type: 'integer', description: 'Number of upcoming slots to preview (1-100, default 20).', required: false, default: 20)]
#[ToolParameter(name: 'name', type: 'string', description: 'Queue name (e.g. "Evening Posts"). Required for create_slot; optional for update_slot.', required: ['create_slot'])]
#[ToolParameter(name: 'day', type: 'string', description: 'Day of week as a name ("monday".."sunday") or number (0-6, Sunday=0). Required (with `time`) for create_slot/update_slot when no `slots` array is given.', required: ['create_slot', 'update_slot'])]
#[ToolParameter(name: 'time', type: 'string', description: 'Time of day in "HH:MM" 24h. Required (with `day`) for create_slot/update_slot when no `slots` array is given.', required: ['create_slot', 'update_slot'])]
#[ToolParameter(name: 'timezone', type: 'string', description: 'IANA timezone for the queue, e.g. "Europe/Berlin".', required: false)]
#[ToolParameter(name: 'slots', type: 'array', description: 'Full slots array: [{dayOfWeek: 0-6, time: "HH:MM"}, …]. Overrides day/time when provided; required for create_slot/update_slot when day/time are not given.', required: ['create_slot', 'update_slot'], items: ['type' => 'object'])]
#[ToolParameter(name: 'active', type: 'boolean', description: 'Whether the queue is active. Defaults to true.', required: false, default: true)]
#[ToolParameter(name: 'set_as_default', type: 'boolean', description: 'Make this queue the profile default (update_slot only).', required: false, default: false)]
#[ToolParameter(name: 'reshuffle_existing', type: 'boolean', description: 'Reschedule existing queued posts to match the new slots (update_slot only).', required: false, default: false)]
final class ZernioQueueTool extends AbstractZernioTool
{
    private const SLOTS_PATH = '/queue/slots';

    public function execute(
        array $arguments,
        int $agentId,
        ?int $userId = null,
        ?int $taskId = null,
        ?PrincipalContext $context = null,
    ): ToolResult {
        $ownerId = $context->ownerUserId ?? $userId;
        return $this->withConfig($agentId, $ownerId, fn(ZernioConfig $config): ToolResult => $this->guard(
            fn(): ToolResult => match ($this->getOperationName($arguments)) {
                'preview_queue' => $this->readPath('Queue preview', '/queue/preview', $arguments, $config),
                'next_slot'     => $this->readPath('Next slot', '/queue/next-slot', $arguments, $config),
                'create_slot'   => $this->createSlot($arguments, $config),
                'update_slot'   => $this->updateSlot($arguments, $config),
                'delete_slot'   => $this->deleteSlot($arguments, $config),
                default         => $this->listSlots($arguments, $config),
            },
        ));
    }

    public function describeAction(array $arguments): string
    {
        $profile = $this->arg($arguments, 'profile_id');
        $queue   = $this->arg($arguments, 'queue_id');
        return match ($this->getOperationName($arguments)) {
            'preview_queue'  => "Preview Zernio queue for profile {$profile}",
            'next_slot'      => "Get next Zernio queue slot for profile {$profile}",
            'create_slot'    => "Create a Zernio queue for profile {$profile}",
            'update_slot'    => "Update Zernio queue {$queue} on profile {$profile}",
            'delete_slot'    => "Delete Zernio queue {$queue} on profile {$profile}",
            default          => "List Zernio queues for profile {$profile}",
        };
    }

    /** @param array<string, mixed> $arguments */
    private function listSlots(array $arguments, ZernioConfig $config): ToolResult
    {
        $profileId = $this->requireParam($arguments, 'profile_id', 'list_slots requires a profile_id.');
        if ($profileId instanceof ToolResult) {
            return $profileId;
        }
        $query = ['profileId' => $profileId];
        $queueId = $this->arg($arguments, 'queue_id');
        if ($queueId !== '') {
            $query['queueId'] = $queueId;
        }
        if ((bool) ($arguments['all'] ?? false)) {
            $query['all'] = 'true';
        }
        return $this->renderSlots($this->client->get(self::SLOTS_PATH, $query, $config));
    }

    /** @param array<string, mixed> $arguments */
    private function readPath(string $label, string $path, array $arguments, ZernioConfig $config): ToolResult
    {
        $query = QueuePayloadBuilder::queueReadQuery($arguments, $this->getOperationName($arguments));
        if ($query instanceof ToolResult) {
            return $query;
        }
        return $this->jsonResult("{$label}:\n", $this->client->get($path, $query, $config));
    }

    /** @param array<string, mixed> $arguments */
    private function createSlot(array $arguments, ZernioConfig $config): ToolResult
    {
        $payload = QueuePayloadBuilder::createQueuePayload($arguments);
        if ($payload instanceof ToolResult) {
            return $payload;
        }
        $response = $this->client->post(self::SLOTS_PATH, $payload, $config);
        return $this->jsonResult("Created queue:\n", $response);
    }

    /** @param array<string, mixed> $arguments */
    private function updateSlot(array $arguments, ZernioConfig $config): ToolResult
    {
        $payload = QueuePayloadBuilder::updateQueuePayload($arguments);
        if ($payload instanceof ToolResult) {
            return $payload;
        }
        $queueId = $this->arg($arguments, 'queue_id');
        if ($queueId !== '') {
            $payload['queueId'] = $queueId;
        }
        $response = $this->client->put(self::SLOTS_PATH, $payload, $config);
        return $this->jsonResult("Updated queue:\n", $response);
    }

    /** @param array<string, mixed> $arguments */
    private function deleteSlot(array $arguments, ZernioConfig $config): ToolResult
    {
        $profileId = $this->requireParam($arguments, 'profile_id', 'delete_slot requires a profile_id.');
        if ($profileId instanceof ToolResult) {
            return $profileId;
        }
        $queueId = $this->requireParam($arguments, 'queue_id', 'delete_slot requires a queue_id.');
        if ($queueId instanceof ToolResult) {
            return $queueId;
        }
        $this->client->delete(self::SLOTS_PATH, ['profileId' => $profileId, 'queueId' => $queueId], $config);
        return new ToolResult(true, "Deleted queue {$queueId} on profile {$profileId}.", [
            'queue_id'   => $queueId,
            'profile_id' => $profileId,
        ]);
    }

    /**
     * @param array<string, mixed> $response
     */
    private function renderSlots(array $response): ToolResult
    {
        if (isset($response['queues']) && is_array($response['queues'])) {
            $items = $this->listKey($response, 'queues');
            return new ToolResult(
                true,
                'Queues (' . count($items) . "):\n" . $this->encode($items),
                ['count' => count($items)],
            );
        }
        return $this->jsonResult("Queue:\n", $response);
    }
}
