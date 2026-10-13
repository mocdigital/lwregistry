<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Notifications\DatabaseNotification;

/**
 * The app's own view of a row in `notifications`.
 *
 * The table is Laravel's (`0001_..._create_notifications_table`) and the ~40
 * classes in `App\Notifications` already write to it. What was missing was
 * anything that could turn one of those rows into the designed notification
 * card: the screen groups by category, flags "Action Required", and picks an
 * icon and a tint per notification. All of that is derived from the `type` key
 * every `toArray()` payload already carries, so it lives here rather than being
 * re-derived in the controller or guessed at in Vue.
 *
 * Extends DatabaseNotification so `markAsRead()`, the `read`/`unread` scopes
 * and the notifiable morph keep working unchanged; `User::notifications()`
 * points at this class.
 *
 * @property string $id
 * @property array<string, mixed> $data
 * @property \Illuminate\Support\Carbon|null $read_at
 */
final class Notification extends DatabaseNotification
{
    /** The tab bar on `/notifications`, in the order the design draws it. */
    public const CATEGORY_APPLICATIONS = 'applications';

    public const CATEGORY_PAYMENTS = 'payments';

    public const CATEGORY_NETWORK = 'network';

    public const CATEGORY_SYSTEM = 'system';

    /**
     * Which tab a notification falls under.
     *
     * Keyed on the `type` slug in the payload, not on the notification class
     * name, so an admin-side and an owner-side notification about the same
     * event land in the same tab.
     *
     * @var array<string, string>
     */
    private const CATEGORIES = [
        'sbo_registration_received' => self::CATEGORY_APPLICATIONS,
        'sbo_approved' => self::CATEGORY_APPLICATIONS,
        'sbo_declined' => self::CATEGORY_APPLICATIONS,
        'sbo_info_requested' => self::CATEGORY_APPLICATIONS,
        'sbo_verified' => self::CATEGORY_APPLICATIONS,
        'sbo_documents_compliant' => self::CATEGORY_APPLICATIONS,
        'sbo_upload_documents' => self::CATEGORY_APPLICATIONS,
        'compliance_document_submitted' => self::CATEGORY_APPLICATIONS,
        'compliance_document_approved' => self::CATEGORY_APPLICATIONS,
        'compliance_document_rejected' => self::CATEGORY_APPLICATIONS,

        'sbo_fees_paid' => self::CATEGORY_PAYMENTS,

        'sbo_connection_requested' => self::CATEGORY_NETWORK,
        'sbo_connection_accepted' => self::CATEGORY_NETWORK,
        'sbo_message_received' => self::CATEGORY_NETWORK,
        'sbo_network_report' => self::CATEGORY_NETWORK,
        'purchase_order_invite' => self::CATEGORY_NETWORK,
        /*
         * Bidding and opportunities are network activity: they are about
         * trading with other members, which is what that tab collects.
         */
        'procurement_posted' => self::CATEGORY_NETWORK,
        'procurement_invited' => self::CATEGORY_NETWORK,
        'procurement_access_requested' => self::CATEGORY_NETWORK,
        'procurement_access_granted' => self::CATEGORY_NETWORK,
        'bid_received' => self::CATEGORY_NETWORK,
        'bid_accepted' => self::CATEGORY_NETWORK,
        'bid_declined' => self::CATEGORY_NETWORK,
        'testimonial_requested' => self::CATEGORY_NETWORK,
        'review_received' => self::CATEGORY_NETWORK,
        'sbo_manager_assigned' => self::CATEGORY_NETWORK,

        // An advert is a purchase, so its outcome belongs with payments.
        'ad_campaign_approved' => self::CATEGORY_PAYMENTS,
        'ad_campaign_rejected' => self::CATEGORY_PAYMENTS,

        'support_enquiry_submitted' => self::CATEGORY_SYSTEM,
        'support_enquiry_responded' => self::CATEGORY_SYSTEM,
        'support_enquiry_escalated' => self::CATEGORY_SYSTEM,
    ];

    /**
     * Types that need the owner to do something before the application can
     * move on. These get the amber "Action Required" pill and are counted in
     * the summary bar's third stat.
     *
     * @var array<int, string>
     */
    private const ACTION_REQUIRED = [
        'sbo_upload_documents',
        'sbo_info_requested',
        'compliance_document_rejected',
        'sbo_connection_requested',
        'procurement_access_requested',
        'sbo_declined',
        // Both need the member to do something next: accept the work, or fix
        // the advert and resubmit.
        'bid_accepted',
        'ad_campaign_rejected',
    ];

