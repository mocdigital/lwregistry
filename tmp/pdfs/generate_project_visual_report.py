from reportlab.lib import colors
from reportlab.lib.pagesizes import landscape, A4
from reportlab.lib.styles import ParagraphStyle, getSampleStyleSheet
from reportlab.lib.units import mm
from reportlab.pdfbase.pdfmetrics import stringWidth
from reportlab.platypus import Paragraph
from reportlab.pdfgen import canvas

from pathlib import Path


ROOT = Path("/Applications/MAMP/htdocs/lw_registry")
OUT_DIR = ROOT / "output" / "pdf"
TMP_DIR = ROOT / "tmp" / "pdfs"
PAGES_DIR = TMP_DIR / "pages"
FINAL_PDF = OUT_DIR / "lw-registry-structured-project-brief.pdf"

PAGE_W, PAGE_H = landscape(A4)

NAVY = colors.HexColor("#0F2747")
NAVY_DARK = colors.HexColor("#0A1930")
GREEN = colors.HexColor("#1D7A55")
MINT = colors.HexColor("#6FD4A7")
SAND = colors.HexColor("#F5F2EA")
SAND_DEEP = colors.HexColor("#EAE4D7")
WHITE = colors.white
INK = colors.HexColor("#102033")
SLATE = colors.HexColor("#5B6472")
LINE = colors.HexColor("#D7DDE5")
BLUE_SOFT = colors.HexColor("#E8F0FA")
GREEN_SOFT = colors.HexColor("#E8F6F0")
AMBER_SOFT = colors.HexColor("#FBF2DE")
RED_SOFT = colors.HexColor("#F8E6E4")
RISK = colors.HexColor("#A43A35")
WARNING = colors.HexColor("#C88A1B")

MARGIN_X = 18 * mm
MARGIN_Y = 12 * mm

styles = getSampleStyleSheet()
TITLE = ParagraphStyle(
    "Title",
    parent=styles["Normal"],
    fontName="Helvetica-Bold",
    fontSize=28,
    leading=30,
    textColor=NAVY,
)
SUBTITLE = ParagraphStyle(
    "Subtitle",
    parent=styles["Normal"],
    fontName="Helvetica",
    fontSize=12.5,
    leading=15,
    textColor=SLATE,
)
BODY = ParagraphStyle(
    "Body",
    parent=styles["Normal"],
    fontName="Helvetica",
    fontSize=10.5,
    leading=13.5,
    textColor=INK,
)
BODY_SMALL = ParagraphStyle(
    "BodySmall",
    parent=BODY,
    fontSize=9,
    leading=11.5,
)
BODY_WHITE = ParagraphStyle(
    "BodyWhite",
    parent=BODY,
    textColor=WHITE,
)
BODY_WHITE_SMALL = ParagraphStyle(
    "BodyWhiteSmall",
    parent=BODY_WHITE,
    fontSize=8.5,
    leading=10,
)
SMALL_LABEL = ParagraphStyle(
    "SmallLabel",
    parent=styles["Normal"],
    fontName="Helvetica-Bold",
    fontSize=8.5,
    leading=10,
    textColor=SLATE,
)
CARD_TITLE = ParagraphStyle(
    "CardTitle",
    parent=styles["Normal"],
    fontName="Helvetica-Bold",
    fontSize=12,
    leading=14,
    textColor=NAVY,
)
CARD_TITLE_WHITE = ParagraphStyle(
    "CardTitleWhite",
    parent=CARD_TITLE,
    textColor=WHITE,
)


def draw_paragraph(c, text, style, x, y_top, width, height):
    p = Paragraph(text, style)
    w, h = p.wrap(width, height)
    p.drawOn(c, x, y_top - h)
    return h


def round_rect(c, x, y, w, h, fill, stroke=LINE, radius=10, stroke_width=1):
    c.setFillColor(fill)
    c.setStrokeColor(stroke)
    c.setLineWidth(stroke_width)
    c.roundRect(x, y, w, h, radius, stroke=1 if stroke_width > 0 else 0, fill=1)


