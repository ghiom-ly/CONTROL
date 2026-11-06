# NOURS STORE MONITORING
### نظام إدارة تقارير ومتابعة المتاجر – شركة النورس

نظام إداري مستقل (Full-Stack / Laravel) لإدارة التقارير اليومية للموظفين، متابعة المتاجر والشحنات، سجل الاتصالات، المشكلات، والتحليلات الإدارية.
**لا يتصل بمنظومة الشحن الحالية بأي شكل** — مصدر البيانات الوحيد هو الإدخال اليدوي ورفع ملفات Excel/PDF.

> الحالة الحالية: **المراحل 1–6 مكتملة** (المرحلة 6: البحث بالكود ورابطه في منظومة الشحن، سجل الاتصالات وإحصائياته، إدارة المشكلات بالتحديثات والإسناد والمهلة؛ المرحلة 5: قوالب رسائل المتاجر واعتمادها وإرسالها عبر واتساب؛ المرحلة 4: استيراد المتاجر من Excel، تعيين المتاجر للموظفين، نسبة التسليم لكل متجر عند بداية الشفت، الحضور والانصراف والتقرير الشهري والتنبيهات؛ المرحلة 3: استيراد ملف منظومة الشحن لبداية الشفت ونهايته، الفروق مع الملف، واستيراد تقارير واتساب القديمة؛ التأسيس، قاعدة البيانات الكاملة، الدخول، الأدوار والصلاحيات، التصميم العام، إدارة الموظفين، الإعدادات، سجل العمليات، النسخ الاحتياطي؛ المرحلة 2: المتاجر، تقرير الشفت اليومي بقالب الشركة، السكرينات، المراجعة والاعتماد، الأكواد المفتوحة، نسخ التقرير كنص واتساب).
> انظر `docs/ARCHITECTURE.md` للبنية وخطة المراحل.

---

## المتطلبات

| المكوّن | الإصدار |
|---|---|
| PHP | 8.2 أو أحدث (تم الاختبار على 8.3) مع الامتدادات: `pdo_mysql, mbstring, intl, zip, gd, xml, fileinfo, bcmath` |
| MySQL / MariaDB | MySQL 8.0+ أو MariaDB 10.6+ (تم الاختبار على MariaDB 10.11) |
| Composer | 2.x |
| Node.js | 20+ (للبناء فقط) |
| اختياري (المرحلة 3) | `tesseract-ocr` + `tesseract-ocr-ara` و `poppler-utils` لقراءة ملفات PDF المصورة |

## التثبيت

```bash
# 1) الحزم
composer install
npm install && npm run build

# 2) البيئة
cp .env.example .env
php artisan key:generate
```

حرّر `.env`:

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://monitoring.example.com

DB_DATABASE=nours_monitoring
DB_USERNAME=nours
DB_PASSWORD=********

INITIAL_ADMIN_USERNAME=admin
INITIAL_ADMIN_PASSWORD=كلمة-مرور-قوية-مؤقتة
```

أنشئ قاعدة البيانات بترميز utf8mb4:

```sql
CREATE DATABASE nours_monitoring CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'nours'@'localhost' IDENTIFIED BY '********';
GRANT ALL ON nours_monitoring.* TO 'nours'@'localhost';
```

```bash
# 3) الجداول + البيانات الأساسية (أدوار، صلاحيات، إعدادات، قواعد التنبيه، المدير العام)
php artisan migrate --seed

# 4) صلاحيات المجلدات
chmod -R 775 storage bootstrap/cache
```

سجّل الدخول بحساب المدير العام، وسيُطلب منك تغيير كلمة المرور المؤقتة فوراً.
إذا تُركت `INITIAL_ADMIN_PASSWORD` فارغة تُولَّد كلمة مرور عشوائية وتُطبع في الطرفية مرة واحدة.

## التشغيل

```bash
# التطوير
composer dev          # الخادم + الطابور + Vite

# الإنتاج
php artisan config:cache && php artisan route:cache && php artisan view:cache
php artisan queue:work --tries=3       # لمعالجة ملفات الاستيراد الكبيرة (عبر Supervisor)
```

أضف إلى cron (للمهام المجدولة: النسخ الاحتياطي اليومي وتنبيهات الحضور كل 15 دقيقة — على ويندوز للتجربة: `php artisan schedule:work`):

```cron
* * * * * cd /path/to/app && php artisan schedule:run >> /dev/null 2>&1
```

## البيانات التجريبية (منفصلة عن الإنتاج)

```bash
php artisan db:seed --class=DemoSeeder     # يرفض العمل إذا كان APP_ENV=production
```
ينشئ: `demo.admin`, `demo.ahmed`, `demo.fatima`, `demo.mohamed`, `demo.viewer` — كلمة المرور `Demo@12345`.

## الاختبارات

تستخدم قاعدة بيانات MySQL منفصلة `nours_testing` (انظر `phpunit.xml`):

```bash
composer test     # أو: vendor/bin/phpunit
```

## النسخ الاحتياطي والاستعادة

```bash
php artisan nours:backup            # ينشئ storage/backups/nours_backup_YYYY-MM-DD_His.zip
php artisan nours:backup --keep=30  # الاحتفاظ بآخر 30 نسخة
```

النسخة تحتوي على `database.sql` (mysqldump) ومجلد `files/` (المرفقات والملفات المستوردة الأصلية).
يجري نسخ تلقائي يومياً الساعة 02:00 عند تفعيل cron. **انقل النسخ إلى خادم آخر** بشكل دوري.

**الاستعادة:**

```bash
php artisan down
unzip nours_backup_2026-11-04_020000.zip -d /tmp/restore
mysql -u nours -p nours_monitoring < /tmp/restore/database.sql
rsync -a /tmp/restore/files/ storage/app/private/nours/
php artisan cache:clear && php artisan up
```

## الأمان

- كلمات المرور مشفرة (bcrypt) ولا تُسجَّل أبداً في سجل العمليات.
- حماية CSRF على كل النماذج، ومنع SQL Injection عبر Eloquent/Query Builder فقط.
- تحديد محاولات الدخول (5 محاولات/دقيقة لكل مستخدم+IP).
- إنهاء الجلسة فوراً عند إيقاف الحساب، وإلزام تغيير كلمة المرور المؤقتة.
- Policies + Middleware على كل مسار؛ الوصول لسجل بمعرّف لا يملكه المستخدم يُرجع 403.
- الملفات المرفوعة تُخزن في قرص خاص `storage/app/private/nours` لا يُخدم من الويب.
- ترويسات أمان (X-Frame-Options, nosniff, Referrer-Policy).
- سجل تدقيق لكل عملية إنشاء/تعديل/حذف/دخول/تغيير صلاحيات مع القيم قبل وبعد.

## ملاحظة عن ملف composer.lock

لم يُرفق `composer.lock` لأن بيئة البناء لم تكن تصل إلى Packagist. أول `composer install` على الخادم سيحلّ الإصدارات وينشئ الملف — احفظه في Git بعدها لضمان تطابق الإصدارات.
الإصدارات التي تم الاختبار عليها: Laravel 12.71، maatwebsite/excel 3.1.70، PhpSpreadsheet 1.30، mPDF 8.3، smalot/pdfparser 2.13، PHPUnit 11.5.
