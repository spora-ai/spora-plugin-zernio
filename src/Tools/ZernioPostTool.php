<?php

declare(strict_types=1);

namespace Spora\Plugins\Zernio\Tools;

use Spora\Plugins\Zernio\Support\PostPayloadBuilder;
use Spora\Plugins\Zernio\Support\ZernioConfig;
use Spora\Services\PrincipalContext;
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
#[ToolParameter(name: 'account_ids', type: 'array', description: 'Convenience: a flat list of account IDs. Combined with `platform` to build the platforms[] array. Use either account_ids + platform OR platforms, not both.', required: false, items: ['type' => 'string'])]
#[ToolParameter(name: 'platform', type: 'string', description: 'Platform name used when account_ids is given (e.g. "twitter", "instagram", "linkedin"). Ignored if `platforms` is set.', required: false)]
#[ToolParameter(name: 'content', type: 'string', description: 'The text content of the post. Required for create_post unless media_items are attached or every platform has customContent.', required: false)]
#[ToolParameter(name: 'title', type: 'string', description: 'Optional post title (e.g. used by YouTube).', required: false)]
#[ToolParameter(name: 'media_items', type: 'array', description: 'Media to attach: [{url, type ("image"|"video"), thumbnailUrl?, alt?}, …].', required: false, items: ['type' => 'object'])]
#[ToolParameter(name: 'tags', type: 'array', description: 'Tags/keywords for the post.', required: false, items: ['type' => 'string'])]
#[ToolParameter(name: 'hashtags', type: 'array', description: 'Hashtags to add to the post.', required: false, items: ['type' => 'string'])]
#[ToolParameter(name: 'mentions', type: 'array', description: 'Mention identifiers (stored for reference; for LinkedIn @mentions use get_account_health → linkedin-mentions).', required: false, items: ['type' => 'string'])]
#[ToolParameter(name: 'scheduled_for', type: 'string', description: 'ISO-8601 datetime to schedule the post for (requires timezone). Omit for draft or publish_now. Do not combine with queued_from_profile.', required: false)]
#[ToolParameter(name: 'timezone', type: 'string', description: 'IANA timezone for scheduled_for, e.g. "Europe/Berlin".', required: false)]
#[ToolParameter(name: 'publish_now', type: 'boolean', description: 'Publish immediately instead of scheduling or drafting.', required: false, default: false)]
#[ToolParameter(name: 'is_draft', type: 'boolean', description: 'Save the post as a draft explicitly. Implicit when none of publish_now/scheduled_for/queued_from_profile are given.', required: false, default: false)]
#[ToolParameter(name: 'queued_from_profile', type: 'string', description: 'Profile ID to schedule via the posting queue. The post is auto-assigned to the next available slot. Do not also pass scheduled_for — that bypasses queue locking.', required: false)]
#[ToolParameter(name: 'queue_id', type: 'string', description: 'Specific queue ID when queued_from_profile is set. Defaults to the profile default queue.', required: false)]
#[ToolParameter(name: 'recycling', type: 'object', description: 'Recycling config { gap, gapFreq ("day"|"week"|"month"), expireCount, contentVariations[] } for evergreen posts.', required: false)]
#[ToolParameter(name: 'tiktok_settings', type: 'object', description: 'TikTok platform-specific settings (privacyLevel, allowComment, draft, …).', required: false)]
#[ToolParameter(name: 'facebook_settings', type: 'object', description: 'Facebook platform-specific settings (draft, carouselLink, carouselCards, …).', required: false)]
#[ToolParameter(name: 'post_id', type: 'string', description: 'The post ID. Required for get_post, update_post, delete_post, retry_post, unpublish_post, edit_post, update_post_metadata.', required: ['get_post', 'update_post', 'delete_post', 'retry_post', 'unpublish_post', 'edit_post', 'update_post_metadata'])]
#[ToolParameter(name: 'status', type: 'string', description: 'Filter posts by status (draft, scheduled, published, failed). Used by list_posts.', required: false)]
#[ToolParameter(name: 'profile_id', type: 'string', description: 'Filter posts by profile ID. Used by list_posts.', required: false)]
#[ToolParameter(name: 'account_id', type: 'string', description: 'Account ID. Filter for list_posts; required for sync_external_posts; alternative to post_id for update_post_metadata.', required: ['sync_external_posts'])]
#[ToolParameter(name: 'platform_filter', type: 'string', description: 'Filter posts by platform (used by list_posts to avoid name clash with the create_post `platform` param).', required: false)]
#[ToolParameter(name: 'date_from', type: 'string', description: 'Filter posts on or after this ISO date (YYYY-MM-DD). Used by list_posts.', required: false)]
#[ToolParameter(name: 'date_to', type: 'string', description: 'Filter posts on or before this ISO date (YYYY-MM-DD). Used by list_posts.', required: false)]
#[ToolParameter(name: 'include_hidden', type: 'boolean', description: 'Include hidden posts in list_posts results.', required: false, default: false)]
#[ToolParameter(name: 'search', type: 'string', description: 'Search posts by content text. Used by list_posts.', required: false)]
#[ToolParameter(name: 'sort_by', type: 'string', description: 'Sort order for list_posts: scheduled-desc (default), scheduled-asc, created-desc, created-asc, status, platform.', required: false)]
#[ToolParameter(name: 'source', type: 'string', description: 'Post source for list_posts: "zernio" (default) or "external" (synced from the platform).', required: false)]
#[ToolParameter(name: 'page', type: 'integer', description: 'Page number for list_posts (default 1).', required: false, default: 1)]
#[ToolParameter(name: 'limit', type: 'integer', description: 'Page size for list_posts (default 10, max 100).', required: false, default: 10)]
#[ToolParameter(name: 'platform_for_unpublish', type: 'string', description: 'Platform to unpublish the post from (threads, facebook, twitter, linkedin, youtube, pinterest, reddit, bluesky, googlebusiness, telegram). Required for unpublish_post.', required: ['unpublish_post'])]
#[ToolParameter(name: 'edit_content', type: 'string', description: 'New text content for edit_post (X Premium only).', required: ['edit_post'])]
#[ToolParameter(name: 'video_id', type: 'string', description: 'YouTube video ID for update_post_metadata (alternative to post_id-based update).', required: false)]
#[ToolParameter(name: 'yt_title', type: 'string', description: 'YouTube video title for update_post_metadata.', required: false)]
#[ToolParameter(name: 'yt_description', type: 'string', description: 'YouTube video description for update_post_metadata.', required: false)]
#[ToolParameter(name: 'yt_tags', type: 'array', description: 'YouTube video tags for update_post_metadata (combined ≤500 chars, each ≤100).', required: false, items: ['type' => 'string'])]
#[ToolParameter(name: 'yt_category_id', type: 'string', description: 'YouTube category ID for update_post_metadata.', required: false)]
#[ToolParameter(name: 'yt_privacy_status', type: 'string', description: 'YouTube privacy status: public, private, unlisted.', required: false)]
#[ToolParameter(name: 'yt_thumbnail_url', type: 'string', description: 'YouTube thumbnail image URL for update_post_metadata.', required: false)]
#[ToolParameter(name: 'yt_made_for_kids', type: 'boolean', description: 'Mark the YouTube video as made for kids.', required: false)]
#[ToolParameter(name: 'yt_contains_synthetic_media', type: 'boolean', description: 'Mark the YouTube video as containing synthetic media.', required: false)]
#[ToolParameter(name: 'yt_playlist_id', type: 'string', description: 'Add the YouTube video to this playlist (use get_account_health → youtube-playlists to find IDs).', required: false)]
#[ToolParameter(name: 'external_url', type: 'string', description: 'Optional URL hint for sync_external_posts.', required: false)]
#[ToolParameter(name: 'external_post_id', type: 'string', description: 'Optional platform post ID for sync_external_posts.', required: false)]
#[ToolParameter(name: 'csv_content', type: 'string', description: 'Raw CSV content for bulk_upload. The first row must be the header.', required: ['bulk_upload'])]
#[ToolParameter(name: 'dry_run', type: 'boolean', description: 'For bulk_upload, validate the CSV without creating posts.', required: false, default: false)]
final class ZernioPostTool extends AbstractZernioTool
{
    private const POSTS_PATH          = '/posts';
    private const POST_PATH           = '/posts/';
    private const RETRY_SUFFIX        = '/retry';
    private const UNPUBLISH_SUFFIX    = '/unpublish';
    private const EDIT_SUFFIX         = '/edit';
    private const UPDATE_META_SUFFIX  = '/update-metadata';
    private const BULK_UPLOAD_PATH    = '/posts/bulk-upload';
    private const SYNC_EXTERNAL_PATH  = '/posts/sync-external';