def pill(c, x, y, w, h, text, fill, text_color, font="Helvetica-Bold", font_size=9):
    round_rect(c, x, y, w, h, fill, stroke=fill, radius=h / 2, stroke_width=0)
    c.setFillColor(text_color)
    c.setFont(font, font_size)
    tw = stringWidth(text, font, font_size)
    c.drawString(x + (w - tw) / 2, y + (h - font_size) / 2 + 1, text)


def arrow(c, x1, y1, x2, y2, color=NAVY, width=1.6):
    c.setStrokeColor(color)
    c.setLineWidth(width)
    c.line(x1, y1, x2, y2)
    import math

    angle = math.atan2(y2 - y1, x2 - x1)
    ah = 7
    ax1 = x2 - ah * math.cos(angle - math.pi / 6)
    ay1 = y2 - ah * math.sin(angle - math.pi / 6)
    ax2 = x2 - ah * math.cos(angle + math.pi / 6)
    ay2 = y2 - ah * math.sin(angle + math.pi / 6)
    c.line(x2, y2, ax1, ay1)
    c.line(x2, y2, ax2, ay2)


def footer(c, page_num, source_text, dark=False):
    line_color = colors.HexColor("#8DA0BA") if dark else LINE
    text_color = colors.HexColor("#DDE7F4") if dark else SLATE
    page_color = WHITE if dark else NAVY
    c.setStrokeColor(line_color)
    c.setLineWidth(0.6)
    c.line(MARGIN_X, 9 * mm, PAGE_W - MARGIN_X, 9 * mm)
    c.setFillColor(text_color)
    c.setFont("Helvetica", 8.5)
    c.drawString(MARGIN_X, 4.6 * mm, source_text)
    c.setFillColor(page_color)
    c.setFont("Helvetica-Bold", 10)
    c.drawRightString(PAGE_W - MARGIN_X, 4.4 * mm, f"{page_num:02d}")


def page_title(c, title, subtitle, dark=False):
    title_style = TITLE.clone("darkTitle") if dark else TITLE
    if dark:
        title_style.textColor = WHITE
    subtitle_style = SUBTITLE.clone("darkSub") if dark else SUBTITLE
    if dark:
        subtitle_style.textColor = colors.HexColor("#DDE7F4")
    draw_paragraph(c, title, title_style, MARGIN_X, PAGE_H - 18 * mm, PAGE_W - 2 * MARGIN_X, 30 * mm)
    draw_paragraph(c, subtitle, subtitle_style, MARGIN_X, PAGE_H - 33 * mm, PAGE_W - 2 * MARGIN_X, 18 * mm)


def numbered_chip(c, x, y, n, fill=NAVY):
    c.setFillColor(fill)
    c.circle(x + 10, y + 10, 10, fill=1, stroke=0)
    c.setFillColor(WHITE)
    c.setFont("Helvetica-Bold", 8.5)
    tw = stringWidth(str(n), "Helvetica-Bold", 8.5)
    c.drawString(x + 10 - tw / 2, y + 7, str(n))


def add_bullet_list(c, items, x, y_top, width, style=BODY, bullet_color=NAVY):
    current = y_top
    for item in items:
        c.setFillColor(bullet_color)
        c.circle(x + 3, current - 5, 2.2, fill=1, stroke=0)
        h = draw_paragraph(c, item, style, x + 10, current, width - 10, 40 * mm)
        current -= max(h, 10) + 4
    return current


