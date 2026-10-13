<?php

declare(strict_types=1);

namespace App\Support\Chatbot;

/**
 * Everything the assistant can be asked about, as content rather than as code.
 *
 * Ten subjects, each with the questions people actually ask about it and a
 * written answer to each. DefaultBlueprint expands this into the graph — a
 * menu, a submenu per subject, and an answer node per question — which is how
 * a hundred-odd nodes exist without a hundred-odd blocks of hand-written PHP.
 *
 * Kept as data for two reasons. Adding a question is a line here rather than a
 * node, a wire and a layout coordinate; and the same answers are written into
 * the knowledge bank, so a question nobody anticipated still finds them
 * through retrieval instead of falling off the end of the graph.
 *
 * @see DefaultBlueprint  for how this becomes nodes and wires
 * @see SiteKnowledge     for the registry facts read from the application
 */
final class ConversationTopics
{
    /**
     * @return array<string, array{label: string, questions: array<int, array{q: string, a: string}>}>
     */
    public static function all(): array
    {
        return [
            'about' => [
                'label' => 'About the registry',
                'questions' => [
                    ['q' => 'What is the LW Commerce Registry?', 'a' => 'It is a directory of verified Spirit-led businesses. Every listing has been through verification, so you are looking at businesses whose documents have been checked rather than at self-declared entries.'],
                    ['q' => 'Who can join?', 'a' => 'Any business willing to go through verification. There is no restriction by size or sector — the registry spans everything from single-person consultancies to construction groups.'],
                    ['q' => 'What do I get as a member?', 'a' => 'A public profile in the directory, a digital seal and certificate, access to the SBO network for connecting and messaging other members, and the ability to post and bid on procurement requests.'],
                    ['q' => 'Is the registry free to browse?', 'a' => 'The public pages are open to anyone. The directory, the network and procurement are for businesses that have registered and been verified — that is what keeps the listings worth trusting.'],
                    ['q' => 'What countries does it cover?', 'a' => 'Members trade across several countries, and the registration requirements differ by country because the compliance documents do. The application shows you which apply to yours.'],
                    ['q' => 'How is this different from a normal directory?', 'a' => 'A normal directory lists whoever fills in the form. Here, an administrator reads the compliance documents before a listing goes live, and the listing is marked verified only when the documents, the approval and the fee are all settled.'],
                ],
            ],

            'registering' => [
                'label' => 'Registering a business',
                'questions' => [
                    ['q' => 'How do I register?', 'a' => 'Create an account, add your business, then complete three steps: your business details, the registration fee, and your compliance documents. Once the fee is settled and the documents accepted, the registration is reviewed and the listing goes live.'],
                    ['q' => 'What details do I need to hand?', 'a' => 'The business name, what it does, the category it trades under, its addresses, and contact details. Nothing you will not already know — you can save and come back to it.'],
                    ['q' => 'Can I register more than one business?', 'a' => 'Yes. Several businesses can sit under one account, each with its own application, fee and verification.'],
                    ['q' => 'Can I list more than one address?', 'a' => 'Yes. A business trading from several places can list each address, and the profile shows them all with the country each is in.'],
                    ['q' => 'How long does registration take?', 'a' => 'The form itself takes a few minutes. What takes longer is verification, which depends on how quickly the compliance documents are supplied and reviewed.'],
                    ['q' => 'Can I change my details after registering?', 'a' => 'Yes. Your business details, addresses, category and contacts can all be edited from your listing afterwards.'],
                    ['q' => 'What if my application is declined?', 'a' => 'You are told why. Most declines are about a document being unclear or missing rather than the business itself, and you can correct it and submit again without starting over.'],
                ],
            ],

            'fees' => [
                'label' => 'Fees and payment',
                'questions' => [
                    ['q' => 'How much does registration cost?', 'a' => 'The fee depends on the size of your business and the country you register in, so there is no single price. The fee page calculates yours once you choose your business size, and shows the figure before you pay anything.'],
                    ['q' => 'What does the fee cover?', 'a' => 'Verification of your documents, your entry in the directory, and your digital seal for the registration period.'],
                    ['q' => 'How do I pay?', 'a' => 'Two ways. Espees checkout takes you to the Espees payment page and confirms automatically when it completes. Or transfer to the merchant code shown on the fee page and upload the proof, which an administrator confirms.'],
                    ['q' => 'Do you accept Espees?', 'a' => 'Yes. Espees checkout is the quickest route: it confirms on its own, so your application moves to the next step without waiting for anybody.'],
                    ['q' => 'What happens if I do not pay?', 'a' => 'The registration cannot proceed to verification, and an unpaid listing does not appear in the directory. Nothing is lost — the application waits until the fee is settled.'],
                    ['q' => 'Is the fee refundable?', 'a' => 'Ask support about a specific case. Refunds are handled by an administrator rather than automatically, and the answer depends on how far the verification has gone.'],
                    ['q' => 'Do I pay again each year?', 'a' => 'The seal and listing cover a registration period, after which a renewal is due. Support can tell you the date for your own registration.'],
                ],
            ],

            'verification' => [
                'label' => 'Verification and documents',
                'questions' => [
                    ['q' => 'What does verified mean?', 'a' => 'Three things are true: the registration has been approved, the compliance documents have been accepted, and the fee has been settled. All three — a listing that is approved but unpaid is not verified, and neither is one that has paid with documents outstanding.'],
                    ['q' => 'Which documents do I need?', 'a' => 'It depends on the country you register in. The application lists the ones that apply to yours, and each can be uploaded, replaced and re-submitted.'],
                    ['q' => 'How long does verification take?', 'a' => 'It depends on how quickly the documents are supplied and how clear they are. The application shows you which step you are on and what is outstanding.'],
                    ['q' => 'A document was rejected — what now?', 'a' => 'You are told why, and you can upload a replacement without starting the application again. Most rejections are about legibility or an expired date.'],
                    ['q' => 'Can I use the network before I am verified?', 'a' => 'No. The SBO network, bidding on procurement, and reporting other members are all for fully verified businesses — that is what makes them safe to use.'],
                    ['q' => 'Who sees my documents?', 'a' => 'Only administrators reviewing your application. They are stored on a private disk and served through a gated route, never as public files.'],
                ],
            ],

            'seal' => [
                'label' => 'The digital seal and certificate',
                'questions' => [
                    ['q' => 'What is the digital seal?', 'a' => 'A badge carrying your own registration number, issued as soon as the registration fee is settled. It is provisional until verification completes, and becomes a verified seal at that point.'],
                    ['q' => 'What is on the certificate?', 'a' => 'Your business name, category, address, contacts, and the dates the registration reached each stage — plus the seal itself and a QR code.'],
                    ['q' => 'Can I see the certificate before downloading it?', 'a' => 'Yes. The Digital SBO Seal panel on your profile and on the application progress page both have a preview that opens it in the browser.'],
                    ['q' => 'What is the QR code for?', 'a' => 'It links to the registry\'s verification page for your business, so anybody holding your certificate can check it against the registry rather than taking it at face value.'],
                    ['q' => 'Where can I use the seal?', 'a' => 'On your own website, proposals and documents. It says the business has been verified by the registry, and the QR code lets anyone confirm that.'],
                    ['q' => 'My seal has not been issued', 'a' => 'Issuance begins at payment, so a seal appears once the registration fee is confirmed. If the fee is settled and the seal is not there, contact support.'],
                ],
            ],

            'directory' => [
                'label' => 'The directory and your profile',
                'questions' => [
                    ['q' => 'How do I find a business?', 'a' => 'The directory can be searched by name and browsed by category, country and business size. Every listing shown has been verified.'],
                    ['q' => 'How do I get listed?', 'a' => 'Registering is what lists you. The profile goes live in the directory once verification completes.'],
                    ['q' => 'Can I add photos and a logo?', 'a' => 'Yes. A logo, a cover image and a gallery can all be uploaded from your listing, and the directory cards use your own artwork rather than stock images.'],
                    ['q' => 'How do reviews work?', 'a' => 'Verified members can leave a rating and a comment on a listing that is not their own, one review each, which they can update rather than duplicate.'],
                    ['q' => 'Can I hide my listing?', 'a' => 'Privacy settings control what is shown and whether you accept connection requests. Support can help with anything the settings do not cover.'],
                    ['q' => 'How do I change my category?', 'a' => 'Edit it on your listing. The category also decides which industry group chat you are in, so changing it moves you to the right room.'],
                ],
            ],

            'network' => [
                'label' => 'The network and messaging',
                'questions' => [
                    ['q' => 'What is the SBO network?', 'a' => 'Where verified businesses find and talk to each other. You send a connection request, and once it is accepted either side can open a conversation.'],
                    ['q' => 'How do I connect with a business?', 'a' => 'Press Connect on their listing or their directory card. They are notified, and the conversation opens once they accept.'],
                    ['q' => 'Can I send files?', 'a' => 'Yes — photographs, video and documents, in both one-to-one and group conversations. A message can be a file with no words at all.'],
                    ['q' => 'What are the group chats?', 'a' => 'Every verified business is placed in the group for its industry automatically. There is no joining or inviting: verified means you are in the room for your sector.'],
                    ['q' => 'Can I message someone privately from a group?', 'a' => 'Yes. Any member of a room can be taken aside into a private conversation, from the menu beside their name or from the member list.'],
                    ['q' => 'How do I contact the registry itself?', 'a' => 'Message admin, from a group chat or the member list, opens a private thread with the registry that an administrator answers.'],
                    ['q' => 'Can I mute a conversation?', 'a' => 'Yes. Group Info has a notifications switch. Muting silences the bell and nothing else — the messages still arrive and still count as unread.'],
                    ['q' => 'Can I read a message in my own language?', 'a' => 'Where translation is configured, an incoming message in another language offers a translation, and you can switch back to the original.'],
                ],
            ],

            'procurement' => [
                'label' => 'Procurement and bidding',
                'questions' => [
                    ['q' => 'What is a procurement request?', 'a' => 'A member posting what they need to buy — the description, budget, delivery location and the date proposals are due. Other members bid on it.'],
                    ['q' => 'How do I post one?', 'a' => 'Post RFQ from the procurement page. You give the details, choose whether it is public or invite-only, and can attach a specification and supporting documents.'],
                    ['q' => 'Who can bid?', 'a' => 'Any fully verified business except the one that posted the request. A bid carries a proposal and can include an attachment.'],
                    ['q' => 'What is the difference between public and invite-only?', 'a' => 'A public request is open to the whole registry. An invite-only one is visible to the businesses the buyer invites, and to nobody else.'],
                    ['q' => 'What is the RFP schedule?', 'a' => 'The dates a request runs to: when it was advertised, the briefing, when questions may be asked and answered, when proposals are due, and when the award is decided. Each is optional, but the ones given must run in order.'],
                    ['q' => 'How is a bid awarded?', 'a' => 'The buyer reviews the bids and awards the work to one. Both the winning and the unsuccessful bidders are notified.'],
                    ['q' => 'Can I ask a question about a request?', 'a' => 'Yes. Questions and their answers are visible to every bidder, so a clarification one asks for is available to all of them.'],
                    ['q' => 'What currency are budgets in?', 'a' => 'The buyer chooses — Espees, Naira, dollars, pounds, euros and several others. The currency is shown beside the amount so there is no guessing.'],
                ],
            ],

            'advertising' => [
                'label' => 'Advertising',
                'questions' => [
                    ['q' => 'Can I advertise on the registry?', 'a' => 'Yes. Campaigns are bought from the advertise page, priced in Espees, and reviewed by an administrator before they go live.'],
                    ['q' => 'How much does advertising cost?', 'a' => 'It depends on the slot and the duration. The advertise page shows the options and the price before you commit.'],
                    ['q' => 'How long does approval take?', 'a' => 'An administrator reviews each campaign. If it is not approved you are told why and can correct it and submit again.'],
                    ['q' => 'Does advertising affect my directory ranking?', 'a' => 'No. The directory is not ordered by who has paid for advertising, and a business does not need to advertise to be listed.'],
                    ['q' => 'Where do the adverts appear?', 'a' => 'In defined slots across the registry\'s pages. The advertise page shows which slots exist and what each looks like.'],
                ],
            ],

            'trust' => [
                'label' => 'Trust and safety',
                'questions' => [
                    ['q' => 'How do I know a listing is genuine?', 'a' => 'Every listing in the directory has been verified — its documents read by an administrator, its fee settled, its registration approved. A listing that has not cleared all three is not shown as verified.'],
                    ['q' => 'Can I check a certificate someone shows me?', 'a' => 'Yes. Every certificate carries a QR code linking to the registry\'s own verification page for that business, so you can confirm it against the registry rather than trusting the document.'],
                    ['q' => 'Somebody is behaving badly in a chat', 'a' => 'Report the message. The menu on any message has Report, which sends it to administrators with the reason you choose. Reporting is available to verified businesses.'],
                    ['q' => 'What happens to a business that loses verification?', 'a' => 'It leaves the network and its industry group, and it can no longer bid. Its listing stops showing as verified.'],
                    ['q' => 'Is my data shared with other members?', 'a' => 'Your public profile is what other members see. Compliance documents are never public, and privacy settings control what else is shown and whether you accept connection requests.'],
                    ['q' => 'Are my messages private?', 'a' => 'A direct conversation is between the two businesses in it. Files sent in a chat are stored on a private disk and served only to people in that conversation.'],
                ],
            ],

            'getting_started' => [
                'label' => 'Making the most of membership',
                'questions' => [
                    ['q' => 'I have just joined — what should I do first?', 'a' => 'Finish your profile: a logo, a cover image and a proper description. Businesses with their own artwork lead the featured row, and a complete profile is what makes a connection request worth accepting.'],
                    ['q' => 'How do I get found?', 'a' => 'Your category and country are how people browse, and your name and description are what search matches. Being specific in the description is what brings the right enquiries.'],
                    ['q' => 'How do I win work here?', 'a' => 'Watch the procurement page for open requests in your sector, and bid with a proposal that answers the specification. Buyers see every bid side by side.'],
                    ['q' => 'Should I post my own requests?', 'a' => 'If you buy anything — supplies, services, contractors — posting it as an RFQ puts it in front of verified businesses who can quote for it.'],
                    ['q' => 'How do I build connections?', 'a' => 'Start with your industry group, which you are in automatically once verified, and connect with businesses whose work touches yours. A connection is what opens a private conversation.'],
                    ['q' => 'What does a good profile look like?', 'a' => 'A real logo, a cover photograph of your own work, a description that says what you actually do rather than what sector you are in, every trading address, and current contact details.'],
                ],
            ],

            'account' => [
                'label' => 'Your account and support',
                'questions' => [
                    ['q' => 'I cannot sign in', 'a' => 'Use the forgotten-password link on the sign-in page. If the reset email does not arrive, check the spam folder and then contact support.'],
                    ['q' => 'I did not get my verification email', 'a' => 'The verify-email screen has a resend button. If it still does not arrive, contact support with the address you registered.'],
                    ['q' => 'How do I turn on two-factor authentication?', 'a' => 'From your account security settings. You will be given recovery codes at the same time — keep them somewhere safe, as they are shown once.'],
                    ['q' => 'How do I change my notification settings?', 'a' => 'Account settings has notification preferences, and individual conversations can be muted from Group Info.'],
                    ['q' => 'How do I change the language?', 'a' => 'The language switcher in the header. The interface is available in English, French and Spanish.'],
                    ['q' => 'How do I contact support?', 'a' => 'The support page takes an enquiry without signing in. Signed-in members can also message the registry directly from the SBO network — Message admin opens a private thread.'],
                    ['q' => 'Can I delete my account?', 'a' => 'Contact support. Deleting an account affects listings, connections and any procurement history, so it is handled by an administrator rather than automatically.'],
                ],
            ],
        ];
    }
}
