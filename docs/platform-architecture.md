# LW Registry Platform Architecture

This document gives a high-level view of the LW Registry platform, its major features, role-based workflows, and core data relationships.

The diagrams are written in Mermaid so they can render in GitHub, many IDEs, and architecture documentation tools.

## Platform Context

```mermaid
flowchart LR
    Guest["Guest / Public Visitor"]
    User["Registered User / Vendor"]
    Manager["SBO Manager"]
    Admin["Admin"]
    SuperAdmin["Super Admin"]

    App["LW Registry Web App<br/>Laravel 12 + Livewire + Volt + Blade"]
    DB[("Application Database")]
    Files[("Media / Uploaded Files<br/>Spatie Media Library + public disk")]
    Queue["Queue Workers<br/>Mail + Notifications"]
    Mail["Email Provider<br/>SMTP / Mailgun / Resend"]

    Guest -->|"Search registry, view businesses, verify SBO number"| App
    User -->|"List vendor business, bid, review, support"| App
    Manager -->|"Register SBO, track progress, upload docs, post requests"| App
    Admin -->|"Review, approve, configure, award, respond"| App
    SuperAdmin -->|"All admin capabilities + escalation ownership"| App

    App --> DB
    App --> Files
    App --> Queue
    Queue --> Mail
```

## Application Modules

```mermaid
flowchart TB
    subgraph Public["Public Registry"]
        Home["Home / Search"]
        Directory["Business Directory"]
        BusinessProfile["Business Profile"]
        VerifySbo["Public SBO Verification URL"]
        Ads["Sponsored Ads + Click Tracking"]
    end

    subgraph Auth["Authentication + Account"]
        AuthModal["Join / Login Modal"]
        EmailVerify["Email Verification"]
        AccountDashboard["User Dashboard"]
        Settings["Profile / Password / Security"]
        Notifications["Bell + Notification History"]
    end

    subgraph Vendor["Vendor Business Layer"]
        BusinessListing["List Vendor Business"]
        BusinessVerification["Admin Business Verification"]
        ProductsServices["Business Products / Services Metadata"]
        Reviews["Reviews + Ratings"]
        Favorites["Favorites + Views"]
    end

    subgraph Sbo["SBO Registration Layer"]
        JoinForm["SBO Join Form"]
        ApplicationProgress["Application Progress Workflow"]
        ComplianceDocs["Country-Based Compliance Documents"]
        PaymentProof["Proof of Payment"]
        FinalVerification["MOC Registration Number"]
    end

    subgraph Procurement["Procurement Marketplace"]
        RequestCreate["Product / Service Procurement Request"]
        VendorDiscovery["Eligible Vendor Discovery"]
        BidSubmit["Bid Submission"]
        BidReview["Bid Review + Award"]
        Completion["Request Completion"]
    end

    subgraph Support["Support"]
        SupportForm["Authenticated Support Form"]
        AdminSupport["Admin Support Inbox"]
        SupportResponse["Respond / Done / Escalate"]
    end

    subgraph Admin["Admin Backend"]
        AdminDashboard["Admin Dashboard"]
        Users["Users + Admins"]
        Sbos["SBOs"]
        Businesses["Businesses / SBO Scorecards"]
        RequiredDocs["Required Documents by Country"]
        ProcurementAdmin["Procurement Requests"]
        Reports["Reports"]
        Activity["Activity Logs"]
        AdAdmin["Ads"]
    end

    Public --> Auth
    Auth --> Vendor
    Auth --> Sbo
    Sbo --> Procurement
    Vendor --> Procurement
    Auth --> Support
    Admin --> Vendor
    Admin --> Sbo
    Admin --> Procurement
    Admin --> Support
```

## Core Data Model

```mermaid
erDiagram
    USERS ||--o{ BUSINESSES : owns
    USERS ||--o{ SBOS : creates
    USERS ||--o{ PURCHASE_ORDER_BIDS : submits
    USERS ||--o{ SUPPORT_ENQUIRIES : opens

    CATEGORIES ||--o{ BUSINESSES : classifies
    CATEGORIES ||--o{ PURCHASE_ORDERS : classifies

    COUNTRIES ||--o{ STATES : contains
    STATES ||--o{ CITIES : contains
    COUNTRIES ||--o{ COMPLIANCE_DOCUMENT_TYPES : scopes

    SBOS ||--o{ PURCHASE_ORDERS : posts
    SBOS ||--o{ SBO_COMPLIANCE_DOCUMENTS : uploads
    SBOS ||--o| SBO_PAYMENT_PROOFS : has
    SBOS ||--o{ REPORTS : has
    SBOS ||--o{ SUPPORT_ENQUIRIES : references

    COMPLIANCE_DOCUMENT_TYPES ||--o{ SBO_COMPLIANCE_DOCUMENTS : requires

    PURCHASE_ORDERS ||--o{ PURCHASE_ORDER_BIDS : receives
    PURCHASE_ORDERS ||--o| PURCHASE_ORDER_BIDS : awards
    BUSINESSES ||--o{ PURCHASE_ORDER_BIDS : bids_as

    BUSINESSES ||--o{ REVIEWS : receives
    BUSINESSES ||--o{ BUSINESS_PRODUCTS : lists
    BUSINESSES ||--o{ BUSINESS_HOURS : has
    BUSINESSES ||--o{ BUSINESS_VIEWS : tracks
    BUSINESSES ||--o{ FAVORITES : saved_by
```

