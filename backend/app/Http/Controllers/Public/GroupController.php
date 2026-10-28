<?php

namespace App\Http\Controllers\Public;

use App\Enums\GroupStatus;
use App\Enums\GroupType;
use App\Http\Controllers\Controller;
use App\Http\Resources\GroupResource;
use App\Models\Group;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class GroupController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'type' => ['nullable', Rule::enum(GroupType::class)],
            'status' => ['nullable', Rule::enum(GroupStatus::class)],
            'q' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $groups = Group::query()
            ->with('leader')
            ->withCount('activeMembers')
            ->when($filters['type'] ?? null, fn ($q, $v) => $q->where('type', $v))
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($filters['q'] ?? null, fn ($q, $v) => $q->whereLike('name', "%{$v}%"))
            ->orderBy('name')
            ->paginate($filters['per_page'] ?? 24)
            ->withQueryString();

        return GroupResource::collection($groups);
    }

    public function show(Group $group): GroupResource
    {
        $group->load(['leader', 'activeMembers'])->loadCount('activeMembers');

        return new GroupResource($group);
    }
}
