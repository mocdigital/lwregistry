{{--
    Every registered business: name, category, email, phone, website.

    Standalone on purpose — no app layout, no nav, no links back into the
    product, so this page cannot appear in any menu and carries nothing that
    would lead a crawler onward.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow, noarchive, nosnippet, noimageindex">
    <meta name="googlebot" content="noindex, nofollow">
    <meta name="referrer" content="no-referrer">
    <title>Registered businesses</title>
    <style>
        :root { color-scheme: light; }
        * { box-sizing: border-box; }
        body {
            margin: 0; padding: 24px 16px 64px;
            font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", sans-serif;
            background: #f8fafc; color: #0f172a; font-size: 14px;
        }
        .wrap { max-width: 1180px; margin: 0 auto; }
        header { display: flex; flex-wrap: wrap; gap: 12px; align-items: baseline; justify-content: space-between; margin-bottom: 18px; }
        h1 { margin: 0; font-size: 20px; font-weight: 650; }
        .count { font-size: 13px; color: #64748b; }
        .tools { display: flex; gap: 10px; align-items: center; margin-bottom: 14px; flex-wrap: wrap; }
        #q {
            flex: 1 1 260px; min-width: 0; padding: 10px 13px; font-size: 14px;
            border: 1px solid #e2e8f0; border-radius: 9px; background: #fff; color: #0f172a;
        }
        #q:focus { outline: none; border-color: #0d9488; }
        .lock { font-size: 13px; color: #64748b; text-decoration: underline; background: none; border: 0; cursor: pointer; padding: 0; font-family: inherit; }
        .panel { background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; min-width: 900px; }
        th, td { text-align: left; padding: 11px 14px; border-bottom: 1px solid #f1f5f9; vertical-align: top; }
        th { font-size: 12px; text-transform: uppercase; letter-spacing: .03em; color: #64748b; font-weight: 650; background: #f8fafc; position: sticky; top: 0; }
        tr:last-child td { border-bottom: 0; }
        td.name { font-weight: 600; }
        .tag { display: inline-block; margin-left: 6px; padding: 1px 6px; border-radius: 999px; font-size: 11px; font-weight: 650; background: #f1f5f9; color: #475569; }
        .none { color: #94a3b8; }
        a { color: #0d9488; }
        .empty { padding: 28px 14px; text-align: center; color: #64748b; }
        @media (max-width: 640px) { body { padding: 16px 10px 48px; } }
    </style>
</head>
<body>
<div class="wrap">
    <header>
        <h1>Registered businesses</h1>
        <span class="count">{{ number_format($businesses->count()) }} total</span>
    </header>

    <div class="tools">
        <input id="q" type="search" placeholder="Filter by name, category, email, phone or website" autocomplete="off">
        <form method="POST" action="{{ route('rlist.businesses.lock') }}">
            @csrf
            <button class="lock" type="submit">Lock</button>
        </form>
    </div>

    <div class="panel">
        <table>
            <thead>
                <tr>
                    <th>Business</th>
                    <th>Category</th>
                    <th>Email</th>
                    <th>Phone</th>
                    <th>Website</th>
                </tr>
            </thead>
            <tbody id="rows">
                @forelse($businesses as $business)
                    @php
                        // `category` is the stored value; `business_type` held it on
                        // older rows, so fall back rather than print a blank.
                        $category = $business->category ?: $business->business_type;
                        $website = $business->website;
                        $href = $website && ! str_starts_with($website, 'http') ? 'https://' . $website : $website;
                    @endphp
                    <tr>
                        <td class="name">
                            {{ $business->name }}
                            <span class="tag">{{ $business->entity_type ?: 'SBO' }}</span>
                        </td>
                        <td>{!! $category ? e($category) : '<span class="none">—</span>' !!}</td>
                        <td>
                            @if($business->primary_contact_email)
                                <a href="mailto:{{ $business->primary_contact_email }}" rel="noreferrer">{{ $business->primary_contact_email }}</a>
                            @else
                                <span class="none">—</span>
                            @endif
                        </td>
                        <td>{!! $business->phone ? e($business->phone) : '<span class="none">—</span>' !!}</td>
                        <td>
                            @if($website)
                                {{-- noreferrer so following one does not hand this page's URL to the destination --}}
                                <a href="{{ $href }}" target="_blank" rel="noreferrer noopener nofollow">{{ $website }}</a>
                            @else
                                <span class="none">—</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="empty">No businesses registered yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

<script>
    // Filter in the page: the whole list is already here, so searching it needs
    // no round trip and no extra route to leave lying around.
    const q = document.getElementById('q')
    const rows = Array.from(document.querySelectorAll('#rows tr'))

    q.addEventListener('input', () => {
        const needle = q.value.trim().toLowerCase()
        rows.forEach((row) => {
            row.style.display = !needle || row.textContent.toLowerCase().includes(needle) ? '' : 'none'
        })
    })
</script>
</body>
</html>
