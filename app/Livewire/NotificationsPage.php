<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Models\Sbo;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

final class NotificationsPage extends Component
{
    use WithPagination;

    /** Filter: 'all' or 'unread'. */
    #[Url(as: 'filter', history: true, keep: true)]
    public string $filter = 'all';

    /** Render pagination with Bootstrap markup (this app is Bootstrap-based). */
    protected string $paginationTheme = 'bootstrap';

    public function setFilter(string $filter): void
    {
        $this->filter = in_array($filter, ['all', 'unread'], true) ? $filter : 'all';
        $this->resetPage();
    }

    public function markRead(string $id): void
    {
        Auth::user()?->notifications()->whereKey($id)->first()?->markAsRead();
    }

    public function markAllRead(): void
    {
        Auth::user()?->unreadNotifications->markAsRead();
    }

    public function markUnread(string $id): void
    {
        $notification = Auth::user()?->notifications()->whereKey($id)->first();
        $notification?->forceFill(['read_at' => null])->save();
    }

    public function delete(string $id): void
    {
        Auth::user()?->notifications()->whereKey($id)->delete();
    }

    public function open(string $id): void
    {
        $notification = Auth::user()?->notifications()->whereKey($id)->first();

        if ( ! $notification) {
            return;
        }

        $notification->markAsRead();
        $data = (array) $notification->data;

        if (filled($data['url'] ?? null)) {
            $this->redirect((string) $data['url'], navigate: true);

            return;
        }

        $sboId = $data['sbo_id'] ?? null;

        if (filled($sboId)) {
            $type = (string) ($data['type'] ?? '');
            $step = str_contains($type, 'document')
                ? 'documents'
                : (str_contains($type, 'fee') || str_contains($type, 'registration') ? 'fees' : 'submitted');
            $sbo = Sbo::find($sboId);

            if ($sbo) {
                $this->redirect(route('sbo.applications.progress', ['sbo' => $sbo, 'step' => $step]), navigate: true);
            }
        }
    }

    /**
     * @return LengthAwarePaginator<int, \Illuminate\Notifications\DatabaseNotification>
     */
    public function notifications(): LengthAwarePaginator
    {
        $query = Auth::user()->notifications()->latest();

        if ('unread' === $this->filter) {
            $query->whereNull('read_at');
        }

        return $query->paginate(15);
    }

    public function render()
    {
        return view('livewire.notifications-page', [
            'notifications' => $this->notifications(),
            'unreadCount' => Auth::user()->unreadNotifications()->count(),
        ]);
    }
}
