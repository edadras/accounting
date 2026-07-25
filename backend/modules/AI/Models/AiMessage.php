<?php

declare(strict_types=1);

namespace Modules\AI\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Concerns\BelongsToWorkspace;
use Modules\Core\Concerns\HasUlidKey;

final class AiMessage extends Model
{
    use BelongsToWorkspace;
    use HasUlidKey;

    public const ROLE_USER = 'user';

    public const ROLE_ASSISTANT = 'assistant';

    public const ROLE_TOOL = 'tool';

    protected $fillable = [
        'workspace_id', 'conversation_id', 'role', 'content', 'tool_calls',
        'tool_name', 'tool_result', 'provider', 'model', 'usage',
    ];

    protected function casts(): array
    {
        return [
            'tool_calls' => 'array',
            'tool_result' => 'array',
            'usage' => 'array',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(AiConversation::class, 'conversation_id');
    }
}
