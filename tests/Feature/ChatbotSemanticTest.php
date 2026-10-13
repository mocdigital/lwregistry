<?php

declare(strict_types=1);

use App\Support\Chatbot\Embedder;
use App\Support\Chatbot\KnowledgeBank;
use App\Support\Chatbot\Responder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;

uses(RefreshDatabase::class);

/*
 * A stand-in for the on-box embedding model, so the semantic path can be tested
 * without a running service. It places text on two orthogonal concept axes —
 * "refund" and "hours" — so a question and a passage that MEAN the same thing
 * get the same vector while sharing no words.
 */
final class FakeConceptEmbedder implements Embedder
{
    public function available(): bool
    {
        return true;
    }

    public function embed(array $texts): array
    {
        return array_map(fn (string $t): array => $this->vector($t), array_values($texts));
    }

    public function embedOne(string $text): ?array
    {
        return $this->vector($text);
    }

    /** @return array<int, float> */
    private function vector(string $text): array
    {
        $t = mb_strtolower($text);
        $refund = str_contains($t, 'refund') || str_contains($t, 'reimburse') || str_contains($t, 'money back');
        $hours = str_contains($t, 'hour') || str_contains($t, 'open') || str_contains($t, 'closing time');

        return [$refund ? 1.0 : 0.0, $hours ? 1.0 : 0.0, 0.1];
    }
}

function bankAdds(string $text): void
{
    app(KnowledgeBank::class)->add(UploadedFile::fake()->createWithContent('kb.txt', $text), 'Guidance');
}

test('the assistant answers by meaning when the words differ', function (): void {
    app()->instance(Embedder::class, new FakeConceptEmbedder());

    // A passage a keyword search would never reach from the question below — it
    // shares no word with "money back".
    bankAdds('Reimbursements are issued to the original payment method within 30 days of approval.');

    // Give the bank its vectors (through the fake).
    $this->artisan('chatbot:embed-knowledge')->assertSuccessful();

    $answer = app(Responder::class)->answer('can I get my money back');

    expect($answer->answered)->toBeTrue()
        ->and($answer->text)->toContain('Reimbursements');
});

test('meaning does not force an unrelated passage', function (): void {
    app()->instance(Embedder::class, new FakeConceptEmbedder());

    // Only an hours passage exists; a refund question is on the other axis, so
    // the cosine is ~0 and there is nothing to answer with.
    bankAdds('Our office is open from 9am, and the closing time is 5pm on weekdays.');
    $this->artisan('chatbot:embed-knowledge')->assertSuccessful();

    $answer = app(Responder::class)->answer('can I get my money back');

    expect($answer->answered)->toBeFalse();
});