def cover_page(c):
    c.setFillColor(SAND)
    c.rect(0, 0, PAGE_W, PAGE_H, stroke=0, fill=1)
    c.setFillColor(BLUE_SOFT)
    c.circle(PAGE_W - 58 * mm, PAGE_H - 34 * mm, 24 * mm, fill=1, stroke=0)
    c.setFillColor(GREEN_SOFT)
    c.circle(PAGE_W - 30 * mm, PAGE_H - 62 * mm, 17 * mm, fill=1, stroke=0)

    pill(c, MARGIN_X, PAGE_H - 22 * mm, 58 * mm, 9 * mm, "Structured export", GREEN_SOFT, GREEN)
    draw_paragraph(
        c,
        "LW Registry<br/>Project Visual Brief",
        TITLE.clone("coverTitle"),
        MARGIN_X,
        PAGE_H - 34 * mm,
        110 * mm,
        40 * mm,
    )
    draw_paragraph(
        c,
        "A cleaned, visual PDF version of the project review conversation. "
        "This report explains how the platform is structured, how the system connects, "
        "how each role moves through it, and how the modules link together.",
        SUBTITLE,
        MARGIN_X,
        PAGE_H - 67 * mm,
        120 * mm,
        34 * mm,
    )

    round_rect(c, PAGE_W - 95 * mm, PAGE_H - 88 * mm, 78 * mm, 64 * mm, WHITE, LINE, radius=14)
    draw_paragraph(c, "What this PDF contains", CARD_TITLE, PAGE_W - 89 * mm, PAGE_H - 32 * mm, 64 * mm, 8 * mm)
    add_bullet_list(
        c,
        [
            "System architecture and stack",
            "Platform connection map",
            "Public, SBO manager, and admin flow maps",
            "Module landscape and dependencies",
            "Workflow and event connections",
        ],
        PAGE_W - 89 * mm,
        PAGE_H - 40 * mm,
        64 * mm,
        style=BODY_SMALL,
        bullet_color=GREEN,
    )

    round_rect(c, MARGIN_X, 30 * mm, PAGE_W - 2 * MARGIN_X, 46 * mm, WHITE, LINE, radius=14)
    draw_paragraph(c, "Executive summary", CARD_TITLE, MARGIN_X + 8 * mm, 70 * mm, 60 * mm, 8 * mm)
    add_bullet_list(
        c,
        [
            "The platform is already a substantial Laravel 12 web system, not a prototype. It handles public discovery, SBO onboarding, payments, procurement, support, and admin operations.",
            "Its architecture is centered on one application, one database, one media store, and queued mail/notification flows.",
            "Each role enters through a different route, but the workflows converge on the same approval, payment, document, and verification core.",
            "The module structure is capability-based, so the clearest way to understand the platform is to read it as connected journeys rather than isolated screens.",
        ],
        MARGIN_X + 8 * mm,
        63 * mm,
        PAGE_W - 2 * MARGIN_X - 16 * mm,
        style=BODY,
        bullet_color=NAVY,
    )
    footer(c, 1, "Sources: PRODUCT.md, docs/platform-architecture.md, composer.json, package.json")


def system_page(c):
    c.setFillColor(WHITE)
    c.rect(0, 0, PAGE_W, PAGE_H, stroke=0, fill=1)
    page_title(
        c,
        "System at a glance",
        "The project works as one connected web platform: roles come in through the app, the app writes to the database and files, and queued notifications push outward.",
    )

    y = 110 * mm
    role_w = 42 * mm
    role_h = 18 * mm
    xs = [MARGIN_X, MARGIN_X + 50 * mm, MARGIN_X + 100 * mm]
    roles = [
        ("Public", BLUE_SOFT, NAVY),
        ("SBO manager", GREEN_SOFT, GREEN),
        ("Admin", AMBER_SOFT, WARNING),
    ]
    for idx, (label, fill, text_col) in enumerate(roles):
        round_rect(c, xs[idx], y, role_w, role_h, fill, LINE, radius=10)
        draw_paragraph(c, label, CARD_TITLE, xs[idx] + 6 * mm, y + 14 * mm, role_w - 12 * mm, 8 * mm)

    app_x = 135 * mm
    app_y = 92 * mm
    app_w = 72 * mm
    app_h = 40 * mm
    round_rect(c, app_x, app_y, app_w, app_h, NAVY, NAVY, radius=16, stroke_width=0)
    draw_paragraph(c, "LW Registry web app", CARD_TITLE_WHITE, app_x + 8 * mm, app_y + 30 * mm, 56 * mm, 10 * mm)
    draw_paragraph(c, "Laravel 12 + Livewire + Volt + Blade", BODY_WHITE, app_x + 8 * mm, app_y + 20 * mm, 58 * mm, 10 * mm)

    for idx in range(3):
        arrow(c, xs[idx] + role_w, y + role_h / 2, app_x, app_y + app_h / 2, color=GREEN if idx == 1 else NAVY)

    lower_y = 34 * mm
    blocks = [
        ("Application database", "Core records, approvals, RFQs, bids, users, notifications", app_x - 16 * mm, lower_y, 54 * mm),
        ("Media storage", "Compliance docs, seals, payment proofs, business media", app_x + 44 * mm, lower_y, 54 * mm),
        ("Queue and mail", "Notifications, mail dispatch, async events", app_x + 104 * mm, lower_y, 54 * mm),
    ]
    for label, body, x, by, bw in blocks:
        round_rect(c, x, by, bw, 25 * mm, SAND, LINE, radius=12)
        draw_paragraph(c, label, CARD_TITLE, x + 5 * mm, by + 18 * mm, bw - 10 * mm, 8 * mm)
        draw_paragraph(c, body, BODY_SMALL, x + 5 * mm, by + 11 * mm, bw - 10 * mm, 12 * mm)
        arrow(c, app_x + app_w / 2, app_y, x + bw / 2, by + 25 * mm, color=NAVY)

    round_rect(c, PAGE_W - 83 * mm, 42 * mm, 64 * mm, 72 * mm, WHITE, LINE, radius=14)
    draw_paragraph(c, "Stack snapshot", CARD_TITLE, PAGE_W - 77 * mm, 108 * mm, 52 * mm, 10 * mm)
    stack_items = [
        "Backend: PHP 8.4, Laravel 12, Reverb, Spatie Media Library",
        "Frontend: Blade, Livewire, Volt, Tailwind CSS 4, DaisyUI, Vite",
        "Dev tooling: Pest, Rector, Pint, Larastan",
    ]
    add_bullet_list(c, stack_items, PAGE_W - 77 * mm, 100 * mm, 52 * mm, style=BODY_SMALL, bullet_color=GREEN)

    footer(c, 2, "Sources: docs/platform-architecture.md, composer.json, package.json, routes/web.php")


