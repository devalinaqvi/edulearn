<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>409 · EduLearn</title>
<link rel="stylesheet" href="{{ asset('app.css') }}">
</head>
<body>
<main>
<section class="panel empty">
<span class="eyebrow">409</span>
<h1>This action is not available.</h1>
<p>{{ $exception->getMessage() ?: 'The record has changed. Refresh the page and try again.' }}</p>
<a class="button secondary" href="/">Return to your workspace →</a>
</section>
</main>
</body>
</html>