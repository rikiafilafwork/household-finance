<?php

namespace App\Http\Controllers\Api;

use App\Actions\Transfers\CreateTransfer;
use App\Actions\Transfers\DeleteTransfer;
use App\Actions\Transfers\TransferPair;
use App\Actions\Transfers\UpdateTransfer;
use App\Http\Controllers\Controller;
use App\Http\Requests\Transfers\StoreTransferRequest;
use App\Http\Requests\Transfers\UpdateTransferRequest;
use App\Http\Resources\TransferResource;
use App\Models\Household;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * Transfer diidentifikasi transfer_group_id (UUID), bukan id baris.
 */
class TransferController extends Controller
{
    public function store(StoreTransferRequest $request, Household $household, CreateTransfer $createTransfer): JsonResponse
    {
        Gate::authorize('view', $household);

        [$pair, $created] = $createTransfer->handle($household, $this->user($request), $request->toInput());

        return (new TransferResource($pair))->response()->setStatusCode($created ? 201 : 200);
    }

    public function show(Request $request, Household $household, string $transfer): TransferResource
    {
        $pair = TransferPair::find($household, $transfer);
        abort_unless($pair->visibleTo($this->user($request)), 403);

        return new TransferResource($pair);
    }

    public function update(UpdateTransferRequest $request, Household $household, string $transfer, UpdateTransfer $updateTransfer): TransferResource
    {
        $user = $this->user($request);
        $pair = TransferPair::find($household, $transfer);
        abort_unless($pair->manageableBy($user), 403);

        return new TransferResource($updateTransfer->handle($household, $user, $pair, $request->toInput($pair)));
    }

    public function destroy(Request $request, Household $household, string $transfer, DeleteTransfer $deleteTransfer): Response
    {
        $user = $this->user($request);
        $pair = TransferPair::find($household, $transfer);
        abort_unless($pair->manageableBy($user), 403);

        $deleteTransfer->handle($household, $user, $pair);

        return response()->noContent();
    }

    private function user(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