def role_flows_page(c):
    c.setFillColor(SAND)
    c.rect(0, 0, PAGE_W, PAGE_H, stroke=0, fill=1)
    page_title(
        c,
        "Role-by-role flow map",
        "The same platform serves three very different journeys. The main difference is the entry point and the workflow rules applied after login.",
    )

    lane_y = 30 * mm
    lane_h = 114 * mm
    lane_w = (PAGE_W - 2 * MARGIN_X - 12 * mm * 2) / 3
    lane_xs = [MARGIN_X, MARGIN_X + lane_w + 12 * mm, MARGIN_X + 2 * (lane_w + 12 * mm)]
    lane_info = [
        ("Public", BLUE_SOFT, NAVY, [
            "Search directory",
            "Open business profile",
            "Verify SBO number",
            "Click ads or send enquiry",
            "Optionally log in to review or report",
        ]),
        ("SBO manager", GREEN_SOFT, GREEN, [
            "Join and create account",
            "Application is approved",
            "Complete fee payment / proof",
            "Upload compliance documents",
            "Become verified, then use RFQs and network",
        ]),
        ("Admin", AMBER_SOFT, WARNING, [
            "Open admin dashboard",
            "Review SBOs, payments, docs",
            "Respond to support and procurement",
            "Manage users, ads, categories, reports",
            "Escalate or finalize decisions",
        ]),
    ]
    for idx, (title, fill, accent, steps) in enumerate(lane_info):
        x = lane_xs[idx]
        round_rect(c, x, lane_y, lane_w, lane_h, WHITE, LINE, radius=14)
        pill(c, x + 7 * mm, lane_y + lane_h - 12 * mm, 34 * mm, 8 * mm, title, fill, accent)
        draw_paragraph(c, f"{title} journey", CARD_TITLE, x + 7 * mm, lane_y + lane_h - 16 * mm, lane_w - 14 * mm, 8 * mm)
        sy = lane_y + lane_h - 30 * mm
        for step_no, step in enumerate(steps, start=1):
            round_rect(c, x + 7 * mm, sy - (step_no - 1) * 18 * mm, lane_w - 14 * mm, 12 * mm, fill, LINE, radius=8)
            numbered_chip(c, x + 9 * mm, sy - (step_no - 1) * 18 * mm + 1 * mm, step_no, fill=accent)
            draw_paragraph(c, step, BODY_SMALL, x + 20 * mm, sy - (step_no - 1) * 18 * mm + 10 * mm, lane_w - 33 * mm, 8 * mm)
            if step_no < len(steps):
                arrow(c, x + lane_w / 2, sy - (step_no - 1) * 18 * mm, x + lane_w / 2, sy - step_no * 18 * mm + 11 * mm, color=accent)

    footer(c, 3, "Sources: routes/web.php, SboApplicationProgress.php, SBO dashboard/application views")


