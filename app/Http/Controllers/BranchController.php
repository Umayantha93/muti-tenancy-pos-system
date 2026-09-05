<?php

namespace App\Http\Controllers;

use App\Models\Bill;
use App\Models\Branch;
use App\Models\Expense;
use App\Support\BranchContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BranchController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $branches = Branch::query()
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get();

        return response()->json([
            'data' => $branches,
            'active_branch' => BranchContext::branch(),
            'can_switch' => $request->user()->role === 'business_owner' && $branches->where('status', 'active')->count() > 1,
        ]);
    }

    public function update(Request $request, Branch $branch): JsonResponse
    {
        abort_unless($request->user()->role === 'business_owner', 403);
        abort_unless($branch->tenant_id === $request->user()->tenant_id, 404);

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'regex:/^[0-9+() -]{7,20}$/'],
        ]);
        $branch->update($data);

        return response()->json($branch->refresh());
    }

    public function activate(Request $request): JsonResponse
    {
        abort_unless($request->user()->role === 'business_owner', 403);
        $data = $request->validate([
            'branch_id' => ['required', 'integer'],
        ]);
        $branch = Branch::query()
            ->where('tenant_id', $request->user()->tenant_id)
            ->whereKey($data['branch_id'])
            ->firstOrFail();
        abort_unless($branch->isActive(), 422, 'This shop is inactive.');

        $request->user()->update(['last_branch_id' => $branch->id]);
        BranchContext::set((int) $branch->id);

        return response()->json([
            'active_branch' => $branch,
            'message' => 'Now working as '.$branch->name.'.',
        ]);
    }

    public function summary(Request $request, Branch $branch): JsonResponse
    {
        abort_unless($request->user()->role === 'business_owner', 403);
        abort_unless($branch->tenant_id === $request->user()->tenant_id, 404);

        return response()->json([
            'branch' => $branch,
            'open_bills' => Bill::withoutGlobalScope('branch')->where('branch_id', $branch->id)->whereIn('status', ['open', 'partially_paid', 'owe_in'])->count(),
            'today_sales' => (float) Bill::withoutGlobalScope('branch')->where('branch_id', $branch->id)->whereDate('admission_date', today())->sum('subtotal'),
            'staff_count' => $request->user()->tenant->users()->where('role', 'staff')->where('home_branch_id', $branch->id)->where('status', 'active')->count(),
            'month_expenses' => (float) Expense::withoutGlobalScope('branch')->where('branch_id', $branch->id)->whereMonth('expense_date', now()->month)->whereYear('expense_date', now()->year)->sum('amount'),
        ]);
    }
}
