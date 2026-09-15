<?php

namespace App\Http\Controllers;

use App\Jobs\RefreshProfile;
use App\Models\Profile;
use Illuminate\Http\{Request, RedirectResponse};
use Illuminate\Support\Facades\Redis;

class ProfileController extends Controller
{
    public function index(Request $request)
    {
        $query = Profile::with('account');

        if ($search = $request->input('q')) {
            $query->where('username', 'like', "%{$search}%")
                ->orWhere('name', 'like', "%{$search}%");
        }

        $profiles = $query->orderBy('next_refresh_at', 'asc')
            ->paginate(25)
            ->withQueryString();

        return view('profiles.index', compact('profiles', 'search'));
    }

    public function refresh(Profile $profile): RedirectResponse
    {
        $mode = config('refresh.mode', 'fixed');
        RefreshProfile::dispatch($profile->id, $mode)->onQueue('refresh');

        return back()->with('status', "Refresh dispatched for @{$profile->username}");
    }
}