def modules_page(c):
    c.setFillColor(WHITE)
    c.rect(0, 0, PAGE_W, PAGE_H, stroke=0, fill=1)
    page_title(
        c,
        "Module landscape",
        "The codebase is organized around business capabilities, not isolated microservices. Most future API work can follow these same module boundaries.",
    )

    groups = [
        ("Public registry", ["Home / search", "Business directory", "Business profile", "SBO verification", "Ads"], BLUE_SOFT),
        ("Auth and account", ["Auth modal", "Email verification", "Dashboard", "Profile / password", "Notifications"], GREEN_SOFT),
        ("SBO registration", ["Join form", "Application progress", "Compliance docs", "Payment proof", "Final verification"], GREEN_SOFT),
        ("Vendor / business", ["Business listing", "Products / services", "Reviews", "Favorites", "Views"], SAND),
        ("Procurement", ["RFQ creation", "Vendor discovery", "Bid submission", "Award / completion"], AMBER_SOFT),
        ("Support", ["Support form", "Admin support inbox", "Respond / done / escalate"], BLUE_SOFT),
        ("Admin backend", ["Users", "SBOs", "Businesses", "Required docs", "Reports", "Activity logs", "Ads"], AMBER_SOFT),
    ]

    positions = [
        (MARGIN_X, 112 * mm, 58 * mm, 34 * mm),
        (MARGIN_X + 64 * mm, 112 * mm, 58 * mm, 34 * mm),
        (MARGIN_X + 128 * mm, 112 * mm, 58 * mm, 34 * mm),
        (MARGIN_X + 192 * mm, 112 * mm, 58 * mm, 34 * mm),
        (MARGIN_X + 32 * mm, 62 * mm, 66 * mm, 36 * mm),
        (MARGIN_X + 108 * mm, 62 * mm, 62 * mm, 36 * mm),
        (MARGIN_X + 180 * mm, 58 * mm, 74 * mm, 42 * mm),
    ]

    for (title, items, fill), (x, y, w, h) in zip(groups, positions):
        round_rect(c, x, y, w, h, fill, LINE, radius=12)
        draw_paragraph(c, title, CARD_TITLE, x + 4 * mm, y + h - 6 * mm, w - 8 * mm, 8 * mm)
        add_bullet_list(c, items, x + 4 * mm, y + h - 12 * mm, w - 8 * mm, style=BODY_SMALL, bullet_color=GREEN if fill == GREEN_SOFT else NAVY)

    # Connector lines toward procurement and admin
    arrow(c, MARGIN_X + 29 * mm, 112 * mm, MARGIN_X + 65 * mm, 98 * mm, color=NAVY)
    arrow(c, MARGIN_X + 93 * mm, 112 * mm, MARGIN_X + 100 * mm, 98 * mm, color=GREEN)
    arrow(c, MARGIN_X + 157 * mm, 112 * mm, MARGIN_X + 137 * mm, 98 * mm, color=GREEN)
    arrow(c, MARGIN_X + 221 * mm, 112 * mm, MARGIN_X + 213 * mm, 100 * mm, color=WARNING)

    round_rect(c, MARGIN_X, 20 * mm, PAGE_W - 2 * MARGIN_X, 18 * mm, NAVY, NAVY, radius=10, stroke_width=0)
    draw_paragraph(c, "What this means: the platform already has clear functional seams, those seams can map to API resources later, and most coupling today is workflow- and state-driven rather than service-driven.", BODY_WHITE, MARGIN_X + 5 * mm, 32 * mm, PAGE_W - 2 * MARGIN_X - 10 * mm, 14 * mm)

    footer(c, 4, "Sources: docs/platform-architecture.md, routes/web.php")


