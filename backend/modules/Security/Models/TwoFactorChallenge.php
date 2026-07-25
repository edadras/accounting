<?php

declare(strict_types=1);

namespace Modules\Security\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use Modules\Core\Concerns\HasUlidKey;

/**
 * A half-finished sign-in: the password was right, the second factor is still
 * missing.
 *
 * It is deliberately not a token — it grants nothing on its own, expires in
 * minutes, and is spent the moment it is exchanged. Only its hash is stored, so
 * reading the table gives an attacker nothing to replay.
 */
final class TwoFactorChallenge extends Model
{
    use HasUlidKey;

    /** Long enough to finish typing a code out of a phone, short enough to be useless if intercepted. */
    public const LIFETIME_MINUTES = 5;

    /** Guessing a six-digit code needs far more tries than a person ever will. */
    public const MAX_ATTEMPTS = 5;

    protected $fillable = ['user_id', 'token_hash', 'expires_at', 'ip'];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return [
            'attempts' => 'integer',
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
        ];
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    public static function newToken(): string
    {
        return Str::random(64);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isUsable(): bool
    {
        return $this->consumed_at === null
            && $this->attempts < self::MAX_ATTEMPTS
            && $this->expires_at->isFuture();
    }

    public function isExhausted(): bool
    {
        return $this->attempts >= self::MAX_ATTEMPTS;
    }

    public function recordFailure(): void
    {
        $this->increment('attempts');
    }

    public function consume(): void
    {
        $this->forceFill(['consumed_at' => now()])->save();
    }

    public function scopeUsable(Builder $query): Builder
    {
        return $query->whereNull('consumed_at')
            ->where('attempts', '<', self::MAX_ATTEMPTS)
            ->where('expires_at', '>', now());
    }
}
