<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\ImpersonationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ImpersonationController extends Controller
{
    public function __construct(private ImpersonationService $service) {}

    public function store(Request $request, User $user): RedirectResponse
    {
        $this->service->start($user);

        return redirect()->route('home')->with('success', __('Impersonation started.'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        $this->service->stop();

        return redirect()->route('users.list')->with('success', __('Impersonation ended.'));
    }
}
