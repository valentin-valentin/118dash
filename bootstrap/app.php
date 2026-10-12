<?php

use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->encryptCookies(except: ['sidebar_state']);

        // Pas d'AddLinkHeadersForPreloadedAssets : son en-tête Link (une entrée par fichier JS/CSS)
        // dépassait les 4 Ko de tampon nginx par défaut et provoquait des 502 au chargement complet.
        // Les mêmes préchargements sont déjà dans le HTML (<link rel="modulepreload">).
        $middleware->web(append: [
            HandleInertiaRequests::class,
        ]);

        // Trust all proxies for HTTPS detection
        $middleware->trustProxies(at: '*');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
