<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>422 · EduLearn</title>
<link rel="stylesheet" href="{{ asset('app.css') }}">
</head>
<body>
<main>
<section class="panel empty">
<span class="eyebrow">422</span>
<h1>Please check your request.</h1>
<p>{{ $exception->getMessage() ?: 'Some information is not valid for this action.' }}</p>
<a class="button secondary" href="/">Return to your workspace →</a>
</section>
</main>
</body>
</html>