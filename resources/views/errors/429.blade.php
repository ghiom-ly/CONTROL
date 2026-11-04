<!DOCTYPE html>
<html lang="ar" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>طلبات كثيرة</title>
@vite(['resources/css/app.css'])</head>
<body class="grid min-h-screen place-items-center bg-surface p-6">
<div class="card max-w-md p-10 text-center">
<p class="text-6xl font-extrabold text-navy-800">429</p>
<h1 class="mt-4 text-xl font-extrabold text-navy-900">طلبات كثيرة</h1>
<p class="mt-2 text-sm text-slate-500">{{ $exception->getMessage() && 429 == 403 ? $exception->getMessage() : 'يرجى الانتظار قليلاً ثم المحاولة مرة أخرى.' }}</p>
<a href="{{ url('/') }}" class="btn btn-primary mt-6">العودة للرئيسية</a>
</div></body></html>
