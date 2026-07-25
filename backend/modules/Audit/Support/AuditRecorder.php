<?php

declare(strict_types=1);

namespace Modules\Audit\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Modules\Audit\Models\AuditLog;
use Modules\Core\Support\WorkspaceContext;
use Throwable;

/**
 * Writes trail entries.
 *
 * Two properties matter more than completeness here:
 *
 * 1. **Recording never breaks the thing it observes.** A failure to write the
 *    trail must not roll back the transaction the user just recorded, so every
 *    write is wrapped and swallowed. A missing audit row is bad; a lost expense
 *    because auditing hiccuped is worse.
 * 2. **Secrets never enter the trail.** Money is the point of the log and is
 *    kept verbatim, but credentials and tokens are dropped before storage.
 */
final class AuditRecorder
{
    /** Never stored, whatever model they arrive from. */
    private const REDACTED = [
        'password', 'remember_token', 'two_factor_secret',
        'two_factor_recovery', 'token', 'plain_text_token', 'api_token',
        'secret', 'card_number',
    ];

    /** Bookkeeping columns whose change carries no information. */
    private const NOISE = ['updated_at', 'created_at', 'version'];

    private bool $enabled = true;

    public function __construct(private readonly WorkspaceContext $context) {}

    /** Suspends recording for the duration of $callback — used by seeders. */
    public function withoutRecording(callable $callback): mixed
    {
        $previous = $this->enabled;
        $this->enabled = false;

        try {
            return $callback();
        } finally {
            $this->enabled = $previous;
        }
    }

    public function disable(): void
    {
        $this->enabled = false;
    }

    public function enable(): void
    {
        $this->enabled = true;
    }

    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    public function record(
        string $action,
        ?Model $subject = null,
        ?array $before = null,
        ?array $after = null,
        ?string $workspaceId = null,
    ): ?AuditLog {
        if (! $this->enabled) {
            return null;
        }

        try {
            if (! Schema::hasTable('audit_logs')) {
                return null;
            }

            $request = request();

            return AuditLog::query()->create([
                'workspace_id' => $workspaceId
                    ?? $this->workspaceIdOf($subject)
                    ?? $this->context->id(),
                'user_id' => auth()->id(),
                'action' => $action,
                'subject_type' => $subject === null ? null : $this->typeOf($subject),
                'subject_id' => $subject?->getKey() === null ? null : (string) $subject->getKey(),
                'before' => $before === null ? null : $this->clean($before),
                'after' => $after === null ? null : $this->clean($after),
                'ip' => $request?->ip(),
                'user_agent' => substr((string) $request?->userAgent(), 0, 255) ?: null,
            ]);
        } catch (Throwable) {
            return null;
        }
    }

    /** Short, stable name: `transaction`, not the full class path. */
    private function typeOf(Model $subject): string
    {
        $parts = explode('\\', $subject::class);

        return Str::snake(end($parts));
    }

    private function workspaceIdOf(?Model $subject): ?string
    {
        if ($subject === null) {
            return null;
        }

        $id = $subject->getAttribute('workspace_id');

        return is_string($id) ? $id : null;
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public function clean(array $attributes): array
    {
        $cleaned = [];

        foreach ($attributes as $key => $value) {
            if (in_array($key, self::NOISE, true)) {
                continue;
            }

            $cleaned[$key] = in_array($key, self::REDACTED, true) ? '[redacted]' : $value;
        }

        return $cleaned;
    }
}
