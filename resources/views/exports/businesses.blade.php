<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Businesses export</title>
    <style>
        body { font-family: DejaVu Sans, Arial, sans-serif; color: #0f172a; margin: 0; }
        h1 { font-size: 16px; margin: 0 0 2px; }
        .meta { font-size: 10px; color: #64748b; margin: 0 0 12px; }
        table { width: 100%; border-collapse: collapse; font-size: 10px; }
        th, td { border: 1px solid #cbd5e1; padding: 5px 7px; text-align: left; vertical-align: top; }
        th { background: #f1f5f9; font-weight: bold; }
        tr:nth-child(even) td { background: #f8fafc; }
    </style>
</head>
<body>
    @unless($forExcel)
        <h1>Loveworld Registry — Businesses</h1>
        <p class="meta">{{ count($rows) }} {{ \Illuminate\Support\Str::plural('business', count($rows)) }} · Generated {{ $generatedAt }}</p>
    @endunless

    <table>
        <thead>
            <tr>
                @foreach($headers as $header)
                    <th>{{ $header }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @forelse($rows as $row)
                <tr>
                    @foreach($row as $cell)
                        <td>{{ $cell }}</td>
                    @endforeach
                </tr>
            @empty
                <tr>
                    <td colspan="{{ count($headers) }}">No businesses match the current filters.</td>
                </tr>
            @endforelse
        </tbody>
    </table>
</body>
</html>
