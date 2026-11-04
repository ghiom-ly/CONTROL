<?php

namespace App\Http\Controllers;

use App\Models\StoreAlias;
use Illuminate\Http\RedirectResponse;

class StoreAliasController extends Controller
{
    /** حذف اسم بديل خاطئ — لا يغيّر التقارير السابقة */
    public function destroy(StoreAlias $alias): RedirectResponse
    {
        $this->authorize('stores.manage');
        $alias->delete();

        return back()->with('success', 'حُذف الاسم البديل.');
    }
}
