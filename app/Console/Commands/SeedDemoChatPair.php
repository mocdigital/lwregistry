<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Models\Country;
use App\Models\Sbo;
use App\Models\SboConnection;
use App\Models\SboConversation;
use App\Models\SboMessage;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Two accounts you can sign into at once, already able to chat.
 *
 * `LocalDemoSeeder` builds 100 SBOs but leaves the network empty: one pending
 * connection, no conversations, no messages. Messaging needs an *accepted*
 * connection on both sides, so there was no way to exercise the inbox without
 * hand-building one.
 *
 * This creates (or refreshes) two fully-verified SBOs, connects them, opens
 * their conversation and seeds a short thread. Sign into one in a normal
 * window and the other in a private window, and the two inboxes talk to each
 * other — over Reverb if it is running, on reload if not.
 *
 * Re-runnable: it upserts by email and resets the thread rather than stacking
 * duplicates.
 */
final class SeedDemoChatPair extends Command
{
    private const PASSWORD = 'password';

    /** @var array<int, array<string, string>> */
    private const PEOPLE = [
        [
            'email' => 'chat.ada@example.test',
            'firstname' => 'Ada',
            'lastname' => 'Nwosu',
            'sbo' => 'Ada Commerce Ltd',
            'category' => 'Technology Services',
            'city' => 'Lagos',
            'country' => 'Nigeria',
        ],
        [
            'email' => 'chat.ben@example.test',
            'firstname' => 'Ben',
            'lastname' => 'Adeyemi',
            'sbo' => 'Ben Logistics Ltd',
            'category' => 'Transport & Logistics',
            'city' => 'Abuja',
            'country' => 'Nigeria',
        ],
    ];

    /**
     * Author index, then body. Ends on Ben so that Ada — the first row of the
     * printed credentials, and so the likelier first login — opens the inbox
     * with one unread and can see the badge and the Unread chip do something.
     *
     * @var array<int, array{0: int, 1: string}>
     */
    private const THREAD = [
        [1, 'Hello — saw your listing in the registry. Do you handle cold-chain deliveries?'],
        [0, 'We do. Lagos to Abuja twice a week, refrigerated up to 2 tonnes.'],
        [1, 'That could work for us. What lead time do you need on a booking?'],
        [0, 'Three working days for a standing slot, same week if there is spare capacity.'],
        [1, 'Perfect. I will send over the volumes this afternoon.'],
    ];

    protected $signature = 'demo:chat
        {--fresh : Wipe the seeded conversation and start the thread over}
        {--unpaid : Force the second SBO unpaid so the payment flow can be walked}
        {--paid : Force both SBOs paid}';

    protected $description = 'Create two connected demo accounts with an open conversation, for testing chat';

    public function handle(): int
    {
        if (app()->isProduction()) {
            $this->error('demo:chat refuses to run in production.');

            return self::FAILURE;
        }

        $result = DB::transaction(function (): array {
            $sbos = [];
            $users = [];

            foreach (self::PEOPLE as $i => $person) {
                $user = $this->upsertUser($person);
                $users[] = $user;
                /*
                 * Payment state is only touched when explicitly asked for.
                 *
                 * This command used to set it on every run, so re-seeding the
                 * chat — which you do often — silently marked both SBOs paid
                 * and switched the payment wall off. Re-running it now leaves
                 * an existing application exactly as it was; only a brand new
                 * one gets a default, and only --paid / --unpaid override an
                 * existing one.
                 */
                $paid = match (true) {
                    $this->option('paid') => true,
                    // --unpaid targets the second SBO; the first stays usable
                    // so the chat itself is still reachable.
                    $this->option('unpaid') => 1 !== $i,
                    default => null,
                };

                $sbos[] = $this->upsertSbo($user, $person, $paid);
            }

            $connection = $this->connect($sbos[0], $sbos[1]);
            $conversation = $this->conversation($connection, $sbos);
            $this->thread($conversation, $sbos, $users);

            return ['users' => $users, 'sbos' => $sbos, 'conversation' => $conversation];
        });

        $this->newLine();
        $this->info('Two demo accounts are ready. Sign into one per browser profile.');
        $this->newLine();

        $this->table(
            ['Email', 'Password', 'SBO', 'Fees', 'Payment wall'],
            collect(self::PEOPLE)->map(fn (array $p, int $i): array => [
                $p['email'],
                self::PASSWORD,
                $result['sbos'][$i]->name,
                $result['sbos'][$i]->feesPaid() ? 'paid' : 'UNPAID',
                // The wall is what /businesses and /dashboard check, so it is
                // the thing worth stating outright rather than inferring.
                $result['sbos'][$i]->feesPaid() ? 'off' : 'ON — walk the payment flow',
            ])->all(),
        );

        if ( ! $this->option('paid') && ! $this->option('unpaid')) {
            $this->line('  Payment state left as it was. Use --unpaid or --paid to change it.');
        }

        $this->line('  Chat:      /sbo/network/messages');
        $this->line('  Directory: /businesses  (search "Ada Commerce" or "Ben Logistics")');

        if ($this->option('unpaid')) {
            $this->line('  Payment:   /application/fees  (as ' . self::PEOPLE[1]['email'] . ')');
        }

        $this->newLine();
        $this->comment('Live delivery needs REVERB_APP_KEY set and `php artisan reverb:start` running.');
        $this->comment('Without it the thread is still correct — it just updates on reload rather than instantly.');

        return self::SUCCESS;
    }

