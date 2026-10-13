# Premium UI Refinement Audit

This audit reviews the current Loveworld Registry interface across public pages,
authenticated account flows, SBO onboarding, advertising, feedback, and admin
workflows. The platform is feature-rich; the main premium gap is not missing
functionality, but inconsistent presentation, page-local styling, and uneven form
guidance across screens.

## Evidence Snapshot

- Public and authenticated layouts are split between `resources/views/layouts/app.blade.php`,
  `resources/views/components/nav.blade.php`, `resources/views/components/account-nav.blade.php`,
  and `resources/views/components/layouts/admin.blade.php`.
- Core styling is split across `public/css/style.css`, `public/css/account-nav.css`,
  `public/css/admin.css`, `public/css/form-help.css`, `public/css/lists.css`, and
  page-level `<style>` blocks.
- Initial scan found roughly 54 form or submit surfaces, 16 explicit `data-form-help`
  descriptions, 214 inline style or style-block markers, and 25 AOS animation markers.
- The global form helper exists in `public/js/form-help.js` and now enhances both
  labeled controls and unlabeled placeholder-led controls, but many critical fields
  should still receive deliberate business-specific helper copy over time.

## Execution Status

### Pass 1: Foundation fixes applied

- Fixed the invalid heading declaration and dangling `font` declaration in
  `public/css/style.css`.
- Removed the brittle `margin-left: -40%` mobile account nav offset and replaced it with
  stable flex sizing, truncation, and max-width handling in `public/css/account-nav.css`.
- Aligned account nav, profile, and listing `--dark-color` tokens with the global brand navy.
- Updated `public/css/form-help.css` so helper icons use the platform green and support
  generated labels for unlabeled controls.
- Expanded `public/js/form-help.js` with richer descriptions for SBO, ads, fees, RFQ,
  admin, profile, payment, and registration fields.
- Enhanced `public/js/form-help.js` so placeholder-led forms such as Login and Join can
  receive generated labels and pulsing help icons without manually editing every field.
- Bumped cache query versions for the changed shared style/script assets.

### Pass 2: Priority user-facing flows applied

- Added shared premium flow primitives in `public/css/style.css` for guided cards,
  summary panels, prominent payment cards, upload cards, sticky mobile action bars,
  campaign summaries, status timelines, and status chips.
- Polished the Join/Login modal with premium form panels, a clearer SBO stepper, explicit
  login and business-name helper copy, a mobile-friendly action row, and payment-next
  wording that matches the auto-approved signup flow.
- Upgraded SBO Registration Fees into a guided fee setup card with structured fee summary,
  stronger Espees primary payment treatment, and clearer manual proof helper copy.
- Converted compliance document rows into document upload cards with visible requirement,
  status, rejection reason, current-file, upload/dropzone, and loading states.
- Added a sticky Advertise campaign summary that updates from placement and duration
  selections, including desktop/mobile dimensions and locked package price.
- Enhanced the Advertise helper script so placement changes filter incompatible rate-card
  options and update the live dimension/summary guidance.
- Polished Feedback with a stronger received-confirmation state, premium form shell,
  sticky action row, and optional related-page URL prefill from query/referrer context.
- Verified the priority flow pass with:
  `php artisan test tests/Feature/AuthModalJoinFormTest.php tests/Feature/SboFeesTest.php tests/Feature/Sbo/CountryComplianceDocumentsTest.php tests/Feature/AdvertiseBannerTest.php tests/Feature/FeedbackPageTest.php`.

## Global Design System

### Issues

- The initial syntax issue in the heading rule and dangling `font` declaration in
  `.navbar-search input` have been fixed.
- Design tokens are inconsistent. For example, `style.css` defines `--dark-color` as
  `#0B2545`, while `account-nav.css` redefines it as `#142A3B`.
- Buttons are globally forced to `border-radius: 0px`, while many newer components use
  rounded pills. This creates an uneven visual language.
- Large amounts of CSS live inside Blade views, which makes similar screens look and
  behave differently.

### Recommendations

- Create a single token layer for color, type, spacing, radius, borders, shadows,
  focus rings, status colors, and motion.
- Use one radius scale: for example, 6px or 8px for cards and inputs, 999px only for
  badges and primary pill CTAs.
- Replace page-level CSS with reusable classes and components:
  - `page-shell`
  - `page-header`
  - `metric-card`
  - `status-chip`
  - `data-table`
  - `form-section`
  - `upload-dropzone`
  - `modal-shell`
  - `premium-cta`
- Remove generic global selectors like `body, *` for font assignment and use a cleaner
  typographic hierarchy.

## Navigation And Menus

