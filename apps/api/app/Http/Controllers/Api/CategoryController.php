<?php

namespace App\Http\Controllers\Api;

use App\Actions\Categories\DeleteCategory;
use App\Enums\CategoryKind;
use App\Http\Controllers\Controller;
use App\Http\Requests\Categories\StoreCategoryRequest;
use App\Http\Requests\Categories\UpdateCategoryRequest;
use App\Http\Resources\CategoryResource;
use App\Models\Category;
use App\Models\Household;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Kategori per household. Default daftar = yang aktif (untuk pilihan transaksi baru);
 * `include_archived=1` untuk halaman kelola dan menampilkan transaksi lama.
 */
class CategoryController extends Controller
{
    public function index(Request $request, Household $household): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', [Category::class, $household]);

        $request->validate([
            'kind' => ['sometimes', Rule::enum(CategoryKind::class)],
            'include_archived' => ['sometimes', 'boolean'],
        ]);

        $categories = $household->categories()
            ->when($request->filled('kind'), fn ($q) => $q->where('kind', $request->string('kind')->toString()))
            ->when(! $request->boolean('include_archived'), fn ($q) => $q->whereNull('archived_at'))
            ->orderBy('kind')
            ->orderBy('name')
            ->get();

        return CategoryResource::collection($categories);
    }

    public function store(StoreCategoryRequest $request, Household $household): JsonResponse
    {
        Gate::authorize('create', [Category::class, $household]);

        /** @var array{kind: string, name: string, color?: string|null, icon?: string|null} $data */
        $data = $request->validated();
        $category = $household->categories()->create($data);

        return (new CategoryResource($category))->response()->setStatusCode(201);
    }

    public function show(Household $household, Category $category): CategoryResource
    {
        Gate::authorize('viewAny', [Category::class, $household]);

        return new CategoryResource($category);
    }

    public function update(UpdateCategoryRequest $request, Household $household, Category $category): CategoryResource
    {
        Gate::authorize('manage', $category);

        $category->update($request->validated());

        return new CategoryResource($category);
    }

    public function destroy(Household $household, Category $category, DeleteCategory $deleteCategory): Response
    {
        Gate::authorize('manage', $category);

        $deleteCategory->handle($category);

        return response()->noContent();
    }

    public function archive(Household $household, Category $category): CategoryResource
    {
        Gate::authorize('manage', $category);

        $category->update(['archived_at' => $category->archived_at ?? now()]);

        return new CategoryResource($category);
    }

    public function unarchive(Household $household, Category $category): CategoryResource
    {
        Gate::authorize('manage', $category);

        $category->update(['archived_at' => null]);

        return new CategoryResource($category);
    }
}
