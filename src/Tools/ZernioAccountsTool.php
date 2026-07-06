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
 * Read-only discovery of the social accounts and profiles connected to the
 * Zernio workspace. An agent uses this first to learn which `account_ids` and
 * platforms it can target before creating a post.
 */
#[Tool(
    name: 'zernio_accounts',
    description: 'Discover the social media accounts and profiles connected to Zernio. Use this to find the account IDs and platforms available before creating or scheduling a post.',
    displayName: 'Zernio Accounts',
    category: 'social-media',
)]
#[ToolOperation(name: 'list_accounts', description: 'List connected social media accounts', enabledByDefault: true, requiresApprovalByDefault: false)]
#[ToolOperation(name: 'list_profiles', description: 'List Zernio profiles (brand/project workspaces)', enabledByDefault: true, requiresApprovalByDefault: false)]
#[ToolSetting(key: 'api_key', label: 'Zernio API Key', type: 'password', description: 'Bearer token for the Zernio API. Falls back to the ZERNIO_API_KEY environment variable.', required: false)]
#[ToolSetting(key: 'base_url', label: 'Base URL', type: 'text', description: 'Zernio API base URL (default: https://zernio.com/api/v1).', default: 'https://zernio.com/api/v1')]
#[ToolSetting(key: 'http_timeout', label: 'HTTP Timeout', type: 'text', description: 'Seconds before an HTTP request fails (default: 30).')]
#[ToolParameter(name: 'profile_id', type: 'string', description: 'Optional profile ID to filter accounts by. Used by list_accounts.', required: false)]
final class ZernioAccountsTool extends AbstractZernioTool
{
    public function execute(array $arguments, int $agentId, ?int $userId = null, ?int $taskId = null): ToolResult
    {
        $config = $this->resolveConfig($agentId, $userId);
        if ($config === null) {
            return $this->missingCredentialResult();
        }

        return $this->guard(function () use ($arguments, $config): ToolResult {
            return match ($this->getOperationName($arguments)) {
                'list_profiles' => $this->formatList('Profiles', $this->client->get('/profiles', [], $config)),
                default         => $this->listAccounts($arguments, $config),
            };
        });
    }

    public function describeAction(array $arguments): string
    {
        return match ($this->getOperationName($arguments)) {
            'list_profiles' => 'List Zernio profiles',
            default         => 'List connected Zernio social accounts',
        };
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function listAccounts(array $arguments, ZernioConfig $config): ToolResult
    {
        $profileId = trim((string) ($arguments['profile_id'] ?? ''));
        $query = $profileId !== '' ? ['profileId' => $profileId] : [];

        return $this->formatList('Accounts', $this->client->get('/accounts', $query, $config));
    }

    /**
     * @param array<string, mixed> $response
     */
    private function formatList(string $label, array $response): ToolResult
    {
        $items = $response['data'] ?? $response;
        $count = is_array($items) ? count($items) : 0;

        return new ToolResult(
            true,
            "{$label} ({$count}):\n" . json_encode($items, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            ['count' => $count],
        );
    }
}