## Role-Based Entry Points

```mermaid
flowchart LR
    Login["Login / Post Login Router"]

    Login -->|"Admin or Super Admin"| AdminDash["/admin"]
    Login -->|"SBO Manager"| SboDash["/sbo/dashboard"]
    Login -->|"Regular User"| UserDash["/dashboard"]

    AdminDash --> AdminFeatures["Users, SBOs, Businesses, Documents, Procurement, Support, Reports, Ads"]
    SboDash --> SboFeatures["Application Progress, Documents, Payment Proof, Procurement Requests"]
    UserDash --> UserFeatures["Business Listings, Available Requests, Bids Sent, Accepted Bids, Support"]
```

## SBO Registration and Verification Workflow

```mermaid
flowchart TD
    Start["User submits Join Form"]
    CreateUser["Create / assign user as SBO Manager"]
    CreateSbo["Create provisional SBO<br/>application_id generated"]
    NotifyAdmins["Notify admins of new SBO"]
    NotifyOwner["Email owner with received application + required documents"]

    AdminReview{"Admin review"}
    InfoRequested["Info requested<br/>Owner can resubmit"]
    Declined["Declined<br/>Reason shown"]
    Approved["Approved"]

    Docs["Required documents<br/>Global + country-specific"]
    UploadDocs["SBO uploads required files"]
    DocsReview{"Admin document review"}
    DocsRejected["Rejected with reason<br/>Re-upload allowed"]
    DocsCompliant["Documents compliant"]

    Payment["Upload proof of payment"]
    PaymentReview{"Admin confirms payment"}
    PaymentRejected["Rejected with reason"]
    PaymentPaid["Payment paid"]

    Verified["Fully verified SBO"]
    MocNumber["MOC registration number assigned"]
    PublicVerify["Public verification URL validates number"]

    Start --> CreateUser --> CreateSbo --> NotifyAdmins
    CreateSbo --> NotifyOwner --> AdminReview
    AdminReview --> InfoRequested --> AdminReview
    AdminReview --> Declined
    AdminReview --> Approved --> Docs
    Docs --> UploadDocs --> DocsReview
    DocsReview --> DocsRejected --> UploadDocs
    DocsReview --> DocsCompliant --> Payment
    Payment --> PaymentReview
    PaymentReview --> PaymentRejected --> Payment
    PaymentReview --> PaymentPaid --> Verified
    Verified --> MocNumber --> PublicVerify
```

## Country-Based Compliance Documents

```mermaid
flowchart TB
    Country["SBO business country"]
    Resolver["SboComplianceRequirements"]
    GlobalDocs["Global active document types"]
    CountryDocs["Country-specific active document types"]
    RequiredList["Applicable required document list"]
    Progress["Application Progress document step"]
    AdminReview["Admin SBO detail review"]
    Email["Registration received email"]

    Country --> Resolver
    GlobalDocs --> Resolver
    CountryDocs --> Resolver
    Resolver --> RequiredList
    RequiredList --> Progress
    RequiredList --> AdminReview
    RequiredList --> Email
```

## Procurement Request and Bidding Workflow

```mermaid
flowchart TD
    Creator{"Request creator"}
    AdminCreate["Admin creates request<br/>/admin/purchase-orders"]
    SboCreate["Verified SBO creates request<br/>/my-rfqs"]

    Request["PurchaseOrder record<br/>type: product, service, product_service<br/>status: open"]
    EligibleVendors["Verified businesses in matching category"]
    NotifyVendors["Notify eligible vendors"]
    Dashboard["Vendor dashboard shows available requests"]

    BidModal["Vendor opens request and submits bid"]
    ValidateBid{"Bid validation"}
    RejectBid["Block bid<br/>unverified, expired, wrong category, duplicate, closed"]
    SaveBid["Create PurchaseOrderBid<br/>status: pending"]
    NotifyOwnerAdmin["Notify SBO owner + admins"]

    Review["Admin or SBO reviews bids"]
    Award["Award one bid"]
    Accepted["Winning bid accepted"]
    Rejected["Other bids rejected"]
    NotifyBidders["Notify winner and unsuccessful bidders"]
    Completed["Mark request completed"]

    Creator --> AdminCreate
    Creator --> SboCreate
    AdminCreate --> Request
    SboCreate --> Request
    Request --> EligibleVendors --> NotifyVendors --> Dashboard
    Dashboard --> BidModal --> ValidateBid
    ValidateBid -->|"Fails"| RejectBid
    ValidateBid -->|"Passes"| SaveBid --> NotifyOwnerAdmin --> Review
    Review --> Award
    Award --> Accepted
    Award --> Rejected
    Accepted --> NotifyBidders
    Rejected --> NotifyBidders
    NotifyBidders --> Completed
```

