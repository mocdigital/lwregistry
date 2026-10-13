# Registration Fees — Plain-English Guide

This guide explains how an SBO (Small Business Owner) pays its registration fee, and
how an administrator confirms that payment. It is written for non-technical readers.
No jargon is assumed — every term is explained in the glossary at the end.

There are two sides to the process:

1. **The SBO side** — the business owner works out their fee, pays it, and uploads
   proof.
2. **The Admin side** — an administrator at the Ministry of Commerce (MOC) checks the
   proof and confirms the payment.

Uploading proof does **not** automatically mark a fee as paid. A human administrator
always has the final say.

---

## Part 1 — The SBO's view (paying the fee)

The SBO owner does this from their dashboard, under the **"Registration fees"**
section of their application progress.

### Step 1 — Choose your company structure
The owner selects what kind of company they run (the **share structure**). For example:
- **Private company** — shares are owned privately (has share capital).
- **Public company** — shares can be offered to the public (has share capital).
- **No shares / limited by guarantee** — pays a single flat fee.
- **Sole proprietor / partnership** — pays a single flat fee.

The first two have **share capital**, so their fee depends on the size of that capital.
The last two do not, so they simply pay a fixed **flat fee**.

### Step 2 — Enter your share details (only for share companies)
If the company has shares, the owner enters three things:
- **Share capital type** — the class of shares (e.g. Ordinary Shares, Preference Shares).
- **Volume of issued shares** — how many shares the company has issued (e.g. 1,000).
- **Per-share value** — what each share is worth (e.g. 100.00).

The system multiplies these two numbers together to get the **equity size**
(issued shares × per-share value). This equity size decides which fee **band** the
company falls into.

*(A company with no shares skips this step entirely.)*

### Step 3 — See your fee
As soon as enough detail is entered, the system instantly shows:
- The **estimated equity size** (for share companies), and
- The **registration fee** in the chosen currency (e.g. Espees).

The fee is calculated on the system's side, not typed in by the owner — so the amount
can always be trusted.

### Step 4 — Confirm and continue
The owner clicks **"Confirm & continue to payment."** This locks in the fee and moves
them to the payment screen. They can still click **"Change selection"** to go back and
adjust — right up until they've submitted proof.

### Step 5 — Make the payment
The payment screen shows:
- The **amount due**,
- The **band** it falls under (for reference), and
- **How to pay**: transfer the amount to the named **Espees Merchant Account** using the
  named payment app.

There is no native **Pay with Espees** checkout during SBO onboarding. Manual transfer and
proof upload are the only registration payment method.

The owner makes this transfer themselves, outside the platform, using their own Espees
account.

### Step 6 — Upload proof of payment
Back on the platform, the owner:
- Optionally enters a **payment reference** (the transaction ID from their transfer), and
- Uploads a **proof of payment** file — a receipt or screenshot (PDF or JPG, up to 5 MB).

When they click **"Submit proof,"** the system:
- Saves the file,
- Marks the payment as **"submitted — awaiting confirmation,"** and
- Emails the administrators to let them know a payment is waiting for review.

The owner then sees a message: *"Proof submitted — awaiting administrator confirmation."*
Their fee still shows as **unpaid** at this stage. If they made a mistake, they can
**replace the proof** with a new file until an admin confirms it.

---

## Part 2 — The Admin's view (confirming the payment)

An administrator with the right permission (approving SBOs) handles this from the SBO's
detail page in the admin area.

### Step 1 — Get notified
When an SBO submits proof, the system automatically **emails the admins**. The email
includes the SBO's name, the fee amount, the structure, and the payment reference.

### Step 2 — Open the SBO and review the proof
On the SBO's page, the admin sees a **"Proof of payment"** panel showing:
- A **fee breakdown** — the structure, share details, equity size, the fee, and the band.
- The uploaded **proof file** (which the admin can open and view).
- A **status badge**: *Not uploaded*, *Pending review*, *Approved*, or *Rejected*.

The admin opens the file and checks it against the amount owed.

### Step 3 — Approve or reject

**If the payment is genuine → Approve:**
The admin clicks **"Approve payment."** The system then:
- Marks the proof as **approved**,
- Marks the SBO's registration fee as **paid**,
- Records who approved it and when,
- Logs the action in the activity history, and
- Emails the owner to confirm their fees are paid.

**If something is wrong → Reject:**
The admin clicks **"Reject,"** enters a **reason** (required), and confirms. The system:
- Marks the proof as **rejected** with that reason,
- Records who rejected it and when, and
- Logs the action.

The fee stays **unpaid**, and the owner can upload a corrected proof.

### Step 4 — Full verification
Paying the fee is one of **three independent checks** an SBO must clear to become fully
**Verified**:
1. Registration **approved** by an admin,
2. Required **documents** confirmed compliant, and
3. Registration **fees paid**.

Once all three are done, the SBO becomes **Verified**. Until then it stays
**Provisional**.

---

## How the fee amounts are set (Admin-managed)

Administrators control the whole fee schedule from the **Registration Fees** admin page —
no developer or code change is needed. From there they can set:
- The **flat fee** (for companies with no shares),
- The **currency** (e.g. Espees),
- The **Espees Merchant Account** and payment app details, and
- The **fee bands** and **share-capital types** for each share structure.

### Country-specific fees
The registry serves many countries. Each fee setting has two levels:
- A **Global default** — applies everywhere by default.
- A **country override** — a specific country can have its own fees.

If a country hasn't been given its own fees, it simply uses the global default. When an
SBO pays, the system automatically uses that SBO's country's fees if they exist, and
falls back to the global default if they don't. Admins choose which country they're
editing using a country switcher at the top of the Fees page.

---

## Glossary — what each term means

| Term | Plain meaning |
|---|---|
| **SBO** | Small Business Owner — a registered business on the platform. |
| **MOC** | Ministry of Commerce (Loveworld Nation) — the body running the registry. |
| **Share structure** | The type of company (private, public, no-shares, or sole/partnership). |
| **Share capital** | Money raised by a company by issuing shares. Only some structures have it. |
| **Share capital type** | The class of shares, e.g. Ordinary or Preference Shares. |
| **Issued shares (volume)** | How many shares the company has actually given out. |
| **Per-share value** | The value of a single share. |
| **Equity size** | Issued shares × per-share value. Used to decide the fee band. |
| **Fee band** | A price bracket. Bigger equity falls into a higher band with a higher fee. |
| **Flat fee** | A single fixed fee for companies that don't have shares. |
| **Registration fee** | The total amount the SBO must pay to register. |
| **Currency** | The unit fees are charged in (e.g. Espees). |
| **Espees** | The digital currency used for payment. |
| **Merchant Account** | The account the SBO transfers the fee to. |
| **Payment reference** | The transaction ID from the owner's transfer (optional). |
| **Proof of payment** | The receipt/screenshot the owner uploads (PDF or JPG). |
| **Submitted / Pending review** | Proof uploaded, but not yet confirmed by an admin. |
| **Approved** | An admin has confirmed the payment; fees are now marked paid. |
| **Rejected** | An admin has declined the proof, with a reason; fee stays unpaid. |
| **Provisional** | Registration not yet fully complete. |
| **Verified** | Fully complete: approved + documents compliant + fees paid. |
| **Global default** | The fee schedule used everywhere unless a country overrides it. |
| **Country override** | A country-specific fee schedule that replaces the global default there. |

---

### The process in one line
**SBO:** pick structure → see fee → make a manual Espees transfer → upload proof.
**Admin:** get notified → review proof → approve (fees marked paid) or reject (with a reason).
