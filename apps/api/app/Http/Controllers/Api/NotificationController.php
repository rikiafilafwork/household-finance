<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\NotificationResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Notifications\DatabaseNotification;

/**
 * Lonceng web & mobile (in-app saja, tanpa email): notifikasi milik user yang login.
 * Id notifikasi orang lain → 404.
 */
class NotificationController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'unread' => ['sometimes', 'boolean'],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
        ]);
        $user = $this->user($request);

        $page = $user->notifications()
            ->when($request->boolean('unread'), fn ($q) => $q->whereNull('read_at'))
            ->reorder()->orderByDesc('created_at')->orderByDesc('id')
            ->paginate($request->integer('per_page', 20))
            ->withQueryString();

        return NotificationResource::collection($page)->additional([
            'meta' => ['unread_count' => $user->unreadNotifications()->count()],
        ]);
    }

    public function read(Request $request, string $notification): NotificationResource
    {
        $found = $this->find($request, $notification);
        $found->markAsRead();

        return new NotificationResource($found);
    }

    public function readAll(Request $request): JsonResponse
    {
        $marked = $this->user($request)->unreadNotifications()->update(['read_at' => now()]);

        return response()->json(['data' => ['marked' => $marked, 'unread_count' => 0]]);
    }

    private function find(Request $request, string $id): DatabaseNotification
    {
        /** @var DatabaseNotification */
        return $this->user($request)->notifications()->whereKey($id)->firstOrFail();
    }

    private function user(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
