<?php

namespace App\Http\Controllers;

use App\Http\Resources\ActivityResource;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Spatie\Activitylog\Models\Activity;

class LogController extends Controller
{
    /** ตัดวันตามเวลาไทยที่ผู้ใช้เห็น แล้วแปลงเป็น UTC ตอนค้นฐานข้อมูล */
    private const TZ = 'Asia/Bangkok';

    public function index(Request $request): AnonymousResourceCollection
    {
        $f = $request->validate([
            // หมวด = log_name (changes = แก้ไขข้อมูลทั่วไป = default)
            'type' => ['nullable', Rule::in(['auth', 'access', 'changes', 'transactions', 'groups', 'duty'])],
            'event' => ['nullable', 'string', 'max:32'],
            'user_id' => ['nullable', 'integer'],
            'q' => ['nullable', 'string', 'max:100'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $logs = Activity::query()
            ->with('causer')
            ->when($f['type'] ?? null, fn ($q, $t) => $q->where('log_name', $t === 'changes' ? 'default' : $t))
            ->when($f['event'] ?? null, fn ($q, $e) => $q->where('event', $e))
            ->when($f['user_id'] ?? null, fn ($q, $id) => $q->where('causer_type', User::class)->where('causer_id', $id))
            ->when($f['q'] ?? null, fn ($q, $term) => $q->where(function ($q) use ($term) {
                $q->whereLike('description', "%{$term}%")
                    ->orWhereHasMorph('causer', [User::class], fn ($c) => $c->whereLike('name', "%{$term}%"));
            }))
            ->when($f['from'] ?? null, fn ($q, $d) => $q->where('created_at', '>=', Carbon::parse($d, self::TZ)->startOfDay()->utc()))
            ->when($f['to'] ?? null, fn ($q, $d) => $q->where('created_at', '<=', Carbon::parse($d, self::TZ)->endOfDay()->utc()))
            ->latest('id')
            ->paginate($f['per_page'] ?? 50)
            ->withQueryString();

        return ActivityResource::collection($logs);
    }
}
