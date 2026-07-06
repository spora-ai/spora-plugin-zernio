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
 * Discovery and management of the social accounts and profiles connected to
 * the Zernio workspace. An agent uses `list_accounts` / `list_profiles` first
 * to learn which `account_ids` and platforms it can target; the remaining
 * operations manage profiles (CRUD) and accounts (rename, move, disconnect,
 * health) so an agent can keep the workspace tidy without going to the
 * dashboard.
 */
#[Tool(
    name: 'zernio_accounts',
    description: 'Discover and manage Zernio profiles and social accounts. List profiles and accounts, create/update/delete profiles, rename/move/disconnect accounts, and inspect account health before posting.',
    displayName: 'Zernio Accounts',
    category: 'social-media',
)]
#[ToolOperation(name: 'list_accounts', description: 'List connected social media accounts', enabledByDefault: true, requiresApprovalByDefault: false)]
#[ToolOperation(name: 'list_profiles', description: 'List Zernio profiles (brand/project workspaces)', enabledByDefault: true, requiresApprovalByDefault: false)]
#[ToolOperation(name: 'create_profile', description: 'Create a new Zernio profile', enabledByDefault: true, requiresApprovalByDefault: true)]
#[ToolOperation(name: 'update_profile', description: 'Update a Zernio profile', enabledByDefault: true, requiresApprovalByDefault: true)]
#[ToolOperation(name: 'delete_profile', description: 'Delete a Zernio profile', enabledByDefault: true, requiresApprovalByDefault: true)]
#[ToolOperation(name: 'update_account', description: 'Update a connected social account (username, display name, X capabilities)', enabledByDefault: true, requiresApprovalByDefault: true)]
#[ToolOperation(name: 'move_account', description: 'Move a social account to a different profile', enabledByDefault: true, requiresApprovalByDefault: true)]
#[ToolOperation(name: 'disconnect_account', description: 'Disconnect a social account from Zernio', enabledByDefault: true, requiresApprovalByDefault: true)]
#[ToolOperation(name: 'account_health', description: 'Bulk health snapshot across accounts (token status, posting/analytics permissions)', enabledByDefault: true, requiresApprovalByDefault: false)]
#[ToolOperation(name: 'get_account_health', description: 'Per-account health (token, permissions, issues, recommendations)', enabledByDefault: true, requiresApprovalByDefault: false)]
#[ToolSetting(key: 'api_key', label: 'Zernio API Key', type: 'password', description: 'Bearer token for the Zernio API. Falls back to the ZERNIO_API_KEY environment variable.', required: false)]
#[ToolSetting(key: 'base_url', label: 'Base URL', type: 'text', description: 'Zernio API base URL (default: https://zernio.com/api/v1).', default: 'https://zernio.com/api/v1')]
#[ToolSetting(key: 'http_timeout', label: 'HTTP Timeout', type: 'text', description: 'Seconds before an HTTP request fails (default: 30).')]
#[ToolParameter(name: 'profile_id', type: 'string', description: 'Profile ID to filter accounts by (list_accounts), or the target profile for move_account. Profile ID is also used by create_profile/update_profile.', required: false)]
#[ToolParameter(name: 'platform', type: 'string', description: 'Filter accounts by platform (e.g. "twitter", "instagram", "linkedin").', required: false)]
#[ToolParameter(name: 'status', type: 'string', description: 'Filter accounts by status: "connected" or "disconnected".', required: false)]
#[ToolParameter(name: 'include_over_limit', type: 'boolean', description: 'When true, include accounts from over-limit profiles.', required: false, default: false)]
#[ToolParameter(name: 'account_id', type: 'string', description: 'Account ID for update_account/move_account/disconnect_account/get_account_health.', required: false)]
#[ToolParameter(name: 'name', type: 'string', description: 'Profile name (create_profile/update_profile).', required: false)]
#[ToolParameter(name: 'description', type: 'string', description: 'Profile description (create_profile/update_profile).', required: false)]
#[ToolParameter(name: 'color', type: 'string', description: 'Profile color in hex form, e.g. "#4CAF50" (create_profile/update_profile).', required: false)]
#[ToolParameter(name: 'is_default', type: 'boolean', description: 'Mark the profile as the workspace default (update_profile).', required: false)]
#[ToolParameter(name: 'username', type: 'string', description: 'Override the social account username (update_account).', required: false)]
#[ToolParameter(name: 'display_name', type: 'string', description: 'Override the social account display name (update_account).', required: false)]
#[ToolParameter(name: 'x_capabilities', type: 'object', description: 'X-specific opt-in flags { analytics, inbox } (update_account).', required: false)]
final class ZernioAccountsTool extends AbstractZernioTool
{
    private const ACCOUNTS_PATH     = '/accounts';
    private const PROFILES_PATH     = '/profiles';
    private const PROFILE_PATH      = '/profiles/';
    private const ACCOUNT_PATH      = '/accounts/';
    private const HEALTH_PATH       = '/accounts/health';

