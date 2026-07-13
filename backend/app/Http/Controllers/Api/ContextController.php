<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ContextController extends Controller
{
    /**
     * Switch the caller's active company/branch context ("single login,
     * switch context" from SRS §4.12). Scoped to the companies/branches the
     * user is assigned to, unless they're a super-admin/admin.
     */
    public function switch(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'company_id' => ['required', 'integer', 'exists:companies,id'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
        ]);

        $isSuperAdmin = $user->hasRole(['super-admin', 'admin']);

        if (! $isSuperAdmin && ! $user->companies()->where('companies.id', $data['company_id'])->exists()) {
            return response()->json(['message' => 'You do not have access to this company.'], 403);
        }

        $branchId = $data['branch_id'] ?? null;
        if ($branchId) {
            $branch = Branch::find($branchId);
            if (! $branch || (int) $branch->company_id !== (int) $data['company_id']) {
                return response()->json(['message' => 'Selected branch does not belong to this company.'], 422);
            }
            if (! $isSuperAdmin && ! $user->branches()->where('branches.id', $branchId)->exists()) {
                return response()->json(['message' => 'You do not have access to this branch.'], 403);
            }
        }

        $user->update([
            'current_company_id' => $data['company_id'],
            'current_branch_id' => $branchId,
        ]);

        return response()->json($user->fresh()->sessionPayload());
    }
}
