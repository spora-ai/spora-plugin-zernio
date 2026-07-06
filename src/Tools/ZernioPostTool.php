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
 * Create, schedule, publish, list, inspect, update, retry, unpublish, edit,
 * and bulk-upload Zernio posts. The `platforms` array is the primary input
 * for create_post and update_post (matching the Zernio OpenAPI shape); the
 * legacy `account_ids` + `platform` pair is still accepted for convenience.
 *
 * Publishing and deletion are irreversible on the target networks, so
 * `create_post`, `update_post`, `unpublish_post`, `bulk_upload`, and
 * `edit_post` require approval by default. `create_post` automatically
 * generates an `X-Request-Id` header for safe retry (idempotency).
 */
#[Tool(
    name: 'zernio_post',
    description: 'Create, schedule, publish, list, inspect, update, retry, unpublish, edit, or bulk-upload social media posts via Zernio. Pass `platforms` as [{platform, accountId, customContent?, customMedia[]?, scheduledFor?, platformSpecificData?}] — the simplest alternative is `account_ids` (list of account IDs) plus an optional `platform` name. Set publish_now to post immediately, scheduled_for + timezone to schedule, queued_from_profile to schedule via the posting queue, or none to save as a draft.',
    displayName: 'Zernio Post',
    category: 'social-media',
)]
#[ToolOperation(name: 'create_post', description: 'Create a draft, schedule, or publish a post', enabledByDefault: true, requiresApprovalByDefault: true)]
#[ToolOperation(name: 'list_posts', description: 'List posts', enabledByDefault: true, requiresApprovalByDefault: false)]
#[ToolOperation(name: 'get_post', description: 'Get a single post by ID', enabledByDefault: true, requiresApprovalByDefault: false)]
#[ToolOperation(name: 'update_post', description: 'Update a draft, scheduled, or failed post', enabledByDefault: true, requiresApprovalByDefault: true)]
#[ToolOperation(name: 'delete_post', description: 'Delete a draft or scheduled post (use unpublish_post for published ones)', enabledByDefault: true, requiresApprovalByDefault: true)]
#[ToolOperation(name: 'retry_post', description: 'Retry a failed post', enabledByDefault: true, requiresApprovalByDefault: true)]
#[ToolOperation(name: 'unpublish_post', description: 'Unpublish a published post from a specific platform', enabledByDefault: true, requiresApprovalByDefault: true)]
#[ToolOperation(name: 'edit_post', description: 'Edit the text of an X (Twitter) Premium post (within ~1h, max 5 edits)', enabledByDefault: true, requiresApprovalByDefault: true)]
#[ToolOperation(name: 'update_post_metadata', description: 'Update YouTube video metadata (title, description, tags, privacy, thumbnail, playlist)', enabledByDefault: true, requiresApprovalByDefault: true)]
#[ToolOperation(name: 'sync_external_posts', description: 'Sync posts authored on the platform outside Zernio for a given account', enabledByDefault: true, requiresApprovalByDefault: false)]
#[ToolOperation(name: 'bulk_upload', description: 'Upload a CSV of posts to schedule in bulk (set dry_run=true to validate first)', enabledByDefault: true, requiresApprovalByDefault: true)]
#[ToolSetting(key: 'api_key', label: 'Zernio API Key', type: 'password', description: 'Bearer token for the Zernio API. Falls back to the ZERNIO_API_KEY environment variable.', required: false)]
#[ToolSetting(key: 'base_url', label: 'Base URL', type: 'text', description: 'Zernio API base URL (default: https://zernio.com/api/v1).', default: 'https://zernio.com/api/v1')]
#[ToolSetting(key: 'http_timeout', label: 'HTTP Timeout', type: 'text', description: 'Seconds before an HTTP request fails (default: 30).')]
#[ToolParameter(name: 'platforms', type: 'array', description: 'Per-platform targets: [{platform, accountId, customContent?, customMedia[]?, scheduledFor?, platformSpecificData?}, …]. Preferred over account_ids for new integrations.', required: false, items: ['type' => 'object'])]
#[ToolParameter(name: 'account_ids', type: 'string[]', description: 'Convenience: a flat list of account IDs. Combined with `platform` to build the platforms[] array. Use either account_ids + platform OR platforms, not both.', required: false, items: ['type' => 'string'])]
#[ToolParameter(name: 'platform', type: 'string', description: 'Platform name used when account_ids is given (e.g. "twitter", "instagram", "linkedin"). Ignored if `platforms` is set.', required: false)]
#[ToolParameter(name: 'content', type: 'string', description: 'The text content of the post. Required for create_post unless media_items are attached or every platform has customContent.', required: false)]
#[ToolParameter(name: 'title', type: 'string', description: 'Optional post title (e.g. used by YouTube).', required: false)]
#[ToolParameter(name: 'media_items', type: 'array', description: 'Media to attach: [{url, type ("image"|"video"), thumbnailUrl?, alt?}, …].', required: false, items: ['type' => 'object'])]
#[ToolParameter(name: 'tags', type: 'string[]', description: 'Tags/keywords for the post.', required: false, items: ['type' => 'string'])]
#[ToolParameter(name: 'hashtags', type: 'string[]', description: 'Hashtags to add to the post.', required: false, items: ['type' => 'string'])]
#[ToolParameter(name: 'mentions', type: 'string[]', description: 'Mention identifiers (stored for reference; for LinkedIn @mentions use get_account_health → linkedin-mentions).', required: false, items: ['type' => 'string'])]
#[ToolParameter(name: 'scheduled_for', type: 'string', description: 'ISO-8601 datetime to schedule the post for (requires timezone). Omit for draft or publish_now. Do not combine with queued_from_profile.', required: false)]
#[ToolParameter(name: 'timezone', type: 'string', description: 'IANA timezone for scheduled_for, e.g. "Europe/Berlin".', required: false)]
#[ToolParameter(name: 'publish_now', type: 'boolean', description: 'Publish immediately instead of scheduling or drafting.', required: false, default: false)]
#[ToolParameter(name: 'is_draft', type: 'boolean', description: 'Save the post as a draft explicitly. Implicit when none of publish_now/scheduled_for/queued_from_profile are given.', required: false, default: false)]
#[ToolParameter(name: 'queued_from_profile', type: 'string', description: 'Profile ID to schedule via the posting queue. The post is auto-assigned to the next available slot. Do not also pass scheduled_for — that bypasses queue locking.', required: false)]
#[ToolParameter(name: 'queue_id', type: 'string', description: 'Specific queue ID when queued_from_profile is set. Defaults to the profile default queue.', required: false)]
#[ToolParameter(name: 'recycling', type: 'object', description: 'Recycling config { gap, gapFreq ("day"|"week"|"month"), expireCount, contentVariations[] } for evergreen posts.', required: false)]
#[ToolParameter(name: 'tiktok_settings', type: 'object', description: 'TikTok platform-specific settings (privacyLevel, allowComment, draft, …).', required: false)]
#[ToolParameter(name: 'facebook_settings', type: 'object', description: 'Facebook platform-specific settings (draft, carouselLink, carouselCards, …).', required: false)]
#[ToolParameter(name: 'post_id', type: 'string', description: 'The post ID. Required for get_post, update_post, delete_post, retry_post, unpublish_post, edit_post, update_post_metadata.', required: false)]
#[ToolParameter(name: 'status', type: 'string', description: 'Filter posts by status (draft, scheduled, published, failed). Used by list_posts.', required: false)]
#[ToolParameter(name: 'profile_id', type: 'string', description: 'Filter posts by profile ID. Used by list_posts.', required: false)]
#[ToolParameter(name: 'account_id', type: 'string', description: 'Filter posts to a specific social account. Used by list_posts and sync_external_posts.', required: false)]
#[ToolParameter(name: 'platform_filter', type: 'string', description: 'Filter posts by platform (used by list_posts to avoid name clash with the create_post `platform` param).', required: false)]
#[ToolParameter(name: 'date_from', type: 'string', description: 'Filter posts on or after this ISO date (YYYY-MM-DD). Used by list_posts.', required: false)]
#[ToolParameter(name: 'date_to', type: 'string', description: 'Filter posts on or before this ISO date (YYYY-MM-DD). Used by list_posts.', required: false)]
#[ToolParameter(name: 'include_hidden', type: 'boolean', description: 'Include hidden posts in list_posts results.', required: false, default: false)]
#[ToolParameter(name: 'search', type: 'string', description: 'Search posts by content text. Used by list_posts.', required: false)]
#[ToolParameter(name: 'sort_by', type: 'string', description: 'Sort order for list_posts: scheduled-desc (default), scheduled-asc, created-desc, created-asc, status, platform.', required: false)]
#[ToolParameter(name: 'source', type: 'string', description: 'Post source for list_posts: "zernio" (default) or "external" (synced from the platform).', required: false)]
#[ToolParameter(name: 'page', type: 'integer', description: 'Page number for list_posts (default 1).', required: false, default: 1)]
#[ToolParameter(name: 'limit', type: 'integer', description: 'Page size for list_posts (default 10, max 100).', required: false, default: 10)]
#[ToolParameter(name: 'platform_for_unpublish', type: 'string', description: 'Platform to unpublish the post from (threads, facebook, twitter, linkedin, youtube, pinterest, reddit, bluesky, googlebusiness, telegram). Required for unpublish_post.', required: false)]
#[ToolParameter(name: 'edit_content', type: 'string', description: 'New text content for edit_post (X Premium only).', required: false)]
#[ToolParameter(name: 'video_id', type: 'string', description: 'YouTube video ID for update_post_metadata (alternative to post_id-based update).', required: false)]
#[ToolParameter(name: 'yt_title', type: 'string', description: 'YouTube video title for update_post_metadata.', required: false)]
#[ToolParameter(name: 'yt_description', type: 'string', description: 'YouTube video description for update_post_metadata.', required: false)]
#[ToolParameter(name: 'yt_tags', type: 'string[]', description: 'YouTube video tags for update_post_metadata (combined ≤500 chars, each ≤100).', required: false, items: ['type' => 'string'])]
#[ToolParameter(name: 'yt_category_id', type: 'string', description: 'YouTube category ID for update_post_metadata.', required: false)]
#[ToolParameter(name: 'yt_privacy_status', type: 'string', description: 'YouTube privacy status: public, private, unlisted.', required: false)]
#[ToolParameter(name: 'yt_thumbnail_url', type: 'string', description: 'YouTube thumbnail image URL for update_post_metadata.', required: false)]
#[ToolParameter(name: 'yt_made_for_kids', type: 'boolean', description: 'Mark the YouTube video as made for kids.', required: false)]
#[ToolParameter(name: 'yt_contains_synthetic_media', type: 'boolean', description: 'Mark the YouTube video as containing synthetic media.', required: false)]
#[ToolParameter(name: 'yt_playlist_id', type: 'string', description: 'Add the YouTube video to this playlist (use get_account_health → youtube-playlists to find IDs).', required: false)]
#[ToolParameter(name: 'external_url', type: 'string', description: 'Optional URL hint for sync_external_posts.', required: false)]
#[ToolParameter(name: 'external_post_id', type: 'string', description: 'Optional platform post ID for sync_external_posts.', required: false)]
#[ToolParameter(name: 'csv_content', type: 'string', description: 'Raw CSV content for bulk_upload. The first row must be the header.', required: false)]
#[ToolParameter(name: 'dry_run', type: 'boolean', description: 'For bulk_upload, validate the CSV without creating posts.', required: false, default: false)]
final class ZernioPostTool extends AbstractZernioTool
{
    public function execute(array $arguments, int $agentId, ?int $userId = null, ?int $taskId = null): ToolResult
    {
        $config = $this->resolveConfig($agentId, $userId);
        if ($config === null) {
            return $this->missingCredentialResult();
        }

        return $this->guard(fn(): ToolResult => match ($this->getOperationName($arguments)) {
            'list_posts'            => $this->listPosts($arguments, $config),
            'get_post'              => $this->getPost($arguments, $config),
            'update_post'           => $this->updatePost($arguments, $config),
            'delete_post'           => $this->deletePost($arguments, $config),
            'retry_post'            => $this->retryPost($arguments, $config),
            'unpublish_post'        => $this->unpublishPost($arguments, $config),
            'edit_post'             => $this->editPost($arguments, $config),
            'update_post_metadata'  => $this->updatePostMetadata($arguments, $config),
            'sync_external_posts'   => $this->syncExternalPosts($arguments, $config),
            'bulk_upload'           => $this->bulkUpload($arguments, $config),
            default                 => $this->createPost($arguments, $config),
        });
    }

