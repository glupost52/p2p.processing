<?php

namespace App\Http\Controllers\Admin;

use App\Enums\CommissionOperationType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\PaymentGateway\SyncCommissionTiersRequest;
use App\Http\Resources\PaymentGatewayCommissionTierResource;
use App\Models\PaymentGateway;
use App\Services\Commission\CommissionTierService;
use App\Services\Commission\Exceptions\CommissionTierException;

class PaymentGatewayCommissionTierController extends Controller
{
    public function __construct(
        protected CommissionTierService $commissionTierService,
    ) {}

    public function index(PaymentGateway $paymentGateway)
    {
        $tiers = $paymentGateway->commissionTiers()
            ->orderBy('operation_type')
            ->orderBy('sort_order')
            ->orderBy('min_amount')
            ->get();

        return response()->json([
            'success' => true,
            'data' => PaymentGatewayCommissionTierResource::collection($tiers)->resolve(),
        ]);
    }

    public function sync(SyncCommissionTiersRequest $request, PaymentGateway $paymentGateway)
    {
        try {
            $operationType = CommissionOperationType::from($request->validated('operation_type'));
            $tiers = $this->commissionTierService->syncForGateway(
                paymentGateway: $paymentGateway,
                operationType: $operationType,
                tiers: $request->validated('tiers'),
            );
        } catch (CommissionTierException $exception) {
            return response()->json([
                'success' => false,
                'message' => $exception->getMessage(),
            ], 422);
        }

        return response()->json([
            'success' => true,
            'data' => PaymentGatewayCommissionTierResource::collection($tiers)->resolve(),
        ]);
    }
}
