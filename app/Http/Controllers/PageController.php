<?php

namespace App\Http\Controllers;

use App\Models\Ticket;
use App\Models\WorkspaceSetting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PageController extends Controller
{
    public function home(Request $request): RedirectResponse
    {
        if (! $request->user()) {
            return to_route(WorkspaceSetting::needsSetup() ? 'register' : 'login');
        }

        $view = $request->user()->preferences['initial_view'] ?? 'all';

        return to_route('tickets.index', $view === 'all' ? [] : ['view' => $view]);
    }

    public function auth(Request $request): Response|RedirectResponse
    {
        $mode = $request->route('mode');
        if ($request->user() && $mode !== 'reset') {
            return $this->home($request);
        }
        if (WorkspaceSetting::needsSetup() && $mode !== 'register') {
            return to_route('register');
        }
        if (! WorkspaceSetting::needsSetup() && $mode === 'register') {
            return to_route('login');
        }

        return Inertia::render('Auth', ['mode' => $mode]);
    }

    public function show(Request $request): Response
    {
        Inertia::encryptHistory();
        if ($request->routeIs('tickets.show')) {
            Ticket::findOrFail($request->route('id'));
        }

        return Inertia::render($request->route('component'));
    }
}
