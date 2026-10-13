{{--
    The password prompt. Says nothing about what is behind it: an unlisted URL
    that announces its own contents to anyone who guesses the path is only half
    unlisted.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow, noarchive, nosnippet, noimageindex">
    <meta name="googlebot" content="noindex, nofollow">
    <meta name="referrer" content="no-referrer">
    <title>Restricted</title>
    <style>
        :root { color-scheme: light; }
        * { box-sizing: border-box; }
        body {
            margin: 0; min-height: 100dvh; display: grid; place-items: center; padding: 24px;
            font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", sans-serif;
            background: #f8fafc; color: #0f172a;
        }
        .card {
            width: 100%; max-width: 380px; background: #fff; border: 1px solid #e2e8f0;
            border-radius: 14px; padding: 28px;
            box-shadow: 0 4px 12px rgb(0 0 0 / 0.03);
        }
        h1 { margin: 0 0 4px; font-size: 18px; font-weight: 650; }
        p.sub { margin: 0 0 20px; font-size: 13px; color: #64748b; }
        label { display: block; font-size: 13px; font-weight: 600; margin-bottom: 6px; }
        input {
            width: 100%; padding: 12px 14px; font-size: 15px; color: #0f172a;
            border: 1px solid #e2e8f0; border-radius: 9px; background: #f8fafc;
        }
        input:focus { outline: none; border-color: #0d9488; background: #fff; }
        button {
            width: 100%; margin-top: 16px; padding: 12px 14px; font-size: 15px; font-weight: 650;
            color: #fff; background: #0d9488; border: 0; border-radius: 9px; cursor: pointer;
        }
        button:hover { background: #007035; }
        .error {
            margin: 14px 0 0; padding: 10px 12px; font-size: 13px;
            color: #b91c1c; background: #fef2f2; border: 1px solid #fecaca; border-radius: 9px;
        }
    </style>
</head>
<body>
    <main class="card">
        <h1>Restricted</h1>
        <p class="sub">Enter the password to continue.</p>

        <form method="POST" action="{{ route('rlist.businesses.unlock') }}">
            @csrf
            <label for="password">Password</label>
            {{-- autofocus + a real password field so a manager's browser can store it --}}
            <input id="password" name="password" type="password" autocomplete="current-password" autofocus required>
            <button type="submit">Continue</button>

            @error('password')
                <p class="error" role="alert">{{ $message }}</p>
            @enderror
        </form>
    </main>
</body>
</html>
