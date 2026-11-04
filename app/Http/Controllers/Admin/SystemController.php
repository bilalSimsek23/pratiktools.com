<?php

namespace App\Http\Controllers\Admin;

use App\Health\HealthChecker;
use App\Http\Controllers\Controller;
use Illuminate\View\View;

final class SystemController extends Controller
{
    public function __invoke(HealthChecker $health): View
    {
        return view('admin.system', ['health' => $health->check()]);
    }
}
