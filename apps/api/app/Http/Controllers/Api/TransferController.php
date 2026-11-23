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
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Transfer diidentifikasi transfer_group_id (UUID), bukan id baris. Milik user lain = 404.
 */
class TransferController extends Controller
{
    public function store(StoreTransferRequest $request, CreateTransfer $createTransfer): JsonResponse
    {
        [$pair, $created] = $createTransfer->handle($this->user($request), $request->toInput());

        return (new TransferResource($pair))->response()->setStatusCode($created ? 201 : 200);
    }

    public function show(Request $request, string $transfer): TransferResource
    {
        return new TransferResource(TransferPair::find($this->user($request), $transfer));
    }

    public function update(UpdateTransferRequest $request, string $transfer, UpdateTransfer $updateTransfer): TransferResource
    {
        $user = $this->user($request);
        $pair = TransferPair::find($user, $transfer);

        return new TransferResource($updateTransfer->handle($user, $pair, $request->toInput($pair)));
    }

    public function destroy(Request $request, string $transfer, DeleteTransfer $deleteTransfer): Response
    {
        $user = $this->user($request);
        $deleteTransfer->handle($user, TransferPair::find($user, $transfer));

        return response()->noContent();
    }

    private function user(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
