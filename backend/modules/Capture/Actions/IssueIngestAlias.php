<?php

declare(strict_types=1);

namespace Modules\Capture\Actions;

use Modules\Capture\Models\IngestAlias;
use Modules\Core\Support\WorkspaceContext;

/**
 * Mints the address that routes mail into one workspace.
 *
 * The plaintext token exists for exactly the length of this call: it goes back
 * to the caller once and only its hash is stored, so a lost address is
 * reissued rather than recovered.
 */
final readonly class IssueIngestAlias
{
    public function __construct(private WorkspaceContext $context) {}

    /** @return array{alias: IngestAlias, address: string} */
    public function handle(?string $label = null, ?int $userId = null): array
    {
        $this->context->require();

        $bytes = max(8, (int) config('capture.email.token_bytes', 16));
        $token = bin2hex(random_bytes($bytes));
        $domain = (string) config('capture.email.domain', 'inbox.finora.app');

        $alias = IngestAlias::query()->create([
            'token_hash' => IngestAlias::hash($token),
            'domain' => $domain,
            'label' => $label,
            'created_by' => $userId,
        ]);

        return ['alias' => $alias, 'address' => $token.'@'.$domain];
    }
}
