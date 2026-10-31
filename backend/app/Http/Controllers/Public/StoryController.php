<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Http\Resources\PublicStoryResource;
use App\Models\Story;

/** หนังสือสัญญาสตอรี่ตามลิงก์แชร์ (อ่านอย่างเดียว ไม่ต้องล็อกอิน) */
class StoryController extends Controller
{
    public function show(string $token): PublicStoryResource
    {
        abort_unless(preg_match('/^[A-Za-z0-9]{40}$/', $token) === 1, 404);

        $story = Story::where('share_token', $token)->with(['groupA', 'groupB', 'starter'])->firstOrFail();

        return new PublicStoryResource($story);
    }
}