### Public Navbar

The public navbar now has Advertise, Give Feedback, Join, and Log in links. The mobile
toggle behavior is much improved, with an internal close button and icon switching.

Recommended refinements:

- Make the mobile collapsed panel feel like a deliberate drawer with stronger spacing,
  larger link targets, and a subtle divider between search and navigation.
- Keep search full-width on mobile and avoid cramped inline controls.
- Ensure the login/register modal redirect context remains visible when a user arrives
  from Advertise or Feedback.

### Authenticated Account Nav

The account offcanvas has the correct menu order and workspace actions, but
`public/css/account-nav.css` uses `margin-left: -40%` for the mobile account trigger.
That is brittle and can break on narrow screens.

Recommended refinements:

- Replace the negative margin with a stable flex or CSS grid layout.
- Move inline menu text styles into account nav classes.
- Add consistent active-state styling for the current page.
- Make the bottom CTA context-aware:
  - `Complete payment` for unpaid SBO owners.
  - `Manage listings` for paid owners.
  - `Go to admin workspace` for dual-role admin owners.

### Admin Sidebar

The admin sidebar is functionally strong and grouped well. It should stay dense and
operational rather than decorative.

Recommended refinements:

- Add collapsible parent sections for mobile and long admin menus.
- Keep current active state visible when a section is collapsed.
- Standardize section headings, icon sizes, and row heights.
- Move page-specific modal scripts out of the admin layout into page components or one
  generic modal bridge.

## Forms

### Issues

- Many important forms do not have explicit helper descriptions:
  - Login and Join modal.
  - Create/Edit business listing modal.
  - Profile and Security modals.
  - SBO registration fee fields.
  - Compliance document uploads.
  - Procurement request modal.
  - Admin users, admins, ads, fees, reports, and document settings.
- Some forms use placeholders instead of labels, especially the Join modal. This is less
  premium and less accessible because context disappears after typing.
- Long modal forms can feel cramped on mobile.

### Recommendations

- Create a Blade form component set:
  - `x-form.group`
  - `x-form.label`
  - `x-form.help-icon`
  - `x-form.input`
  - `x-form.select`
  - `x-form.textarea`
  - `x-form.file`
  - `x-form.error`
- Require every new field to have one of:
  - explicit `data-form-help`, or
  - a component-level `help` prop.
- Keep the pulsing help icon, but make it subtle:
  - pause on hover and focus,
  - respect `prefers-reduced-motion`,
  - consider stopping the pulse after the first interaction.
- Add mobile sticky action bars for long forms like Join, Add Listing, RFQ creation, SBO
  fees, and admin modals.
- Standardize field states: idle, hover, focus, disabled, valid, error, loading.

## Public Website

### Homepage

The homepage has strong content direction but should feel more premium and less
template-like.

Recommended refinements:

- Replace mixed remote/generic icons with a consistent local visual set.
- Use actual registry, seal, business, or platform imagery in key sections.
- Reduce heavy or repetitive scroll animation. Motion should communicate hierarchy, not
  decorate every section.
- Make trust signals prominent above the fold: verified SBOs, registry seal, categories,
  and search.

### Business Directory

The directory is useful but needs stronger mobile ergonomics.

Recommended refinements:

- Convert filters into a mobile filter drawer.
- Show active filter chips below the page title.
- Make sort meaningful or remove inactive sort options.
- Give listing cards a consistent action menu on mobile instead of hiding several actions.
- Improve empty states with one primary action and one secondary recovery path.

### Business Detail

Recommended refinements:

- Make verification, seal, and registration identifiers more visually prominent.
- Standardize cards and remove inline style-driven sections.
- Make Review and Report modals follow the same form component system.
- Keep contact actions sticky or prominent on mobile.

## Advertise Workflow

The Advertise page is one of the strongest newer surfaces. Placement diagrams and exact
banner dimensions are the right direction.

Recommended refinements:

- Add a sticky campaign summary showing:
  - selected placement,
  - desktop and mobile dimensions,
  - duration,
  - price,
  - desired start date,
  - CTA destination,
  - payment state.
- Add explicit helper copy to the Placement field. It currently relies on inferred copy.
- Keep live dimension helper text under Desktop banner and Mobile banner.
- Add upload preview thumbnails before payment.
- Turn recent campaign rows into responsive cards on mobile.
- Make campaign status a clear timeline: Draft, Awaiting payment, Paid, Under review,
  Approved, Active, Expired or Rejected.

## Feedback Workflow

The Feedback page is well aligned with the newer form guidance pattern.

Recommended refinements:

