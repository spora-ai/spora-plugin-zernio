<?php

declare(strict_types=1);

namespace Spora\Plugins\Zernio\Tools;

use Spora\Plugins\Zernio\Support\ZernioConfig;
use Spora\Services\PrincipalContext;
use Spora\Tools\Attributes\Tool;
use Spora\Tools\Attributes\ToolOperation;
use Spora\Tools\Attributes\ToolParameter;
use Spora\Tools\Attributes\ToolSetting;
use Spora\Tools\ValueObjects\ToolResult;

/**
 * Pre-flight checks for posts and media. Use these before `create_post` to
 * catch issues (over-limit length, missing required media, unreachable URL,
 * non-existent subreddit) instead of waiting for the post to fail at
 * publish time.
 */
#[Tool(
    name: 'zernio_validate',
    description: 'Pre-flight checks before publishing: post content, post length per platform, media URL reachability, and subreddit existence. Use these to catch issues before create_post.',
    displayName: 'Zernio Validate',
    category: 'social-media',
)]
#[ToolOperation(name: 'validate_post', description: 'Validate a post (content + media items) against platform rules', enabledByDefault: true, requiresApprovalByDefault: false)]
#[ToolOperation(name: 'validate_post_length', description: 'Check whether the content fits a platform character limit', enabledByDefault: true, requiresApprovalByDefault: false)]
#[ToolOperation(name: 'validate_media', description: 'Check whether a media URL is reachable', enabledByDefault: true, requiresApprovalByDefault: false)]
#[ToolOperation(name: 'validate_subreddit', description: 'Check whether a subreddit exists on Reddit', enabledByDefault: true, requiresApprovalByDefault: false)]
#[ToolSetting(key: 'api_key', label: 'Zernio API Key', type: 'password', description: 'Bearer token for the Zernio API. Falls back to the ZERNIO_API_KEY environment variable.', required: false)]
#[ToolSetting(key: 'base_url', label: 'Base URL', type: 'text', description: 'Zernio API base URL (default: https://zernio.com/api/v1).', default: 'https://zernio.com/api/v1')]
#[ToolSetting(key: 'http_timeout', label: 'HTTP Timeout', type: 'text', description: 'Seconds before an HTTP request fails (default: 30).')]
#[ToolParameter(name: 'content', type: 'string', description: 'Post text to validate. Required for validate_post_length; optional for validate_post.', required: ['validate_post_length'])]
#[ToolParameter(name: 'media_items', type: 'array', description: 'Media items to validate: [{url, type}, …].', required: false, items: ['type' => 'object'])]
#[ToolParameter(name: 'platform', type: 'string', description: 'Target platform (e.g. "twitter", "instagram", "linkedin"). Required for validate_post_length; optional for validate_post.', required: ['validate_post_length'])]
#[ToolParameter(name: 'url', type: 'string', description: 'Media URL to reachability-check.', required: ['validate_media'])]
#[ToolParameter(name: 'subreddit', type: 'string', description: 'Subreddit name (without "r/") to verify exists.', required: ['validate_subreddit'])]
final class ZernioValidateTool extends AbstractZernioTool
{
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
                'validate_post_length' => $this->postLength($arguments, $config),
                'validate_media'       => $this->mediaUrl($arguments, $config),
                'validate_subreddit'   => $this->subreddit($arguments, $config),
                default                => $this->post($arguments, $config),
            },
        ));
    }

    public function describeAction(array $arguments): string
    {
        return match ($this->getOperationName($arguments)) {
            'validate_post_length' => 'Validate post length for a platform',
            'validate_media'       => 'Validate a media URL',
            'validate_subreddit'   => 'Validate a subreddit',
            default                => 'Validate a Zernio post',
        };
    }

    /** @param array<string, mixed> $arguments */
    private function post(array $arguments, ZernioConfig $config): ToolResult
    {
        $payload = [];
        $content = $this->arg($arguments, 'content');
        if ($content !== '') {
            $payload['content'] = $content;
        }
        if (isset($arguments['media_items']) && is_array($arguments['media_items']) && $arguments['media_items'] !== []) {
            $payload['mediaItems'] = array_values($arguments['media_items']);
        }
        $platform = $this->arg($arguments, 'platform');
        if ($platform !== '') {
            $payload['platform'] = $platform;
        }
        if ($payload === []) {
            return new ToolResult(false, 'validate_post requires at least one of content, media_items.');
        }
        return $this->jsonResult("Post validation:\n", $this->client->post('/tools/validate/post', $payload, $config));
    }

    /** @param array<string, mixed> $arguments */
    private function postLength(array $arguments, ZernioConfig $config): ToolResult
    {
        $content  = $this->requireParam($arguments, 'content', 'validate_post_length requires content.');
        if ($content instanceof ToolResult) {
            return $content;
        }
        $platform = $this->requireParam($arguments, 'platform', 'validate_post_length requires a platform.');
        if ($platform instanceof ToolResult) {
            return $platform;
        }
        return $this->jsonResult("Post length:\n", $this->client->post('/tools/validate/post-length', [
            'content'  => $content,
            'platform' => $platform,
        ], $config));
    }

    /** @param array<string, mixed> $arguments */
    private function mediaUrl(array $arguments, ZernioConfig $config): ToolResult
    {
        $url = $this->requireParam($arguments, 'url', 'validate_media requires a url.');
        if ($url instanceof ToolResult) {
            return $url;
        }
        return $this->jsonResult("Media validation:\n", $this->client->post('/tools/validate/media', ['url' => $url], $config));
    }

    /** @param array<string, mixed> $arguments */
    private function subreddit(array $arguments, ZernioConfig $config): ToolResult
    {
        $name = $this->requireParam($arguments, 'subreddit', 'validate_subreddit requires a subreddit.');
        if ($name instanceof ToolResult) {
            return $name;
        }
        return $this->jsonResult("Subreddit validation:\n", $this->client->post('/tools/validate/subreddit', ['subreddit' => $name], $config));
    }
}
