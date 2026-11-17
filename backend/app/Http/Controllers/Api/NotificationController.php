<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Carbon;

/**
 * The signed-in administrator's own in-app notifications. Every query goes through
 * $user->notifications(), so another user's notification is simply "not found" (404).
 */
class NotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'unread' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
        $user = $request->user();
        $page = $user->notifications()
            ->when($request->boolean('unread'), fn ($q) => $q->whereNull('read_at'))
            ->paginate((int) ($validated['per_page'] ?? 20));

        return response()->json([
            'data' => collect($page->items())->map(fn (DatabaseNotification $n) => self::present($n))->values(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'unread_count' => $user->unreadNotifications()->count(),
            ],
        ]);
    }

    public function unreadCount(Request $request): JsonResponse
    {
        return response()->json(['data' => ['unread_count' => $request->user()->unreadNotifications()->count()]]);
    }

    public function markRead(Request $request, string $notification): JsonResponse
    {
        $found = $request->user()->notifications()->whereKey($notification)->firstOrFail();
        if ($found->read_at === null) {
            $found->forceFill(['read_at' => Carbon::now()])->save();
        }

        return response()->json(['data' => self::present($found), 'meta' => ['unread_count' => $request->user()->unreadNotifications()->count()]]);
    }

    public function markAllRead(Request $request): JsonResponse
    {
        $updated = $request->user()->unreadNotifications()->update(['read_at' => Carbon::now()]);

        return response()->json(['data' => ['marked' => $updated, 'unread_count' => 0]]);
    }

    /** @return array<string, mixed> */
    public static function present(DatabaseNotification $n): array
    {
        $data = (array) $n->data;
        $href = (string) ($data['href'] ?? '/dashboard');

        return [
            'id' => $n->id,
            'kind' => $data['kind'] ?? $n->type,
            'title' => $data['title'] ?? '',
            'body' => $data['body'] ?? '',
            // Only internal workspace paths are ever followed (defence in depth against a crafted record).
            'href' => str_starts_with($href, '/') && ! str_starts_with($href, '//') ? $href : '/dashboard',
            'matter' => isset($data['matter_id']) ? ['id' => $data['matter_id'], 'matter_number' => $data['matter_number'] ?? null, 'title' => $data['matter_title'] ?? null] : null,
            'read_at' => $n->read_at?->toIso8601String(),
            'created_at' => $n->created_at?->toIso8601String(),
        ];
    }
}
