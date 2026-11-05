<x-layouts.app :title="$template->exists ? 'تعديل قالب' : 'قالب جديد'">
    <x-page-header :title="$template->exists ? 'تعديل القالب: '.$template->name : 'قالب رسالة جديد'"
                   :subtitle="$template->status === 'approved' ? 'تنبيه: حفظ التعديل يعيد القالب مسودة حتى يعتمده المدير من جديد' : 'اكتب النص وأدرج المتغيرات — يملؤها النظام بأرقام المتجر عند الإرسال'"/>

    <form method="POST" action="{{ $template->exists ? route('messages.templates.update', $template) : route('messages.templates.store') }}"
          class="grid gap-5 lg:grid-cols-2"
          x-data="{
              type: @js(old('type', $template->type)), body: @js(old('body', $template->body ?? '')), storeId: '',
              vars: @js($variables), preview: '', unknown: [],
              insert(v) { const el = this.$refs.body; const s = el.selectionStart ?? this.body.length; this.body = this.body.slice(0, s) + '{' + v + '}' + this.body.slice(el.selectionEnd ?? s); this.refresh(); },
              async refresh() {
                  const r = await fetch(@js(route('messages.templates.preview')), { method: 'POST', headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content },
                      body: JSON.stringify({ type: this.type, body: this.body, store_id: this.storeId || null }) });
                  if (r.ok) { const d = await r.json(); this.preview = d.text; this.unknown = d.unknown; }
              }
          }" x-init="refresh()">
        @csrf
        @if($template->exists) @method('PUT') @endif
        <div class="card p-5 space-y-3">
            <div>
                <label class="label">اسم القالب</label>
                <input name="name" value="{{ old('name', $template->name) }}" required maxlength="150" class="input">
                @error('name')<p class="field-error">{{ $message }}</p>@enderror
            </div>
            <div>
                <label class="label">النوع</label>
                <select name="type" class="input" x-model="type" x-on:change="refresh()">
                    @foreach(\App\Models\MessageTemplate::TYPES as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach
                </select>
            </div>
            <div>
                <p class="label">المتغيرات (اضغط للإدراج)</p>
                <div class="flex flex-wrap gap-1">
                    <template x-for="v in vars[type]" :key="v"><button type="button" class="badge badge-navy cursor-pointer" x-on:click="insert(v)" x-text="'{' + v + '}'"></button></template>
                </div>
            </div>
            <div>
                <label class="label">نص الرسالة</label>
                <textarea name="body" rows="12" required maxlength="2000" class="input leading-7" x-ref="body" x-model="body" x-on:input.debounce.400ms="refresh()"></textarea>
                @error('body')<p class="field-error">{{ $message }}</p>@enderror
                <p x-show="unknown.length" class="field-error" x-text="'متغيرات غير متاحة لهذا النوع: ' + unknown.map(u => '{' + u + '}').join('، ')"></p>
            </div>
            <button class="btn btn-primary">حفظ كمسودة</button>
        </div>
        <div class="card p-5 space-y-3">
            <div class="flex items-center gap-2">
                <h3 class="card-title">المعاينة على متجر</h3>
                <select class="input !w-56" x-model="storeId" x-on:change="refresh()">
                    <option value="">أول متجر</option>
                    @foreach($stores as $s)<option value="{{ $s->id }}">{{ $s->name }}</option>@endforeach
                </select>
            </div>
            <pre class="whitespace-pre-wrap rounded-lg bg-emerald-50 p-4 font-sans text-sm leading-7" x-text="preview"></pre>
        </div>
    </form>
</x-layouts.app>
