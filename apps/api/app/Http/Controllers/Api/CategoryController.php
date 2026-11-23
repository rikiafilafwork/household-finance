<?php

namespace App\Http\Controllers\Api;

use App\Actions\Categories\DeleteCategory;
use App\Enums\CategoryKind;
use App\Http\Controllers\Controller;
use App\Http\Requests\Categories\StoreCategoryRequest;
use App\Http\Requests\Categories\UpdateCategoryRequest;
use App\Http\Resources\CategoryResource;
use App\Models\Category;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/**
 * Kategori milik user login. Default daftar = yang aktif (untuk pilihan transaksi baru);
 * `include_archived=1` untuk halaman kelola dan menampilkan transaksi lama.
 */
class CategoryController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'kind' => ['sometimes', Rule::enum(CategoryKind::class)],
            'include_archived' => ['sometimes', 'boolean'],
        ]);

        $categories = Category::query()
            ->when($request->filled('kind'), fn ($q) => $q->where('kind', $request->string('kind')->toString()))
            ->when(! $request->boolean('include_archived'), fn ($q) => $q->whereNull('archived_at'))
            ->orderBy('kind')
            ->orderBy('name')
            ->get();

        return CategoryResource::collection($categories);
    }

    public function store(StoreCategoryRequest $request): JsonResponse
    {
        /** @var array{kind: string, name: string, color?: string|null, icon?: string|null} $data */
        $data = $request->validated();
        $category = Category::create($data + ['user_id' => $request->user()?->getAuthIdentifier()]);

        return (new CategoryResource($category))->response()->setStatusCode(201);
    }

    public function show(Category $category): CategoryResource
    {
        return new CategoryResource($category);
    }

    public function update(UpdateCategoryRequest $request, Category $category): CategoryResource
    {
        $category->update($request->validated());

        return new CategoryResource($category);
    }

    public function destroy(Category $category, DeleteCategory $deleteCategory): Response
    {
        $deleteCategory->handle($category);

        return response()->noContent();
    }

    public function archive(Category $category): CategoryResource
    {
        $category->update(['archived_at' => $category->archived_at ?? now()]);

        return new CategoryResource($category);
    }

    public function unarchive(Category $category): CategoryResource
    {
        $category->update(['archived_at' => null]);

        return new CategoryResource($category);
    }
}
