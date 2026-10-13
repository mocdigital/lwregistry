<?php

declare(strict_types=1);

namespace App\Support\Chatbot;

use App\Models\ChatbotDocument;
use App\Models\ChatbotPassage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Taking a document in, and making it answerable.
 *
 * Extraction happens once, at upload, rather than per question: parsing a PDF
 * is expensive, the file does not change, and doing it on the answering path
 * would make the first visitor of the day wait for it.
 */
final class KnowledgeBank
{
    public function __construct(
        private readonly TextExtractor $extractor,
        private readonly PassageSplitter $splitter,
        private readonly Embedder $embedder,
    ) {}

    public function add(UploadedFile $file, string $title, ?int $uploadedBy = null): ChatbotDocument
    {
        $path = $file->storeAs(
            'knowledge',
            // Generated, never the sender's name: a filename is user-supplied
            // text and building a path from it is how a file escapes its
            // directory.
            Str::uuid()->toString() . '.' . mb_strtolower($file->getClientOriginalExtension()),
            'chat',
        );

        $document = ChatbotDocument::create([
            'title' => $title,
            'disk' => 'chat',
            'path' => $path,
            'original_name' => mb_substr($file->getClientOriginalName(), 0, 180),
            'mime_type' => (string) $file->getMimeType(),
            'size' => (int) $file->getSize(),
            'status' => ChatbotDocument::STATUS_PENDING,
            'uploaded_by' => $uploadedBy,
        ]);

        return $this->index($document);
    }

    /**
     * Read the document and replace its passages.
     *
     * Replace rather than append, so re-indexing after a change to the
     * splitter does not leave the old passages answering alongside the new.
     */
    public function index(ChatbotDocument $document): ChatbotDocument
    {
        /*
         * A generated document — registry facts, the Q&A library, the pages,
         * the learned answers — has no file behind it (its path is empty), so
         * there is nothing to re-read: reading `path('')` lands on the disk
         * root, a directory, which is the "Is a directory" error. These are
         * rebuilt by "Refresh registry knowledge", not reindexed, so leave it
         * as it is rather than break trying.
         */
        if ('' === mb_trim((string) $document->path)) {
            return $document;
        }

        try {
            $text = $this->extractor->extract(
                Storage::disk($document->disk)->path($document->path),
                $document->mime_type,
            );

            $passages = $this->splitter->split($text);

            if ([] === $passages) {
                /*
                 * A scanned PDF is a stack of images with no text in it. It
                 * uploads cleanly and answers nothing, so it has to be visibly
                 * broken rather than quietly useless.
                 */
                throw new RuntimeException('No readable text was found. A scanned document needs to be run through OCR first.');
            }

            DB::transaction(function () use ($document, $passages): void {
                $document->passages()->delete();

                foreach ($passages as $ordinal => $content) {
                    ChatbotPassage::create([
                        'document_id' => $document->id,
                        'ordinal' => $ordinal,
                        'content' => $content,
                        'searchable' => ChatbotPassage::normalise($content),
                    ]);
                }

                $document->update([
                    'status' => ChatbotDocument::STATUS_READY,
                    'error' => null,
                    'passage_count' => count($passages),
                ]);
            });

            // Give the new passages their meaning-vectors now, so semantic
            // search reaches an uploaded document immediately rather than only
            // after the next hourly embed. Best-effort — see embed().
            $this->embed($document);
        } catch (Throwable $exception) {
            $document->update([
                'status' => ChatbotDocument::STATUS_FAILED,
                'error' => Str::limit($exception->getMessage(), 400),
                'passage_count' => 0,
            ]);
        }

        return $document->fresh();
    }

    /**
     * Give a document's passages their embedding vectors now.
     *
     * Best-effort and self-contained: the document has already been stored and
     * marked ready by the time this runs, so a model that is off or a batch that
     * fails must not undo that. Anything left without a vector still answers by
     * keyword, and the scheduled `chatbot:embed-knowledge` fills the rest in on
     * its next pass — the same safety net a document uploaded while the model
     * was down relies on.
     */
    private function embed(ChatbotDocument $document): void
    {
        if (! $this->embedder->available()) {
            return;
        }

        try {
            $document->passages()
                ->whereNull('embedding')
                ->select(['id', 'content'])
                ->chunkById(64, function ($rows): void {
                    // The opening of a passage carries its meaning, and the model
                    // has a token limit — the same cap the embed command uses.
                    $texts = $rows->map(
                        fn (ChatbotPassage $p): string => Str::limit((string) $p->content, 1200, ''),
                    )->all();

                    $vectors = $this->embedder->embed(array_values($texts));

                    // A short batch means the call half-failed; leave those for
                    // the scheduled embed rather than pair the wrong vectors.
                    if (count($vectors) !== $rows->count()) {
                        return;
                    }

                    foreach ($rows->values() as $i => $row) {
                        ChatbotPassage::query()->whereKey($row->id)->update([
                            'embedding' => json_encode($vectors[$i]),
                        ]);
                    }
                });
        } catch (Throwable) {
            // Unreachable model, a timeout — the hourly embed will catch up.
        }
    }
}
