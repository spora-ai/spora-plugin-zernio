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
 * Read-only analytics for Zernio. The endpoints here match the Zernio
 * OpenAPI spec v1.0.4:
 *
 *   - post_analytics         → GET /v1/analytics
 *   - follower_analytics     → GET /v1/accounts/follower-stats
 *   - best_time_to_post      → GET /v1/analytics/best-time
 *   - content_decay          → GET /v1/analytics/content-decay
 *   - daily_metrics          → GET /v1/analytics/daily-metrics
 *   - posting_frequency      → GET /v1/analytics/posting-frequency
 *   - account_health         → GET /v1/accounts/health (bulk)
 */
#[Tool(
    name: 'zernio_analytics',
    description: 'Read Zernio analytics: per-post metrics, follower stats, best time to post, content decay, daily rollups, posting frequency, and account health. All operations are read-only.',
    displayName: 'Zernio Analytics',
    category: 'social-media',
)]
#[ToolOperation(name: 'post_analytics', description: 'Per-post or per-account performance metrics', enabledByDefault: true, requiresApprovalByDefault: false)]
#[ToolOperation(name: 'follower_analytics', description: 'Follower count history for one or more accounts', enabledByDefault: true, requiresApprovalByDefault: false)]
#[ToolOperation(name: 'best_time_to_post', description: 'Best times of day to post for a given account/platform', enabledByDefault: true, requiresApprovalByDefault: false)]
#[ToolOperation(name: 'content_decay', description: 'Performance decay curve for a post or account', enabledByDefault: true, requiresApprovalByDefault: false)]
#[ToolOperation(name: 'daily_metrics', description: 'Cross-platform daily metrics rollup for a profile', enabledByDefault: true, requiresApprovalByDefault: false)]
#[ToolOperation(name: 'posting_frequency', description: 'Posting frequency vs engagement for a profile', enabledByDefault: true, requiresApprovalByDefault: false)]
#[ToolOperation(name: 'account_health', description: 'Bulk account health snapshot (token status, permissions)', enabledByDefault: true, requiresApprovalByDefault: false)]
#[ToolSetting(key: 'api_key', label: 'Zernio API Key', type: 'password', description: 'Bearer token for the Zernio API. Falls back to the ZERNIO_API_KEY environment variable.', required: false)]
#[ToolSetting(key: 'base_url', label: 'Base URL', type: 'text', description: 'Zernio API base URL (default: https://zernio.com/api/v1).', default: 'https://zernio.com/api/v1')]
#[ToolSetting(key: 'http_timeout', label: 'HTTP Timeout', type: 'text', description: 'Seconds before an HTTP request fails (default: 30).')]
#[ToolParameter(name: 'post_id', type: 'string', description: 'Post ID for post_analytics (single post lookup).', required: false)]
#[ToolParameter(name: 'account_id', type: 'string', description: 'Account ID for post_analytics (batch lookup), follower_analytics, best_time_to_post, or content_decay.', required: false)]
#[ToolParameter(name: 'account_ids', type: 'string', description: 'Comma-separated list of account IDs for follower_analytics (e.g. "id1,id2"). Defaults to all user accounts when omitted.', required: false)]
#[ToolParameter(name: 'platform', type: 'string', description: 'Platform name (e.g. "twitter", "instagram") — required for post_analytics/best_time_to_post, optional filter for account_health.', required: false)]
#[ToolParameter(name: 'profile_id', type: 'string', description: 'Profile ID filter for account_health, daily_metrics, posting_frequency, follower_analytics.', required: false)]
#[ToolParameter(name: 'status', type: 'string', description: 'Account-health status filter: "healthy" | "warning" | "error".', required: false)]
#[ToolParameter(name: 'from_date', type: 'string', description: 'ISO-8601 date (YYYY-MM-DD) inclusive lower bound. Defaults to 90 days ago for post_analytics, 30 days ago for follower_analytics.', required: false)]
#[ToolParameter(name: 'to_date', type: 'string', description: 'ISO-8601 date (YYYY-MM-DD) inclusive upper bound. Defaults to today.', required: false)]
#[ToolParameter(name: 'granularity', type: 'string', description: 'Aggregation level for follower_analytics: "daily" (default) | "weekly" | "monthly".', required: false)]
#[ToolParameter(name: 'sort_by', type: 'string', description: 'Sort by for post_analytics: "date" (default) | "engagement" | "impressions" | "reach" | "likes" | "comments" | "shares" | "saves" | "clicks" | "views".', required: false)]
#[ToolParameter(name: 'order', type: 'string', description: 'Sort order: "desc" (default) | "asc".', required: false)]
#[ToolParameter(name: 'source', type: 'string', description: 'Post source filter for post_analytics: "all" (default) | "late" (Zernio-authored) | "external" (synced from platform).', required: false)]
#[ToolParameter(name: 'page', type: 'integer', description: 'Page number for post_analytics (default 1).', required: false, default: 1)]
#[ToolParameter(name: 'limit', type: 'integer', description: 'Page size for post_analytics (1-100, default 50).', required: false, default: 50)]
final class ZernioAnalyticsTool extends AbstractZernioTool
{
    public function execute(array $arguments, int $agentId, ?int $userId = null, ?int $taskId = null): ToolResult
    {
        $config = $this->resolveConfig($agentId, $userId);
        if ($config === null) {
            return $this->missingCredentialResult();
        }

        return $this->guard(fn(): ToolResult => match ($this->getOperationName($arguments)) {
            'follower_analytics' => $this->followerAnalytics($arguments, $config),
            'best_time_to_post'  => $this->accountRead('Best time to post', '/analytics/best-time', $arguments, $config, requirePlatform: true),
            'content_decay'      => $this->contentDecay($arguments, $config),
            'daily_metrics'      => $this->profileRead('Daily metrics', '/analytics/daily-metrics', $arguments, $config),
            'posting_frequency'  => $this->profileRead('Posting frequency', '/analytics/posting-frequency', $arguments, $config),
            'account_health'     => $this->accountHealth($arguments, $config),
            default              => $this->postAnalytics($arguments, $config),
        });
    }

