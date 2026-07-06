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
 * Read-only analytics for Zernio posts and accounts: post performance metrics
 * and follower counts over time.
 *
 * NOTE: the exact Zernio analytics endpoint paths and response fields should be
 * confirmed against https://docs.zernio.com/ and the SDK's AnalyticsApi docs;
 * the paths below reflect the documented `/analytics/*` surface.
 */
#[Tool(
    name: 'zernio_analytics',
    description: 'Read Zernio analytics: post performance metrics and follower statistics for connected accounts.',
    displayName: 'Zernio Analytics',
    category: 'social-media',
)]
#[ToolOperation(name: 'post_analytics', description: 'Get performance metrics for posts', enabledByDefault: true, requiresApprovalByDefault: false)]
#[ToolOperation(name: 'follower_analytics', description: 'Get follower statistics for an account', enabledByDefault: true, requiresApprovalByDefault: false)]
#[ToolSetting(key: 'api_key', label: 'Zernio API Key', type: 'password', description: 'Bearer token for the Zernio API. Falls back to the ZERNIO_API_KEY environment variable.', required: false)]
#[ToolSetting(key: 'base_url', label: 'Base URL', type: 'text', description: 'Zernio API base URL (default: https://zernio.com/api/v1).', default: 'https://zernio.com/api/v1')]
#[ToolSetting(key: 'http_timeout', label: 'HTTP Timeout', type: 'text', description: 'Seconds before an HTTP request fails (default: 30).')]
#[ToolParameter(name: 'post_id', type: 'string', description: 'Post ID to fetch metrics for. Used by post_analytics.', required: false)]
#[ToolParameter(name: 'account_id', type: 'string', description: 'Account ID to fetch follower stats for. Used by follower_analytics.', required: false)]
#[ToolParameter(name: 'start_date', type: 'string', description: 'Optional ISO-8601 start date for the reporting range.', required: false)]
#[ToolParameter(name: 'end_date', type: 'string', description: 'Optional ISO-8601 end date for the reporting range.', required: false)]
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
            default              => $this->postAnalytics($arguments, $config),
        });
    }

    public function describeAction(array $arguments): string
    {
        return match ($this->getOperationName($arguments)) {
            'follower_analytics' => 'Get Zernio follower analytics',
            default              => 'Get Zernio post analytics',
        };
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function postAnalytics(array $arguments, ZernioConfig $config): ToolResult
    {
        $query = $this->dateRange($arguments);
        $postId = trim((string) ($arguments['post_id'] ?? ''));
        if ($postId !== '') {
            $query['postId'] = $postId;
        }

        $response = $this->client->get('/analytics/posts', $query, $config);

        return new ToolResult(true, "Post analytics:\n" . json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function followerAnalytics(array $arguments, ZernioConfig $config): ToolResult
    {
        $accountId = trim((string) ($arguments['account_id'] ?? ''));
        if ($accountId === '') {
            return new ToolResult(false, 'follower_analytics requires an account_id.');
        }

        $query = $this->dateRange($arguments);
        $query['accountId'] = $accountId;

        $response = $this->client->get('/analytics/followers', $query, $config);

        return new ToolResult(true, "Follower analytics:\n" . json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    /**
     * @param  array<string, mixed> $arguments
     * @return array<string, string>
     */
    private function dateRange(array $arguments): array
    {
        $query = [];
        foreach (['start_date' => 'startDate', 'end_date' => 'endDate'] as $arg => $param) {
            $value = trim((string) ($arguments[$arg] ?? ''));
            if ($value !== '') {
                $query[$param] = $value;
            }
        }
        return $query;
    }
}