    public function execute(
        array $arguments,
        int $agentId,
        ?int $userId = null,
        ?int $taskId = null,
        ?PrincipalContext $context = null,
    ): ToolResult {
        return $this->withConfig($agentId, $userId, fn(ZernioConfig $config): ToolResult => $this->guard(
            fn(): ToolResult => match ($this->getOperationName($arguments)) {
                'list_posts'           => $this->listPosts($arguments, $config),
                'get_post'             => $this->getById($arguments, $config, self::POST_PATH, 'post_id', 'get_post'),
                'update_post'          => $this->updatePost($arguments, $config),
                'delete_post'          => $this->deletePost($arguments, $config),
                'retry_post'           => $this->postSubresource($arguments, $config, 'post_id', self::POST_PATH, self::RETRY_SUFFIX, 'retry_post', successLabel: "Retried post:\n"),
                'unpublish_post'       => $this->unpublishPost($arguments, $config),
                'edit_post'            => $this->editPost($arguments, $config),
                'update_post_metadata' => $this->updatePostMetadata($arguments, $config),
                'sync_external_posts'  => $this->syncExternalPosts($arguments, $config),
                'bulk_upload'          => $this->bulkUpload($arguments, $config),
                default                => $this->createPost($arguments, $config),
            },
        ));
    }

