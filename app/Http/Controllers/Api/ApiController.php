<?php

namespace App\Http\Controllers\Api;

use App\Api\ApiData;
use App\Http\Controllers\Controller;
use App\Support\FinancialYear;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The read-only endpoints. Each answers what App\Api\ApiData works out from the query string,
 * which is the screens' own searches and reports. The MCP server's tools call the same class.
 */
class ApiController extends Controller
{
    public function __construct(private ApiData $data) {}

    /**
     * What the API has, for whoever opens it.
     */
    public function index(): JsonResponse
    {
        $endpoints = ['clients', 'invoices', 'outstanding', 'tasks', 'time-logs', 'recurring', 'expenses', 'profit-loss', 'sites'];

        return response()->json(['data' => [
            'name' => 'tkcrm',
            'read_only' => true,
            'currency' => 'AUD',
            'endpoints' => array_map(fn (string $endpoint) => url("/api/v1/{$endpoint}"), $endpoints),
        ]]);
    }

    public function clients(Request $request): JsonResponse
    {
        return response()->json($this->data->clients($request->query()));
    }

    public function invoices(Request $request): JsonResponse
    {
        return response()->json($this->data->invoices($request->query()));
    }

    public function outstanding(Request $request): JsonResponse
    {
        return response()->json($this->data->outstanding($request->query()));
    }

    public function tasks(Request $request): JsonResponse
    {
        return response()->json($this->data->tasks($request->query()));
    }

    public function timeLogs(Request $request): JsonResponse
    {
        return response()->json($this->data->timeLogs($request->query()));
    }

    public function recurrings(Request $request): JsonResponse
    {
        return response()->json($this->data->recurrings($request->query()));
    }

    public function expenses(Request $request): JsonResponse
    {
        return response()->json($this->data->expenses($request->query()));
    }

    /**
     * The token's user's current financial year, or the one named: `/profit-loss/2027`.
     */
    public function profitLoss(?int $year = null): JsonResponse
    {
        return response()->json($this->data->profitLoss($year ?? FinancialYear::current()->year));
    }

    public function sites(Request $request): JsonResponse
    {
        return response()->json($this->data->sites($request->query()));
    }
}
