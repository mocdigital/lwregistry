<?php

declare(strict_types=1);

namespace App\Support\Chatbot;

use App\Models\Category;
use App\Models\ChatbotDocument;
use App\Models\ChatbotPassage;
use App\Models\Sbo;
use App\Support\SboFeeCalculator;
use Illuminate\Support\Facades\DB;

/**
 * What the assistant knows about this registry without anybody uploading a file.
 *
 * The site already states its own rules — what a registration costs, what
 * verification requires, what a seal is, how bidding works. Making an
 * administrator retype all of that into a PDF before the assistant can answer
 * "how much is it" is asking them to maintain a second copy of something the
 * application already knows.
 *
 * So this writes the registry's own facts into the knowledge bank as
 * documents. The numbers are READ from where they actually live — the fee
 * settings, the categories table, the count of approved listings — so a fee
 * change is reflected the next time this runs rather than quietly leaving the
 * assistant quoting last year's price.
 *
 * They are ordinary documents once written: an administrator can read them in
 * Admin > Sales Assistant, and replace any of them with something better.
 * `refresh()` rewrites only the ones it wrote.
 */
final class SiteKnowledge
{
    /**
     * Marks a document as this class's to rewrite.
     *
     * Prefixing the title rather than adding a column: an administrator
     * scanning the list can see at a glance which entries maintain themselves,
     * and anything they write is left alone by definition.
     */
    private const PREFIX = 'Registry: ';

    public static function refresh(): int
    {
        $written = 0;

        foreach (self::documents() as $title => $body) {
            self::write(self::PREFIX . $title, $body);
            $written++;
        }

        return $written;
    }

    /**
     * The registry, as the application itself describes it.
     *
     * @return array<string, string>
     */
    private static function documents(): array
    {
        return [
            'What the registry is' => self::about(),
            'Registering a business' => self::registering(),
            'Fees and payment' => self::fees(),
            'Verification and documents' => self::verification(),
            'The digital seal and certificate' => self::seal(),
            'The network and messaging' => self::network(),
            'Procurement, RFQs and bidding' => self::procurement(),
            'Categories and industries' => self::categories(),
            'Advertising' => self::advertising(),
            'Support and contact' => self::support(),
        ] + self::fromConversationTopics();
    }

    /**
     * The scripted answers, written into the bank as well.
     *
     * The graph answers the questions somebody anticipated; the bank answers
     * the ones they did not. Putting the same answers in both means a visitor
     * who types "can I bid on someone else's request" finds the answer that
     * the Procurement submenu would also have given them, rather than falling
     * off the end of a flow that only knew the button.
     *
     * @return array<string, string>
     */
    private static function fromConversationTopics(): array
    {
        $documents = [];

        foreach (ConversationTopics::all() as $topic) {
            $lines = [];

            foreach ($topic['questions'] as $item) {
                // Question and answer together: the question carries the words
                // somebody will actually type, which is what makes the passage
                // findable at all.
                $lines[] = $item['q'] . "\n" . $item['a'];
            }

            $documents['Common questions — ' . $topic['label']] = implode("\n\n", $lines);
        }

        return $documents;
    }

    private static function about(): string
    {
        $listings = Sbo::query()
            ->where('verification_status', 'approved')
            ->where('is_registry', false)
            ->count();

        $countries = Sbo::query()
            ->where('verification_status', 'approved')
            ->whereNotNull('country')
            ->distinct()
            ->count('country');

        return <<<TEXT
            What the LW Commerce Registry is

            The LW Commerce Registry is a directory of verified Spirit-led
            businesses. Every listing in it has been through verification, so a
            member searching the directory is looking at businesses whose
            documents have been checked rather than at self-declared entries.

            There are currently {$listings} verified businesses listed, trading
            across {$countries} countries.

            Membership gives a business a public profile in the directory, a
            digital seal and certificate, access to the SBO network for
            connecting and messaging other members, and the ability to post and
            bid on procurement requests.

            The registry is members-only to browse in full. Anyone can see the
            public pages; the directory, the network and procurement are for
            businesses that have registered and been verified.

            Do you have an app? Is there a mobile app? No. The registry has no
            app and there is no mobile app to download or install. It is a
            website you use in a web browser on any device — a phone, a tablet or
            a computer — so there is no app to get from an app store; you simply
            open the site and sign in.

            The registry's mission is to empower global commerce by providing an
            unshakeable, technology-driven platform where companies grow,
            integrate and trade securely.

            Its vision is to become the gold standard in sovereign digital
            business registry, enabling radical operational transparency and
            cross-border connectivity across the Loveworld network.

            The registry's core values are absolute compliance, engineering
            innovation, and collaborative synergy across public and private
            jurisdictions. It describes itself as the digital backbone that
            verifies corporate records, standardises compliance, and validates
            institutional identity across the world — a globally recognised,
            centralised registry powering a trusted digital business economy.
            TEXT;
    }

