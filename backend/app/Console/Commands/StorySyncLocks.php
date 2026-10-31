<?php

namespace App\Console\Commands;

use App\Services\StoryService;
use Illuminate\Console\Command;

class StorySyncLocks extends Command
{
    protected $signature = 'stories:sync-locks';

    protected $description = 'ล็อกสล็อตของสตอรี่ที่ถึงเวลาล็อก (ก่อนเริ่มเล่น) แล้ว';

    public function handle(StoryService $stories): int
    {
        $n = $stories->syncLocks();
        $this->info("ล็อกสล็อต {$n} สตอรี่");

        return self::SUCCESS;
    }
}
