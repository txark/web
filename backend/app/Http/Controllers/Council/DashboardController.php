<?php

namespace App\Http\Controllers\Council;

use App\Enums\GroupStatus;
use App\Enums\GroupType;
use App\Enums\TransactionKind;
use App\Enums\TransactionStatus;
use App\Http\Controllers\Controller;
use App\Models\CouncilLoan;
use App\Models\DutyShift;
use App\Models\Group;
use App\Models\Transaction;
use App\Models\User;
use App\Services\DutyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** แดชบอร์ดสรุปภาพรวมสำหรับประธานและรองประธานสภา (อ่านอย่างเดียว) */
class DashboardController extends Controller
{
    public function __construct(private DutyService $duty) {}

    public function show(Request $request): JsonResponse
    {
        $tz = config('council.report_timezone');
        $weekStart = now($tz)->startOfWeek();
        $monthStart = now($tz)->startOfMonth();

        return response()->json(['data' => [
            'generated_at' => now()->toIso8601String(),
            'week_from' => $weekStart->toDateString(),
            'groups' => $this->groups($monthStart),
            'requests' => $this->requests($weekStart),
            'finance' => $this->finance($request),
            'loans' => $this->loans(),
            'duty' => $this->dutyData($weekStart),
            'alerts' => app(AlertController::class)->index($request)->getData(true)['meta'] ?? [],
        ]]);
    }

    private function groups($monthStart): array
    {
        $all = Group::query()->get(['type', 'is_lady', 'status', 'registered_at']);

        return [
            'total' => $all->count(),
            'gangs' => $all->where('type', GroupType::Gang)->count(),
            'families' => $all->where('type', GroupType::Family)->count(),
            'new_this_month' => $all->filter(fn ($g) => $g->registered_at && $g->registered_at->gte($monthStart->copy()->utc()))->count(),
            'by_status' => collect(GroupStatus::cases())->map(fn (GroupStatus $s) => [
                'key' => $s->value, 'label' => $s->label(), 'count' => $all->where('status', $s)->count(),
            ])->values(),
        ];
    }

    private function requests($weekStart): array
    {
        $pending = Transaction::query()->where('status', TransactionStatus::Pending->value)->get(['kind', 'created_at']);
        $oldest = $pending->min('created_at');
        $since = $weekStart->copy()->utc();

        return [
            'pending' => $pending->count(),
            'pending_over_day' => $pending->filter(fn ($t) => $t->created_at->lt(now()->subDay()))->count(),
            'oldest_pending_at' => $oldest?->toIso8601String(),
            'by_kind' => $pending->groupBy(fn ($t) => $t->kind->value)->map(fn ($g, $k) => [
                'key' => $k, 'label' => TransactionKind::from($k)->label(), 'count' => $g->count(),
            ])->sortByDesc('count')->values(),
            'approved_this_week' => Transaction::where('status', TransactionStatus::Approved->value)->where('reviewed_at', '>=', $since)->count(),
            'rejected_this_week' => Transaction::where('status', TransactionStatus::Rejected->value)->where('reviewed_at', '>=', $since)->count(),
        ];
    }

    private function finance(Request $request): array
    {
        $finance = app(FinanceController::class);
        $month = $finance->summary(Request::create('/', 'GET'))->getData(true)['data'];

        return [
            'currency' => $month['currency'],
            'central' => $finance->centralBalance()['balance'],
            'month_from' => $month['from'],
            'month_income' => $month['income'],
            'month_expense' => $month['expense'],
            'month_balance' => $month['balance'],
            'top_income' => array_slice($month['by_kind'], 0, 4),
            'top_expense' => array_slice($month['by_category'], 0, 4),
        ];
    }

    private function loans(): array
    {
        $rows = CouncilLoan::query()->with('payments')->whereNull('cancelled_at')->get();
        $open = $rows->filter(fn (CouncilLoan $l) => $l->outstanding() > 0);

        return [
            'active' => $open->count(),
            'overdue' => $open->filter(fn (CouncilLoan $l) => $l->status() === 'overdue')->count(),
            'outstanding' => (int) $open->sum(fn (CouncilLoan $l) => $l->outstanding()),
            'lent_total' => (int) $rows->sum('principal'),
        ];
    }

    private function dutyData($weekStart): array
    {
        $this->duty->closeAllStale();
        $seconds = $this->duty->secondsByUser($weekStart->copy()->utc(), now()->utc());

        $onNow = DutyShift::query()->whereNull('ended_at')->with('user')->get()
            ->filter(fn (DutyShift $s) => $s->user && ! $s->user->role->isAdmin())
            ->map(fn (DutyShift $s) => [
                'id' => $s->user->id,
                'name' => $s->user->character_name ?: $s->user->name,
                'role_label' => $s->user->role->label(),
                'started_at' => $s->started_at->toIso8601String(),
            ])->values();

        $users = User::query()->whereIn('id', array_keys($seconds))->get()->keyBy('id');
        $top = collect($seconds)->filter(fn ($s, $id) => $s > 0 && $users->has($id))
            ->sortDesc()->take(5)
            ->map(fn ($s, $id) => ['id' => $id, 'name' => $users[$id]->character_name ?: $users[$id]->name, 'role_label' => $users[$id]->role->label(), 'seconds' => (int) $s])
            ->values();

        return [
            'on_now' => $onNow,
            'week_total_seconds' => (int) array_sum($seconds),
            'top_week' => $top,
        ];
    }
}