- Add optional screenshot or attachment upload.
- Auto-fill page URL when the user opens feedback from a platform page.
- After submission, show a polished confirmation state with next steps.
- In admin Support, make Feedback a first-class queue with filters for type, priority,
  status, and assignee.

## Account And Listings

The account dashboard is powerful but visually dense.

Recommended refinements:

- Convert tabs into a premium segmented control with horizontal scroll on mobile.
- Use a consistent listing card with:
  - business logo,
  - verification status,
  - category,
  - stats,
  - action menu.
- Add skeleton loading states for Livewire refreshes.
- Keep Profile separate from Listings, but use the same page header and account summary
  pattern on both.

## SBO Onboarding And Payments

### Application Progress

Recommended refinements:

- Present the process as a guided stepper:
  - Application submitted,
  - Registration fees,
  - Required documents,
  - Verified.
- Locked steps should be visible with clear prerequisites.
- Current step should have one primary action and concise next-step copy.

### Registration Fees

The logic is strong, but the UI should feel more guided.

Recommended refinements:

- Add explicit helper copy for:
  - Share structure,
  - Share capital type,
  - Issued Shares Range,
  - Per-share value,
  - Payment reference,
  - Proof of payment.
- Upgrade the fee selection stage into a card-based wizard.
- Make Espees the premium primary payment card with amount, provider, and security copy.
- Keep manual payment collapsed as the fallback.
- Use a consistent success state after payment is confirmed.

### Compliance Documents

The current document upload rows are functional but plain.

Recommended refinements:

- Replace each row with a document card.
- Show required/optional, current file, status, rejection reason, and upload action.
- Use a dropzone style for upload.
- Add progress/loading state while Livewire reads files.

## Procurement And RFQ

Recommended refinements:

- Use a multi-section RFQ form instead of one long modal:
  - Basics,
  - Product details,
  - Service scope,
  - Vendor requirements,
  - Review and submit.
- Keep product/service conditional fields, but animate and label them clearly.
- Add helper descriptions to every RFQ field.
- Convert tables to mobile cards with row action menus.

## Admin Experience

Admin should feel like a quiet operational cockpit: dense, fast, and consistent.

Recommended refinements:

- Standardize all admin tables:
  - toolbar filters,
  - sticky table header,
  - compact status chips,
  - row action menu,
  - empty state,
  - pagination summary.
- Admin Fees should use a sticky subnav:
  - Payment settings,
  - Espees checkout,
  - Capital types,
  - Fee bands,
  - Country overrides.
- Admin Ads should split clearly:
  - Live Ads,
  - Campaign Submissions,
  - Rate Cards.
- Admin Users/Admins should share one modal pattern for create, promote, suspend, delete,
  and permission assignment.
- Confirmation modals should show exactly what will change and who gets notified.

## Motion And Interaction

Recommended refinements:

- Keep premium motion restrained. Avoid too many gradients and blinking effects at once.
- Respect `prefers-reduced-motion` everywhere.
- Use motion primarily for:
  - open/close transitions,
  - step progression,
  - loading,
  - payment emphasis,
  - success confirmation.
- Replace festive or overly playful movement in operational flows with quiet confidence.

## Accessibility And Mobile Standards

Recommended refinements:

- Maintain at least 44px practical touch targets for mobile controls.
- Ensure every focusable element has a visible focus state.
- Do not rely on placeholder-only labels.
- Make modal close buttons and offcanvas controls keyboard obvious.
- Ensure tables have mobile card alternatives where horizontal scroll becomes tedious.
- Keep contrast strong for status badges and helper text.

Useful references:

- WCAG 2.2 Target Size: https://www.w3.org/WAI/WCAG22/Understanding/target-size-minimum.html
- WCAG 2.2 Focus Appearance: https://www.w3.org/WAI/WCAG22/Understanding/focus-appearance.html
- Apple Human Interface Guidelines, Buttons: https://developer.apple.com/design/human-interface-guidelines/buttons
- Material Design 3 Text Fields: https://m3.material.io/components/text-fields

## Workflow Coverage Matrix

The following matrix maps the visible platform workflows to the recommended premium
refinement direction. It is intended to make implementation sequencing easier.

