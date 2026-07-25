<?php

declare(strict_types=1);

namespace Modules\AI\Providers\Gateway;

use App\Core\Money\Currency;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Modules\AI\Contracts\AiProvider;
use Modules\AI\Exceptions\AiException;
use Modules\AI\Support\AiResponse;
use Modules\AI\Support\DecimalValue;
use Modules\AI\Support\PromptBuilder;
use Modules\AI\Support\ToolCall;

/**
 * The OpenAI-compatible gateway, spoken over plain HTTP.
 *
 * No SDK: the three calls this product makes are a POST each, and a vendor
 * package would be a dependency to keep current in exchange for nothing. Any
 * server implementing the same routes — a proxy, a local model, a different
 * vendor — works by changing `ai.base_url`.
 *
 * The provider only transports. It does not decide what may be asked, which
 * workspace is in scope, or whether an answer may be shown; those all live in
 * the Actions, so swapping this class cannot weaken them.
 */
final class OpenAiProvider implements AiProvider
{
    public function name(): string
    {
        return 'openai';
    }

    public function complete(string $system, string $user, array $tools = []): AiResponse
    {
        $isChat = PromptBuilder::taskOf($system) === PromptBuilder::TASK_CHAT;

        $payload = [
            'model' => (string) config($isChat ? 'ai.models.chat' : 'ai.models.classify'),
            'messages' => [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user', 'content' => $user],
            ],
            'temperature' => 0,
        ];

        if ($tools !== []) {
            $payload['tools'] = array_map(
                static fn (array $tool) => ['type' => 'function', 'function' => $tool],
                $tools,
            );
            $payload['tool_choice'] = 'auto';
        }

        $body = $this->post('chat/completions', $payload);
        $message = $body['choices'][0]['message'] ?? [];

        return new AiResponse(
            content: (string) ($message['content'] ?? ''),
            toolCalls: $this->toolCalls(is_array($message['tool_calls'] ?? null) ? $message['tool_calls'] : []),
            model: (string) ($body['model'] ?? ''),
            usage: is_array($body['usage'] ?? null) ? $body['usage'] : [],
        );
    }

    public function transcribe(string $audioPath): string
    {
        if (! is_readable($audioPath)) {
            throw AiException::fileNotReadable($audioPath);
        }

        $response = $this->client()
            ->attach('file', (string) file_get_contents($audioPath), basename($audioPath))
            ->post($this->url('audio/transcriptions'), [
                'model' => (string) config('ai.models.transcribe'),
                'response_format' => 'json',
            ]);

        if ($response->failed()) {
            throw AiException::providerUnavailable($this->name(), 'transcription HTTP '.$response->status());
        }

        return trim((string) $response->json('text', ''));
    }

    public function extractReceipt(string $imagePath): array
    {
        if (! is_readable($imagePath)) {
            throw AiException::fileNotReadable($imagePath);
        }

        $body = $this->post('chat/completions', [
            'model' => (string) config('ai.models.vision'),
            'temperature' => 0,
            'response_format' => ['type' => 'json_object'],
            'messages' => [
                ['role' => 'system', 'content' => $this->receiptSystemPrompt()],
                ['role' => 'user', 'content' => [
                    ['type' => 'text', 'text' => 'Receipt image follows. Return JSON only.'],
                    ['type' => 'image_url', 'image_url' => ['url' => $this->dataUri($imagePath)]],
                ]],
            ],
        ]);

        $decoded = json_decode((string) ($body['choices'][0]['message']['content'] ?? ''), true);

        return $this->normalizeReceipt(is_array($decoded) ? $decoded : []);
    }

    private function receiptSystemPrompt(): string
    {
        return PromptBuilder::system('finora.extract_receipt', [
            'Return only JSON with keys: merchant, date, currency, tax, total, items[].',
            'Amounts are major-unit decimal strings exactly as printed; never convert between currencies.',
            'Every key also gets a sibling <key>_confidence between 0 and 1.',
            'Leave a field null when it is not legible. Do not infer a total you cannot see.',
        ]);
    }

