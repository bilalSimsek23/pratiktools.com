<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\CustomerResource;
use App\Models\Customer;
use App\Tenancy\Permission;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * Read-only in F0; customers are written by the commerce sync (F1).
 */
final class CustomerController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        Gate::authorize(Permission::CustomersView->value);

        return CustomerResource::collection(Customer::query()->orderByDesc('created_at')->cursorPaginate(50));
    }

    public function show(Customer $customer): CustomerResource
    {
        Gate::authorize(Permission::CustomersView->value);

        return new CustomerResource($customer);
    }
}
