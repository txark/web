<?php

namespace App\Console\Commands;

use App\Services\DutyService;
use Illuminate\Console\Command;

class DutyCloseAbandoned extends Command
{
    protected $signature = 'duty:close-abandoned';

    protected $description = 'บังคับออกเวรให้สภาที่ปิดหน้าเว็บ/เบราว์เซอร์/คอมโดยไม่ออกเวร (หมดสัญญาณเกินกำหนด)';

    public function handle(DutyService $duty): int
    {
        $closed = $duty->closeAllStale();

        if ($closed > 0) {
            $this->info("บังคับออกเวร {$closed} เวร");
        }

        return self::SUCCESS;
    }
}
