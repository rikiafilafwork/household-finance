<?php

namespace App\Http\Controllers\Api;

use App\Actions\Plan\SavePlanTemplate;
use App\Http\Controllers\Controller;
use App\Http\Requests\Plan\PlanTemplateRequest;
use App\Http\Resources\PlanTemplateResource;
use App\Models\PlanTemplate;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * Pengaturan → Template bulanan (Keputusan #9). Nonaktifkan lewat PATCH `is_active=false`.
 * Hapus template: item yang sudah dibuat tetap ada (template_id jadi NULL).
 */
class PlanTemplateController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate(['active' => ['sometimes', 'boolean']]);

        $templates = PlanTemplate::query()
            ->with('category')
            ->when($request->has('active'), fn ($q) => $q->where('is_active', $request->boolean('active')))
            ->orderByDesc('is_active')
            ->orderBy('type')                                        // income dulu
            ->orderBy('mode')                                        // checklist dulu
            ->orderByRaw('day_of_month IS NULL, day_of_month')
            ->orderBy('name')
            ->orderBy('id')
            ->get();

        return PlanTemplateResource::collection($templates);
    }

    public function store(PlanTemplateRequest $request, SavePlanTemplate $save): JsonResponse
    {
        $template = $save->create($this->user($request), $request->toInput());

        return (new PlanTemplateResource($template->load('category')))->response()->setStatusCode(201);
    }

    public function show(PlanTemplate $planTemplate): PlanTemplateResource
    {
        return new PlanTemplateResource($planTemplate->load('category'));
    }

    public function update(PlanTemplateRequest $request, PlanTemplate $planTemplate, SavePlanTemplate $save): PlanTemplateResource
    {
        $template = $save->update($this->user($request), $planTemplate, $request->toInput($planTemplate));

        return new PlanTemplateResource($template->load('category'));
    }

    public function destroy(PlanTemplate $planTemplate): Response
    {
        $planTemplate->delete();

        return response()->noContent();
    }

    private function user(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