| Area | Current Surface | Refinement Direction |
|---|---|---|
| Public homepage | `/`, `resources/views/home.blade.php` | Strengthen first-viewport trust signals, replace mixed icon art with consistent assets, reduce repeated scroll animation, and make search/category discovery feel deliberate. |
| About page | `/about`, `resources/views/about.blade.php` | Keep the editorial structure, but reduce animation density and align cards, image frames, and section spacing with the shared design system. |
| Public navbar | `components/nav.blade.php` | Keep the improved mobile toggle; polish the collapsed panel as a clean mobile drawer with full-width search, active states, and consistent touch targets. |
| Auth account nav | `components/account-nav.blade.php`, `public/css/account-nav.css` | Remove brittle negative mobile margins, unify active states, move inline text styles into classes, and make the workspace CTA context-aware. |
| Footer | `components/footer.blade.php` | Keep ad/footer visibility rules, but move inline footer styles into shared CSS and standardize footer CTAs with the button system. |
| Business directory | `/businesses`, `businesses/index.blade.php`, `public/css/lists.css` | Add mobile filter drawer, active filter chips, better sort behavior, mobile listing action menu, and polished empty states. |
| Business detail | `/business/{slug}`, `businesses/show.blade.php` | Reduce inline styles, elevate verification/seal signals, make contact actions sticky on mobile, and standardize review/report modals. |
| Login modal | `livewire/auth-modal.blade.php` | Add real labels/helper copy, improve password field styling, preserve redirect intent messaging, and align modal shell with the shared component. |
| Join/SBO signup | `livewire/auth-modal.blade.php` | Convert placeholder-led fields into labeled fields, keep the stepper sticky on mobile, add field help, and make auto-approval/payment-next copy more visible. |
| Forgot/reset password | `livewire/auth/forgot-password.blade.php`, `reset-password.blade.php` | Bring starter-kit forms into the same Loveworld form system, including helper icons, focus rings, and page shell. |
| Profile page | `/profile`, `account/profile.blade.php`, `livewire/profile-modal.blade.php` | Keep profile separate from listings; add helper copy for profile photo/KC handle, standardize avatar preview, and polish modal spacing on mobile. |
| Listings dashboard | `/dashboard`, `dashboard.blade.php`, `create-business-modal.blade.php` | Replace heavy inline styles with listing components, convert tabs to a mobile segmented control, and use a guided Add Listing form with sticky submit. |
| Favorites/recent views | `/dashboard#favorites`, `/dashboard#recently-viewed` | Use consistent saved-list cards and empty states with one primary action. |
| SBO dashboard | `/sbo/dashboard`, `livewire/sbo/dashboard.blade.php` | Keep the overview cards but reduce playful/festive animation, make payment/progress CTAs feel premium and registry-grade. |
| Application list | `/sbo/applications` | Use compact application cards with status timeline, payment state, documents state, and one next-step CTA. |
| Application progress | `/sbo/applications/{sbo}/progress` | Present the four-step flow as a guided wizard with completed/current/locked states and direct next-action panels. |
| Registration fees | `livewire/sbo-fees.blade.php` | Turn fee selection into a card wizard, make manual transfer and proof upload the sole payment path, and keep the instructions prominent. |
| Compliance documents | `livewire/sbo-compliance-documents.blade.php` | Replace plain rows with document cards/dropzones showing requirement, status, rejection reason, current file, and upload progress. |
| Payment required gate | `/sbo/complete-payment-required`, `sbo/payment-required.blade.php` | Keep the full-page block, but align motion, iconography, and CTA styling with the payment system. |
| Procurement/RFQs | `/my-rfqs`, `livewire/sbo/rfqs/index.blade.php` | Split the long RFQ modal into sections, add helper copy, improve conditional fields, and render mobile RFQ cards. |
| Support enquiry | `/support`, `livewire/support/index.blade.php` | Add explicit field help, optional attachment, and a clearer success/expected-response state. |
| Feedback | `/feedback`, `feedback.blade.php`, `admin/support/feedback.blade.php` | Add optional screenshot upload, auto-fill source page when possible, and polish admin queue filters/status handling. |
| Advertise discovery | `/advertise`, `advertise.blade.php` | Keep diagrams, but add selected-placement summary, sticky price panel, campaign preview, and mobile campaign cards. |
| Advertise campaign builder | `/advertise/campaigns`, `AdCampaignController` | Keep exact dimension validation; add placement helper copy, live creative preview, and visible status timeline after submission. |
| Ad payment | `advertise/campaigns/{campaign}/pay`, `AdCampaignPaymentController` | Match the SBO Espees trust pattern: clear amount, provider, confirmation behavior, retry guidance, and failure states. |
| Ad click tracking | `/go/ad/{ad}`, `AdClickController` | Keep behavior invisible to users; ensure ad click targets feel trustworthy with consistent CTA styling. |
| Public SBO verification | `/verify/sbo/{number}`, `sbo/verify.blade.php` | Make this page ceremonial and trust-heavy: seal, registration number, status, verification timestamp, and share/download CTA. |
| Admin dashboard | `/admin`, `livewire/admin/dashboard.blade.php` | Keep operational density, but standardize metric cards, pending queues, and shortcut actions. |
| Admin users | `/admin/users`, `livewire/admin/users/index.blade.php` | Standardize table toolbar, user status chips, suspend/delete/promote actions, and Add User modal helper copy. |
| Admin admins | `/admin/admins`, `livewire/admin/admins/index.blade.php` | Keep create/promote tabs, but improve capability selection as grouped permission cards with clear presets. |
| Admin businesses | `/admin/businesses`, `/admin/businesses/{business}` | Use consistent table/list shells, verification badges, row actions, and detail-page review panels. |
| Admin organizations | `/admin/organizations`, `/admin/sbos/{sbo}` | Preserve workflow depth, but use sticky review summary, section navigation, and consistent approval/info request modals. |
| Admin approvals | `/admin/approvals/*` | Treat approvals as a parent review center with shared filters, evidence preview, confirmation modals, and notification summaries. |
| Admin fees | `/admin/fees`, `livewire/admin/fees/index.blade.php` | Add sticky subnav for payment settings, Espees, capital types, bands, and country overrides; add helper copy to all settings. |
| Admin compliance docs | `/admin/compliance/documents` | Make document-type setup card-based with country/scope chips, active state, and clear required/optional behavior. |
| Admin ads | `/admin/ads`, `livewire/admin/ads/index.blade.php` | Keep Live Ads, Campaign Submissions, Rate Cards; add visual preview, mobile table cards, and helper copy in ad modal/rate fields. |
| Admin support | `/admin/support`, `/admin/support/feedback` | Standardize enquiry and feedback queues with priority/status filters, assignment, notes, and response history. |
| Admin purchase orders | `/admin/purchase-orders` | Align procurement tables and creation modal with the RFQ component system. |
| Admin reports | `/admin/reports` | Treat reports as dashboard tools: filters first, chart/table switcher, export actions, and clear loading/empty states. |
| Admin categories/locations | `/admin/categories-locations` | Use compact management cards and consistent rename/delete confirmation patterns. |
| Admin zones | `/admin/zones/referrals`, `/admin/zones/momentum` | Use consistent analytics cards, filters, and drill-down tables. |
| Settings | `/settings/profile`, `/settings/password`, `/settings/appearance` | Either restyle starter-kit screens to match Loveworld or keep them hidden behind the custom Profile page to avoid visual mismatch. |

