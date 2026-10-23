<?php

namespace App\Http\Controllers\Api\V1;

use App\Consent\ConsentReports;
use App\Consent\SendEligibility;
use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Tenancy\Permission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Consent panel API (Overview, Reconciliation, Conflicts) and the send
 * decision of one customer. Read-only, counts and masked samples only, no
 * manual overrides.
 */
final class ConsentController extends Controller
{
    public function __construct(private readonly ConsentReports $reports) {}

    public function overview(): JsonResponse
    {
        Gate::authorize(Permission::CustomersView->value);

        return response()->json(['data' => $this->reports->overview()]);
    }

    public function reconciliation(): JsonResponse
    {
        Gate::authorize(Permission::CustomersView->value);

        return response()->json(['data' => [
            'matrix' => $this->reports->matrix(),
            'classes' => $this->reports->reconciliation(),
            'newsletter' => $this->reports->newsletter(),
        ]]);
    }

    public function conflicts(Request $request): JsonResponse
    {
        Gate::authorize(Permission::CustomersView->value);
        $data = $request->validate(['kind' => ['sometimes', 'string', 'max:48'], 'limit' => ['sometimes', 'integer', 'min:1', 'max:100']]);

        return response()->json(['data' => $this->reports->conflicts($data['kind'] ?? null, (int) ($data['limit'] ?? 25))]);
    }

    public function eligibility(Customer $customer, SendEligibility $eligibility): JsonResponse
    {
        Gate::authorize(Permission::CustomersView->value);
        $decision = $eligibility->forCustomer($customer->id);

        return response()->json(['data' => [
            'customer_id' => $customer->id,
            'channel' => $decision->channel->value,
            'can_send' => $decision->allowed(),
            'reason' => $decision->reason->value,
            'details' => $decision->details,
        ]]);
    }
}