def lifecycle_page(c):
    c.setFillColor(SAND)
    c.rect(0, 0, PAGE_W, PAGE_H, stroke=0, fill=1)
    page_title(
        c,
        "How the platform works end-to-end",
        "The live code path is: register, approval, payment, document compliance, verification, then wider platform access.",
    )

    steps = [
        ("Join", "User creates account and submits SBO registration"),
        ("Approval", "Admins review the application and approve it into the paid workflow"),
        ("Payment", "Fee is confirmed through Espees or manual proof review"),
        ("Documents", "After payment confirmation, the owner uploads required compliance files"),
        ("Verification", "If all conditions are met, the SBO becomes verified"),
        ("Operate", "Verified SBO can post RFQs, join the network, and appear as trusted"),
    ]
    x = MARGIN_X
    y = 88 * mm
    box_w = 40 * mm
    gap = 7 * mm
    for idx, (title, body) in enumerate(steps, start=1):
        bx = x + (idx - 1) * (box_w + gap)
        fill = GREEN_SOFT if idx in (1, 3, 5) else WHITE
        round_rect(c, bx, y, box_w, 36 * mm, fill, LINE, radius=12)
        numbered_chip(c, bx + 3 * mm, y + 29 * mm, idx, fill=GREEN if idx in (1, 3, 5) else NAVY)
        draw_paragraph(c, title, CARD_TITLE, bx + 7 * mm, y + 31 * mm, box_w - 10 * mm, 8 * mm)
        draw_paragraph(c, body, BODY_SMALL, bx + 4 * mm, y + 22 * mm, box_w - 8 * mm, 20 * mm)
        if idx < len(steps):
            arrow(c, bx + box_w, y + 18 * mm, bx + box_w + gap - 1 * mm, y + 18 * mm, color=NAVY)

    round_rect(c, MARGIN_X, 32 * mm, 114 * mm, 38 * mm, WHITE, LINE, radius=12)
    draw_paragraph(c, "After verification", CARD_TITLE, MARGIN_X + 5 * mm, 65 * mm, 40 * mm, 8 * mm)
    add_bullet_list(
        c,
        [
            "Public users can trust the listing and verify the MOC number.",
            "The SBO manager can access procurement and network tools.",
            "Admins continue governance through support, reporting, and approvals.",
        ],
        MARGIN_X + 5 * mm,
        58 * mm,
        103 * mm,
        style=BODY_SMALL,
        bullet_color=GREEN,
    )

    round_rect(c, MARGIN_X + 122 * mm, 30 * mm, 126 * mm, 42 * mm, NAVY, NAVY, radius=12, stroke_width=0)
    draw_paragraph(c, "Why this matters technically", CARD_TITLE_WHITE, MARGIN_X + 127 * mm, 67 * mm, 60 * mm, 8 * mm)
    add_bullet_list(
        c,
        [
            "Verification state drives access control.",
            "Payment confirmation is the gate that unlocks the document stage in the live UI.",
            "Documents, payment, and admin review are not separate features; they are one workflow.",
            "Any API or rebuild path must preserve that same state machine.",
        ],
        MARGIN_X + 127 * mm,
        59 * mm,
        114 * mm,
        style=BODY_WHITE_SMALL,
        bullet_color=MINT,
    )

    footer(c, 5, "Sources: SboApplicationProgress.php, sbo-fees.blade.php, SboRegistrationReceivedNotification.php, SboUploadDocumentsNotification.php, routes/web.php")


