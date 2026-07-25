<?php

declare(strict_types=1);

namespace Modules\AI\Tools;

use Modules\AI\Contracts\Tool;
use Modules\AI\Exceptions\AiException;
use Modules\Core\Support\WorkspaceContext;

/**
 * The only way a model reaches the database.
 *
 * The registry is where the security rule is actually enforced rather than
 * merely intended: before any tool runs, every argument whose name mentions a
 * workspace or a tenant is thrown away. The active workspace comes from
 * WorkspaceContext, which the middleware set after verifying membership, and
 * nothing a model emits can reach that value.
 *
 * Rejecting rather than ignoring would be worse: a model that names a
 * workspace is confused, not hostile, and killing the turn teaches it nothing.
 * Dropping the argument means the tool answers about the right books either
 * way, and the drop is recorded on the result so an audit can see it happened.
 */
final class ToolRegistry
{
    /**
     * Argument names that would, if honoured, choose the books being read.
     * There is no legitimate reason for a model to send one.
     */
    private const FORBIDDEN = ['workspace', 'workspace_id', 'workspaceid', 'tenant', 'tenant_id', 'user_id', 'owner_id'];

    /** @var array<string, Tool> */
    private array $tools = [];

    /** @param iterable<Tool> $tools */
    public function __construct(private readonly WorkspaceContext $context, iterable $tools = [])
    {
        foreach ($tools as $tool) {
            $this->tools[$tool->name()] = $tool;
        }
    }

    /** @return array<string, Tool> */
    public function all(): array
    {
        return $this->tools;
    }

    public function has(string $name): bool
    {
        return isset($this->tools[$name]);
    }

    /**
     * Declarations to hand a model, in the shape tool-calling APIs expect.
     *
     * @return list<array{name: string, description: string, parameters: array<string, mixed>}>
     */
    public function schemas(): array
    {
        return array_values(array_map(static fn (Tool $tool): array => [
            'name' => $tool->name(),
            'description' => $tool->description(),
            'parameters' => $tool->schema(),
        ], $this->tools));
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array{tool: string, arguments: array<string, mixed>, dropped_arguments: list<string>, result: array<string, mixed>}
     */
    public function execute(string $name, array $arguments = []): array
    {
        $tool = $this->tools[$name] ?? throw AiException::unknownTool($name);

        // Fail closed: with no active workspace the scope would already return
        // nothing, but refusing here makes the reason obvious in a stack trace
        // instead of surfacing as a mysteriously empty answer.
        $this->context->require();

        [$safe, $dropped] = $this->sanitize($arguments);

        return [
            'tool' => $name,
            'arguments' => $safe,
            'dropped_arguments' => $dropped,
            'result' => $tool->run($safe),
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array{0: array<string, mixed>, 1: list<string>}
     */
    private function sanitize(array $arguments): array
    {
        $safe = [];
        $dropped = [];

        foreach ($arguments as $key => $value) {
            $normalized = strtolower((string) $key);

            if (in_array($normalized, self::FORBIDDEN, true) || str_contains($normalized, 'workspace')) {
                $dropped[] = (string) $key;

                continue;
            }

            $safe[(string) $key] = $value;
        }

        return [$safe, $dropped];
    }
}
