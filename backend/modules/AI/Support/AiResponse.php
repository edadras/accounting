<?php

declare(strict_types=1);

namespace Modules\AI\Support;

/**
 * One turn back from a provider: either prose, or a request to run tools.
 */
final readonly class AiResponse
{
    /**
     * @param  list<ToolCall>  $toolCalls
     * @param  array<string, mixed>  $usage
     */
    public function __construct(
        public string $content = '',
        public array $toolCalls = [],
        public string $model = '',
        public array $usage = [],
    ) {}

    public function wantsTools(): bool
    {
        return $this->toolCalls !== [];
    }

    /**
     * The content read as JSON.
     *
     * Models fence JSON in markdown often enough that unwrapping it here is
     * cheaper than making every caller defend against it.
     *
     * @return array<string, mixed>
     */
    public function json(): array
    {
        $body = trim($this->content);

        if (str_starts_with($body, '```')) {
            $body = (string) preg_replace('/^```[a-z]*\s*|\s*```$/i', '', $body);
        }

        $decoded = json_decode($body, true);

        return is_array($decoded) ? $decoded : [];
    }
}
