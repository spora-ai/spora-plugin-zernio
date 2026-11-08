<?php

declare(strict_types=1);

namespace Spora\Plugins\Zernio\Support;

/**
 * The unpublish request for one post: the account whose copy goes down, the
 * body Zernio expects, and the account the approval prompt names.
 *
 * Kept out of {@see \Spora\Plugins\Zernio\Tools\ZernioPostTool} so that
 * class stays inside SonarQube's method-count rule. All methods are pure:
 * they take `$arguments` and never touch the HTTP client.
 */
final class UnpublishRequest
{
    /**
     * The account the copy goes down from.
     *
     * `account_id_for_unpublish` decides, because it is the one parameter
     * every agent is offered — `account_id` is bound to `sync_external_posts`
     * and an agent that may only unpublish never sees it. `account_id` is
     * still read, so a model that filled the general parameter instead of
     * the dedicated one still names the account.
     *
     * @param array<string, mixed> $arguments
     */
    public static function accountId(array $arguments): string
    {
        $accountId = self::trimmedArg($arguments, 'account_id_for_unpublish');

        return $accountId !== '' ? $accountId : self::trimmedArg($arguments, 'account_id');
    }

    /**
     * @param  array<string, mixed> $arguments
     * @return array<string, string>
     */
    public static function body(array $arguments, string $platform): array
    {
        $accountId = self::accountId($arguments);

        $body = ['platform' => $platform];
        if ($accountId !== '') {
            $body['accountId'] = $accountId;
        }

        return $body;
    }

    /**
     * The account the operator approves, so the prompt says which copy of
     * the post goes down. Absent when no account was named.
     *
     * @param array<string, mixed> $arguments
     */
    public static function accountSuffix(array $arguments): string
    {
        $accountId = self::accountId($arguments);

        return $accountId === '' ? '' : ' (account ' . $accountId . ')';
    }

    /** @param array<string, mixed> $arguments */
    private static function trimmedArg(array $arguments, string $key): string
    {
        return trim((string) ($arguments[$key] ?? ''));
    }
}