    public function describeAction(array $arguments): string
    {
        return match ($this->getOperationName($arguments)) {
            'follower_analytics' => 'Get Zernio follower analytics',
            'best_time_to_post'  => 'Get best times to post for a Zernio account',
            'content_decay'      => 'Get content decay for a Zernio post or account',
            'daily_metrics'      => 'Get cross-platform daily metrics for a Zernio profile',
            'posting_frequency'  => 'Get posting frequency vs engagement for a Zernio profile',
            'account_health'     => 'Get Zernio account health snapshot',
            default              => 'Get Zernio post analytics',
        };
    }

    /** @param array<string, mixed> $arguments */
    private function postAnalytics(array $arguments, ZernioConfig $config): ToolResult
    {
        $postId = $this->arg($arguments, 'post_id');
        $query  = $postId !== ''
            ? ['postId' => $postId]
            : $this->requireAccountAnalyticsQuery($arguments);
        if ($query instanceof ToolResult) {
            return $query;
        }
        $query['sortBy'] = $this->arg($arguments, 'sort_by') ?: 'date';
        $query['order']  = $this->arg($arguments, 'order') ?: 'desc';
        $query          += $this->stringMap($arguments, ['from_date' => 'fromDate', 'to_date' => 'toDate']);
        $query['page']   = isset($arguments['page']) ? max(1, (int) $arguments['page']) : 1;
        $query['limit']  = isset($arguments['limit']) ? max(1, min(100, (int) $arguments['limit'])) : 50;
        return $this->jsonResult("Post analytics:\n", $this->client->get('/analytics', $query, $config));
    }

    /** @param array<string, mixed> $arguments */
    private function followerAnalytics(array $arguments, ZernioConfig $config): ToolResult
    {
        $query = $this->stringMap($arguments, [
            'account_ids' => 'accountIds',
            'profile_id'  => 'profileId',
            'granularity' => 'granularity',
            'from_date'   => 'fromDate',
            'to_date'     => 'toDate',
        ]);
        return $this->jsonResult("Follower analytics:\n", $this->client->get('/accounts/follower-stats', $query, $config));
    }

    /** @param array<string, mixed> $arguments */
    private function contentDecay(array $arguments, ZernioConfig $config): ToolResult
    {
        $accountId = $this->arg($arguments, 'account_id');
        $postId    = $this->arg($arguments, 'post_id');
        if ($accountId === '' && $postId === '') {
            return new ToolResult(false, 'content_decay requires either account_id (with platform) or post_id.');
        }
        $query = $this->stringMap($arguments, ['account_id' => 'accountId', 'post_id' => 'postId', 'platform' => 'platform']);
        return $this->jsonResult("Content decay:\n", $this->client->get('/analytics/content-decay', $query, $config));
    }

    /** @param array<string, mixed> $arguments */
    private function accountHealth(array $arguments, ZernioConfig $config): ToolResult
    {
        $query = $this->stringMap($arguments, [
            'profile_id' => 'profileId',
            'platform'   => 'platform',
            'status'     => 'status',
        ]);
        return $this->jsonResult("Account health:\n", $this->client->get('/accounts/health', $query, $config));
    }

    /**
     * Account-scoped read: requires account_id, optionally platform.
     *
     * @param array<string, mixed> $arguments
     */
    private function accountRead(string $label, string $path, array $arguments, ZernioConfig $config, bool $requirePlatform): ToolResult
    {
        $accountId = $this->requireParam($arguments, 'account_id', "{$this->getOperationName($arguments)} requires an account_id.");
        if ($accountId instanceof ToolResult) {
            return $accountId;
        }
        $platform = $this->arg($arguments, 'platform');
        if ($platform === '' && $requirePlatform) {
            return new ToolResult(false, "{$this->getOperationName($arguments)} requires a platform.");
        }
        $query = ['accountId' => $accountId];
        if ($platform !== '') {
            $query['platform'] = $platform;
        }
        return $this->jsonResult("{$label}:\n", $this->client->get($path, $query, $config));
    }

    /**
     * Profile-scoped read: requires profile_id, plus optional date range.
     *
     * @param array<string, mixed> $arguments
     */
    private function profileRead(string $label, string $path, array $arguments, ZernioConfig $config): ToolResult
    {
        $profileId = $this->requireParam($arguments, 'profile_id', "{$this->getOperationName($arguments)} requires a profile_id.");
        if ($profileId instanceof ToolResult) {
            return $profileId;
        }
        $query = ['profileId' => $profileId] + $this->stringMap($arguments, ['from_date' => 'fromDate', 'to_date' => 'toDate']);
        return $this->jsonResult("{$label}:\n", $this->client->get($path, $query, $config));
    }

    /**
     * @param  array<string, mixed> $arguments
     * @return array<string, string>|ToolResult
     */
    private function requireAccountAnalyticsQuery(array $arguments): array|ToolResult
    {
        $accountId = $this->requireParam($arguments, 'account_id', 'post_analytics requires an account_id (or a post_id for a single post).');
        if ($accountId instanceof ToolResult) {
            return $accountId;
        }
        $platform = $this->requireParam($arguments, 'platform', 'post_analytics requires a platform when fetching by account_id.');
        if ($platform instanceof ToolResult) {
            return $platform;
        }
        return ['accountId' => $accountId, 'platform' => $platform];
    }
}