    public function execute(array $arguments, int $agentId, ?int $userId = null, ?int $taskId = null): ToolResult
    {
        $config = $this->resolveConfig($agentId, $userId);
        if ($config === null) {
            return $this->missingCredentialResult();
        }

        return $this->guard(function () use ($arguments, $config): ToolResult {
            return match ($this->getOperationName($arguments)) {
                'list_profiles'      => $this->formatList('Profiles', $this->listProfiles($arguments, $config), 'profiles'),
                'create_profile'     => $this->createProfile($arguments, $config),
                'update_profile'     => $this->updateProfile($arguments, $config),
                'delete_profile'     => $this->deleteProfile($arguments, $config),
                'update_account'     => $this->updateAccount($arguments, $config),
                'move_account'       => $this->moveAccount($arguments, $config),
                'disconnect_account' => $this->disconnectAccount($arguments, $config),
                'account_health'     => $this->accountHealth($arguments, $config),
                'get_account_health' => $this->getAccountHealth($arguments, $config),
                default              => $this->listAccounts($arguments, $config),
            };
        });
    }

    public function describeAction(array $arguments): string
    {
        return match ($this->getOperationName($arguments)) {
            'list_profiles'      => 'List Zernio profiles',
            'create_profile'     => 'Create a Zernio profile',
            'update_profile'     => 'Update Zernio profile ' . $this->arg($arguments, 'profile_id'),
            'delete_profile'     => 'Delete Zernio profile ' . $this->arg($arguments, 'profile_id'),
            'update_account'     => 'Update Zernio account ' . $this->arg($arguments, 'account_id'),
            'move_account'       => 'Move Zernio account ' . $this->arg($arguments, 'account_id'),
            'disconnect_account' => 'Disconnect Zernio account ' . $this->arg($arguments, 'account_id'),
            'account_health'     => 'Get Zernio account health snapshot',
            'get_account_health' => 'Get health for Zernio account ' . $this->arg($arguments, 'account_id'),
            default              => 'List connected Zernio social accounts',
        };
    }

    /** @param array<string, mixed> $arguments */
    private function listAccounts(array $arguments, ZernioConfig $config): ToolResult
    {
        $response = $this->client->get(self::ACCOUNTS_PATH, $this->accountQuery($arguments), $config);
        return $this->formatList('Accounts', $response, 'accounts');
    }

    /** @param array<string, mixed> $arguments */
    private function accountHealth(array $arguments, ZernioConfig $config): ToolResult
    {
        $query = $this->stringMap($arguments, ['profile_id' => 'profileId', 'platform' => 'platform', 'status' => 'status']);
        return $this->jsonResult("Account health:\n", $this->client->get(self::HEALTH_PATH, $query, $config));
    }

    /** @param array<string, mixed> $arguments */
    private function getAccountHealth(array $arguments, ZernioConfig $config): ToolResult
    {
        $accountId = $this->requireParam($arguments, 'account_id', 'get_account_health requires an account_id.');
        if ($accountId instanceof ToolResult) {
            return $accountId;
        }
        $response = $this->client->get(self::ACCOUNT_PATH . rawurlencode($accountId) . '/health', [], $config);
        return $this->jsonResult("Account health:\n", $response);
    }

    /**
     * @param  array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    private function listProfiles(array $arguments, ZernioConfig $config): array
    {
        return $this->client->get(self::PROFILES_PATH, $this->overLimitQuery($arguments), $config);
    }

    /**
     * @param  array<string, mixed> $arguments
     * @return array<string, scalar|null>
     */
    private function accountQuery(array $arguments): array
    {
        return $this->stringMap($arguments, [
            'profile_id' => 'profileId',
            'platform'   => 'platform',
            'status'     => 'status',
        ]) + $this->overLimitQuery($arguments);
    }

