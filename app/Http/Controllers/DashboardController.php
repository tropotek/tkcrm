<?php

namespace App\Http\Controllers;

use App\Dashboard\Dashboard;
use Illuminate\View\View;

/**
 * The landing page.
 */
class DashboardController extends Controller
{
    public function __invoke(): View
    {
        return view('dashboard', ['dashboard' => Dashboard::now()]);
    }
}
