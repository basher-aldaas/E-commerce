<?php

namespace Modules\Products\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Products\Http\Requests\StoreCategoryRequest;
use Modules\Products\Services\CategoryService;

class CategoryController extends Controller
{
    public function __construct(
        private readonly CategoryService $categoryService
    ) {}

    public function index(): JsonResponse
    {
        return Response::Success(
            data: $this->categoryService->getAll(),
            message: 'Categories retrieved successfully',
            code: 200
        );
    }

    public function store(StoreCategoryRequest $request): JsonResponse
    {
        $category = $this->categoryService->create(
            $request->validated()
        );

        return Response::Success(
            data: $category,
            message: 'Category created successfully',
            code: 201
        );
    }
}
