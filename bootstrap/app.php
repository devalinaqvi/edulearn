<?php

use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append(SecurityHeaders::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->dontFlash(['api_key']);
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        /*
         * A stale form is answered like a failed validation, not like a crash.
         *
         * Optimistic concurrency refuses a write whose version has moved on, which is correct and
         * stays correct. What was wrong was the response: a full-page 409 replaced the form and
         * discarded everything the person had typed, so recovering meant writing it all again.
         * Returning them to the form with their input intact is the same pattern Laravel already
         * uses for validation, and loses nothing — the write is still refused either way.
         *
         * JSON callers and GET requests keep the 409 status, where the code is the useful answer.
         */
        $exceptions->respond(function (Response $response, Throwable $exception, Request $request) {
            if ($response->getStatusCode() !== 409 || $request->expectsJson() || $request->isMethod('GET')) {
                return $response;
            }

            $message = method_exists($exception, 'getMessage') ? trim($exception->getMessage()) : '';

            return back()->withInput()->withErrors([
                'conflict' => $message !== ''
                    ? $message
                    : 'This record changed while you were working on it. Your text is below; reload to see the current version before saving again.',
            ]);
        });
    })->create();