    /** @param array<string, string> $person */
    private function upsertUser(array $person): User
    {
        $user = User::firstOrNew(['email' => $person['email']]);

        $user->forceFill([
            'firstname' => $person['firstname'],
            'lastname' => $person['lastname'],
            'role' => UserRole::SBO_MANAGER,
            'password' => Hash::make(self::PASSWORD),
            'email_verified_at' => now(),
            // A suspended account cannot sign in; make sure a re-run clears it.
            'suspended_at' => null,
            'suspended_by' => null,
        ])->save();

        return $user;
    }

    /**
     * @param  array<string, string>  $person
     * @param  bool|null  $paid  null = leave whatever payment state is already there
     */
    private function upsertSbo(User $owner, array $person, ?bool $paid): Sbo
    {
        $sbo = Sbo::firstOrNew(['name' => $person['sbo']]);
        $isNew = ! $sbo->exists;

        if ($isNew) {
            $sbo->slug = Str::slug($person['sbo']);
        }

        $sbo->forceFill([
            'created_by' => $owner->id,
            'category' => $person['category'],
            'business_type' => $person['category'],
            'description' => $person['sbo'] . ' is a demo listing created by `php artisan demo:chat`.',
            'address' => '1 Demo Way',
            'city' => $person['city'],
            'country' => $person['country'],
            'country_id' => Country::where('name', $person['country'])->value('id'),
            'website' => Str::slug($person['sbo']) . '.example',
            'phone' => '+2348030000000',
            'primary_contact_name' => $person['firstname'] . ' ' . $person['lastname'],
            'primary_contact_email' => $person['email'],
            'owner_first_name' => $person['firstname'],
            'owner_surname' => $person['lastname'],
            'status' => 'active',
            'is_active' => true,
            /*
             * The network gate is approved + documents compliant + fees paid
             * (Sbo::isFullyVerified). Anything less and /sbo/network 403s, so
             * a "demo" account that could not open the inbox would be useless.
             */
            'verification_status' => 'approved',
            'date_approved' => now(),
            'documents_status' => 'compliant',
            'documents_compliant_at' => now(),
        ])->save();

        /*
         * Payment is a separate write, and a conditional one. A new SBO with
         * no instruction defaults to paid so the chat works out of the box;
         * an existing one is left alone unless --paid or --unpaid says
         * otherwise, so re-seeding the conversation cannot switch the wall off.
         */
        if (null !== $paid || $isNew) {
            $wantPaid = $paid ?? true;

            $sbo->forceFill([
                'payment_status' => $wantPaid ? 'paid' : 'unpaid',
                'payment_confirmed_at' => $wantPaid ? now() : null,
                'payment_submitted_at' => null,
                // Left null when unpaid on purpose: confirming the fee is the
                // first step of the payment flow, and pre-filling it would
                // skip the thing being tested.
                'calculated_fee' => $wantPaid ? $sbo->calculated_fee : null,
                'fee_band' => $wantPaid ? $sbo->fee_band : null,
            ])->save();
        }

        return $sbo->fresh();
    }

    private function connect(Sbo $a, Sbo $b): SboConnection
    {
        $connection = SboConnection::query()
            ->where(fn ($q) => $q
                ->where(fn ($w) => $w->where('requester_sbo_id', $a->id)->where('recipient_sbo_id', $b->id))
                ->orWhere(fn ($w) => $w->where('requester_sbo_id', $b->id)->where('recipient_sbo_id', $a->id)))
            ->first() ?? new SboConnection([
                'requester_sbo_id' => $a->id,
                'recipient_sbo_id' => $b->id,
            ]);

        $connection->forceFill([
            'requester_sbo_id' => $connection->requester_sbo_id ?? $a->id,
            'recipient_sbo_id' => $connection->recipient_sbo_id ?? $b->id,
            'status' => SboConnection::STATUS_ACCEPTED,
            'responded_at' => now(),
        ])->save();

        return $connection;
    }

    /** @param array<int, Sbo> $sbos */
    private function conversation(SboConnection $connection, array $sbos): SboConversation
    {
        $conversation = SboConversation::firstOrCreate(
            ['connection_id' => $connection->id],
            [
                'created_by_sbo_id' => $sbos[0]->id,
                'type' => SboConversation::TYPE_DIRECT,
                'last_message_at' => now(),
            ],
        );

        // The pivot is what group/bid threads read and what the send path uses
        // to find recipients; a conversation without it looks empty.
        $conversation->participants()->syncWithoutDetaching([$sbos[0]->id, $sbos[1]->id]);

        return $conversation;
    }

    /**
     * @param  array<int, Sbo>  $sbos
     * @param  array<int, User>  $users
     */
    private function thread(SboConversation $conversation, array $sbos, array $users): void
    {
        $existing = SboMessage::where('conversation_id', $conversation->id)->count();

        if ($existing > 0 && ! $this->option('fresh')) {
            $this->line('  Thread already has ' . $existing . ' message(s); pass --fresh to reset it.');

            return;
        }

        SboMessage::where('conversation_id', $conversation->id)->delete();

        foreach (self::THREAD as $i => [$author, $body]) {
            $at = now()->subMinutes((count(self::THREAD) - $i) * 7);

            $message = SboMessage::create([
                'conversation_id' => $conversation->id,
                'sbo_id' => $sbos[$author]->id,
                'user_id' => $users[$author]->id,
                'body' => $body,
                // Everything but the last message is read, so the inbox opens
                // with exactly one unread — enough to see the badge and the
                // Unread chip do something.
                'read_at' => $i < count(self::THREAD) - 1 ? now() : null,
            ]);

            // Timestamps are guarded, so they are backdated after the insert;
            // otherwise the four messages share a second and the thread has no
            // legible order.
            $message->forceFill(['created_at' => $at, 'updated_at' => $at])->save();
        }

        $conversation->forceFill(['last_message_at' => now()])->save();
    }
}