    /**
     * @param  array<string, mixed> $arguments
     * @return array<string, bool>
     */
    private function overLimitQuery(array $arguments): array
    {
        return (bool) ($arguments['include_over_limit'] ?? false) ? ['includeOverLimit' => true] : [];
    }

    /** @param array<string, mixed> $arguments */
    private function createProfile(array $arguments, ZernioConfig $config): ToolResult
    {
        $payload = $this->profilePayload($arguments);
        if ($payload instanceof ToolResult) {
            return $payload;
        }
        $response = $this->client->post(self::PROFILES_PATH, $payload, $config);
        return $this->jsonResult("Created profile:\n", $response);
    }

    /** @param array<string, mixed> $arguments */
    private function updateProfile(array $arguments, ZernioConfig $config): ToolResult
    {
        $profileId = $this->requireParam($arguments, 'profile_id', 'update_profile requires a profile_id.');
        if ($profileId instanceof ToolResult) {
            return $profileId;
        }
        $payload = $this->stringMap($arguments, ['name' => 'name', 'description' => 'description', 'color' => 'color']);
        if (array_key_exists('is_default', $arguments)) {
            $payload['isDefault'] = (bool) $arguments['is_default'];
        }
        if ($payload === []) {
            return new ToolResult(false, 'update_profile requires at least one of name, description, color, is_default.');
        }
        $response = $this->client->put(self::PROFILE_PATH . rawurlencode($profileId), $payload, $config);
        return $this->jsonResult("Updated profile:\n", $response);
    }

    /** @param array<string, mixed> $arguments */
    private function deleteProfile(array $arguments, ZernioConfig $config): ToolResult
    {
        return $this->deleteById(
            $arguments,
            $config,
            self::PROFILE_PATH,
            'profile_id',
            'delete_profile',
            'Deleted profile.',
        );
    }

    /** @param array<string, mixed> $arguments */
    private function updateAccount(array $arguments, ZernioConfig $config): ToolResult
    {
        $accountId = $this->requireParam($arguments, 'account_id', 'update_account requires an account_id.');
        if ($accountId instanceof ToolResult) {
            return $accountId;
        }
        $payload = $this->stringMap($arguments, ['username' => 'username', 'display_name' => 'displayName']);
        if (isset($arguments['x_capabilities']) && is_array($arguments['x_capabilities'])) {
            $payload['xCapabilities'] = $arguments['x_capabilities'];
        }
        if ($payload === []) {
            return new ToolResult(false, 'update_account requires at least one of username, display_name, x_capabilities.');
        }
        $response = $this->client->put(self::ACCOUNT_PATH . rawurlencode($accountId), $payload, $config);
        return $this->jsonResult("Updated account:\n", $response);
    }

    /** @param array<string, mixed> $arguments */
    private function moveAccount(array $arguments, ZernioConfig $config): ToolResult
    {
        $accountId = $this->requireParam($arguments, 'account_id', 'move_account requires an account_id.');
        if ($accountId instanceof ToolResult) {
            return $accountId;
        }
        $profileId = $this->requireParam($arguments, 'profile_id', 'move_account requires a target profile_id.');
        if ($profileId instanceof ToolResult) {
            return $profileId;
        }
        $response = $this->client->patch(self::ACCOUNT_PATH . rawurlencode($accountId), ['profileId' => $profileId], $config);
        return $this->jsonResult("Moved account:\n", $response);
    }

    /** @param array<string, mixed> $arguments */
    private function disconnectAccount(array $arguments, ZernioConfig $config): ToolResult
    {
        return $this->deleteById(
            $arguments,
            $config,
            self::ACCOUNT_PATH,
            'account_id',
            'disconnect_account',
            'Disconnected account.',
        );
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>|ToolResult
     */
    private function profilePayload(array $arguments): array|ToolResult
    {
        $name = $this->arg($arguments, 'name');
        if ($name === '') {
            return new ToolResult(false, 'create_profile requires a name.');
        }
        $payload = ['name' => $name];
        foreach (['description', 'color'] as $field) {
            $value = $this->arg($arguments, $field);
            if ($value !== '') {
                $payload[$field] = $value;
            }
        }
        return $payload;
    }

    /**
     * @param  array<string, mixed> $response
     */
    private function formatList(string $label, array $response, string $primaryKey): ToolResult
    {
        $items = $this->listKey($response, $primaryKey);
        $count = count($items);
        return new ToolResult(
            true,
            "{$label} ({$count}):\n" . $this->encode($items),
            ['count' => $count],
        );
    }
}