    private static function registering(): string
    {
        return <<<'TEXT'
            How to register a business

            Registration is done from the website. You create an account, then
            add your business and complete the application in three steps.

            Step one is your business details: the name, what it does, where it
            trades from, and how to contact it. You can list several trading
            addresses if the business operates from more than one place.

            Step two is the registration fee. It is calculated from your
            business size and the country you are registering in, and is paid
            with Espees or by transfer to the merchant account shown on the fee
            page.

            Step three is your compliance documents. What is required depends on
            the country you register in, and the application tells you which
            documents apply to yours.

            Once the fee is settled and the documents are accepted, the
            registration is reviewed and, when approved, the listing goes live
            in the directory and the digital seal is issued.

            You can register more than one business under the same account.
            TEXT;
    }

    private static function fees(): string
    {
        $currency = SboFeeCalculator::currency(null);
        $gateway = SboFeeCalculator::espeesGateway(null);
        $espees = $gateway['configured'] ? 'Espees is accepted for online payment.' : '';

        return <<<TEXT
            Registration fees and how to pay

            Is it free? No — registration is not free. There is a fee to
            register a business, and it must be paid before the registration can
            be verified. The registry itself is free to browse on its public
            pages, but listing a business is a paid registration.

            The registration fee depends on the size of the business and the
            country it is registering in, so there is no single price — the fee
            page calculates yours once you have chosen your business size, and
            shows the figure before you pay anything.

            Fees are quoted in {$currency}. {$espees}

            There are two ways to pay. Espees checkout takes you to the Espees
            payment page and confirms automatically when it completes. The
            alternative is a manual transfer to the merchant code shown on the
            fee page, after which you upload the proof of payment and an
            administrator confirms it.

            The fee covers the verification of your documents, your entry in the
            directory, and your digital seal for the registration period.

            A registration that has not been paid for cannot proceed to
            verification, and an unpaid listing does not appear in the
            directory.
            TEXT;
    }

    private static function verification(): string
    {
        return <<<'TEXT'
            Verification and compliance documents

            Verification is the check that makes the directory worth using: a
            listing marked verified is one whose documents have been read by an
            administrator, not one that has simply filled in a form.

            A business is fully verified when three things are true — the
            registration has been approved, the compliance documents have been
            accepted, and the registration fee has been settled. All three are
            required. A listing that is approved but unpaid is not verified, and
            a listing that has paid but whose documents are outstanding is not
            either.

            Which documents are required depends on the country you register in.
            The application lists the ones that apply to yours, and each can be
            uploaded, replaced and re-submitted if an administrator asks for
            something clearer.

            If a document is rejected you are told why, and can upload a
            replacement without starting the application again.

            Only fully verified businesses can use the SBO network, bid on
            procurement requests, or report other members.
            TEXT;
    }

    private static function seal(): string
    {
        return <<<'TEXT'
            The digital SBO seal and certificate

            Every registered business gets a digital seal carrying its own
            registration number, and a certificate that sets out the business
            name, category, address, contacts and the dates the registration
            reached each stage.

            The seal is issued as soon as the registration fee is settled. It is
            provisional until verification completes, and becomes a verified
            seal at that point.

            The certificate can be previewed in the browser before it is
            downloaded, from the Digital SBO Seal panel on your profile or on
            the application progress page.

            The certificate carries a QR code that links to the registry's own
            verification page for that business, so anybody holding the
            certificate can check it against the registry rather than taking it
            at face value.
            TEXT;
    }

