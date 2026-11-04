// نموذج التقرير اليومي (Alpine). الخادم يعيد التحقق والتطبيع لكل الأكواد.
const AR_DIGITS = {
    '٠': '0', '١': '1', '٢': '2', '٣': '3', '٤': '4', '٥': '5', '٦': '6', '٧': '7', '٨': '8', '٩': '9',
    '۰': '0', '۱': '1', '۲': '2', '۳': '3', '۴': '4', '۵': '5', '۶': '6', '۷': '7', '۸': '8', '۹': '9',
};

export function splitCodes(raw) {
    const seen = new Set();
    return String(raw || '')
        .replace(/\p{Cf}+/gu, '')
        .split(/[\s,،;؛]+/u)
        .map((c) => c.replace(/[٠-٩۰-۹]/g, (d) => AR_DIGITS[d]).trim().toUpperCase())
        .filter((c) => c && !seen.has(c) && seen.add(c));
}

export default function reportForm(state, storeOptions, results, maxAttempts) {
    const byId = Object.fromEntries(storeOptions.map((s) => [String(s.id), s]));
    let keySeq = Date.now();

    return {
        results,
        maxAttempts,
        dirty: false,   // تعديلات غير محفوظة — تحذير قبل مغادرة الصفحة
        storeSearch: '',
        stores: (state.stores || []).map((s) => ({
            store_id: Number(s.store_id),
            shipments_count: s.shipments_count ?? '',
            start_orders: s.start_orders ?? '', start_rate: s.start_rate ?? '', start_source: s.start_source ?? null,
            notes: s.notes ?? '',
            codes: (s.codes || []).map((c) => ({ code: c.code, result: c.result })),
            open: false, paste: '', pasteResult: 'courier_promised_delivery',
        })),
        unanswered: Object.assign({ customer: '', courier: '', customer_store_id: '', courier_store_id: '' }, state.unanswered || {}),
        attempts: [...(state.attempt_times || [])],
        overLimitReason: state.attempts_over_limit_reason || '',
        notes: state.notes || '',
        inquiries: state.inquiries || '',
        issues: (state.issue_requests || []).map((i) => ({ ...i })),

        storeName(id) { return byId[String(id)]?.name ?? `#${id}`; },
        isMine(id) { return !!byId[String(id)]?.mine; },
        availableStores() {
            const used = new Set(this.stores.map((s) => String(s.store_id)));
            return storeOptions.filter((o) => !used.has(String(o.id)));
        },
        addStoreFromSearch() {
            const found = storeOptions.find((o) => `${o.name} — ${o.code}` === this.storeSearch || o.name === this.storeSearch);
            if (found && !this.stores.some((s) => s.store_id === found.id)) {
                this.stores.push({ store_id: found.id, shipments_count: '', start_orders: '', start_rate: '', start_source: null, notes: '', codes: [], open: true, paste: '', pasteResult: 'courier_promised_delivery' });
            }
            this.storeSearch = '';
        },
        removeStore(i) { this.stores.splice(i, 1); },
        pasteInto(s) {
            const existing = new Set(s.codes.map((c) => c.code.toUpperCase()));
            splitCodes(s.paste).forEach((code) => { if (!existing.has(code)) s.codes.push({ code, result: s.pasteResult }); });
            s.paste = '';
        },
        // مثل StartTotals في الخادم: الإجمالي = مجموع الطلبيات، والنسبة مرجّحة بعدد الطلبيات
        startTotals() {
            const num = (v) => { const n = parseFloat(String(v ?? '').replace(/[٠-٩۰-۹]/g, (d) => AR_DIGITS[d]).replace(/[٫,]/, '.')); return Number.isNaN(n) ? null : n; };
            let total = null; let w = 0; let sum = 0;
            this.stores.forEach((s) => {
                const o = num(s.start_orders); const r = num(s.start_rate);
                if (o !== null) total = (total ?? 0) + o;
                if (o !== null && r !== null && o > 0) { sum += o * r; w += o; }
            });
            return { total, rate: w > 0 ? Math.round((sum / w) * 100) / 100 : null };
        },
        totalOrders() {
            const nums = this.stores.map((s) => parseInt(String(s.shipments_count).replace(/[٠-٩۰-۹]/g, (d) => AR_DIGITS[d]), 10)).filter((n) => !Number.isNaN(n));
            return nums.length ? nums.reduce((a, b) => a + b, 0) : 'غير متوفر';
        },
        codesWithResult(result) {
            return this.stores.flatMap((s) => s.codes.filter((c) => c.result === result && c.code)
                .map((c) => ({ code: c.code, store: this.storeName(s.store_id) })));
        },
        addIssue() {
            this.issues.push({ key: `i${keySeq++}`, store_id: this.stores[0]?.store_id, type: 'other', title: '', description: '' });
        },
    };
}
