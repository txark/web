<?php

namespace App\Http\Resources;

use App\Enums\TransactionKind;
use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Transaction */
class TransactionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $p = $this->payload ?? [];
        unset($p['logo_path'], $p['outfit_zip_path']); // path ไฟล์ภายในไม่ต้องเปิดเผย

        $person = fn ($u) => $u ? ['id' => $u->id, 'name' => $u->character_name ?: $u->name] : null;

        return [
            'id' => $this->id,
            'number' => $this->number,
            'kind' => $this->kind->value,
            'kind_label' => $this->kind->label(),
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'summary' => $this->summary($p),
            'payload' => $p,
            'group' => $this->group ? [
                'id' => $this->group->id,
                'name' => $this->group->name,
                'type' => $this->group->type->value,
                'dissolved' => $this->group->trashed(),
            ] : null,
            'approval_required' => $this->approval_required,
            'requested_by' => $person($this->requester),
            'requested_role' => $this->requested_role,
            'reviewed_by' => $person($this->reviewer),
            'review_reason' => $this->review_reason,
            'reviewed_at' => $this->reviewed_at,
            'created_at' => $this->created_at,
            // ภาพกลุ่มก่อน-หลังที่บันทึกไว้ตอนธุรกรรมมีผล (มีเมื่อโหลดความสัมพันธ์ activities ไว้)
            'before' => $this->whenLoaded('activitiesAsSubject', fn () => $this->logProperty('before')),
            'after' => $this->whenLoaded('activitiesAsSubject', fn () => $this->logProperty('after')),
        ];
    }

    /** @param  array<string, mixed>  $p */
    private static function names(array $p): string
    {
        return implode(', ', $p['member_names'] ?? (isset($p['member_name']) ? [$p['member_name']] : []));
    }

    public const OUTFIT_LABELS = ['suit' => 'Suit', 'hoodie' => 'Hoodie', 'tshirt' => 'T-Shirt', 'polo' => 'Polo', 'armor' => 'Armor', 'gun' => 'Gun', 'mod1' => 'MOD 1', 'mod2' => 'MOD 2', 'accessories' => 'Accessories'];

    /** เช่น "Suit → Hoodie (0 ชิ้น), Hoodie (1 ชิ้น)" */
    private static function replaced(array $p): string
    {
        $parts = [];
        $label = fn ($k) => self::OUTFIT_LABELS[$k] ?? $k;
        foreach ($p['types'] ?? [] as $key) {
            $n = $p['counts'][$key] ?? null;
            $swap = $p['swaps'][$key] ?? null;
            $parts[] = $label($key)
                .($swap ? ' → '.$label($swap) : '')
                .($n !== null ? " ({$n} ชิ้น)" : '');
        }

        return $parts ? implode(', ', $parts) : '-';
    }

    /**
     * รายละเอียดว่าธุรกรรมนี้ทำอะไรบ้าง สำหรับบันทึกกิจกรรม: [[หัวข้อ, ข้อความ], ...]
     *
     * @return list<array{0: string, 1: string}>
     */
    public static function detailLines(Transaction $tx): array
    {
        $p = $tx->payload ?? [];
        $lines = [['ธุรกรรม', $tx->kind->label().' '.$tx->number], ['สถานะ', $tx->status->label()]];

        switch ($tx->kind) {
            case TransactionKind::Outfit:
                if (! empty($p['add'])) {
                    $lines[] = ['ลงชุดเพิ่ม', self::counts(['counts' => $p['add']])];
                }
                if (! empty($p['replace']['types'])) {
                    $lines[] = ['เปลี่ยน/แทนชุด', self::replaced($p['replace'])];
                }
                $lines[] = ['ไฟล์ชุด', ! empty($p['replace']['types']) ? 'แทนที่ไฟล์ชุดหลักของกลุ่ม' : 'เก็บไว้กับธุรกรรม (ไม่แทนไฟล์ชุดหลัก)'];
                break;
            case TransactionKind::OutfitCounts:
                $lines[] = ['จำนวนชุดใหม่', self::counts($p)];
                break;
            default:
                $lines[] = ['รายการ', (new self($tx))->toArray(request())['summary']];
        }

        if (! empty($p['note'])) {
            $lines[] = ['หมายเหตุ', $p['note']];
        }
        foreach ($p['fee']['items'] ?? [] as $item) {
            $lines[] = ['ค่าบริการ', $item['label'].' · '.($item['amount'] > 0 ? number_format($item['amount']).' '.($p['fee']['currency'] ?? '') : 'ฟรี')];
        }
        if (($p['fee']['total'] ?? 0) > 0) {
            $lines[] = ['ค่าบริการรวม', number_format($p['fee']['total']).' '.($p['fee']['currency'] ?? '')];
        }
        if ($tx->review_reason) {
            $lines[] = ['เหตุผล', $tx->review_reason];
        }

        return $lines;
    }

    /** @param  array<string, mixed>  $p */
    private static function outfitParts(array $p): string
    {
        $parts = [];
        if (! empty($p['add'])) {
            $parts[] = 'ลงเพิ่ม '.self::counts(['counts' => $p['add']]);
        }
        if (! empty($p['replace']['types'])) {
            $parts[] = 'เปลี่ยน/แทน '.self::replaced($p['replace']);
        }
        $text = $parts ? implode(' · ', $parts) : '-';

        return $text.(! empty($p['note']) ? " ({$p['note']})" : '');
    }

    /** @param  array<string, mixed>  $p */
    private static function counts(array $p): string
    {
        $labels = self::OUTFIT_LABELS;
        $parts = [];
        foreach ($p['counts'] ?? [] as $key => $n) {
            $parts[] = ($labels[$key] ?? $key).' '.$n;
        }

        return $parts ? implode(', ', $parts) : '-';
    }

    /** @param  array<string, mixed>  $p */
    private static function fee(array $p): string
    {
        $f = $p['fee'] ?? null;

        return $f && ($f['total'] ?? 0) > 0 ? ' · ค่าบริการ '.number_format($f['total']).' '.($f['currency'] ?? '') : '';
    }

    private function logProperty(string $key): mixed
    {
        $log = $this->activitiesAsSubject->where('log_name', 'transactions')->sortByDesc('id')->first();

        return $log?->properties[$key] ?? null;
    }

    private function summary(array $p): string
    {
        $g = $this->group?->name;

        $text = match ($this->kind) {
            TransactionKind::Register => "ขึ้นทะเบียน {$p['name']} ({$p['short_name']})",
            TransactionKind::Rename => "เปลี่ยนชื่อ {$g} → {$p['name']}",
            TransactionKind::Recolor => "เปลี่ยนสี {$g} → {$p['color_hex']} {$p['color_name']}",
            TransactionKind::ChangeLeader => "เปลี่ยน Leader {$g} → {$p['name']}",
            TransactionKind::ReleaseSlot => 'ออกสล็อต '.self::names($p)." จาก {$g}",
            TransactionKind::ReleaseFree => 'ออกลอย '.self::names($p)." จาก {$g}",
            TransactionKind::Dissolve => "ยุบ {$g}",
            TransactionKind::Outfit => "ลงชุด/แก้ไขชุด {$g}: ".self::outfitParts($p),
            TransactionKind::OutfitAdd => "ลงชุดเพิ่ม {$g}: ".self::counts($p),
            TransactionKind::OutfitCounts => "แก้จำนวนชุด {$g}: ".self::counts($p),
            TransactionKind::OutfitFile => "เปลี่ยนไฟล์ชุด {$g}: ".self::replaced($p).(! empty($p['note']) ? " ({$p['note']})" : ''),
        };

        return $text.self::fee($p);
    }
}
