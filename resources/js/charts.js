// الرسوم الستة — تقرأ البيانات من <script id="nours-charts"> وترسم ما له canvas فقط
const NAVY = '#1e3a8a';
const ACCENT = '#f59e0b';
const GREEN = '#16a34a';
const RED = '#dc2626';
const PALETTE = ['#1e3a8a', '#f59e0b', '#16a34a', '#dc2626', '#7c3aed', '#0891b2', '#db2777', '#65a30d', '#ea580c'];

export function renderNoursCharts(Chart) {
    const el = document.getElementById('nours-charts');
    if (!el) return;
    const d = JSON.parse(el.textContent);
    const draw = (key, config) => {
        const c = document.getElementById(`chart-${key}`);
        if (c) new Chart(c, { ...config, options: { maintainAspectRatio: false, responsive: true, ...(config.options || {}) } });
    };

    draw('rate_trend', { type: 'line', data: { labels: d.rate_trend.labels, datasets: [
        { label: 'بداية الشفت', data: d.rate_trend.start, borderColor: NAVY, backgroundColor: NAVY, spanGaps: true },
        { label: 'نهاية الشفت', data: d.rate_trend.end, borderColor: GREEN, backgroundColor: GREEN, spanGaps: true },
    ] }, options: { scales: { y: { suggestedMin: 0, suggestedMax: 100 } } } });

    draw('orders', { type: 'bar', data: { labels: d.orders.labels, datasets: [
        { label: 'الحالية', data: d.orders.current, backgroundColor: NAVY },
        { label: 'السابقة', data: d.orders.previous, backgroundColor: '#cbd5e1' },
    ] } });

    draw('results', { type: 'doughnut', data: { labels: d.results.labels, datasets: [
        { data: d.results.values, backgroundColor: PALETTE },
    ] } });

    draw('declining', { type: 'bar', data: { labels: d.declining.labels, datasets: [
        { label: 'التغير %', data: d.declining.values, backgroundColor: RED },
    ] }, options: { indexAxis: 'y', plugins: { legend: { display: false } } } });

    draw('employees', { type: 'bar', data: { labels: d.employees.labels, datasets: [
        { label: 'الطلبيات', data: d.employees.orders, backgroundColor: NAVY, yAxisID: 'y' },
        { label: 'متوسط التحسن', data: d.employees.improvement, backgroundColor: ACCENT, yAxisID: 'y1' },
    ] }, options: { scales: { y: { position: 'left' }, y1: { position: 'right', grid: { drawOnChartArea: false } } } } });

    draw('issues', { type: 'bar', data: { labels: d.issues.labels, datasets: [
        { label: 'عدد المشكلات', data: d.issues.values, backgroundColor: ACCENT },
    ] }, options: { plugins: { legend: { display: false } } } });
}