def connections_page(c):
    c.setFillColor(WHITE)
    c.rect(0, 0, PAGE_W, PAGE_H, stroke=0, fill=1)
    page_title(
        c,
        "How the platform stays connected",
        "Routes, shared workflow state, and notifications are the main glue between modules. That is what makes the system behave like one platform instead of separate pages.",
    )
    col_w = (PAGE_W - 2 * MARGIN_X - 12 * mm * 2) / 3
    cols = [
        (MARGIN_X, "Entry points", BLUE_SOFT, [
            "Public routes: /, /businesses, /business/{slug}, /verify/sbo/{number}",
            "User routes: /dashboard, /profile, /notifications, /support",
            "SBO routes: /sbo/dashboard, /sbo/applications, /my-rfqs, /sbo/network",
            "Admin routes: /admin and capability-based admin pages",
        ]),
        (MARGIN_X + col_w + 12 * mm, "Shared workflow state", GREEN_SOFT, [
            "Email verification gates the post-login router",
            "Role checks redirect users into the right workspace",
            "SBO fee/payment state controls progression",
            "Document compliance and verification state unlock later modules",
        ]),
        (MARGIN_X + 2 * (col_w + 12 * mm), "Event and notification layer", AMBER_SOFT, [
            "Model events and Livewire actions trigger notifications",
            "Queue + mail deliver operational updates",
            "Database notifications power the bell/history surfaces",
            "This event layer keeps admins, SBO owners, and users synchronized",
        ]),
    ]

    for x, title, fill, bullets in cols:
        round_rect(c, x, 42 * mm, col_w, 94 * mm, WHITE, LINE, radius=14)
        pill(c, x + 6 * mm, 126 * mm, 42 * mm, 8 * mm, title, fill, NAVY if fill != AMBER_SOFT else WARNING)
        draw_paragraph(c, title, CARD_TITLE, x + 6 * mm, 120 * mm, col_w - 12 * mm, 8 * mm)
        add_bullet_list(c, bullets, x + 6 * mm, 112 * mm, col_w - 12 * mm, style=BODY_SMALL, bullet_color=GREEN if fill == GREEN_SOFT else NAVY)

    round_rect(c, MARGIN_X, 18 * mm, PAGE_W - 2 * MARGIN_X, 14 * mm, NAVY, NAVY, radius=8, stroke_width=0)
    draw_paragraph(c, "Architecturally, the app is held together by route-based entry points, shared status/state checks, and a notification system that carries changes across the roles.", BODY_WHITE, MARGIN_X + 5 * mm, 28 * mm, PAGE_W - 2 * MARGIN_X - 10 * mm, 10 * mm)

    footer(c, 6, "Sources: docs/platform-architecture.md, routes/web.php, composer.json, package.json")


def close_page(c):
    c.setFillColor(NAVY)
    c.rect(0, 0, PAGE_W, PAGE_H, stroke=0, fill=1)
    page_title(
        c,
        "Architecture distilled",
        "This is the short version of the platform structure shown in the report.",
        dark=True,
    )

    round_rect(c, MARGIN_X, 40 * mm, PAGE_W - 2 * MARGIN_X, 92 * mm, WHITE, stroke=WHITE, radius=16, stroke_width=0)
    add_bullet_list(
        c,
        [
            "What it is: a connected business registry platform for discovery, SBO onboarding, procurement, support, and administration.",
            "How it is connected: one Laravel 12 application orchestrates routes, workflow state, data, files, and notifications.",
            "How roles work: Public, SBO manager, and Admin each enter through different routes but converge on the same platform core.",
            "How modules connect: payment state unlocks document compliance, then verification unlocks the wider SBO workspace.",
            "What the stack is: Laravel 12, Livewire, Volt, Blade, Tailwind CSS 4, DaisyUI, Vite, Reverb, Spatie Media Library, and queued notifications.",
            "Why the visuals matter: the clearest way to understand this codebase is as a set of connected journeys and module boundaries, not as isolated screens.",
        ],
        MARGIN_X + 8 * mm,
        122 * mm,
        PAGE_W - 2 * MARGIN_X - 16 * mm,
        style=BODY,
        bullet_color=GREEN,
    )

    footer(c, 7, "Generated from the structured project review conversation", dark=True)


def build_pdf():
    OUT_DIR.mkdir(parents=True, exist_ok=True)
    TMP_DIR.mkdir(parents=True, exist_ok=True)
    PAGES_DIR.mkdir(parents=True, exist_ok=True)

    c = canvas.Canvas(str(FINAL_PDF), pagesize=(PAGE_W, PAGE_H))
    c.setTitle("LW Registry Structured Project Brief")
    c.setAuthor("Codex")
    c.setSubject("Structured visual export of the LW Registry project review conversation")

    pages = [
        cover_page,
        system_page,
        role_flows_page,
        modules_page,
        lifecycle_page,
        connections_page,
        close_page,
    ]
    for page in pages:
        page(c)
        c.showPage()
    c.save()
    print(FINAL_PDF)


if __name__ == "__main__":
    build_pdf()
