<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>@yield('title', 'Platform Admin')</title>
    <style nonce="{{ $cspNonce }}">
        :root { color-scheme: light dark; --fg: #1d1d1f; --muted: #6e6e73; --bg: #fff; --line: #e5e5ea; --ok: #1a7f37; --bad: #c62828; }
        @media (prefers-color-scheme: dark) { :root { --fg: #f5f5f7; --muted: #a1a1a6; --bg: #161618; --line: #2c2c2e; --ok: #4ac26b; --bad: #ff6b6b; } }
        body { font: 14px/1.5 system-ui, sans-serif; margin: 0; color: var(--fg); background: var(--bg); }
        main { max-width: 1100px; margin: 0 auto; padding: 24px 16px; }
        header { display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid var(--line); padding: 12px 16px; }
        table { border-collapse: collapse; width: 100%; margin: 12px 0 28px; }
        th, td { text-align: left; padding: 6px 8px; border-bottom: 1px solid var(--line); vertical-align: top; }
        th { color: var(--muted); font-weight: 600; }
        .ok { color: var(--ok); } .fail { color: var(--bad); } .muted { color: var(--muted); }
        form.card { max-width: 360px; margin: 64px auto; display: grid; gap: 12px; }
        input { font: inherit; padding: 8px; border: 1px solid var(--line); border-radius: 6px; background: transparent; color: inherit; }
        button { font: inherit; padding: 8px 12px; border-radius: 6px; border: 1px solid var(--line); background: transparent; color: inherit; cursor: pointer; }
        code { word-break: break-all; }
    </style>
</head>
<body>
@auth('platform')
    <header>
        <strong>Platform Admin</strong>
        <form method="post" action="{{ route('admin.logout') }}">@csrf<button type="submit">Log out</button></form>
    </header>
@endauth
<main>
    @if ($errors->any())
        <p class="fail">{{ $errors->first() }}</p>
    @endif
    @yield('content')
</main>
</body>
</html>