    private static function network(): string
    {
        return <<<'TEXT'
            The SBO network and messaging

            The network is where verified businesses find and talk to each
            other. You can send a connection request to another business, and
            once it is accepted either side can open a conversation.

            Messages support attachments — photographs, video and documents —
            and you can reply to a specific message, react to one, pin one for
            everyone in the conversation, and translate an incoming message into
            your own language.

            Every verified business is also placed in the group chat for its
            industry automatically. There is no joining or inviting: a business
            that is verified is in the room for its sector, and one whose
            verification lapses is out of it.

            From a group you can take any member aside into a private
            conversation, including the registry itself — "Message admin" opens
            a private thread with the LW Commerce Registry that an administrator
            answers.

            Notifications for a conversation can be turned off without leaving
            it.
            TEXT;
    }

    private static function procurement(): string
    {
        return <<<'TEXT'
            Procurement, RFQs and bidding

            Members post procurement requests — an RFQ — describing what they
            need to buy, the budget, the delivery location and the date
            proposals are due. A request can be public to the whole registry or
            limited to businesses the buyer invites.

            A request can carry a full RFP schedule: when it was advertised,
            when the briefing is, when questions may be asked and answered, when
            proposals are due, and when the award is decided. Every one of those
            dates is optional, but the ones given have to run in order.

            Any fully verified business can bid on a public request, apart from
            the business that posted it. A bid carries a proposal and can
            include an attachment.

            The buyer reviews the bids and awards the work to one of them. Both
            the winning and the unsuccessful bidders are notified.

            Buyers can also ask and answer questions on a request, so a
            clarification one bidder asks for is visible to all of them.
            TEXT;
    }

    private static function categories(): string
    {
        $top = Category::query()
            ->where('business_count', '>', 0)
            ->orderByDesc('business_count')
            ->take(14)
            ->pluck('name');

        $list = $top->isEmpty() ? '' : "\n\nThe categories with the most members are: " . $top->implode(', ') . '.';

        return <<<TEXT
            Business categories and industries

            Every listing is filed under a category, which is how the directory
            is browsed and how procurement requests are matched to the
            businesses that can fulfil them. You choose yours when you
            register, and it can be corrected later.

            The category also decides which industry group chat the business
            joins once it is verified.{$list}

            The directory can be browsed by category, by country, and by
            business size, and searched by name.
            TEXT;
    }

    private static function advertising(): string
    {
        return <<<'TEXT'
            Advertising on the registry

            Businesses can advertise on the registry's own pages. Campaigns are
            bought through the advertise page, priced in Espees, and are
            reviewed by an administrator before they go live.

            An advert that is approved starts running in the slot it was bought
            for. One that is not approved is returned with the reason, and can
            be corrected and submitted again.

            Advertising is separate from registration: a business does not need
            to advertise to be listed, and the directory is not ordered by who
            has paid for advertising.
            TEXT;
    }

    private static function support(): string
    {
        return <<<'TEXT'
            Getting help

            There are three ways to reach the registry.

            The support page takes an enquiry with your name, your SBO number if
            you have one, a subject and a message, and does not require you to
            be signed in.

            Signed-in members can message the registry directly from the SBO
            network — "Message admin" opens a private conversation that an
            administrator replies to, and everything said in it is kept in one
            thread.

            The feedback page is for comments on the registry itself rather than
            for questions needing an answer.
            TEXT;
    }

    /** Written as a document with its passages, replacing any earlier version. */
    private static function write(string $title, string $body): void
    {
        $text = mb_trim(preg_replace('/^[ \t]+/m', '', $body) ?? $body);
        $passages = (new PassageSplitter())->split($text);

        DB::transaction(function () use ($title, $passages): void {
            $document = ChatbotDocument::updateOrCreate(
                ['title' => $title],
                [
                    // No file behind it: this one is written, not uploaded.
                    'disk' => 'chat',
                    'path' => '',
                    'original_name' => 'generated',
                    'mime_type' => 'text/plain',
                    'size' => 0,
                    'status' => ChatbotDocument::STATUS_READY,
                    'error' => null,
                    'passage_count' => count($passages),
                ],
            );

            $document->passages()->delete();

            foreach ($passages as $ordinal => $content) {
                ChatbotPassage::create([
                    'document_id' => $document->id,
                    'ordinal' => $ordinal,
                    'content' => $content,
                    'searchable' => ChatbotPassage::normalise($content),
                ]);
            }
        });
    }
}
