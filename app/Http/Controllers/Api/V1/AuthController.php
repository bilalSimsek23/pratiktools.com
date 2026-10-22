<?php

namespace App\Http\Controllers\Api\V1;

use App\Audit\Actor;
use App\Audit\AuditAction;
use App\Audit\AuditLogger;
use App\Http\Controllers\Controller;
use App\Models\PersonalAccessToken;
use App\Models\TenantUser;
use App\Models\User;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantDirectory;
use App\Tenancy\TenantScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class AuthController extends Controller
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly TenantDirectory $directory,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Issues an API token bound to one tenant the user is an active member
     * of. Every failure returns the same message.
     */
    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string', 'max:255'],
            'tenant' => ['required', 'string', 'max:64'],
        ]);

        $user = User::query()->where('email', Str::lower($data['email']))->first();
        $tenantId = $this->directory->idBySlug(Str::lower($data['tenant']));

        // Always hash, so response time does not reveal whether the user exists.
        $passwordOk = Hash::check($data['password'], $user->password ?? '$2y$12$'.str_repeat('.', 53));

        try {
            $membership = null;

            if ($user !== null && $passwordOk && $tenantId !== null) {
                $this->context->setUser($user->id);
                $membership = TenantUser::query()
                    ->withoutGlobalScope(TenantScope::class)
                    ->where('tenant_id', $tenantId)
                    ->where('user_id', $user->id)
                    ->where('status', 'active')
                    ->first();
            }

            if ($user === null || $membership === null) {
                $this->audit->record(AuditAction::LoginFailed, metadata: ['reason' => 'invalid_credentials_or_membership'], actor: Actor::system(), tenantId: false);

                throw ValidationException::withMessages(['email' => __('auth.failed')]);
            }

            return $this->context->run($tenantId, function () use ($user, $tenantId, $membership) {
                $issued = $user->createToken('api', ['*'], now()->addMinutes((int) config('sanctum.expiration', 720)));
                /** @var PersonalAccessToken $token */
                $token = $issued->accessToken;
                $token->forceFill(['tenant_id' => $tenantId])->save();

                $this->audit->record(AuditAction::LoginSucceeded, 'user', $user->id, ['token_id' => $token->id], Actor::user($user->id));

                return response()->json([
                    'token' => $issued->plainTextToken,
                    'token_type' => 'Bearer',
                    'expires_at' => $token->expires_at?->toIso8601String(),
                    'tenant_id' => $tenantId,
                    'role' => $membership->role->value,
                ]);
            });
        } finally {
            $this->context->clear();
        }
    }

    public function logout(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        /** @var PersonalAccessToken $token */
        $token = $user->currentAccessToken();

        $this->audit->record(AuditAction::Logout, 'user', $user->id, ['token_id' => $token->id]);
        $token->delete();

        return response()->json(['status' => 'logged_out']);
    }
}
