<?php

declare(strict_types=1);

namespace Tests\Feature\Infra;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Broadcast;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\LedgerTestCase;

/**
 * A broadcast channel is another door into the same books.
 *
 * `ResolveWorkspace` guards the HTTP door; nothing guards this one except the
 * callback in `routes/channels.php`, and a missing check there would let one
 * household watch another's spending arrive in real time.
 */
final class BroadcastChannelTest extends LedgerTestCase
{
    use RefreshDatabase;

    private function authorizes(User $user, string $workspaceId): bool
    {
        // Resolve the channel callback the same way the framework does when an
        // authorisation request arrives.
        $channels = Broadcast::getChannels();

        foreach ($channels as $pattern => $callback) {
            if ($pattern !== 'workspace.{workspaceId}') {
                continue;
            }

            $handler = is_array($callback) ? $callback[0] : $callback;

            return (bool) $handler($user, $workspaceId);
        }

        $this->fail('The workspace broadcast channel is not registered.');
    }

    #[Test]
    public function a_member_may_listen_to_their_own_workspace(): void
    {
        $owner = $this->makeUser('listener@example.test');
        $workspace = $this->makeWorkspace($owner);

        $this->assertTrue($this->authorizes($owner, $workspace->id));
    }

    #[Test]
    public function a_stranger_may_not_listen_to_someone_elses_workspace(): void
    {
        $owner = $this->makeUser('owner-b@example.test');
        $workspace = $this->makeWorkspace($owner);

        $stranger = $this->makeUser('stranger-b@example.test');
        $this->makeWorkspace($stranger, 'Their own');

        $this->assertFalse($this->authorizes($stranger, $workspace->id));
    }

    #[Test]
    public function an_unknown_workspace_id_is_refused_rather_than_erroring(): void
    {
        $user = $this->makeUser('prober-b@example.test');

        $this->assertFalse($this->authorizes($user, '01JNOTAWORKSPACE000000000'));
    }

    #[Test]
    public function a_removed_member_loses_the_channel_immediately(): void
    {
        $owner = $this->makeUser('owner-c@example.test');
        $workspace = $this->makeWorkspace($owner);

        $member = $this->makeUser('leaving-c@example.test');
        $membership = $workspace->members()->create([
            'user_id' => $member->id,
            'role' => 'member',
            'joined_at' => now(),
        ]);

        $this->assertTrue($this->authorizes($member, $workspace->id));

        $membership->delete();

        $this->assertFalse($this->authorizes($member->refresh(), $workspace->id));
    }
}
