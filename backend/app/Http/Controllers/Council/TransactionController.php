<?php

namespace App\Http\Controllers\Council;

use App\Enums\TransactionKind;
use App\Enums\TransactionStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\TransactionResource;
use App\Models\Group;
use App\Models\Transaction;
use App\Services\TransactionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/** ธุรกรรมของแก๊ง/ครอบครัว: ส่ง / ดู / อนุมัติ / ไม่อนุมัติ / ยกเลิก (ตรรกะทั้งหมดอยู่ใน TransactionService) */
class TransactionController extends Controller
{
    public function __construct(private TransactionService $service) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $f = $request->validate([
            'kind' => ['nullable', Rule::enum(TransactionKind::class)],
            'status' => ['nullable', Rule::enum(TransactionStatus::class)],
            'group_id' => ['nullable', 'integer'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $items = Transaction::query()
            ->with(['group', 'requester', 'reviewer', 'activitiesAsSubject'])
            ->when($f['kind'] ?? null, fn ($q, $v) => $q->where('kind', $v))
            ->when($f['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($f['group_id'] ?? null, fn ($q, $v) => $q->where('group_id', $v))
            ->orderByDesc('id')
            ->paginate($f['per_page'] ?? 30)
            ->withQueryString();

        // ราคาค่าบริการต่อสมาชิกที่ออก 1 คน ให้หน้าเว็บคำนวณยอดรวมแบบเรียลไทม์
        return TransactionResource::collection($items)->additional(['fees' => config('council.fees')]);
    }

    public function show(Transaction $transaction): TransactionResource
    {
        return new TransactionResource($transaction->load(['group', 'requester', 'reviewer', 'activitiesAsSubject']));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'kind' => ['required', Rule::enum(TransactionKind::class)],
            'group_id' => ['nullable', 'integer'],
            'payload' => ['sometimes', 'array'],
            'approval_required' => ['sometimes', 'boolean'],
        ], [
            'kind.required' => 'กรุณาเลือกชนิดธุรกรรม',
            'kind.enum' => 'ชนิดธุรกรรมไม่ถูกต้อง',
        ]);

        $group = isset($data['group_id']) ? Group::withTrashed()->find($data['group_id']) : null;

        // all() รวมไฟล์ที่แนบใน payload (multipart) เข้ากับค่าอื่นแล้ว
        $payload = $request->all()['payload'] ?? [];

        $tx = $this->service->submit(
            $request->user(),
            TransactionKind::from($data['kind']),
            $group,
            $payload,
            (bool) ($data['approval_required'] ?? false),
        );

        return (new TransactionResource($tx))->response()->setStatusCode(201);
    }

    public function approve(Request $request, Transaction $transaction): TransactionResource
    {
        return new TransactionResource($this->service->approve($transaction, $request->user()));
    }

    public function reject(Request $request, Transaction $transaction): TransactionResource
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:2000']], ['reason.required' => 'กรุณาระบุเหตุผลที่ไม่อนุมัติ']);

        return new TransactionResource($this->service->reject($transaction, $request->user(), $data['reason']));
    }

    public function cancel(Request $request, Transaction $transaction): TransactionResource
    {
        return new TransactionResource($this->service->cancel($transaction, $request->user()));
    }
}
