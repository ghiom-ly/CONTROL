<?php

namespace App\Http\Controllers;

use App\Models\Shipment;
use App\Models\Store;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** البحث العام — يبحث فقط فيما يملك المستخدم صلاحية عرضه */
class SearchController extends Controller
{
    public function __invoke(Request $request): View
    {
        $q = trim((string) ($request->validate(['q' => ['nullable', 'string', 'max:100']])['q'] ?? ''));
        $user = $request->user();
        $results = ['users' => collect(), 'stores' => collect(), 'shipments' => collect()];

        if (mb_strlen($q) >= 2) {
            if ($user->hasPermission('users.view')) {
                $results['users'] = User::with('role:id,display_name')
                    ->where(fn ($w) => $w->where('name', 'like', "%{$q}%")->orWhere('username', 'like', "%{$q}%")->orWhere('phone', 'like', "%{$q}%"))
                    ->limit(10)->get();
            }
            if ($user->hasPermission('stores.view')) {
                $results['stores'] = Store::where(fn ($w) => $w->where('name', 'like', "%{$q}%")->orWhere('code', 'like', "%{$q}%")->orWhere('phone', 'like', "%{$q}%"))
                    ->limit(10)->get();
            }
            if ($user->hasPermission('shipments.view')) {
                $code = Shipment::normalizeCode($q);
                $results['shipments'] = Shipment::with('store:id,name')->where('code', 'like', "%{$code}%")->limit(10)->get();
            }
        }

        return view('search', compact('q', 'results'));
    }
}
