<?php

declare(strict_types=1);

namespace Tests\Feature\AI;

use Modules\AI\Actions\StoreDraft;
use Modules\AI\Actions\TranscribeVoiceNote;
use Modules\AI\Jobs\TranscribeVoiceNoteJob;
use Modules\AI\Models\AiDraft;
use Modules\AI\Support\AudioNormalizer;
use Modules\Ledger\Models\Transaction;
use PHPUnit\Framework\Attributes\Test;

/**
 * Voice adds transcription and nothing else: what was said goes through the
 * same parser as what would have been typed, so the two cannot drift apart.
 */
final class VoiceNoteTest extends AiTestCase
{
    private const SPOKEN = 'دیروز ۳۵۰ لیر برای شام پرداخت کردم';

    /** @var list<string> */
    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            @unlink($file);
        }

        parent::tearDown();
    }

    #[Test]
    public function a_recording_becomes_the_same_draft_typing_it_would_have(): void
    {
        [, $workspace] = $this->world('voice@example.test');

        $draft = $this->inWorkspace(
            $workspace,
            fn () => app(TranscribeVoiceNote::class)->handle($this->recording()),
        );

        $this->assertSame(35000, $draft->amount);
        $this->assertSame('TRY', $draft->currency);
        $this->assertSame('2026-07-24', $draft->occurredAt->toDateString());
        $this->assertSame(self::SPOKEN, $draft->meta['transcript']);
        $this->assertSame('voice', $draft->meta['source']);
        $this->assertTrue($draft->needsConfirmation());
    }

    #[Test]
    public function the_transcript_travels_with_the_draft_so_it_can_be_corrected(): void
    {
        [, $workspace] = $this->world('transcript@example.test');

        $draft = $this->inWorkspace(
            $workspace,
            fn () => app(TranscribeVoiceNote::class)->handle($this->recording()),
        );

        $this->assertArrayHasKey('audio', $draft->meta);
        $this->assertIsBool($draft->meta['audio']['normalized']);

        // ffmpeg is optional: present it normalises, absent it says so and the
        // original file is used. Either way the pipeline completes.
        $this->assertContains(
            $draft->meta['audio']['note'],
            ['ok', 'ffmpeg_unavailable', 'ffmpeg_failed'],
            'A missing ffmpeg must degrade, not fail.',
        );
    }

    #[Test]
    public function the_normalizer_reports_a_missing_binary_instead_of_throwing(): void
    {
        config(['ai.audio.ffmpeg' => '/nonexistent/bin/ffmpeg']);
        AudioNormalizer::forgetBinary();

        $normalizer = new AudioNormalizer;
        $outcome = $normalizer->normalize($this->recording());

        $this->assertFalse($normalizer->available());
        $this->assertFalse($outcome['normalized']);
        $this->assertSame('ffmpeg_unavailable', $outcome['note']);

        AudioNormalizer::forgetBinary();
    }

    #[Test]
    public function the_queued_job_stores_a_pending_voice_draft_and_records_nothing(): void
    {
        [$user, $workspace] = $this->world('voicejob@example.test');

        (new TranscribeVoiceNoteJob($workspace->id, $this->recording(), null, $user->id))->handle(
            $this->context(),
            app(TranscribeVoiceNote::class),
            app(StoreDraft::class),
        );

        $draft = $this->inWorkspace($workspace, fn () => AiDraft::query()->sole());

        $this->assertSame(AiDraft::KIND_TRANSACTION, $draft->kind);
        $this->assertSame(AiDraft::SOURCE_VOICE, $draft->source);
        $this->assertSame(AiDraft::STATUS_PENDING, $draft->status);
        $this->assertSame(self::SPOKEN, $draft->input_text);
        $this->assertSame(35000, $draft->payload['amount']);
        $this->assertNull($draft->transaction_id);

        $this->assertSame(0, $this->inWorkspace($workspace, fn () => Transaction::query()->count()));
    }

    #[Test]
    public function a_job_for_a_workspace_that_no_longer_exists_does_nothing(): void
    {
        (new TranscribeVoiceNoteJob('01JQZZZZZZZZZZZZZZZZZZZZZZ', $this->recording()))->handle(
            $this->context(),
            app(TranscribeVoiceNote::class),
            app(StoreDraft::class),
        );

        $this->assertDatabaseCount('ai_drafts', 0);
    }

    /** The fixture carries its own transcript; real speech needs a speech model. */
    private function recording(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'voice').'.txt';
        file_put_contents($path, self::SPOKEN);
        $this->files[] = $path;

        return $path;
    }
}