    /**
     * Maps the model's JSON onto the contract's shape, converting the decimal
     * strings it returns into minor units.
     *
     * @param  array<string, mixed>  $decoded
     * @return array<string, mixed>
     */
    private function normalizeReceipt(array $decoded): array
    {
        $code = strtoupper((string) ($decoded['currency'] ?? ''));
        $currency = Currency::isSupported($code) ? Currency::of($code) : Currency::of('USD');

        $items = [];

        foreach ((array) ($decoded['items'] ?? []) as $item) {
            if (! is_array($item)) {
                continue;
            }

            $quantity = max(1, (int) ($item['quantity'] ?? 1));
            $unit = $this->minorUnits($item['unit_price'] ?? null, $currency);

            $items[] = [
                'name' => (string) ($item['name'] ?? ''),
                'quantity' => $quantity,
                'unit_price' => $unit ?? 0,
                'line_total' => $this->minorUnits($item['line_total'] ?? null, $currency) ?? (($unit ?? 0) * $quantity),
            ];
        }

        return [
            'merchant' => $this->field($decoded, 'merchant', fn (mixed $v) => $v === null ? null : (string) $v),
            'occurred_at' => $this->field($decoded, 'date', fn (mixed $v) => $v === null ? null : (string) $v),
            'currency' => ['value' => $currency->code, 'confidence' => (float) ($decoded['currency_confidence'] ?? 0.5)],
            'tax' => $this->field($decoded, 'tax', fn (mixed $v) => $this->minorUnits($v, $currency)),
            'total' => $this->field($decoded, 'total', fn (mixed $v) => $this->minorUnits($v, $currency)),
            'items' => ['value' => $items, 'confidence' => (float) ($decoded['items_confidence'] ?? 0.5)],
            'raw_text' => (string) ($decoded['raw_text'] ?? ''),
        ];
    }

    /**
     * @param  array<string, mixed>  $decoded
     * @return array{value: mixed, confidence: float}
     */
    private function field(array $decoded, string $key, callable $cast): array
    {
        return [
            'value' => $cast($decoded[$key] ?? null),
            'confidence' => (float) ($decoded[$key.'_confidence'] ?? ($decoded[$key] === null ? 0.0 : 0.5)),
        ];
    }

    private function minorUnits(mixed $value, Currency $currency): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return DecimalValue::parse((string) $value)->toMinorUnits($currency);
        } catch (\InvalidArgumentException) {
            return null;
        }
    }

    /**
     * @param  list<array<string, mixed>>  $raw
     * @return list<ToolCall>
     */
    private function toolCalls(array $raw): array
    {
        $calls = [];

        foreach ($raw as $call) {
            $function = is_array($call['function'] ?? null) ? $call['function'] : [];

            $calls[] = ToolCall::fromArray([
                'id' => $call['id'] ?? '',
                'name' => $function['name'] ?? '',
                'arguments' => $function['arguments'] ?? '{}',
            ]);
        }

        return $calls;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function post(string $path, array $payload): array
    {
        $response = $this->client()->asJson()->post($this->url($path), $payload);

        if ($response->failed()) {
            // docs/08-ai-layer.md §7: an outage is reported plainly and the
            // product keeps working without the AI layer.
            throw AiException::providerUnavailable($this->name(), 'HTTP '.$response->status());
        }

        $body = $response->json();

        return is_array($body) ? $body : [];
    }

    private function client(): PendingRequest
    {
        $key = (string) config('ai.key');

        if ($key === '') {
            throw AiException::providerUnavailable($this->name(), 'no API key configured');
        }

        return Http::withToken($key)
            ->timeout((int) config('ai.timeout', 30))
            ->retry((int) config('ai.retries', 2), 200, throw: false);
    }

    private function url(string $path): string
    {
        return rtrim((string) config('ai.base_url'), '/').'/'.ltrim($path, '/');
    }

    private function dataUri(string $path): string
    {
        $mime = match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'png' => 'image/png',
            'webp' => 'image/webp',
            'heic', 'heif' => 'image/heic',
            default => 'image/jpeg',
        };

        return 'data:'.$mime.';base64,'.base64_encode((string) file_get_contents($path));
    }
}