    public function describeAction(array $arguments): string
    {
        return match ($this->getOperationName($arguments)) {
            'list_posts'           => 'List Zernio posts',
            'get_post'             => 'Get Zernio post ' . $this->arg($arguments, 'post_id'),
            'update_post'          => 'Update Zernio post ' . $this->arg($arguments, 'post_id'),
            'delete_post'          => 'Delete Zernio post ' . $this->arg($arguments, 'post_id'),
            'retry_post'           => 'Retry Zernio post ' . $this->arg($arguments, 'post_id'),
            'unpublish_post'       => 'Unpublish Zernio post ' . $this->arg($arguments, 'post_id') . ' from ' . $this->arg($arguments, 'platform_for_unpublish'),
            'edit_post'            => 'Edit Zernio post ' . $this->arg($arguments, 'post_id'),
            'update_post_metadata' => 'Update YouTube metadata for Zernio post ' . $this->arg($arguments, 'post_id'),
            'sync_external_posts'  => 'Sync external posts for account ' . $this->arg($arguments, 'account_id'),
            'bulk_upload'          => (bool) ($arguments['dry_run'] ?? false) ? 'Dry-run bulk post upload' : 'Bulk upload posts via CSV',
            default                => PostPayloadBuilder::describeCreate($arguments),
        };
    }

