<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CommissionRateController extends Controller
{
    public function index(Request $request): Response
    {
        $viewer = $request->user();
        $canSelectTrader = $viewer->hasRole('Super Admin');
        $trader = $this->resolveTargetTrader($viewer, $request);

        $rows = $trader !== null
            ? services()->traderEffectiveCommission()->buildForUser($trader)
            : [];

        return Inertia::render('CommissionRates/Index', [
            'rows' => $rows,
            'trader' => $trader !== null ? [
                'id' => $trader->id,
                'name' => $trader->name,
                'email' => $trader->email,
            ] : null,
            'canSelectTrader' => $canSelectTrader,
            'traders' => $canSelectTrader
                ? User::role('Trader')
                    ->select(['id', 'name', 'email'])
                    ->orderBy('name')
                    ->get()
                : [],
            'filters' => [
                'user_id' => $request->integer('user_id') ?: null,
            ],
        ]);
    }

    private function resolveTargetTrader(User $viewer, Request $request): ?User
    {
        if ($viewer->hasRole('Super Admin')) {
            $userId = $request->integer('user_id');

            if ($userId <= 0) {
                return null;
            }

            $trader = User::role('Trader')->find($userId);

            abort_if($trader === null, 404);

            return $trader;
        }

        abort_unless($viewer->hasRole('Trader'), 403);

        return $viewer;
    }
}
