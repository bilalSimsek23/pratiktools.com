<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>@yield('title') · PratikTools</title>
    <style nonce="{{ $cspNonce }}">
        :root { --bg: #f6f7fb; --surface: #fff; --fg: #14161f; --muted: #5d6475; --line: #e3e6ef; --brand: #4f46e5; --brand-soft: #eef0ff; --bad: #b42318; --bad-bg: #fdeceb; --ok: #0f7b45; --ok-bg: #e7f6ee; }
        @media (prefers-color-scheme: dark) { :root { --bg: #0e1016; --surface: #161922; --fg: #eef0f6; --muted: #9aa1b5; --line: #272c3a; --brand: #7c74ff; --brand-soft: #23244a; --bad: #ff7b6e; --bad-bg: #3a1714; --ok: #47c587; --ok-bg: #12301f; } }
        * { box-sizing: border-box; }
        body { margin: 0; font: 14px/1.55 Inter, ui-sans-serif, system-ui, -apple-system, "Segoe UI", sans-serif; color: var(--fg);
            background: radial-gradient(1200px 600px at 10% 0%, var(--brand-soft), transparent), var(--bg); min-height: 100vh; display: grid; place-items: center; padding: 24px; }
        .card { width: 100%; max-width: 400px; background: var(--surface); border: 1px solid var(--line); border-radius: 16px; padding: 28px; box-shadow: 0 10px 30px rgba(16, 24, 40, .08); }
        .brand { display: flex; align-items: center; gap: 10px; font-weight: 700; font-size: 18px; margin-bottom: 18px; }
        .mark { width: 32px; height: 32px; border-radius: 9px; background: linear-gradient(135deg, #6d5dfc, #22c1c3); color: #fff; display: grid; place-items: center; }
        h1 { font-size: 20px; margin: 0 0 4px; } p { color: var(--muted); margin: 0 0 18px; }
        label { display: grid; gap: 6px; font-weight: 600; font-size: 13px; margin-bottom: 14px; }
        input, select { font: inherit; font-weight: 400; padding: 10px 12px; border: 1px solid var(--line); border-radius: 10px; background: var(--surface); color: var(--fg); width: 100%; }
        button { width: 100%; font: inherit; font-weight: 700; padding: 10px; border-radius: 10px; border: 0; background: var(--brand); color: #fff; cursor: pointer; }
        .alert { padding: 10px 12px; border-radius: 10px; margin-bottom: 14px; background: var(--bad-bg); color: var(--bad); }
        .ok { background: var(--ok-bg); color: var(--ok); }
        .foot { margin-top: 16px; color: var(--muted); font-size: 12px; text-align: center; }
    </style>
</head>
<body>
<div class="card">
    <div class="brand"><span class="mark">P</span> PratikTools</div>
    @if (session('status'))<div class="alert ok">{{ session('status') }}</div>@endif
    @if ($errors->any())<div class="alert">{{ $errors->first() }}</div>@endif
    @yield('content')
</div>
</body>
</html>