    /** @param array<string, mixed> $arguments */
    private function createPost(array $arguments, ZernioConfig $config): ToolResult
    {
        $payload = $this->buildCreatePayload($arguments);
        if ($payload instanceof ToolResult) {
            return $payload;
        }
        $requestId = $this->newRequestId();
        $response  = $this->client->post(self::POSTS_PATH, $payload, $config, ['X-Request-Id' => $requestId]);
        $mode      = PostPayloadBuilder::modeLabel($arguments);
        return new ToolResult(
            true,
            "Post {$mode} (request {$requestId}):\n" . $this->encode($response),
            ['mode' => $mode, 'request_id' => $requestId],
        );
    }

    /**
     * @param  array<string, mixed> $arguments
     * @return array<string, mixed>|ToolResult
     */
    private function buildCreatePayload(array $arguments): array|ToolResult
    {
        $platforms = PostPayloadBuilder::buildPlatforms($arguments);
        if ($platforms instanceof ToolResult || $platforms === []) {
            return $this->platformsError($platforms);
        }
        $scheduling = PostPayloadBuilder::schedulingPayload($arguments);
        if ($scheduling instanceof ToolResult) {
            return $scheduling;
        }
        return $this->assembleCreatePayload($arguments, $platforms, $scheduling);
    }

    /**
     * @param list<array<string, mixed>>|ToolResult $platforms
     */
    private function platformsError(array|ToolResult $platforms): ToolResult
    {
        if ($platforms instanceof ToolResult) {
            return $platforms;
        }
        return new ToolResult(false, 'create_post requires at least one target platform (account_ids + platform, or platforms[]).');
    }

    /**
     * @param  array<string, mixed>           $arguments
     * @param  list<array<string, mixed>>     $platforms
     * @param  array<string, mixed>           $scheduling
     * @return array<string, mixed>|ToolResult
     */
    private function assembleCreatePayload(array $arguments, array $platforms, array $scheduling): array|ToolResult
    {
        $content    = $this->arg($arguments, 'content');
        $hasContent = $content !== ''
            || PostPayloadBuilder::hasMedia($arguments)
            || PostPayloadBuilder::everyPlatformHasCustomContent($platforms);
        if (!$hasContent) {
            return new ToolResult(false, 'create_post requires content unless media_items are attached or every platform has customContent.');
        }
        return $this->mergeCreatePayload($arguments, $platforms, $content, $scheduling);
    }

    /**
     * @param  array<string, mixed>           $arguments
     * @param  list<array<string, mixed>>     $platforms
     * @param  array<string, mixed>           $scheduling
     * @return array<string, mixed>
     */
    private function mergeCreatePayload(array $arguments, array $platforms, string $content, array $scheduling): array
    {
        $payload = ['platforms' => $platforms];
        if ($content !== '') {
            $payload['content'] = $content;
        }
        $payload += PostPayloadBuilder::stringListPayload($arguments, [
            'title'    => 'title',
            'tags'     => 'tags',
            'hashtags' => 'hashtags',
            'mentions' => 'mentions',
        ]);
        if (PostPayloadBuilder::hasMedia($arguments)) {
            $payload['mediaItems'] = array_values($arguments['media_items']);
        }
        return array_merge($payload, $scheduling)
            + PostPayloadBuilder::nestedPayload($arguments, [
                'recycling'         => 'recycling',
                'tiktok_settings'   => 'tiktokSettings',
                'facebook_settings' => 'facebookSettings',
            ]);
    }

    /** @param array<string, mixed> $arguments */
    private function listPosts(array $arguments, ZernioConfig $config): ToolResult
    {
        $query = $this->stringMap($arguments, [
            'status'          => 'status',
            'profile_id'      => 'profileId',
            'account_id'      => 'accountId',
            'platform_filter' => 'platform',
            'date_from'       => 'dateFrom',
            'date_to'         => 'dateTo',
            'search'          => 'search',
            'sort_by'         => 'sortBy',
            'source'          => 'source',
        ]);
        if ((bool) ($arguments['include_hidden'] ?? false)) {
            $query['includeHidden'] = true;
        }
        if (isset($arguments['page'])) {
            $query['page'] = max(1, (int) $arguments['page']);
        }
        if (isset($arguments['limit'])) {
            $query['limit'] = max(1, min(100, (int) $arguments['limit']));
        }
        $items = $this->listKey($this->client->get(self::POSTS_PATH, $query, $config), 'posts');
        $count = count($items);
        return new ToolResult(
            true,
            "Posts ({$count}):\n" . $this->encode($items),
            ['count' => $count],
        );
    }

