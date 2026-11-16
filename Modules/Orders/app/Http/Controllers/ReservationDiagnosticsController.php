<?php

namespace Modules\Orders\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Orders\Http\Resources\ReservationDiagnosticResource;
use Modules\Orders\Models\OrderReservation;

/**
 * Admin diagnostics: Laravel's workflow record of every order attempt, including
 * attempts that never became an order. Read-only.
 */
class ReservationDiagnosticsController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['nullable', 'string', Rule::in(OrderReservation::STATES)],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $page = OrderReservation::query()
            ->with('user:id,name')
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->orderByDesc('id')
            ->paginate(15);

        $counts = OrderReservation::query()
            ->selectRaw('status, COUNT(*) AS total')
            ->groupBy('status')
            ->pluck('total', 'status')
            ->map(fn ($total) => (int) $total)
            ->sortKeys()
            ->all();

        return Response::Success(
            data: [
                'items' => ReservationDiagnosticResource::collection($page->getCollection())->resolve($request),
                'counts' => $counts,
                'pagination' => [
                    'current_page' => $page->currentPage(),
                    'per_page' => $page->perPage(),
                    'total' => $page->total(),
                    'last_page' => $page->lastPage(),
                ],
            ],
            message: 'Reservations retrieved',
        )->header('Cache-Control', 'no-store');
    }
}
