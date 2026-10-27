<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>@yield('title', 'Panel') · PratikTools</title>
    <style nonce="{{ $cspNonce }}">
        :root {
            --bg: #f6f7fb; --surface: #ffffff; --surface-2: #f1f3f9; --fg: #14161f; --muted: #5d6475; --line: #e3e6ef;
            --brand: #4f46e5; --brand-ink: #ffffff; --brand-soft: #eef0ff; --nav: #11131c; --nav-fg: #c9cde0; --nav-active: #ffffff;
            --pass: #0f7b45; --pass-bg: #e7f6ee; --warn: #9a5b00; --warn-bg: #fff4e0; --bad: #b42318; --bad-bg: #fdeceb; --info: #1f5fbf; --info-bg: #e9f1fe;
            --radius: 12px; --shadow: 0 1px 2px rgba(16, 24, 40, .06), 0 1px 3px rgba(16, 24, 40, .08);
        }
        @media (prefers-color-scheme: dark) {
            :root { --bg: #0e1016; --surface: #161922; --surface-2: #1d212c; --fg: #eef0f6; --muted: #9aa1b5; --line: #272c3a;
                --brand: #7c74ff; --brand-soft: #23244a; --nav: #0a0c12; --pass: #47c587; --pass-bg: #12301f; --warn: #f0b44c; --warn-bg: #33270f;
                --bad: #ff7b6e; --bad-bg: #3a1714; --info: #7fb0ff; --info-bg: #142740; --shadow: none; }
        }
        * { box-sizing: border-box; }
        body { margin: 0; font: 14px/1.55 Inter, ui-sans-serif, system-ui, -apple-system, "Segoe UI", sans-serif; color: var(--fg); background: var(--bg); }
        a { color: var(--brand); text-decoration: none; } a:hover { text-decoration: underline; }
        h1 { font-size: 22px; margin: 0 0 4px; letter-spacing: -.01em; } h2 { font-size: 16px; margin: 0 0 12px; } h3 { font-size: 14px; margin: 0 0 8px; }
        .shell { display: grid; grid-template-columns: 236px 1fr; min-height: 100vh; }
        .nav { background: var(--nav); color: var(--nav-fg); padding: 20px 14px; position: sticky; top: 0; height: 100vh; overflow-y: auto; }
        .brand { display: flex; align-items: center; gap: 10px; color: #fff; font-weight: 700; font-size: 16px; padding: 4px 8px 18px; }
        .brand-mark { width: 28px; height: 28px; border-radius: 8px; background: linear-gradient(135deg, #6d5dfc, #22c1c3); display: grid; place-items: center; font-size: 14px; }
        .nav a { display: flex; align-items: center; gap: 10px; color: var(--nav-fg); padding: 8px 10px; border-radius: 8px; margin: 1px 0; }
        .nav a:hover { background: rgba(255, 255, 255, .06); text-decoration: none; color: #fff; }
        .nav a.active { background: rgba(124, 116, 255, .22); color: var(--nav-active); font-weight: 600; }
        .nav .group { font-size: 11px; text-transform: uppercase; letter-spacing: .08em; color: #6f7690; margin: 16px 10px 6px; }
        .nav .count { margin-left: auto; background: #6d5dfc; color: #fff; border-radius: 999px; font-size: 11px; padding: 0 7px; }
        .topbar { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 14px 28px; border-bottom: 1px solid var(--line); background: var(--surface); position: sticky; top: 0; z-index: 2; }
        .topbar .tenant { font-weight: 600; } .topbar .who { color: var(--muted); }
        .topbar form { display: inline; }
        main.content { padding: 24px 28px 48px; max-width: 1280px; }
        .page-head { display: flex; justify-content: space-between; align-items: flex-end; gap: 16px; margin-bottom: 20px; flex-wrap: wrap; }
        .page-head p { margin: 0; color: var(--muted); }
        .grid { display: grid; gap: 16px; } .g2 { grid-template-columns: repeat(2, minmax(0, 1fr)); } .g3 { grid-template-columns: repeat(3, minmax(0, 1fr)); } .g4 { grid-template-columns: repeat(4, minmax(0, 1fr)); }
        .card { background: var(--surface); border: 1px solid var(--line); border-radius: var(--radius); padding: 18px; box-shadow: var(--shadow); min-width: 0; }
        .card + .card { margin-top: 0; }
        .stack > * + * { margin-top: 16px; } .stack-top { margin-top: 16px; }
        .kpi .label { color: var(--muted); font-size: 12px; text-transform: uppercase; letter-spacing: .05em; }
        .kpi .value { font-size: 28px; font-weight: 700; letter-spacing: -.02em; margin-top: 2px; }
        .kpi .sub { color: var(--muted); font-size: 12px; }
        .kpis { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; }
        .kpis .kpi { background: var(--surface-2); border-radius: 10px; padding: 12px; }
        .badge { display: inline-flex; align-items: center; gap: 4px; padding: 2px 9px; border-radius: 999px; font-size: 12px; font-weight: 600; white-space: nowrap; }
        .b-pass { color: var(--pass); background: var(--pass-bg); } .b-warn { color: var(--warn); background: var(--warn-bg); }
        .b-bad { color: var(--bad); background: var(--bad-bg); } .b-info { color: var(--info); background: var(--info-bg); } .b-muted { color: var(--muted); background: var(--surface-2); }
        table.table { width: 100%; border-collapse: collapse; }
        .table th { text-align: left; color: var(--muted); font-weight: 600; font-size: 12px; text-transform: uppercase; letter-spacing: .04em; padding: 10px 12px; border-bottom: 1px solid var(--line); }
        .table td { padding: 11px 12px; border-bottom: 1px solid var(--line); vertical-align: top; }
        .table tr:last-child td { border-bottom: 0; }
        .table-wrap { overflow-x: auto; }
        .muted { color: var(--muted); } .small { font-size: 12px; } .mono { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 12px; } .right { text-align: right; }
        .btn { display: inline-flex; align-items: center; justify-content: center; gap: 6px; font: inherit; font-weight: 600; padding: 8px 14px; border-radius: 9px; border: 1px solid var(--line); background: var(--surface); color: var(--fg); cursor: pointer; text-decoration: none; }
        .btn:hover { text-decoration: none; border-color: var(--brand); }
        .btn-primary { background: var(--brand); border-color: var(--brand); color: var(--brand-ink); } .btn-primary:hover { filter: brightness(1.08); }
        .btn-danger { color: var(--bad); } .btn-sm { padding: 5px 10px; font-size: 13px; }
        .btn[disabled] { opacity: .5; cursor: not-allowed; }
        .actions { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; }
        form.inline { display: inline; }
        label.field { display: grid; gap: 6px; font-weight: 600; font-size: 13px; }
        label.field span.hint { font-weight: 400; color: var(--muted); font-size: 12px; }
        input[type=text], input[type=email], input[type=password], input[type=number], input[type=datetime-local], select, textarea {
            font: inherit; font-weight: 400; width: 100%; padding: 9px 11px; border: 1px solid var(--line); border-radius: 9px; background: var(--surface); color: var(--fg); }
        textarea { min-height: 120px; resize: vertical; } textarea.code { font-family: ui-monospace, Menlo, monospace; font-size: 12px; min-height: 240px; }
        input:focus, select:focus, textarea:focus { outline: 2px solid var(--brand-soft); border-color: var(--brand); }
        .form-grid { display: grid; gap: 14px; } .form-row { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 12px; }
        .check { display: flex; align-items: center; gap: 8px; font-weight: 500; }
        .alert { padding: 12px 14px; border-radius: 10px; margin-bottom: 16px; border: 1px solid transparent; }
        .alert-ok { background: var(--pass-bg); color: var(--pass); } .alert-bad { background: var(--bad-bg); color: var(--bad); } .alert-info { background: var(--info-bg); color: var(--info); }
        .readiness { list-style: none; margin: 0; padding: 0; }
        .readiness li { display: grid; grid-template-columns: 28px 1fr auto; gap: 10px; padding: 10px 0; border-bottom: 1px solid var(--line); align-items: start; }
        .readiness li:last-child { border-bottom: 0; }
        .ricon { width: 24px; height: 24px; border-radius: 50%; display: grid; place-items: center; font-weight: 800; font-size: 13px; }
        .ricon.pass { background: var(--pass-bg); color: var(--pass); } .ricon.warn { background: var(--warn-bg); color: var(--warn); } .ricon.bad { background: var(--bad-bg); color: var(--bad); }
        .todo { color: var(--info); } .readiness .title { font-weight: 600; } .readiness .why { color: var(--muted); font-size: 13px; } .readiness .todo { font-size: 13px; color: var(--info); }
        .steps { display: flex; gap: 6px; flex-wrap: wrap; margin-bottom: 16px; }
        .steps span { padding: 5px 11px; border-radius: 999px; background: var(--surface-2); color: var(--muted); font-size: 12px; font-weight: 600; }
        .steps span.done { background: var(--pass-bg); color: var(--pass); } .steps span.now { background: var(--brand-soft); color: var(--brand); }
        .flow { list-style: none; margin: 0; padding: 0; position: relative; }
        .flow li { display: grid; grid-template-columns: 34px 1fr; gap: 12px; padding: 0 0 18px; position: relative; }
        .flow li::before { content: ""; position: absolute; left: 16px; top: 30px; bottom: 0; width: 2px; background: var(--line); }
        .flow li:last-child::before { display: none; }
        .flow .dot { width: 34px; height: 34px; border-radius: 10px; display: grid; place-items: center; font-weight: 700; font-size: 13px; background: var(--surface-2); }
        .flow .wait { background: var(--warn-bg); color: var(--warn); } .flow .check { background: var(--info-bg); color: var(--info); } .flow .action { background: var(--pass-bg); color: var(--pass); }
        .flow .trigger { background: var(--brand-soft); color: var(--brand); } .flow .exit { background: var(--surface-2); color: var(--muted); }
        .flow .t { font-weight: 700; } .flow .d { color: var(--muted); }
        .previews { display: grid; grid-template-columns: minmax(0, 1fr) 375px; gap: 16px; align-items: start; }
        iframe.preview { width: 100%; height: 560px; border: 1px solid var(--line); border-radius: 10px; background: #fff; }
        iframe.preview.mobile { width: 375px; max-width: 100%; }
        pre.snippet { background: var(--surface-2); border-radius: 10px; padding: 12px; overflow-x: auto; font-size: 12px; white-space: pre; }
        .rule-row { display: grid; grid-template-columns: 1.4fr 1fr 1fr; gap: 8px; margin-bottom: 8px; }
        .group-box { border: 1px dashed var(--line); border-radius: 10px; padding: 12px; margin-bottom: 12px; }
        .list-plain { list-style: none; margin: 0; padding: 0; } .list-plain li { padding: 6px 0; border-bottom: 1px solid var(--line); } .list-plain li:last-child { border-bottom: 0; }
        .split { display: flex; justify-content: space-between; gap: 12px; align-items: center; }
        .login-wrap { min-height: 100vh; display: grid; place-items: center; padding: 24px; background: radial-gradient(1200px 600px at 10% 0%, var(--brand-soft), transparent), var(--bg); }
        .login-card { width: 100%; max-width: 400px; }
        @media (max-width: 1100px) { .g4 { grid-template-columns: repeat(2, minmax(0, 1fr)); } .previews { grid-template-columns: 1fr; } }
        @media (max-width: 860px) {
            .shell { grid-template-columns: 1fr; } .nav { position: static; height: auto; display: flex; flex-wrap: wrap; gap: 4px; padding: 10px; }
            .nav .group, .brand { display: none; } .nav a { padding: 6px 10px; }
            .g2, .g3, .g4 { grid-template-columns: 1fr; } main.content, .topbar { padding-left: 16px; padding-right: 16px; } .rule-row { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
@php($route = request()->route()?->getName() ?? '')
<div class="shell">
    <nav class="nav" aria-label="Ana menü">
        <div class="brand"><span class="brand-mark">P</span> PratikTools</div>
        <a href="{{ route('panel.dashboard') }}" @class(['active' => $route === 'panel.dashboard'])>Dashboard</a>
        <div class="group">Pazarlama</div>
        @if (isset($panelCan) && $panelCan(\App\Tenancy\Permission::CampaignsView))
        <a href="{{ route('panel.campaigns') }}" @class(['active' => str_starts_with($route, 'panel.campaigns')])>Kampanyalar</a>
        @endif
        @if (isset($panelCan) && $panelCan(\App\Tenancy\Permission::AutomationsView))
        <a href="{{ route('panel.automations') }}" @class(['active' => str_starts_with($route, 'panel.automations')])>Otomasyonlar</a>
        @endif
        @if (isset($panelCan) && $panelCan(\App\Tenancy\Permission::CampaignsView))
        <a href="{{ route('panel.segments') }}" @class(['active' => str_starts_with($route, 'panel.segments')])>Segmentler</a>
        @endif
        @if (isset($panelCan) && $panelCan(\App\Tenancy\Permission::CampaignsView))
        <a href="{{ route('panel.templates') }}" @class(['active' => str_starts_with($route, 'panel.templates')])>Şablonlar</a>
        @endif
        <div class="group">Kitle</div>
        @if (isset($panelCan) && $panelCan(\App\Tenancy\Permission::LeadsView))
        <a href="{{ route('panel.forms') }}" @class(['active' => str_starts_with($route, 'panel.forms')])>Lead formları</a>
        @endif
        @if (isset($panelCan) && $panelCan(\App\Tenancy\Permission::LeadsView))
        <a href="{{ route('panel.leads') }}" @class(['active' => $route === 'panel.leads'])>Lead'ler</a>
        @endif
        @if (isset($panelCan) && $panelCan(\App\Tenancy\Permission::SuppressionsView))
        <a href="{{ route('panel.suppressions') }}" @class(['active' => $route === 'panel.suppressions'])>Engellenenler</a>
        @endif
        <div class="group">Operasyon</div>
        @if (isset($panelCan) && $panelCan(\App\Tenancy\Permission::CampaignsView))
        <a href="{{ route('panel.approvals') }}" @class(['active' => $route === 'panel.approvals'])>Onaylar
            @if (($pendingApprovals ?? 0) > 0)<span class="count">{{ $pendingApprovals }}</span>@endif</a>
        @endif
        @if (isset($panelCan) && $panelCan(\App\Tenancy\Permission::CampaignsView))
        <a href="{{ route('panel.dispatches') }}" @class(['active' => str_starts_with($route, 'panel.dispatches')])>Gönderimler</a>
        @endif
        @if (isset($panelCan) && $panelCan(\App\Tenancy\Permission::CampaignsView))
        <a href="{{ route('panel.readiness') }}" @class(['active' => $route === 'panel.readiness'])>Hazırlık</a>
        @endif
        @if (isset($panelCan) && $panelCan(\App\Tenancy\Permission::CampaignsView))
        <a href="{{ route('panel.senders') }}" @class(['active' => $route === 'panel.senders'])>Gönderici kimlikleri</a>
        @endif
        @if (isset($panelCan) && $panelCan(\App\Tenancy\Permission::IntegrationsView))
        <a href="{{ route('panel.reconciliation') }}" @class(['active' => str_starts_with($route, 'panel.reconciliation')])>İYS mutabakat</a>
        @endif
        @if (isset($panelCan) && $panelCan(\App\Tenancy\Permission::IntegrationsView))
        <a href="{{ route('panel.integrations') }}" @class(['active' => $route === 'panel.integrations'])>Entegrasyonlar</a>
        @endif
        <a href="{{ route('panel.settings') }}" @class(['active' => $route === 'panel.settings'])>Ayarlar</a>
    </nav>
    <div>
        <header class="topbar">
            <div><span class="tenant">{{ $panelTenantName ?? '' }}</span> <span class="badge b-muted">{{ $panelRole ?? '' }}</span></div>
            <div class="actions">
                <span class="who">{{ auth('web')->user()?->name }}</span>
                <form method="post" action="{{ route('panel.logout') }}">@csrf<button class="btn btn-sm" type="submit">Çıkış</button></form>
            </div>
        </header>
        <main class="content">
            @if (session('status'))<div class="alert alert-ok">{{ session('status') }}</div>@endif
            @if ($errors->any())<div class="alert alert-bad">{{ $errors->first() }}</div>@endif
            @yield('content')
        </main>
    </div>
</div>
</body>
</html>