    /** @param array<string, mixed> $arguments */
    private function deletePost(array $arguments, ZernioConfig $config): ToolResult
    {
        return $this->deleteById(
            $arguments,
            $config,
            self::POST_PATH,
            'post_id',
            'delete_post',
            'Deleted post.',
        );
    }

    /** @param array<string, mixed> $arguments */
    private function updatePost(array $arguments, ZernioConfig $config): ToolResult
    {
        $postId = $this->requireParam($arguments, 'post_id', 'update_post requires a post_id.');
        if ($postId instanceof ToolResult) {
            return $postId;
        }
        $payload = $this->resolveUpdatePayload($arguments);
        if ($payload instanceof ToolResult) {
            return $payload;
        }
        return $this->jsonResult(
            "Updated post:\n",
            $this->client->put(self::POST_PATH . rawurlencode($postId), $payload, $config),
        );
    }

    /**
     * @param  array<string, mixed> $arguments
     * @return array<string, mixed>|ToolResult
     */
    private function resolveUpdatePayload(array $arguments): array|ToolResult
    {
        $payload = $this->buildUpdatePayload($arguments);
        if ($payload instanceof ToolResult || $payload === []) {
            return $this->emptyUpdateError($payload);
        }
        return $payload;
    }

    /**
     * @param array<string, mixed>|ToolResult $payload
     */
    private function emptyUpdateError(array|ToolResult $payload): ToolResult
    {
        if ($payload instanceof ToolResult) {
            return $payload;
        }
        return new ToolResult(false, 'update_post requires at least one of content, title, tags, hashtags, mentions, scheduled_for, timezone, is_draft, media_items, recycling.');
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>|ToolResult
     */
    private function buildUpdatePayload(array $arguments): array|ToolResult
    {
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
        $payload += PostPayloadBuilder::stringListPayload($arguments, ['tags' => 'tags', 'hashtags' => 'hashtags', 'mentions' => 'mentions']);
        $scheduling = PostPayloadBuilder::schedulingPayload($arguments);
        if ($scheduling instanceof ToolResult) {
            return $scheduling;
        }
        $payload = array_merge($payload, $scheduling);
        if (array_key_exists('is_draft', $arguments)) {
            $payload['isDraft'] = (bool) $arguments['is_draft'];
        }
        if (PostPayloadBuilder::hasMedia($arguments)) {
            $payload['mediaItems'] = array_values($arguments['media_items']);
        }
        return $payload + PostPayloadBuilder::nestedPayload($arguments, ['recycling' => 'recycling']);
    }

    /** @param array<string, mixed> $arguments */
    private function unpublishPost(array $arguments, ZernioConfig $config): ToolResult
    {
        $platform = $this->requireParam($arguments, 'platform_for_unpublish', 'unpublish_post requires a platform_for_unpublish.');
        if ($platform instanceof ToolResult) {
            return $platform;
        }
        $postCheck = $this->requireParam($arguments, 'post_id', 'unpublish_post requires a post_id.');
        if ($postCheck instanceof ToolResult) {
            return $postCheck;
        }
        return $this->postSubresource(
            $arguments,
            $config,
            'post_id',
            self::POST_PATH,
            self::UNPUBLISH_SUFFIX,
            'unpublish_post',
            body: ['platform' => $platform],
            successLabel: "Unpublished post from {$platform}:\n",
        );
    }

    /** @param array<string, mixed> $arguments */
    private function editPost(array $arguments, ZernioConfig $config): ToolResult
    {
        $content = $this->requireParam($arguments, 'edit_content', 'edit_post requires edit_content.');
        if ($content instanceof ToolResult) {
            return $content;
        }
        return $this->postSubresource(
            $arguments,
            $config,
            'post_id',
            self::POST_PATH,
            self::EDIT_SUFFIX,
            'edit_post',
            body: ['platform' => 'twitter', 'content' => $content],
            successLabel: "Edited post:\n",
        );
    }

    /** @param array<string, mixed> $arguments */
    private function updatePostMetadata(array $arguments, ZernioConfig $config): ToolResult
    {
        $postId    = $this->arg($arguments, 'post_id');
        $videoId   = $this->arg($arguments, 'video_id');
        $accountId = $this->arg($arguments, 'account_id');
        if ($postId === '' && ($videoId === '' || $accountId === '')) {
            return new ToolResult(false, 'update_post_metadata requires either a post_id or both video_id and account_id.');
        }
        $payload = $this->metadataPayload($arguments, $postId, $videoId, $accountId);
        $path    = $postId !== ''
            ? self::POST_PATH . rawurlencode($postId) . self::UPDATE_META_SUFFIX
            : self::POSTS_PATH . self::UPDATE_META_SUFFIX;
        return $this->jsonResult("Updated YouTube metadata:\n", $this->client->post($path, $payload, $config));
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    private function metadataPayload(array $arguments, string $postId, string $videoId, string $accountId): array
    {
        $payload = ['platform' => 'youtube'];
        foreach (['postId' => $postId, 'videoId' => $videoId, 'accountId' => $accountId] as $key => $value) {
            if ($value !== '') {
                $payload[$key] = $value;
            }
        }
        $payload += $this->stringMap($arguments, [
            'yt_title'          => 'title',
            'yt_description'    => 'description',
            'yt_category_id'    => 'categoryId',
            'yt_privacy_status' => 'privacyStatus',
            'yt_thumbnail_url'  => 'thumbnailUrl',
            'yt_playlist_id'    => 'playlistId',
        ]);
        if (isset($arguments['yt_tags']) && is_array($arguments['yt_tags'])) {
            $payload['tags'] = array_values($arguments['yt_tags']);
        }
        if (array_key_exists('yt_made_for_kids', $arguments)) {
            $payload['madeForKids'] = (bool) $arguments['yt_made_for_kids'];
        }
        if (array_key_exists('yt_contains_synthetic_media', $arguments)) {
            $payload['containsSyntheticMedia'] = (bool) $arguments['yt_contains_synthetic_media'];
        }
        return $payload;
    }

    /** @param array<string, mixed> $arguments */
    private function syncExternalPosts(array $arguments, ZernioConfig $config): ToolResult
    {
        $accountId = $this->requireParam($arguments, 'account_id', 'sync_external_posts requires an account_id.');
        if ($accountId instanceof ToolResult) {
            return $accountId;
        }
        $payload = ['accountId' => $accountId] + $this->stringMap($arguments, [
            'external_url'     => 'url',
            'external_post_id' => 'postId',
        ]);
        return $this->jsonResult("Synced external posts:\n", $this->client->post(self::SYNC_EXTERNAL_PATH, $payload, $config));
    }

    /** @param array<string, mixed> $arguments */
    private function bulkUpload(array $arguments, ZernioConfig $config): ToolResult
    {
        $csv = (string) ($arguments['csv_content'] ?? '');
        if (trim($csv) === '') {
            return new ToolResult(false, 'bulk_upload requires csv_content.');
        }
        $dryRun   = (bool) ($arguments['dry_run'] ?? false);
        $response = $this->client->postRaw(
            self::BULK_UPLOAD_PATH . ($dryRun ? '?dryRun=true' : ''),
            $csv,
            'text/csv',
            $config,
        );
        return $this->jsonResult("Bulk upload:\n", $response);
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
}
