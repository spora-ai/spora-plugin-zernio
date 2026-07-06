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
 * Create, schedule, publish, inspect, and delete Zernio posts.
 *
 * `create_post` covers all three publishing modes with one call:
 *   - `publish_now: true`           → publish immediately;
 *   - `scheduled_for` + `timezone`  → schedule for a future time;
 *   - neither                       → save as a draft.
 *
 * Publishing and deletion are irreversible on the target networks, so
 * `create_post` and `delete_post` require approval by default.
 */
#[Tool(
    name: 'zernio_post',
    description: 'Create, schedule, publish, list, inspect, or delete social media posts via Zernio. Provide account IDs from zernio_accounts. Set publish_now to post immediately, scheduled_for + timezone to schedule, or neither to save a draft.',
    displayName: 'Zernio Post',
    category: 'social-media',
)]
#[ToolOperation(name: 'create_post', description: 'Create a draft, schedule, or publish a post', enabledByDefault: true, requiresApprovalByDefault: true)]
#[ToolOperation(name: 'list_posts', description: 'List posts', enabledByDefault: true, requiresApprovalByDefault: false)]
#[ToolOperation(name: 'get_post', description: 'Get a single post by ID', enabledByDefault: true, requiresApprovalByDefault: false)]
#[ToolOperation(name: 'delete_post', description: 'Delete a post by ID', enabledByDefault: true, requiresApprovalByDefault: true)]
#[ToolSetting(key: 'api_key', label: 'Zernio API Key', type: 'password', description: 'Bearer token for the Zernio API. Falls back to the ZERNIO_API_KEY environment variable.', required: false)]
#[ToolSetting(key: 'base_url', label: 'Base URL', type: 'text', description: 'Zernio API base URL (default: https://zernio.com/api/v1).', default: 'https://zernio.com/api/v1')]
#[ToolSetting(key: 'http_timeout', label: 'HTTP Timeout', type: 'text', description: 'Seconds before an HTTP request fails (default: 30).')]
#[ToolParameter(name: 'account_ids', type: 'array', description: 'IDs of the connected accounts to post to (from zernio_accounts). Required for create_post.', required: false, items: ['type' => 'string'])]
#[ToolParameter(name: 'content', type: 'string', description: 'The text content of the post. Required for create_post.', required: false)]
#[ToolParameter(name: 'media_urls', type: 'array', description: 'Optional public URLs of images/videos to attach to the post.', required: false, items: ['type' => 'string'])]
#[ToolParameter(name: 'scheduled_for', type: 'string', description: 'ISO-8601 datetime to schedule the post for (requires timezone). Omit for draft or publish_now.', required: false)]
#[ToolParameter(name: 'timezone', type: 'string', description: 'IANA timezone for scheduled_for, e.g. "Europe/Berlin".', required: false)]
#[ToolParameter(name: 'publish_now', type: 'boolean', description: 'Publish immediately instead of scheduling or drafting.', required: false, default: false)]
#[ToolParameter(name: 'post_id', type: 'string', description: 'The post ID. Required for get_post and delete_post.', required: false)]
#[ToolParameter(name: 'status', type: 'string', description: 'Optional status filter for list_posts (e.g. draft, scheduled, published).', required: false)]
#[ToolParameter(name: 'profile_id', type: 'string', description: 'Optional profile ID filter for list_posts.', required: false)]
final class ZernioPostTool extends AbstractZernioTool
{
    public function execute(array $arguments, int $agentId, ?int $userId = null, ?int $taskId = null): ToolResult
    {
        $config = $this->resolveConfig($agentId, $userId);
        if ($config === null) {
            return $this->missingCredentialResult();
        }

        return $this->guard(fn(): ToolResult => match ($this->getOperationName($arguments)) {
            'list_posts'  => $this->listPosts($arguments, $config),
            'get_post'    => $this->getPost($arguments, $config),
            'delete_post' => $this->deletePost($arguments, $config),
            default       => $this->createPost($arguments, $config),
        });
    }