    /**
     * Icon key and background tint per type. The keys name the Figma assets
     * already imported by NotificationsView (card-check, card-dollar, …); the
     * tints are the Icon_Wrapper fills from 253:9003.
     *
     * @var array<string, array{0: string, 1: string}>
     */
    private const ART = [
        'sbo_approved' => ['check', '#ccfbf1'],
        'sbo_verified' => ['award', '#ccfbf1'],
        'sbo_documents_compliant' => ['check', '#ccfbf1'],
        'compliance_document_approved' => ['check', '#ccfbf1'],
        'sbo_registration_received' => ['check', '#ccfbf1'],

        'sbo_fees_paid' => ['dollar', '#dbeafe'],

        'sbo_connection_requested' => ['users', '#fef3c7'],
        'sbo_connection_accepted' => ['users', '#ccfbf1'],
        'sbo_message_received' => ['users', '#dbeafe'],
        'sbo_network_report' => ['alert', '#fef3c7'],
        'purchase_order_invite' => ['briefcase', '#f3e8ff'],

        'sbo_upload_documents' => ['alert', '#fef3c7'],
        'sbo_info_requested' => ['alert', '#fef3c7'],
        'compliance_document_rejected' => ['alert', '#fef3c7'],
        'compliance_document_submitted' => ['briefcase', '#f3e8ff'],
        'sbo_declined' => ['alert', '#fef3c7'],

        'procurement_posted' => ['briefcase', '#f3e8ff'],
        'procurement_invited' => ['briefcase', '#f3e8ff'],
        'procurement_access_requested' => ['users', '#fef3c7'],
        'procurement_access_granted' => ['check', '#ccfbf1'],
        'bid_received' => ['briefcase', '#dbeafe'],
        'bid_accepted' => ['check', '#ccfbf1'],
        'bid_declined' => ['briefcase', '#f1f5f9'],
        'review_received' => ['award', '#dbeafe'],
        'sbo_manager_assigned' => ['users', '#ccfbf1'],

        'ad_campaign_approved' => ['check', '#ccfbf1'],
        'ad_campaign_rejected' => ['alert', '#fef3c7'],

        'support_enquiry_submitted' => ['chart', '#f1f5f9'],
        'support_enquiry_responded' => ['chart', '#f1f5f9'],
        'support_enquiry_escalated' => ['alert', '#fef3c7'],
    ];

    /** The `type` slug from the payload — not the `type` column, which is a class name. */
    public function slug(): string
    {
        $slug = $this->data['type'] ?? null;

        return is_string($slug) ? $slug : 'system';
    }

    public function category(): string
    {
        return self::CATEGORIES[$this->slug()] ?? self::CATEGORY_SYSTEM;
    }

    public function isActionRequired(): bool
    {
        return in_array($this->slug(), self::ACTION_REQUIRED, true);
    }

    public function isUnread(): bool
    {
        return null === $this->read_at;
    }

    /** Icon key the view maps to an imported SVG. */
    public function icon(): string
    {
        return self::ART[$this->slug()][0] ?? 'chart';
    }

    /** Icon_Wrapper background. */
    public function tint(): string
    {
        return self::ART[$this->slug()][1] ?? '#f1f5f9';
    }

    public function title(): string
    {
        $title = $this->data['title'] ?? null;

        return is_string($title) && '' !== $title ? $title : 'Notification';
    }

    public function body(): string
    {
        $message = $this->data['message'] ?? null;

        return is_string($message) ? $message : '';
    }

    /**
     * Where the card's action link points. Most payloads carry an absolute
     * `url`; the rest are inferred from the category so the link is never dead.
     */
    public function url(): ?string
    {
        /*
         * A notification that names a conversation opens that conversation —
         * the exact thread, not the network landing page. Resolved here rather
         * than only in the stored url, so notifications saved before this was
         * the behaviour open the right place too.
         */
        $conversationId = $this->data['conversation_id'] ?? null;

        if (is_int($conversationId) || (is_string($conversationId) && '' !== $conversationId)) {
            return route('sbo.network.index', ['tab' => 'messages', 'conversation' => $conversationId]);
        }

        $url = $this->data['url'] ?? null;

        if (is_string($url) && '' !== $url) {
            return $url;
        }

        return match ($this->category()) {
            self::CATEGORY_APPLICATIONS => route('application.show', ['step' => 'documents']),
            self::CATEGORY_PAYMENTS => route('application.show', ['step' => 'fees']),
            self::CATEGORY_NETWORK => route('sbo.network.index'),
            default => null,
        };
    }

    /**
     * The label on the action link. The design writes a verb per card
     * ("View Application →", "View Receipt →"); this is that verb, derived.
     */
    public function actionLabel(): ?string
    {
        if (null === $this->url()) {
            return null;
        }

        return match ($this->slug()) {
            'sbo_approved', 'sbo_registration_received', 'sbo_declined',
            'sbo_info_requested' => 'View Application',
            'sbo_fees_paid' => 'View Receipt',
            'sbo_upload_documents', 'compliance_document_rejected',
            'compliance_document_submitted' => 'Upload Documents',
            'compliance_document_approved', 'sbo_documents_compliant' => 'View Documents',
            'sbo_verified' => 'View Seal',
            'purchase_order_invite' => 'View Opportunity',
            'sbo_connection_requested', 'sbo_connection_accepted' => 'View Network',
            'sbo_message_received' => 'Open Conversation',
            'procurement_posted', 'bid_received', 'bid_accepted',
            'bid_declined', 'procurement_invited', 'procurement_access_granted' => 'View Opportunity',
            'procurement_access_requested' => 'View Request',
            'review_received' => 'View Listing',
            'sbo_manager_assigned' => 'View Application',
            'ad_campaign_approved', 'ad_campaign_rejected' => 'View Campaign',
            default => 'View Details',
        };
    }

    /**
     * The connection this notification is about, when it is about one — the
     * Accept/Decline pair on the card posts against it.
     */
    public function connectionId(): ?int
    {
        $id = $this->data['connection_id'] ?? null;

        return is_numeric($id) ? (int) $id : null;
    }

    /**
     * The connection id, but only while the request is still pending.
     *
     * The Accept/Decline pair is drawn from this. Once the request has been
     * accepted or declined there is nothing for the buttons to do, so returning
     * null here is what makes them disappear after the visitor acts — leaving
     * them on the card is what read as "the button did nothing".
     */
    public function pendingConnectionId(): ?int
    {
        $id = $this->connectionId();

        if (null === $id) {
            return null;
        }

        return SboConnection::query()
            ->whereKey($id)
            ->where('status', SboConnection::STATUS_PENDING)
            ->exists()
            ? $id
            : null;
    }
}
