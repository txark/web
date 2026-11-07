<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/** รหัสรายการ (WC-ชนิด-วันที่-สุ่ม) ให้รายการการเงิน เงินกู้ ประกาศ และบันทึกทะเบียน พร้อมเติมรหัสให้รายการเดิม */
return new class extends Migration
{
    /** ตาราง => ตัวย่อ (group_records คิดตามชนิดบันทึก) */
    private const TABLES = [
        'council_entries' => 'ENT',
        'council_trades' => 'TRD',
        'council_pay_rounds' => 'BON',
        'council_loan_payments' => 'PAY',
        'announcements' => 'ANN',
        'group_records' => null,
    ];

    public function up(): void
    {
        foreach (self::TABLES as $table => $prefix) {
            if (Schema::hasColumn($table, 'number')) {
                continue;
            }
            Schema::table($table, function (Blueprint $t) {
                $t->string('number', 32)->nullable()->unique();
            });

            $tz = config('council.report_timezone', 'Asia/Bangkok');
            $used = [];
            foreach (DB::table($table)->orderBy('id')->get() as $row) {
                $pre = $prefix ?? $this->recordPrefix($row);
                $date = $row->created_at ? \Illuminate\Support\Carbon::parse($row->created_at, 'UTC')->timezone($tz)->format('Ymd') : now($tz)->format('Ymd');
                do {
                    $n = "WC-{$pre}-{$date}-".strtoupper(Str::random(6));
                } while (isset($used[$n]));
                $used[$n] = true;
                DB::table($table)->where('id', $row->id)->update(['number' => $n]);
            }
        }
    }

    public function down(): void
    {
        foreach (array_keys(self::TABLES) as $table) {
            if (Schema::hasColumn($table, 'number')) {
                Schema::table($table, function (Blueprint $t) use ($table) {
                    $t->dropUnique("{$table}_number_unique");
                    $t->dropColumn('number');
                });
            }
        }
    }

    private function recordPrefix(object $row): string
    {
        $data = json_decode((string) $row->data, true) ?: [];

        return match ($row->kind) {
            'fine' => ($data['penalty_type'] ?? 'fine') === 'penance' ? 'PEN' : 'FINE',
            'report' => 'RPT',
            'protect' => 'PRT',
            'outfit' => 'OUT',
            default => 'REC',
        };
    }
};
