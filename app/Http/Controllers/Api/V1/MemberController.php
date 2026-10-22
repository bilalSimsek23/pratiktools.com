<?php

namespace App\Http\Controllers\Api\V1;

use App\Audit\AuditAction;
use App\Audit\AuditLogger;
use App\Http\Controllers\Controller;
use App\Http\Resources\MemberResource;
use App\Models\TenantUser;
use App\Tenancy\Permission;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantRole;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class MemberController extends Controller
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly AuditLogger $audit,
    ) {}

    public function index(): AnonymousResourceCollection
    {
        Gate::authorize(Permission::MembersView->value);

        return MemberResource::collection(TenantUser::query()->with('user')->orderBy('created_at')->get());
    }

    public function updateRole(Request $request, TenantUser $member): MemberResource
    {
        Gate::authorize(Permission::MembersManage->value);

        $data = $request->validate(['role' => ['required', Rule::enum(TenantRole::class)]]);
        $newRole = TenantRole::from($data['role']);
        $actorRole = $this->context->membership()?->role;

        // Only owners grant or take away the owner role.
        if (($newRole === TenantRole::Owner || $member->role === TenantRole::Owner) && $actorRole !== TenantRole::Owner) {
            abort(403);
        }

        DB::transaction(function () use ($member, $newRole) {
            if ($member->role === TenantRole::Owner && $newRole !== TenantRole::Owner) {
                // Lock the owner rows in a subquery: FOR UPDATE cannot be combined with COUNT.
                $lockedOwners = TenantUser::query()->select('id')->where('role', TenantRole::Owner->value)->where('status', 'active')->lockForUpdate();
                $owners = DB::query()->fromSub($lockedOwners, 'owners')->count();
                if ($owners <= 1) {
                    throw ValidationException::withMessages(['role' => 'A tenant must keep at least one owner.']);
                }
            }

            $previous = $member->role;
            $member->forceFill(['role' => $newRole])->save();

            $this->audit->record(AuditAction::MemberRoleChanged, 'tenant_user', $member->id, [
                'user_id' => $member->user_id,
                'from' => $previous->value,
                'to' => $newRole->value,
            ]);
        });

        return new MemberResource($member->load('user'));
    }
}
