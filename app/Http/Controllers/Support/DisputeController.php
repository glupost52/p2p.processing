<?php

namespace App\Http\Controllers\Support;

use App\Enums\DisputeStatus;
use App\Exceptions\DisputeException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Dispute\CancelRequest;
use App\Http\Requests\Dispute\StoreRequest;
use App\Http\Resources\DisputeResource;
use App\Models\Dispute;
use App\Models\Order;
use Inertia\Inertia;

class DisputeController extends Controller
{
    public function index()
    {
        $filters = $this->getTableFilters();
        $filtersVariants = $this->getFiltersData();

        $disputes = queries()->dispute()->paginateForAdmin($filters);

        $disputes = DisputeResource::collection($disputes);

        $oldestDisputeCreatedAt = Dispute::query()
            ->where('status', DisputeStatus::PENDING)
            ->oldest('created_at')
            ->first('created_at')
            ?->created_at
            ->toDateTimeString();

        return Inertia::render('Support/Dispute/Index', compact('disputes', 'filters', 'filtersVariants', 'oldestDisputeCreatedAt'));
    }

    public function store(StoreRequest $request, Order $order)
    {
        try {
            services()->dispute()->create($order->id, $request->file('receipt'));

            return redirect()->back()->with('message', 'Спор успешно открыт.');
        } catch (DisputeException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }
    }

    public function accept(Dispute $dispute)
    {
        try {
            services()->dispute()->accept($dispute->id);

            return redirect()->back()->with('message', 'Спор принят.');
        } catch (DisputeException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }
    }

    public function cancel(CancelRequest $request, Dispute $dispute)
    {
        try {
            services()->dispute()->cancel($dispute->id, $request->reason);

            return redirect()->back()->with('message', 'Спор отклонен.');
        } catch (DisputeException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }
    }

    public function rollback(Dispute $dispute)
    {
        try {
            services()->dispute()->rollback($dispute->id);

            return redirect()->back()->with('message', 'Спор снова открыт.');
        } catch (DisputeException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }
    }
} 