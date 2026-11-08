<?php

use App\Models\Rule;
use Illuminate\Database\Migrations\Migration;

/** ค่าบริการออกลอยเป็น 250,000 WVDL และเพิ่มอัตรากรณีโดนใบแดง 100,000 WVDL (ฐานข้อมูลที่มีกฎอยู่แล้ว; ฐานข้อมูลใหม่ได้จาก seeder) */
return new class extends Migration
{
    public function up(): void
    {
        $free = Rule::query()->where('section', 'fees')->where('kind', 'price')->where('title', 'ออกลอย')->first();
        if (! $free) {
            return;
        }

        $free->update(['body' => '250,000 WVDL']);

        $exists = Rule::query()->where('section', 'fees')->where('title', 'ออกลอย (กรณีโดนใบแดง)')->exists();
        if (! $exists) {
            $red = $free->replicate(['code']);
            $red->title = 'ออกลอย (กรณีโดนใบแดง)';
            $red->body = '100,000 WVDL';
            $red->sort_order = $free->sort_order + 1; // ต่อจากแถวออกลอย (แถวถัดไปห่างกัน 10)
            $red->save();
        }
    }

    public function down(): void
    {
        Rule::query()->where('section', 'fees')->where('title', 'ออกลอย (กรณีโดนใบแดง)')->delete();
        Rule::query()->where('section', 'fees')->where('title', 'ออกลอย')->update(['body' => '500,000 WVDL']);
    }
};
