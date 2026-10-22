@extends('admin.layout')
@section('title', 'Platform overview')
@section('content')
<h2>System health <span class="{{ $health['status'] === 'ok' ? 'ok' : 'fail' }}">{{ $health['status'] }}</span></h2>
<table>
    <thead><tr><th>Component</th><th>Status</th><th>Details</th></tr></thead>
    <tbody>
    @foreach ($health['checks'] as $name => $check)
        <tr>
            <td>{{ $name }}</td>
            <td class="{{ $check['status'] === 'ok' ? 'ok' : 'fail' }}">{{ $check['status'] }}</td>
            <td class="muted">
                @isset($check['latency_ms']) {{ $check['latency_ms'] }} ms @endisset
                @isset($check['horizon']) Horizon: {{ $check['horizon'] }} @endisset
                @isset($check['backlog'])
                    · backlog @foreach ($check['backlog'] as $queue => $size) {{ $queue }}={{ $size }} @endforeach
                @endisset
            </td>
        </tr>
    @endforeach
    </tbody>
</table>

<h2>Tenants ({{ $tenants->count() }})</h2>
<p class="muted">Aggregate data only. This view has no access to customer or user personal data.</p>
<table>
    <thead><tr><th>Name</th><th>Slug</th><th>Status</th><th>Country</th><th>Members</th><th>Integrations</th><th>Created</th></tr></thead>
    <tbody>
    @forelse ($tenants as $tenant)
        <tr>
            <td>{{ $tenant->name }}</td>
            <td>{{ $tenant->slug }}</td>
            <td class="{{ $tenant->status === 'active' ? 'ok' : 'fail' }}">{{ $tenant->status }}</td>
            <td>{{ $tenant->country }}</td>
            <td>{{ $tenant->members }}</td>
            <td>
                @forelse ($tenant->integrations as $integration)
                    {{ $integration->provider }}/{{ $integration->capability }}: {{ $integration->status }}<br>
                @empty
                    <span class="muted">none</span>
                @endforelse
            </td>
            <td class="muted">{{ \Illuminate\Support\Carbon::parse($tenant->created_at)->toDateString() }}</td>
        </tr>
    @empty
        <tr><td colspan="7" class="muted">No tenants yet.</td></tr>
    @endforelse
    </tbody>
</table>
@endsection
