<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\User;
use App\Support\Period;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AuditLogController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorize('audit.view');
        $period = Period::fromRequest($request, 'last7');
        $filters = $request->validate([
            'user_id' => ['nullable', 'integer'],
            'event' => ['nullable', 'string', 'max:60'],
            'model' => ['nullable', 'string', 'max:150'],
        ]);

        $logs = AuditLog::query()->with('user:id,name,username')
            ->whereBetween('created_at', [$period->from, $period->to])
            ->when($filters['user_id'] ?? null, fn ($q, $v) => $q->where('user_id', $v))
            ->when($filters['event'] ?? null, fn ($q, $v) => $q->where('event', $v))
            ->when($filters['model'] ?? null, fn ($q, $v) => $q->where('auditable_type', $v))
            ->latest('id')
            ->paginate(30)->withQueryString();

        return view('audit.index', [
            'logs' => $logs,
            'period' => $period,
            'filters' => $filters,
            'users' => User::withTrashed()->orderBy('name')->get(['id', 'name']),
        ]);
    }
}
