/**
 * تصغير الصور في المتصفح قبل الرفع (سكرينات الموبايل تكون 3–8 ميغا للصورة).
 * أي <input type="file" data-compress-images> : الصورة الأكبر من MAX_SIDE أو من SKIP_BYTES
 * تتحول JPEG بعرض أقصى MAX_SIDE — تبقى مقروءة وحجمها عادة 150–400 كيلو.
 * لو المتصفح ما يدعمش (DataTransfer/canvas) تُرفع الصور كما هي.
 */
const MAX_SIDE = 1800;
const QUALITY = 0.82;
const SKIP_BYTES = 400 * 1024;

const pending = new WeakMap(); // input → Promise

function loadImage(file) {
    return new Promise((resolve, reject) => {
        const url = URL.createObjectURL(file);
        const img = new Image();
        img.onload = () => { URL.revokeObjectURL(url); resolve(img); };
        img.onerror = () => { URL.revokeObjectURL(url); reject(new Error('bad image')); };
        img.src = url;
    });
}

async function shrink(file) {
    if (!file.type.startsWith('image/') || file.type === 'image/gif') return file;
    try {
        const img = await loadImage(file);
        const scale = Math.min(1, MAX_SIDE / Math.max(img.naturalWidth, img.naturalHeight));
        if (scale === 1 && file.size <= SKIP_BYTES) return file;
        const canvas = document.createElement('canvas');
        canvas.width = Math.round(img.naturalWidth * scale);
        canvas.height = Math.round(img.naturalHeight * scale);
        const ctx = canvas.getContext('2d');
        ctx.fillStyle = '#fff'; // PNG شفاف ⇒ خلفية بيضاء بدل السوداء
        ctx.fillRect(0, 0, canvas.width, canvas.height);
        ctx.drawImage(img, 0, 0, canvas.width, canvas.height);
        const blob = await new Promise((r) => canvas.toBlob(r, 'image/jpeg', QUALITY));
        if (!blob || blob.size >= file.size) return file;
        const name = file.name.replace(/\.[^.]+$/, '') + '.jpg';
        return new File([blob], name, { type: 'image/jpeg', lastModified: file.lastModified });
    } catch {
        return file;
    }
}

async function compressInput(input) {
    const files = [...input.files];
    if (!files.length || typeof DataTransfer === 'undefined') return;
    const out = await Promise.all(files.map(shrink));
    const dt = new DataTransfer();
    out.forEach((f) => dt.items.add(f));
    input.files = dt.files;
}

export function initImageCompression() {
    document.addEventListener('change', (e) => {
        const input = e.target;
        if (!(input instanceof HTMLInputElement) || !input.matches('input[type=file][data-compress-images]')) return;
        const job = compressInput(input).finally(() => pending.delete(input));
        pending.set(input, job);
    });

    // الإرسال قبل ما يكمل التصغير: نستنى ثم نعيد الإرسال بنفس الزر (قيمة الزر مهمة: مسودة / إرسال)
    document.addEventListener('submit', (e) => {
        const form = e.target;
        const jobs = [...form.querySelectorAll('input[type=file][data-compress-images]')].map((i) => pending.get(i)).filter(Boolean);
        if (!jobs.length) return;
        e.preventDefault();
        e.stopImmediatePropagation();
        const submitter = e.submitter;
        Promise.allSettled(jobs).then(() => (submitter ? form.requestSubmit(submitter) : form.requestSubmit()));
    }, true);
}