## Support Workflow

```mermaid
flowchart TD
    User["Authenticated user"]
    Form["/support form<br/>name, SBO number, subject, message"]
    ResolveSbo["Resolve owned SBO by application_id or MOC number"]
    Save["Create support_enquiry<br/>status: open"]
    NotifyAdmins["Notify admins"]
    AdminInbox["/admin/support"]
    Action{"Admin action"}
    Respond["Respond<br/>status: responded<br/>email user"]
    Done["Mark done<br/>status: done"]
    Escalate["Escalate<br/>status: escalated<br/>notify super admins"]

    User --> Form --> ResolveSbo --> Save --> NotifyAdmins --> AdminInbox
    AdminInbox --> Action
    Action --> Respond
    Action --> Done
    Action --> Escalate
```

## Notification and Event Flow

```mermaid
flowchart LR
    ModelEvents["Model Events / Observers"]
    LivewireActions["Livewire Actions"]
    AdminRecipients["AdminRecipients helper"]
    Notifications["Laravel Notifications"]
    MailQueue["Queued Mail"]
    DatabaseBell["Database / Bell Notifications"]
    Users["Users / Admins / SBO Managers"]

    ModelEvents --> Notifications
    LivewireActions --> Notifications
    LivewireActions --> AdminRecipients
    AdminRecipients --> Notifications
    Notifications --> MailQueue
    Notifications --> DatabaseBell
    MailQueue --> Users
    DatabaseBell --> Users
```

## Admin Capability Areas

```mermaid
mindmap
  root((Admin Backend))
    Dashboard
    Users
      Add User
      Roles
      Admin Permissions
      SBO Managers
    SBOs
      Review
      Approve
      Decline
      Request Info
      Documents
      Payment Proof
      Registration Number
    Businesses and SBOs
      Scorecards
      Verification
      Profiles
    Compliance Documents
      Global Requirements
      Country Requirements
      Seed Metadata
      Needs Review
    Procurement Requests
      Create Request
      Review Bids
      Award Bid
      Mark Completed
    Support
      Respond
      Done
      Escalate
    Reports
    Ads
    Activity Logs
```

## Main Workflow Summary

1. Public users discover verified businesses and validate finalized SBO registration numbers.
2. A prospective SBO submits the join form and becomes an SBO manager with a provisional SBO application.
3. Admins review the SBO, request more information, approve, or decline.
4. Required compliance documents are resolved from global and country-specific document rules.
5. SBO managers upload required documents and proof of payment from the application progress workflow.
6. Admin approval of documents and payment completes verification, assigns an MOC registration number, and enables procurement features.
7. Verified SBOs or admins create product/service procurement requests.
8. Verified vendor businesses in matching categories discover requests, receive notifications, and submit bids.
9. Admins or SBO managers review bids, award one vendor, notify all bidders, and mark the request completed.
10. Authenticated users can submit support enquiries; admins can respond, mark done, or escalate to super admins.

## Technology Stack

- Backend: Laravel 12, PHP 8.4+, Eloquent, middleware, policies, observers, notifications, queues.
- UI: Blade, Livewire, Volt, Vite, Tailwind CSS, Bootstrap-style components.
- Realtime/notifications: Laravel notifications, database bell notifications, queued mail, Reverb/Echo dependencies available.
- Files: Spatie Media Library plus public disk uploads for procurement and bid documents.
- Data: relational database tables for users, businesses, SBOs, compliance documents, payment proofs, procurement requests, bids, support, reports, ads, reviews, favorites, and activity logs.
- Test stack: Pest, Laravel testing helpers, factories, Livewire testing.

## Notes

- Internal model names still use `PurchaseOrder` and `PurchaseOrderBid`, while user-facing screens call them procurement requests and bids.
- `SBO` means Strategic Business Organization; final validation is through the MOC registration number.
- No external MCP lookup was required for this diagram; it is derived from the current repository routes, models, Livewire components, observers, notifications, and migrations.
