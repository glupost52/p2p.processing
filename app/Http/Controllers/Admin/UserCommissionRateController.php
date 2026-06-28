<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\User\SyncTraderCommissionRatesRequest;
use App\Http\Resources\TraderCommissionRateResource;
use App\Models\User;
use App\Services\Commission\Exceptions\CommissionTierException;
use App\Services\Commission\TraderCommissionRateService;

class UserCommissionRateController extends Controller
{
    public function __construct(
        protected TraderCommissionRateService $traderCommissionRateService,
    ) {}

    public function index(User $user)
    {
        $rates = $user->traderCommissionRates()
            ->with('paymentGateway:id,name,currency')
            ->orderBy('payment_gateway_id')
            ->orderBy('operation_type')
            ->orderBy('min_amount')
            ->get();

        return response()->json([
            'success' => true,
            'data' => TraderCommissionRateResource::collection($rates)->resolve(),
        ]);
    }

    public function sync(SyncTraderCommissionRatesRequest $request, User $user)
    {
        try {
            $rates = $this->traderCommissionRateService->syncForUser(
                user: $user,
                rates: $request->validated('rates'),
            );
        } catch (CommissionTierException $exception) {
            return response()->json([
                'success' => false,
                'message' => $exception->getMessage(),
            ], 422);
        }

        return response()->json([
            'success' => true,
            'data' => TraderCommissionRateResource::collection($rates)->resolve(),
        ]);
    }
}
