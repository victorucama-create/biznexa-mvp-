<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use App\Traits\ApiResponse;

class DashboardController extends Controller
{
    use ApiResponse;

    public function index(Request $request)
    {
        $company = auth()->user()->company;
        $period = $request->get('period', 'today'); // today, week, month, year

        $today = Carbon::today();
        $startDate = $this->getStartDate($period);

        // Total Sales
        $totalSales = $company->sales()
            ->whereBetween('created_at', [$startDate, Carbon::now()])
            ->sum('total_amount');

        // Sales Count
        $salesCount = $company->sales()
            ->whereBetween('created_at', [$startDate, Carbon::now()])
            ->count();

        // Average Ticket
        $averageTicket = $salesCount > 0 ? $totalSales / $salesCount : 0;

        // Online Orders
        $onlineOrders = $company->sales()
            ->where('type', 'online')
            ->whereBetween('created_at', [$startDate, Carbon::now()])
            ->count();

        // Products Low Stock
        $lowStockProducts = $company->products()
            ->lowStock()
            ->count();

        // Sales by Day (for chart)
        $salesByDay = $company->sales()
            ->select(
                DB::raw('DATE(created_at) as date'),
                DB::raw('SUM(total_amount) as total'),
                DB::raw('COUNT(*) as count')
            )
            ->whereBetween('created_at', [$startDate, Carbon::now()])
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        // Top Products
        $topProducts = DB::table('sale_items')
            ->join('products', 'sale_items.product_id', '=', 'products.id')
            ->join('sales', 'sale_items.sale_id', '=', 'sales.id')
            ->select(
                'products.name',
                'products.sku',
                DB::raw('SUM(sale_items.quantity) as total_quantity'),
                DB::raw('SUM(sale_items.subtotal) as total_revenue')
            )
            ->where('sales.company_id', $company->id)
            ->whereBetween('sales.created_at', [$startDate, Carbon::now()])
            ->groupBy('products.id', 'products.name', 'products.sku')
            ->orderBy('total_quantity', 'desc')
            ->limit(10)
            ->get();

        // Recent Sales
        $recentSales = $company->sales()
            ->with(['items.product', 'cashier'])
            ->latest()
            ->limit(10)
            ->get();

        // Store Visits (simulated - in production would use analytics)
        $storeVisits = $company->store->visits()->count();

        return $this->successResponse([
            'stats' => [
                'total_sales' => $totalSales,
                'sales_count' => $salesCount,
                'average_ticket' => round($averageTicket, 2),
                'online_orders' => $onlineOrders,
                'low_stock_products' => $lowStockProducts,
                'store_visits' => $storeVisits,
                'conversion_rate' => $storeVisits > 0 ? round(($onlineOrders / $storeVisits) * 100, 2) : 0
            ],
            'charts' => [
                'sales_by_day' => $salesByDay,
                'top_products' => $topProducts
            ],
            'recent_sales' => $recentSales,
            'period' => $period,
            'start_date' => $startDate->format('Y-m-d'),
            'end_date' => Carbon::now()->format('Y-m-d')
        ]);
    }

    private function getStartDate($period)
    {
        switch ($period) {
            case 'week':
                return Carbon::now()->subWeek();
            case 'month':
                return Carbon::now()->subMonth();
            case 'year':
                return Carbon::now()->subYear();
            default: // today
                return Carbon::today();
        }
    }

    public function quickStats()
    {
        $company = auth()->user()->company;
        $today = Carbon::today();

        $todaySales = $company->sales()
            ->whereDate('created_at', $today)
            ->sum('total_amount');

        $todayOrders = $company->sales()
            ->whereDate('created_at', $today)
            ->count();

        $pendingOrders = $company->sales()
            ->where('status', 'pending')
            ->where('type', 'online')
            ->count();

        $activeProducts = $company->products()
            ->active()
            ->count();

        return $this->successResponse([
            'today_sales' => $todaySales,
            'today_orders' => $todayOrders,
            'pending_orders' => $pendingOrders,
            'active_products' => $activeProducts
        ]);
    }
}
