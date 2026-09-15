<?php

namespace App\Http\Controllers;

use App\Models\Profile;
use App\Refresh\RefreshDispatcher;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ProfileController extends Controller
{
    public function index(Request $request): View
    {
        $search = $request->string('q')->trim()->toString();

        $profiles = $search === ''
            ? Profile::with('account')->orderBy('next_refresh_at')->paginate(25)
            : Profile::search($search)
                ->query(fn ($query) => $query->with('account')->orderBy('next_refresh_at'))
                ->paginate(25);

        return view('profiles.index', [
            'profiles' => $profiles->withQueryString(),
            'search' => $search,
        ]);
    }

    public function refresh(Profile $profile): RedirectResponse
    {
        $dispatched = RefreshDispatcher::dispatchIfNotPending($profile, config('refresh.mode', 'fixed'));

        return back()->with('status', $dispatched
            ? "Refresh dispatched for @{$profile->username}"
            : "A refresh is already pending for @{$profile->username}");
    }
}
