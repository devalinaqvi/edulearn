<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>429 · EduLearn</title>
<link rel="stylesheet" href="{{ asset('app.css') }}">
</head>
<body>
<main>
<section class="panel empty">
<span class="eyebrow">429</span>
<h1>Take a short pause.</h1>
<p>{{ $exception->getMessage() ?: 'You have reached a request limit. Please try again later.' }}</p>
<a class="button secondary" href="/">Return to your workspace →</a>
</section>
</main>
</body>
</html>