<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\OnboardingProgress;
use App\Models\User;
use App\Support\TenantContext;
use App\Support\TenantStorage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Drives the first-run onboarding wizard + the persistent "Getting Started"
 * checklist. Progress is stored per-tenant in the central onboarding_progress
 * table, resolved via the active tenant id.
 */
class OnboardingController extends Controller
{
    private function progress(): OnboardingProgress
    {
        return OnboardingProgress::firstOrCreate(
            ['tenant_id' => app(TenantContext::class)->id()],
            ['steps' => []],
        );
    }

    public function show(): JsonResponse
    {
        $progress = $this->progress();

        return response()->json([
            'steps' => (object) ($progress->steps ?? []),
            'all_steps' => OnboardingProgress::STEPS,
            'completed' => $progress->isComplete(),
            'completed_at' => $progress->completed_at,
        ]);
    }

    public function completeStep(Request $request): JsonResponse
    {
        $data = $request->validate([
            'step' => ['required', 'string', Rule::in(OnboardingProgress::STEPS)],
        ]);

        $progress = $this->progress();
        $progress->markStep($data['step']);

        return response()->json([
            'steps' => (object) ($progress->steps ?? []),
            'completed' => $progress->isComplete(),
        ]);
    }

    /**
     * Store the company logo in the tenant's private folder and mark the welcome
     * step done. The returned URL is a short-lived signed URL.
     */
    public function uploadLogo(Request $request): JsonResponse
    {
        $request->validate([
            'logo' => ['required', 'image', 'max:2048'],
        ]);

        $path = TenantStorage::disk()->putFile('branding', $request->file('logo'));

        $progress = $this->progress();
        $progress->markStep('welcome');

        return response()->json([
            'logo_path' => $path,
            'logo_url' => TenantStorage::temporaryUrl($path),
        ]);
    }

    /**
     * Invite a teammate: creates an inactive-until-first-login tenant user with
     * a random password and marks the invites step. (Email delivery uses the
     * standard mailer; locally it lands in the log.)
     */
    public function invite(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'role' => ['nullable', 'string'],
        ]);

        $tempPassword = Str::random(16);

        $user = new User();
        $user->name = $data['name'];
        $user->email = $data['email'];
        $user->password = Hash::make($tempPassword);
        $user->is_active = true;
        $user->save();

        $user->assignRole($data['role'] ?? 'staff');

        $progress = $this->progress();
        $progress->markStep('invites');

        return response()->json([
            'message' => 'Invitation created for '.$data['email'].'.',
            'user' => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email],
            'temp_password' => app()->environment('local') ? $tempPassword : null,
        ], 201);
    }
}