    public function describeAction(array $arguments): string
    {
        return match ($this->getOperationName($arguments)) {
            'list_posts'           => 'List Zernio posts',
            'get_post'             => 'Get Zernio post ' . $this->ref($arguments, 'post_id'),
            'update_post'          => 'Update Zernio post ' . $this->ref($arguments, 'post_id'),
            'delete_post'          => 'Delete Zernio post ' . $this->ref($arguments, 'post_id'),
            'retry_post'           => 'Retry Zernio post ' . $this->ref($arguments, 'post_id'),
            'unpublish_post'       => 'Unpublish Zernio post ' . $this->ref($arguments, 'post_id') . ' from ' . $this->ref($arguments, 'platform_for_unpublish'),
            'edit_post'            => 'Edit Zernio post ' . $this->ref($arguments, 'post_id'),
            'update_post_metadata' => 'Update YouTube metadata for Zernio post ' . $this->ref($arguments, 'post_id'),
            'sync_external_posts'  => 'Sync external posts for account ' . $this->ref($arguments, 'account_id'),
            'bulk_upload'          => (bool) ($arguments['dry_run'] ?? false) ? 'Dry-run bulk post upload' : 'Bulk upload posts via CSV',
            default                => $this->describeCreate($arguments),
        };
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function createPost(array $arguments, ZernioConfig $config): ToolResult
    {
        $platforms = $this->buildPlatforms($arguments);
        if ($platforms instanceof ToolResult) {
            return $platforms;
        }
        if (count($platforms) === 0) {
            return new ToolResult(false, 'create_post requires at least one target platform (account_ids + platform, or platforms[]).');
        }

        $content = trim((string) ($arguments['content'] ?? ''));
        $hasMedia = !empty($arguments['media_items']);
        $allCustomContent = $this->everyPlatformHasCustomContent($platforms);
        if ($content === '' && !$hasMedia && !$allCustomContent) {
            return new ToolResult(false, 'create_post requires content unless media_items are attached or every platform has customContent.');
        }

        $payload = ['platforms' => $platforms];
        if ($content !== '') {
            $payload['content'] = $content;
        }
        foreach (['title' => 'title', 'tags' => 'tags', 'hashtags' => 'hashtags', 'mentions' => 'mentions'] as $arg => $field) {
            $value = $arguments[$arg] ?? null;
            if (is_array($value) && $value !== []) {
                $payload[$field] = array_values($value);
            } elseif (is_string($value) && trim($value) !== '') {
                $payload[$field] = $value;
            }
        }
        if (isset($arguments['media_items']) && is_array($arguments['media_items']) && $arguments['media_items'] !== []) {
            $payload['mediaItems'] = array_values($arguments['media_items']);
        }
        $scheduling = $this->schedulingPayload($arguments);
        if ($scheduling instanceof ToolResult) {
            return $scheduling;
        }
        $payload = array_merge($payload, $scheduling);
        foreach (['recycling' => 'recycling', 'tiktok_settings' => 'tiktokSettings', 'facebook_settings' => 'facebookSettings'] as $arg => $field) {
            if (isset($arguments[$arg]) && is_array($arguments[$arg])) {
                $payload[$field] = $arguments[$arg];
            }
        }

        $requestId = $this->newRequestId();
        $response  = $this->client->post('/posts', $payload, $config, ['X-Request-Id' => $requestId]);
        $mode      = $this->modeLabel($arguments);

        return new ToolResult(
            true,
            "Post {$mode} (request {$requestId}):\n" . $this->encode($response),
            ['mode' => $mode, 'request_id' => $requestId],
        );
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function listPosts(array $arguments, ZernioConfig $config): ToolResult
    {
        $map = [
            'status'         => 'status',
            'profile_id'     => 'profileId',
            'account_id'     => 'accountId',
            'platform_filter' => 'platform',
            'date_from'      => 'dateFrom',
            'date_to'        => 'dateTo',
            'search'         => 'search',
            'sort_by'        => 'sortBy',
            'source'         => 'source',
        ];
        $query = [];
        foreach ($map as $arg => $param) {
            $value = trim((string) ($arguments[$arg] ?? ''));
            if ($value !== '') {
                $query[$param] = $value;
            }
        }
        if ((bool) ($arguments['include_hidden'] ?? false)) {
            $query['includeHidden'] = true;
        }
        if (isset($arguments['page'])) {
            $query['page'] = max(1, (int) $arguments['page']);
        }
        if (isset($arguments['limit'])) {
            $query['limit'] = max(1, min(100, (int) $arguments['limit']));
        }
        $response = $this->client->get('/posts', $query, $config);
        $items    = $this->listKey($response, 'posts');
        $count    = count($items);

        return new ToolResult(
            true,
            "Posts ({$count}):\n" . $this->encode($items),
            ['count' => $count],
        );
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function getPost(array $arguments, ZernioConfig $config): ToolResult
    {
        $postId = $this->requireParam($arguments, 'post_id', 'get_post requires a post_id.');
        if ($postId instanceof ToolResult) {
            return $postId;
        }
        $response = $this->client->get('/posts/' . rawurlencode($postId), [], $config);
        return new ToolResult(true, $this->encode($response));
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function updatePost(array $arguments, ZernioConfig $config): ToolResult
    {
        $postId = $this->requireParam($arguments, 'post_id', 'update_post requires a post_id.');
        if ($postId instanceof ToolResult) {
            return $postId;
        }

        $payload = [];
        foreach (['content' => 'content', 'title' => 'title'] as $arg => $field) {
            $value = $arguments[$arg] ?? null;
            if (is_string($value)) {
                $trimmed = trim($value);
                if ($trimmed !== '') {
                    $payload[$field] = $trimmed;
                }
            }
        }
        foreach (['tags' => 'tags', 'hashtags' => 'hashtags', 'mentions' => 'mentions'] as $arg => $field) {
            if (isset($arguments[$arg]) && is_array($arguments[$arg])) {
                $payload[$field] = array_values($arguments[$arg]);
            }
        }
        $payload += $this->schedulingPayload($arguments);
        if (array_key_exists('is_draft', $arguments)) {
            $payload['isDraft'] = (bool) $arguments['is_draft'];
        }
        if (isset($arguments['media_items']) && is_array($arguments['media_items'])) {
            $payload['mediaItems'] = array_values($arguments['media_items']);
        }
        if (isset($arguments['recycling']) && is_array($arguments['recycling'])) {
            $payload['recycling'] = $arguments['recycling'];
        }
        if ($payload === []) {
            return new ToolResult(false, 'update_post requires at least one of content, title, tags, hashtags, mentions, scheduled_for, is_draft, media_items, recycling.');
        }
        $response = $this->client->put('/posts/' . rawurlencode($postId), $payload, $config);
        return new ToolResult(true, "Updated post:\n" . $this->encode($response));
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function deletePost(array $arguments, ZernioConfig $config): ToolResult
    {
        $postId = $this->requireParam($arguments, 'post_id', 'delete_post requires a post_id.');
        if ($postId instanceof ToolResult) {
            return $postId;
        }
        $this->client->delete('/posts/' . rawurlencode($postId), [], $config);
        return new ToolResult(true, "Deleted post {$postId}.", ['post_id' => $postId]);
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function retryPost(array $arguments, ZernioConfig $config): ToolResult
    {
        $postId = $this->requireParam($arguments, 'post_id', 'retry_post requires a post_id.');
        if ($postId instanceof ToolResult) {
            return $postId;
        }
        $response = $this->client->post('/posts/' . rawurlencode($postId) . '/retry', [], $config);
        return new ToolResult(true, "Retried post:\n" . $this->encode($response));
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function unpublishPost(array $arguments, ZernioConfig $config): ToolResult
    {
        $postId = $this->requireParam($arguments, 'post_id', 'unpublish_post requires a post_id.');
        if ($postId instanceof ToolResult) {
            return $postId;
        }
        $platform = $this->requireParam($arguments, 'platform_for_unpublish', 'unpublish_post requires a platform_for_unpublish.');
        if ($platform instanceof ToolResult) {
            return $platform;
        }
        $response = $this->client->post('/posts/' . rawurlencode($postId) . '/unpublish', ['platform' => $platform], $config);
        return new ToolResult(true, "Unpublished post from {$platform}:\n" . $this->encode($response));
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function editPost(array $arguments, ZernioConfig $config): ToolResult
    {
        $postId = $this->requireParam($arguments, 'post_id', 'edit_post requires a post_id.');
        if ($postId instanceof ToolResult) {
            return $postId;
        }
        $content = $this->requireParam($arguments, 'edit_content', 'edit_post requires edit_content.');
        if ($content instanceof ToolResult) {
            return $content;
        }
        $response = $this->client->post('/posts/' . rawurlencode($postId) . '/edit', [
            'platform' => 'twitter',
            'content'  => $content,
        ], $config);
        return new ToolResult(true, "Edited post:\n" . $this->encode($response));
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function updatePostMetadata(array $arguments, ZernioConfig $config): ToolResult
    {
        $postId   = $this->ref($arguments, 'post_id');
        $videoId  = $this->ref($arguments, 'video_id');
        $accountId = $this->ref($arguments, 'account_id');
        if ($postId === '' && ($videoId === '' || $accountId === '')) {
            return new ToolResult(false, 'update_post_metadata requires either a post_id or both video_id and account_id.');
        }
        $payload = ['platform' => 'youtube'];
        if ($postId !== '') {
            $payload['postId'] = $postId;
        }
        if ($videoId !== '') {
            $payload['videoId'] = $videoId;
        }
        if ($accountId !== '') {
            $payload['accountId'] = $accountId;
        }
        foreach ([
            'yt_title'                  => 'title',
            'yt_description'            => 'description',
            'yt_category_id'            => 'categoryId',
            'yt_privacy_status'         => 'privacyStatus',
            'yt_thumbnail_url'          => 'thumbnailUrl',
        ] as $arg => $field) {
            $value = trim((string) ($arguments[$arg] ?? ''));
            if ($value !== '') {
                $payload[$field] = $value;
            }
        }
        if (isset($arguments['yt_tags']) && is_array($arguments['yt_tags'])) {
            $payload['tags'] = array_values($arguments['yt_tags']);
        }
        if (array_key_exists('yt_made_for_kids', $arguments)) {
            $payload['madeForKids'] = (bool) $arguments['yt_made_for_kids'];
        }
        if (array_key_exists('yt_contains_synthetic_media', $arguments)) {
            $payload['containsSyntheticMedia'] = (bool) $arguments['yt_contains_synthetic_media'];
        }
        $playlistId = trim((string) ($arguments['yt_playlist_id'] ?? ''));
        if ($playlistId !== '') {
            $payload['playlistId'] = $playlistId;
        }
        $path = $postId !== ''
            ? '/posts/' . rawurlencode($postId) . '/update-metadata'
            : '/posts/update-metadata';
        $response = $this->client->post($path, $payload, $config);
        return new ToolResult(true, "Updated YouTube metadata:\n" . $this->encode($response));
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function syncExternalPosts(array $arguments, ZernioConfig $config): ToolResult
    {
        $accountId = $this->requireParam($arguments, 'account_id', 'sync_external_posts requires an account_id.');
        if ($accountId instanceof ToolResult) {
            return $accountId;
        }
        $payload = ['accountId' => $accountId];
        foreach (['external_url' => 'url', 'external_post_id' => 'postId'] as $arg => $field) {
            $value = trim((string) ($arguments[$arg] ?? ''));
            if ($value !== '') {
                $payload[$field] = $value;
            }
        }
        $response = $this->client->post('/posts/sync-external', $payload, $config);
        return new ToolResult(true, "Synced external posts:\n" . $this->encode($response));
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function bulkUpload(array $arguments, ZernioConfig $config): ToolResult
    {
        $csv = (string) ($arguments['csv_content'] ?? '');
        if (trim($csv) === '') {
            return new ToolResult(false, 'bulk_upload requires csv_content.');
        }
        $dryRun = (bool) ($arguments['dry_run'] ?? false);
        $path   = '/posts/bulk-upload' . ($dryRun ? '?dryRun=true' : '');
        $body   = [
            'headers' => ['Content-Type' => 'text/csv'],
            'body'    => $csv,
        ];
        $response = $this->client->post($path, $body, $config);
        return new ToolResult(true, "Bulk upload:\n" . $this->encode($response));
    }

    /**
     * @param array<string, mixed> $arguments
     * @return list<array<string, mixed>>|ToolResult
     */
    private function buildPlatforms(array $arguments): array|ToolResult
    {
        if (isset($arguments['platforms']) && is_array($arguments['platforms']) && $arguments['platforms'] !== []) {
            $out = [];
            foreach ($arguments['platforms'] as $entry) {
                if (!is_array($entry)) {
                    return new ToolResult(false, 'Each entry in `platforms` must be an object with at least {platform, accountId}.');
                }
                $platform = trim((string) ($entry['platform'] ?? ''));
                $accountId = trim((string) ($entry['accountId'] ?? $entry['account_id'] ?? ''));
                if ($platform === '' || $accountId === '') {
                    return new ToolResult(false, 'Each entry in `platforms` must have both `platform` and `accountId`.');
                }
                $row = ['platform' => $platform, 'accountId' => $accountId];
                foreach (['customContent' => 'customContent', 'customMedia' => 'customMedia', 'scheduledFor' => 'scheduledFor', 'platformSpecificData' => 'platformSpecificData'] as $k => $alias) {
                    if (isset($entry[$k]) || isset($entry[$alias])) {
                        $row[$k] = $entry[$k] ?? $entry[$alias];
                    }
                }
                $out[] = $row;
            }
            return $out;
        }

        $ids = $arguments['account_ids'] ?? null;
        if (!is_array($ids) || $ids === []) {
            return [];
        }
        $platform = trim((string) ($arguments['platform'] ?? ''));
        if ($platform === '') {
            return new ToolResult(false, 'create_post with account_ids requires a `platform` name (e.g. "twitter"). Use `platforms` instead for per-platform targets.');
        }
        $out = [];
        foreach ($ids as $id) {
            if (!is_string($id) || trim($id) === '') {
                continue;
            }
            $out[] = ['platform' => $platform, 'accountId' => trim($id)];
        }
        return $out;
    }

    /**
     * @param list<array<string, mixed>> $platforms
     */
    private function everyPlatformHasCustomContent(array $platforms): bool
    {
        if ($platforms === []) {
            return false;
        }
        foreach ($platforms as $row) {
            if (empty($row['customContent'])) {
                return false;
            }
        }
        return true;
    }

    /**
     * @param  array<string, mixed> $arguments
     * @return array<string, mixed>|ToolResult
     */
    private function schedulingPayload(array $arguments): array|ToolResult
    {
        if ((bool) ($arguments['publish_now'] ?? false)) {
            return ['publishNow' => true];
        }
        $queued = trim((string) ($arguments['queued_from_profile'] ?? ''));
        if ($queued !== '') {
            $payload = ['queuedFromProfile' => $queued];
            $queueId = trim((string) ($arguments['queue_id'] ?? ''));
            if ($queueId !== '') {
                $payload['queueId'] = $queueId;
            }
            return $payload;
        }
        $scheduledFor = trim((string) ($arguments['scheduled_for'] ?? ''));
        if ($scheduledFor === '') {
            if (array_key_exists('is_draft', $arguments) && (bool) $arguments['is_draft']) {
                return ['isDraft' => true];
            }
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
    private function modeLabel(array $arguments): string
    {
        if ((bool) ($arguments['publish_now'] ?? false)) {
            return 'published';
        }
        if (trim((string) ($arguments['queued_from_profile'] ?? '')) !== '') {
            return 'queue-scheduled';
        }
        if (trim((string) ($arguments['scheduled_for'] ?? '')) !== '') {
            return 'scheduled';
        }
        return 'drafted';
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function describeCreate(array $arguments): string
    {
        $platforms = $this->buildPlatforms($arguments);
        $count = is_array($platforms) ? count($platforms) : 0;
        return ucfirst($this->modeLabel($arguments)) . " a post to {$count} account(s)";
    }

    private function newRequestId(): string
    {
        // RFC 4122 v4 UUID via random_bytes — no external dependency required.
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12),
        );
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return string|ToolResult
     */
    private function requireParam(array $arguments, string $key, string $error): string|ToolResult
    {
        $value = trim((string) ($arguments[$key] ?? ''));
        if ($value === '') {
            return new ToolResult(false, $error);
        }
        return $value;
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function ref(array $arguments, string $key): string
    {
        return trim((string) ($arguments[$key] ?? ''));
    }

    /**
     * @param mixed $value
     */
    private function encode(mixed $value): string
    {
        return (string) json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }
}
