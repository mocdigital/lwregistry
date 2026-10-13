<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class SboConversation extends Model
{
    public const TYPE_DIRECT = 'direct';

    public const TYPE_BID = 'bid';

    /**
     * The category-wide group chat every verified business in that category
     * belongs to. One row per category; see App\Support\IndustryGroupChat. The
     * constant and the `industry` column keep their names so the migrations
     * that reference them still run, but the group key is the category now.
     */
    public const TYPE_INDUSTRY = 'industry';

    /**
     * A group a member created themselves and named, adding businesses they are
     * connected to. Distinct from TYPE_INDUSTRY, which the platform manages by
     * category; this one has an owner (created_by_sbo_id) who manages its roster.
     */
    public const TYPE_GROUP = 'group';

    protected $fillable = [
        'connection_id',
        'purchase_order_id',
        'created_by_sbo_id',
        'type',
        'industry',
        'name',
        'last_message_at',
    ];

    protected $casts = [
        'last_message_at' => 'datetime',
    ];

    /**
     * The connection this conversation hangs off, for direct threads.
     *
     * NOT named `connection()`. `Model::$connection` is the database
     * connection name, so `$conversation->connection` never reached the
     * relation: before hydration it read null (every membership check silently
     * failed 403) and after hydration it read the string "sqlite" (every check
     * fataled on `->requester_sbo_id`). The column is still `connection_id`.
     */
    public function sboConnection(): BelongsTo
    {
        return $this->belongsTo(SboConnection::class, 'connection_id');
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function creatorSbo(): BelongsTo
    {
        return $this->belongsTo(Sbo::class, 'created_by_sbo_id');
    }

    public function participants(): BelongsToMany
    {
        return $this->belongsToMany(Sbo::class, 'sbo_conversation_participants', 'conversation_id', 'sbo_id')
            ->withPivot(['last_read_at', 'muted_at'])
            ->withTimestamps();
    }

    /**
     * Members who have starred this thread. A personal bookmark, so it is keyed
     * by user rather than by SBO.
     *
     * @return BelongsToMany<User, $this>
     */
    public function starredBy(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'sbo_conversation_stars', 'conversation_id', 'user_id')
            ->withTimestamps();
    }

    public function messages(): HasMany
    {
        return $this->hasMany(SboMessage::class, 'conversation_id');
    }

    /**
     * Which of this owner's SBOs is the party to this conversation — the one a
     * message they send is attributed to.
     *
     * @param  iterable<int>  $sboIds
     */
    public function participantSboId(iterable $sboIds): ?int
    {
        $ids = collect($sboIds);

        $connection = $this->sboConnection;

        if ($connection) {
            foreach ([$connection->requester_sbo_id, $connection->recipient_sbo_id] as $id) {
                if ($ids->contains($id)) {
                    return (int) $id;
                }
            }
        }

        $participant = $this->participants()->whereIn('sbos.id', $ids->all())->value('sbos.id');

        return null === $participant ? null : (int) $participant;
    }

    /**
     * Conversations one of these SBOs can see.
     *
     * Two ways in, because the table supports two kinds of conversation: a
     * direct one hangs off an `sbo_connections` row (so membership is the pair
     * on that row), while a bid or group conversation has no connection and
     * lists its members in `sbo_conversation_participants`. Filtering on only
     * one of them — which every caller did — hid the other kind entirely.
     *
     * @param  Builder<SboConversation>  $query
     * @param  iterable<int>  $sboIds
     */
    #[Scope]
    protected function visibleTo(Builder $query, iterable $sboIds): void
    {
        $ids = collect($sboIds)->all();

        $query->where(fn (Builder $q) => $q
            ->whereHas('sboConnection', fn ($c) => $c
                ->whereIn('requester_sbo_id', $ids)
                ->orWhereIn('recipient_sbo_id', $ids))
            ->orWhereHas('participants', fn ($p) => $p->whereIn('sbos.id', $ids)));
    }
}