    public function describeAction(array $arguments): string
    {
        return match ($this->getOperationName($arguments)) {
            'list_posts'  => 'List Zernio posts',
            'get_post'    => 'Get Zernio post ' . trim((string) ($arguments['post_id'] ?? '')),
            'delete_post' => 'Delete Zernio post ' . trim((string) ($arguments['post_id'] ?? '')),
            default       => $this->describeCreate($arguments),
        };
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function createPost(array $arguments, ZernioConfig $config): ToolResult
    {
        $accountIds = $this->stringList($arguments['account_ids'] ?? []);
        $content    = trim((string) ($arguments['content'] ?? ''));

        if ($accountIds === []) {
            return new ToolResult(false, 'create_post requires at least one account ID in account_ids (see zernio_accounts).');
        }
        if ($content === '') {
            return new ToolResult(false, 'create_post requires non-empty content.');
        }

        $scheduling = $this->schedulingPayload($arguments);
        if ($scheduling instanceof ToolResult) {
            return $scheduling;
        }

        $payload = ['accountIds' => $accountIds, 'content' => $content];

        $mediaUrls = $this->stringList($arguments['media_urls'] ?? []);
        if ($mediaUrls !== []) {
            $payload['mediaUrls'] = $mediaUrls;
        }

        $payload += $scheduling;

        $response = $this->client->post('/posts', $payload, $config);
        $mode = $this->modeLabel($arguments);

        return new ToolResult(
            true,
            "Post {$mode}:\n" . json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            ['mode' => $mode],
        );
    }

    /**
     * Build the publish/schedule/draft fields, or a failed ToolResult when
     * scheduling is requested without a timezone.
     *
     * @param  array<string, mixed> $arguments
     * @return array<string, mixed>|ToolResult
     */
    private function schedulingPayload(array $arguments): array|ToolResult
    {
        if ((bool) ($arguments['publish_now'] ?? false)) {
            return ['publishNow' => true];
        }

        $scheduledFor = trim((string) ($arguments['scheduled_for'] ?? ''));
        if ($scheduledFor === '') {
            return [];
        }

        $timezone = trim((string) ($arguments['timezone'] ?? ''));
        if ($timezone === '') {
            return new ToolResult(false, 'Scheduling a post requires a timezone alongside scheduled_for.');
        }

        return ['scheduledFor' => $scheduledFor, 'timezone' => $timezone];
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function listPosts(array $arguments, ZernioConfig $config): ToolResult
    {
        $query = [];
        foreach (['status' => 'status', 'profile_id' => 'profileId'] as $arg => $param) {
            $value = trim((string) ($arguments[$arg] ?? ''));
            if ($value !== '') {
                $query[$param] = $value;
            }
        }

        $response = $this->client->get('/posts', $query, $config);
        $items = $response['data'] ?? $response;
        $count = is_array($items) ? count($items) : 0;

        return new ToolResult(
            true,
            "Posts ({$count}):\n" . json_encode($items, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            ['count' => $count],
        );
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function getPost(array $arguments, ZernioConfig $config): ToolResult
    {
        $postId = trim((string) ($arguments['post_id'] ?? ''));
        if ($postId === '') {
            return new ToolResult(false, 'get_post requires a post_id.');
        }

        $response = $this->client->get('/posts/' . rawurlencode($postId), [], $config);

        return new ToolResult(true, json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function deletePost(array $arguments, ZernioConfig $config): ToolResult
    {
        $postId = trim((string) ($arguments['post_id'] ?? ''));
        if ($postId === '') {
            return new ToolResult(false, 'delete_post requires a post_id.');
        }

        $this->client->delete('/posts/' . rawurlencode($postId), [], $config);

        return new ToolResult(true, "Deleted post {$postId}.", ['post_id' => $postId]);
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function describeCreate(array $arguments): string
    {
        $accounts = count($this->stringList($arguments['account_ids'] ?? []));
        return ucfirst($this->modeLabel($arguments)) . " a post to {$accounts} account(s)";
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function modeLabel(array $arguments): string
    {
        if ((bool) ($arguments['publish_now'] ?? false)) {
            return 'published';
        }
        return trim((string) ($arguments['scheduled_for'] ?? '')) !== '' ? 'scheduled' : 'drafted';
    }

    /**
     * Coerce a tool argument into a clean list of non-empty strings.
     *
     * @return list<string>
     */
    private function stringList(mixed $value): array
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
}
