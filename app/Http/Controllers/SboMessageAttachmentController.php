<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\SboMessageAttachment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * Serve a chat attachment to someone in the conversation.
 *
 * The bytes are on the private `chat` disk, so this route is the only way to
 * them. That is the point: a public disk would make every file in every
 * private conversation readable by anyone who guessed the path, and paths are
 * guessable in a way conversations are not.
 */
final class SboMessageAttachmentController extends Controller
{
    public function __invoke(Request $request, SboMessageAttachment $attachment): Response
    {
        $user = $request->user();
        abort_unless(null !== $user, 403);

        $attachment->loadMissing('message.conversation');
        $conversation = $attachment->message?->conversation;
        abort_unless(null !== $conversation, 404);

        /*
         * Membership is checked exactly as posting into the thread is, so a
         * conversation you cannot read is one whose files you cannot read
         * either. `participantSboId` answers for every conversation type,
         * including bid and group threads which have no connection row.
         */
        abort_if(null === $conversation->participantSboId($user->sbos()->pluck('id')), 403);

        abort_unless($attachment->existsOnDisk(), 404);

        /*
         * Inline for what a browser can show, so a photograph opens where it
         * was sent rather than landing in a downloads folder. Everything else
         * is an attachment.
         *
         * The CSP is the important part: these are files uploaded by one
         * business and read by another, served from our own origin. Without
         * it, anything the browser decides to treat as HTML runs as us.
         */
        $inline = in_array($attachment->kind, [
            SboMessageAttachment::KIND_IMAGE,
            SboMessageAttachment::KIND_VIDEO,
        ], true);

        return Storage::disk($attachment->disk)->response(
            $attachment->path,
            $attachment->original_name,
            [
                'Content-Type' => $attachment->mime_type,
                'Content-Security-Policy' => "default-src 'none'; media-src 'self'; img-src 'self'; style-src 'unsafe-inline'; sandbox",
                'X-Content-Type-Options' => 'nosniff',
                'Cache-Control' => 'private, max-age=3600',
            ],
            $inline ? 'inline' : 'attachment',
        );
    }
}
