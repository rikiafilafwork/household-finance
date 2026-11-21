<?php

namespace App\Http\Controllers\Api;

use App\Actions\Households\CreateHousehold;
use App\Http\Controllers\Controller;
use App\Http\Requests\Households\StoreHouseholdRequest;
use App\Http\Resources\HouseholdResource;
use App\Models\Household;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class HouseholdController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        /** @var User $user */
        $user = $request->user();

        return HouseholdResource::collection($user->households()->orderBy('households.name')->get());
    }

    public function store(StoreHouseholdRequest $request, CreateHousehold $createHousehold): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $household = $createHousehold->handle(
            $user,
            $request->string('name')->toString(),
            $request->string('timezone', 'Asia/Jakarta')->toString(),
        );

        return (new HouseholdResource($household))->response()->setStatusCode(201);
    }

    public function show(Household $household): HouseholdResource
    {
        Gate::authorize('view', $household);

        return new HouseholdResource($household);
    }
}