## Technical And Maintenance Polish

- `php artisan route:list` currently emits PHP 8.5 deprecation warnings for
  `PDO::MYSQL_ATTR_SSL_CA` in database configuration. This does not directly affect
  visual quality, but cleaning it up improves developer confidence and production log
  hygiene.
- The admin route list lives in `routes/admin.php`; keep future admin pages registered
  there so the sidebar, permissions, and route naming stay predictable.
- Several layouts include page-specific JavaScript for modal cleanup. Consolidate this
  into a small reusable modal/offcanvas helper.
- Prefer local assets over remote icon URLs so the UI remains reliable and visually
  consistent.

## Suggested Implementation Phases

### Phase 1: Foundation

- Fix CSS syntax issues.
- Create shared design tokens.
- Replace brittle mobile nav spacing.
- Build reusable form components.
- Add explicit helper copy to critical forms.

### Phase 2: High-Traffic User Flows

- Polish Join/Login.
- Redesign SBO registration fees as a guided payment flow.
- Upgrade compliance document cards.
- Refine Advertise campaign builder and Feedback confirmation.
- Improve directory mobile filters and business detail trust signals.

### Phase 3: Admin Polish

- Standardize admin tables, filters, status chips, and row actions.
- Convert complex admin pages into tabbed or subnav-driven workflows.
- Consolidate modal event scripts and confirmation patterns.

### Phase 4: Quality Pass

- Mobile screenshot review for public, account, SBO, and admin pages.
- Keyboard-only pass for menus, modals, and forms.
- Contrast and touch-target pass.
- Loading and empty-state pass.
- Reduce animation density and verify reduced-motion behavior.

## Immediate Quick Wins

1. Fix the CSS syntax errors in `public/css/style.css`.
2. Remove `margin-left: -40%` from the mobile account nav trigger.
3. Add explicit `data-form-help` to Join, Create Listing, SBO Fees, Compliance Documents,
   RFQ, Admin Fees, Admin Ads, and Admin Users.
4. Move repeated inline styles into shared component classes.
5. Add mobile filter drawer to the business directory.
6. Convert compliance document rows to document upload cards.
7. Make Espees the strongest visual payment path and manual payment a quieter fallback.
8. Standardize admin table rows, badges, and action menus.
