<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>@yield('title', 'Platform Yönetimi') · PratikTools</title>
    <style nonce="{{ $cspNonce }}">
        :root { color-scheme: light dark; --bg: #f6f7fb; --surface: #fff; --fg: #14161f; --muted: #5d6475; --line: #e3e6ef; --brand: #4f46e5; --brand-soft: #eef0ff;
            --ok: #0f7b45; --ok-bg: #e7f6ee; --bad: #b42318; --bad-bg: #fdeceb; --warn: #9a6700; --warn-bg: #fff4d6; }
        @media (prefers-color-scheme: dark) { :root { --bg: #0e1016; --surface: #161922; --fg: #eef0f6; --muted: #9aa1b5; --line: #272c3a; --brand: #7c74ff; --brand-soft: #23244a;
            --ok: #47c587; --ok-bg: #12301f; --bad: #ff7b6e; --bad-bg: #3a1714; --warn: #f2c94c; --warn-bg: #3a2f0f; } }
        * { box-sizing: border-box; }
        body { font: 14px/1.55 Inter, ui-sans-serif, system-ui, -apple-system, "Segoe UI", sans-serif; margin: 0; color: var(--fg); background: var(--bg); }
        header { display: flex; justify-content: space-between; align-items: center; gap: 16px; flex-wrap: wrap; background: var(--surface); border-bottom: 1px solid var(--line); padding: 10px 20px; }
        .brand { display: flex; align-items: center; gap: 10px; font-weight: 700; } .mark { width: 28px; height: 28px; border-radius: 8px; background: linear-gradient(135deg, #6d5dfc, #22c1c3); color: #fff; display: grid; place-items: center; font-size: 13px; }
        nav { display: flex; gap: 4px; flex-wrap: wrap; } nav a { color: var(--muted); text-decoration: none; padding: 6px 10px; border-radius: 8px; font-weight: 600; }
        nav a.active, nav a:hover { color: var(--fg); background: var(--brand-soft); }
        main { max-width: 1180px; margin: 0 auto; padding: 24px 16px 48px; }
        h1 { font-size: 22px; margin: 0 0 4px; } h2 { font-size: 16px; margin: 0 0 12px; } p.lead, .muted { color: var(--muted); }
        .page-head { display: flex; justify-content: space-between; align-items: flex-start; gap: 16px; flex-wrap: wrap; margin-bottom: 18px; }
        .card { background: var(--surface); border: 1px solid var(--line); border-radius: 14px; padding: 18px; margin-bottom: 16px; }
        .grid { display: grid; gap: 16px; } .g2 { grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); } .g4 { grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); }
        .kpi .label { color: var(--muted); font-size: 12px; font-weight: 600; text-transform: uppercase; letter-spacing: .04em; } .kpi .value { font-size: 26px; font-weight: 700; }
        .table-wrap { overflow-x: auto; } table { border-collapse: collapse; width: 100%; }
        th, td { text-align: left; padding: 8px 10px; border-bottom: 1px solid var(--line); vertical-align: top; } th { color: var(--muted); font-weight: 600; font-size: 12px; }
        .badge { display: inline-flex; padding: 2px 9px; border-radius: 999px; font-size: 12px; font-weight: 600; white-space: nowrap; background: var(--brand-soft); color: var(--brand); }
        .b-ok { background: var(--ok-bg); color: var(--ok); } .b-bad { background: var(--bad-bg); color: var(--bad); } .b-warn { background: var(--warn-bg); color: var(--warn); } .b-muted { background: var(--bg); color: var(--muted); }
        .ok { color: var(--ok); } .fail { color: var(--bad); }
        form.inline { display: inline-flex; gap: 6px; align-items: center; flex-wrap: wrap; margin: 0; }
        .form-grid { display: grid; gap: 12px; max-width: 560px; } label { display: grid; gap: 4px; font-weight: 600; font-size: 13px; } .hint { font-weight: 400; color: var(--muted); font-size: 12px; }
        input, select, textarea { font: inherit; font-weight: 400; padding: 8px 10px; border: 1px solid var(--line); border-radius: 8px; background: var(--surface); color: var(--fg); }
        button, .btn { font: inherit; font-weight: 600; padding: 7px 12px; border-radius: 8px; border: 1px solid var(--line); background: var(--surface); color: var(--fg); cursor: pointer; text-decoration: none; display: inline-block; }
        .btn-primary { background: var(--brand); border-color: var(--brand); color: #fff; } .btn-danger { color: var(--bad); }
        .alert { padding: 10px 12px; border-radius: 10px; margin-bottom: 14px; background: var(--bad-bg); color: var(--bad); } .alert.ok { background: var(--ok-bg); color: var(--ok); }
        .tabs { display: flex; gap: 4px; border-bottom: 1px solid var(--line); margin-bottom: 16px; flex-wrap: wrap; } .tabs a { padding: 8px 12px; color: var(--muted); text-decoration: none; font-weight: 600; border-bottom: 2px solid transparent; }
        .tabs a.active { color: var(--fg); border-bottom-color: var(--brand); }
        code, .secret-link { word-break: break-all; } .secret-link { display: block; padding: 10px; border: 1px dashed var(--brand); border-radius: 8px; background: var(--brand-soft); font-family: ui-monospace, monospace; font-size: 12px; }
        dl.kv { display: grid; grid-template-columns: max-content 1fr; gap: 6px 16px; margin: 0; } dl.kv dt { color: var(--muted); } dl.kv dd { margin: 0; }
        form.card.login { max-width: 360px; margin: 64px auto; display: grid; gap: 12px; }
    </style>
</head>
<body>
@auth('platform')
    <header>
        <div class="brand"><span class="mark" aria-hidden="true">P</span>Platform Yönetimi</div>
        <nav aria-label="Platform menüsü">
            <a href="{{ route('admin.dashboard') }}" @class(['active' => request()->routeIs('admin.dashboard')])>Genel Bakış</a>
            <a href="{{ route('admin.tenants') }}" @class(['active' => request()->routeIs('admin.tenants*')])>Müşteriler</a>
            <a href="{{ route('admin.system') }}" @class(['active' => request()->routeIs('admin.system')])>Sistem Durumu</a>
        </nav>
        <form method="post" action="{{ route('admin.logout') }}">@csrf<button type="submit">Çıkış</button></form>
    </header>
@endauth
<main>
    @if (session('status'))<div class="alert ok">{{ session('status') }}</div>@endif
    @if ($errors->any())<div class="alert">{{ $errors->first() }}</div>@endif
    @yield('content')
</main>
</body>
</html>
