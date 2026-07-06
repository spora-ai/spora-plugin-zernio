<?php

declare(strict_types=1);

namespace Spora\Plugins\Zernio\Tools;

use Spora\Tools\AbstractTool;
use Spora\Tools\Attributes\Tool;
use Spora\Tools\Attributes\ToolParameter;
use Spora\Tools\ValueObjects\ToolResult;

/**
 * Placeholder tool for the baseline scaffold. Replaced by the real Zernio
 * social-media tools in the implementation PR.
 */
#[Tool(
    name: 'echo',
    description: 'Returns the supplied message unchanged. Placeholder tool that is replaced by the Zernio social-media tools.',
)]
#[ToolParameter(
    name: 'message',
    type: 'string',
    description: 'The text the tool will return verbatim.',
    required: true,
)]
final class EchoTool extends AbstractTool
{
    /**
     * @param array<string, mixed> $arguments
     */
    public function execute(
        array $arguments,
        int $agentId,
        ?int $userId = null,
        ?int $taskId = null,
    ): ToolResult {
        $message = (string) ($arguments['message'] ?? '');

        return ToolResult::ok(
            content: "Echoed: {$message}",
            data: ['echoed' => $message],
        );
    }

    /**
     * @param array<string, mixed> $arguments
     */
    public function describeAction(array $arguments): string
    {
        $preview = mb_substr(trim((string) ($arguments['message'] ?? '')), 0, 80);
        return "Echo: '{$preview}'";
    }
}
