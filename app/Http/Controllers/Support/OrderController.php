<?php

namespace App\Http\Controllers\Support;

use App\Http\Controllers\Controller;
use App\Http\Resources\TableOrderResource;
use App\Models\Order;
use App\Services\Money\Money;
use Illuminate\Http\Request;
use Inertia\Inertia;

class OrderController extends Controller
{
    public function index()
    {
        $filters = $this->getTableFilters();
        $filtersVariants = $this->getFiltersData();

        $orders = queries()->order()->paginateForAdmin($filters);
        $orders = TableOrderResource::collection($orders);

        return Inertia::render('Support/Order/Index', compact('orders', 'filters', 'filtersVariants'));
    }

    public function updateAmount(Request $request, Order $order)
    {
        $request->validate([
            'amount' => ['required', 'integer', 'min:1'],
        ]);

        services()->order()->updateAmount(
            orderID: $order->id,
            amount: Money::fromPrecision($request->input('amount'), $order->currency),
        );

        return redirect()->back()->with('message', 'Сумма сделки обновлена.');
    }
} 