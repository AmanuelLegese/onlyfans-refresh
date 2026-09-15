<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Profile Refresh Dashboard</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #0f0f0f; color: #e0e0e0; padding: 20px; }
        h1 { color: #fff; margin-bottom: 20px; }
        .search-bar { display: flex; gap: 10px; margin-bottom: 20px; }
        .search-bar input { flex: 1; padding: 10px 14px; border: 1px solid #333; border-radius: 6px; background: #1a1a1a; color: #e0e0e0; font-size: 14px; }
        .search-bar button { padding: 10px 20px; border: none; border-radius: 6px; background: #6366f1; color: #fff; cursor: pointer; font-size: 14px; }
        .search-bar button:hover { background: #5558e6; }
        table { width: 100%; border-collapse: collapse; font-size: 13px; }
        th, td { padding: 10px 12px; text-align: left; border-bottom: 1px solid #222; }
        th { background: #1a1a1a; color: #999; font-weight: 600; text-transform: uppercase; font-size: 11px; letter-spacing: 0.5px; }
        tr:hover { background: #1a1a1a; }
        .badge { padding: 3px 8px; border-radius: 4px; font-size: 11px; font-weight: 600; }
        .badge-success { background: #064e3b; color: #34d399; }
        .badge-error { background: #7f1d1d; color: #f87171; }
        .badge-warn { background: #78350f; color: #fbbf24; }
        .badge-info { background: #1e3a5f; color: #60a5fa; }
        .badge-none { background: #1f2937; color: #6b7280; }
        .btn-refresh { padding: 5px 12px; border: 1px solid #333; border-radius: 4px; background: transparent; color: #e0e0e0; cursor: pointer; font-size: 12px; }
        .btn-refresh:hover { background: #6366f1; border-color: #6366f1; color: #fff; }
        .likes { font-weight: 600; color: #f472b6; }
        .revision { font-family: monospace; color: #a78bfa; }
        .account { color: #60a5fa; }
        .pagination { display: flex; gap: 6px; margin-top: 20px; justify-content: center; }
        .pagination a, .pagination span { padding: 6px 12px; border-radius: 4px; text-decoration: none; font-size: 13px; }
        .pagination a { background: #1a1a1a; color: #e0e0e0; border: 1px solid #333; }
        .pagination a:hover { background: #6366f1; border-color: #6366f1; }
        .pagination .active { background: #6366f1; color: #fff; border: 1px solid #6366f1; }
        .pagination span { color: #666; }
        .status-msg { padding: 10px 14px; margin-bottom: 16px; border-radius: 6px; background: #064e3b; color: #34d399; font-size: 13px; }
        .time { color: #999; font-size: 12px; }
        .time.overdue { color: #f87171; }
    </style>
</head>
<body>
    <h1>Profile Refresh Dashboard</h1>

    @if (session('status'))
        <div class="status-msg">{{ session('status') }}</div>
    @endif

    <form method="GET" action="/" class="search-bar">
        <input type="text" name="q" placeholder="Search by username or name..." value="{{ $search ?? '' }}">
        <button type="submit">Search</button>
    </form>

    <table>
        <thead>
            <tr>
                <th>Username</th>
                <th>Name</th>
                <th>Account</th>
                <th>Likes</th>
                <th>Revision</th>
                <th>Last Outcome</th>
                <th>Last Success</th>
                <th>Next Refresh</th>
                <th>Queued</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            @forelse ($profiles as $profile)
                <tr>
                    <td><strong>{{ $profile->username }}</strong></td>
                    <td>{{ $profile->name ?? '—' }}</td>
                    <td class="account">{{ $profile->account->name ?? '—' }}</td>
                    <td class="likes">{{ number_format($profile->likes) }}</td>
                    <td class="revision">{{ $profile->revision }}</td>
                    <td>
                        @if ($profile->last_attempt_outcome === 'success')
                            <span class="badge badge-success">success</span>
                        @elseif ($profile->last_attempt_outcome === 'rate_limited')
                            <span class="badge badge-warn">rate_limited</span>
                        @elseif ($profile->last_attempt_outcome === 'server_error')
                            <span class="badge badge-error">server_error</span>
                        @elseif ($profile->last_attempt_outcome === 'timeout')
                            <span class="badge badge-warn">timeout</span>
                        @elseif ($profile->last_attempt_outcome === 'stale_revision')
                            <span class="badge badge-info">stale</span>
                        @else
                            <span class="badge badge-none">{{ $profile->last_attempt_outcome ?? '—' }}</span>
                        @endif
                    </td>
                    <td class="time">{{ $profile->last_success_at?->diffForHumans() ?? '—' }}</td>
                    <td class="time {{ $profile->next_refresh_at && $profile->next_refresh_at->isPast() ? 'overdue' : '' }}">
                        {{ $profile->next_refresh_at?->diffForHumans() ?? '—' }}
                    </td>
                    <td>
                        @if ($profile->refresh_queued_at)
                            <span class="badge badge-info">yes</span>
                        @else
                            <span class="badge badge-none">no</span>
                        @endif
                    </td>
                    <td>
                        <form method="POST" action="{{ route('profiles.refresh', $profile) }}" style="display:inline">
                            @csrf
                            <button type="submit" class="btn-refresh">Refresh</button>
                        </form>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="10" style="text-align:center; color:#666; padding:30px;">No profiles found.</td>
                </tr>
            @endforelse
        </tbody>
    </table>

    {{ $profiles->links() }}
</body>
</html>
