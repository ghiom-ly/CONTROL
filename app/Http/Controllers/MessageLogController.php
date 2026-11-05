<?php

namespace App\Http\Controllers;

use App\Models\MessageTemplate;
use App\Models\Store;
use App\Models\StoreMessage;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\View\View;

class MessageLogController extends Controller
{
    public function __invoke(Request $request): View
    {
        $this->authorize('messages.send');
        $f = $request->validate([
            'store_id' => ['nullable', 'integer'], 'template_id' => ['nullable', 'integer'], 'sent_by' => ['nullable', 'integer'],
            'from' => ['nullable', 'date'], 'to' => ['nullable', 'date'],
        ]);

        $messages = StoreMessage::query()->with(['store:id,name', 'template:id,name', 'sender:id,name'])
            ->when($f['store_id'] ?? null, fn ($q, $v) => $q->where('store_id', $v))
            ->when($f['template_id'] ?? null, fn ($q, $v) => $q->where('message_template_id', $v))
            ->when($f['sent_by'] ?? null, fn ($q, $v) => $q->where('sent_by', $v))
            ->when($f['from'] ?? null, fn ($q, $v) => $q->whereDate('created_at', '>=', $v))
            ->when($f['to'] ?? null, fn ($q, $v) => $q->whereDate('created_at', '<=', $v))
            ->latest()->paginate(config('nours.per_page'))->withQueryString();

        return view('messages.log', [
            'messages' => $messages, 'filters' => $f,
            'stores' => Store::orderBy('name')->get(['id', 'name']),
            'templates' => MessageTemplate::withTrashed()->orderBy('name')->get(['id', 'name']),
            'senders' => User::whereIn('id', StoreMessage::distinct()->pluck('sent_by'))->get(['id', 'name']),
        ]);
    }
}
