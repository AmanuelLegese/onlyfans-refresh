<?php

namespace App\Http\Controllers;

use App\Models\Profile;
use App\Refresh\RefreshDispatcher;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

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
        dispatch(RefreshDispatcher::jobFor($profile->id, config('refresh.mode', 'fixed')));

        return back()->with('status', "Refresh dispatched for @{$profile->username}");
    }
}
