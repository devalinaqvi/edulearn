<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>403 · EduLearn</title>
<link rel="stylesheet" href="{{ asset('app.css') }}">
</head>
<body>
<main>
<section class="panel empty">
<span class="eyebrow">403</span>
<h1>This space is restricted.</h1>
<p>{{ $exception->getMessage() ?: 'You do not have permission to access this content.' }}</p>
<a class="button secondary" href="/">Return to your workspace →</a>
</section>
</main>
</body>
</html>