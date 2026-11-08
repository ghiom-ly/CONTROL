import Alpine from 'alpinejs';
import Chart from 'chart.js/auto';
import reportForm from './report-form';
import { renderNoursCharts } from './charts';

document.addEventListener('DOMContentLoaded', () => renderNoursCharts(Chart));

window.Chart = Chart;
Chart.defaults.font.family = "'Cairo', sans-serif";
Chart.defaults.color = '#64748b';
Chart.defaults.plugins.legend.rtl = true;
Chart.defaults.plugins.tooltip.rtl = true;

const safeStorage = {
    get(key, fallback) {
        try { const v = window.localStorage.getItem(key); return v === null ? fallback : JSON.parse(v); } catch { return fallback; }
    },
    set(key, value) {
        try { window.localStorage.setItem(key, JSON.stringify(value)); } catch { /* ignore */ }
    },
};

Alpine.store('layout', {
    collapsed: safeStorage.get('nours.sidebar.collapsed', false),
    mobileOpen: false,
    toggle() {
        if (window.matchMedia('(max-width: 1023px)').matches) {
            this.mobileOpen = !this.mobileOpen;
        } else {
            this.collapsed = !this.collapsed;
            safeStorage.set('nours.sidebar.collapsed', this.collapsed);
        }
    },
});

// تعطيل الأزرار أثناء الإرسال لمنع الإرسال المزدوج + حالة تحميل
document.addEventListener('submit', (e) => {
    const form = e.target;
    if (form.dataset.noLoading !== undefined) return;
    // التعطيل بعد تكوين بيانات النموذج: الزر المعطّل وقت الإرسال لا تصل قيمته (name/value) للخادم
    setTimeout(() => form.querySelectorAll('button:not([type="button"])').forEach((btn) => {
        btn.disabled = true;
        btn.dataset.originalText = btn.innerHTML;
        btn.innerHTML = '<svg class="size-4 animate-spin" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" opacity=".25"/><path d="M22 12a10 10 0 0 0-10-10" stroke="currentColor" stroke-width="3"/></svg> جارٍ التنفيذ...';
    }), 0);
});

window.addEventListener('pageshow', () => {
    document.querySelectorAll('button[data-original-text]').forEach((btn) => {
        btn.disabled = false;
        btn.innerHTML = btn.dataset.originalText;
    });
});

Alpine.data('reportForm', reportForm);

window.Alpine = Alpine;
Alpine.start();
