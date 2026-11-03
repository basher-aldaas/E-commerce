<?php

namespace Modules\Notifications\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class NotificationsController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    #[OA\Get(
        path: '/api/v1/notifications',
        tags: ['Notifications'],
        summary: 'List notifications (NOT YET IMPLEMENTED)',
        description: 'Scaffolded `apiResource` route. The controller currently returns a view / empty body and does not yet produce the standard JSON envelope. Documented here only so the route is visible.',
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(response: 200, description: 'Not yet implemented'),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
        ],
    )]
    public function index()
    {
        return view('notifications::index');
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        return view('notifications::create');
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request) {}

    /**
     * Show the specified resource.
     */
    public function show($id)
    {
        return view('notifications::show');
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit($id)
    {
        return view('notifications::edit');
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, $id) {}

    /**
     * Remove the specified resource from storage.
     */
    public function destroy($id) {}
}
