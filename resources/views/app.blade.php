<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}"  @class(['dark' => ($appearance ?? 'system') == 'dark'])>
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        {{-- Inline script to detect system dark mode preference and apply it immediately --}}
        <script>
            (function() {
                const appearance = '{{ $appearance ?? "system" }}';

                if (appearance === 'system') {
                    const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;

                    if (prefersDark) {
                        document.documentElement.classList.add('dark');
                    }
                }
            })();
        </script>

        {{-- Inline style to set the HTML background color based on our theme in app.css --}}
        <style>
            html {
                background-color: oklch(1 0 0);
            }

            html.dark {
                background-color: oklch(0.145 0 0);
            }
        </style>

        <title inertia>{{ config('app.name', '118 Dash') }}</title>

        <link rel="icon" type="image/svg+xml" href="data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 width=%22256%22 height=%22256%22 viewBox=%220 0 100 100%22><rect width=%22100%22 height=%22100%22 rx=%2220%22 fill=%22%23111111%22></rect><path fill=%22%23fff%22 d=%22M15.48 36.79L7.27 40.03Q6.26 39.52 5.40 38.52Q4.53 37.51 4.53 35.78L4.53 35.78Q4.53 32.61 8.49 31.10L8.49 31.10L17.56 27.57L19.58 27.57Q21.88 27.57 23.25 28.90Q24.62 30.24 24.62 32.54L24.62 32.54L24.62 71.85Q23.97 72.00 22.86 72.21Q21.74 72.43 20.44 72.43L20.44 72.43Q17.85 72.43 16.66 71.49Q15.48 70.56 15.48 68.18L15.48 68.18L15.48 36.79ZM42.55 36.79L34.34 40.03Q33.33 39.52 32.47 38.52Q31.60 37.51 31.60 35.78L31.60 35.78Q31.60 32.61 35.56 31.10L35.56 31.10L44.64 27.57L46.65 27.57Q48.96 27.57 50.32 28.90Q51.69 30.24 51.69 32.54L51.69 32.54L51.69 71.85Q51.04 72.00 49.93 72.21Q48.81 72.43 47.52 72.43L47.52 72.43Q44.92 72.43 43.74 71.49Q42.55 70.56 42.55 68.18L42.55 68.18L42.55 36.79ZM61.05 59.97L61.05 59.97Q61.05 55.72 63.25 53.02Q65.44 50.32 68.61 48.96L68.61 48.96Q65.59 47.52 63.86 45.14Q62.13 42.76 62.13 39.31L62.13 39.31Q62.13 36.50 63.28 34.20Q64.44 31.89 66.56 30.31Q68.68 28.72 71.64 27.86Q74.59 27.00 78.19 27.00L78.19 27.00Q85.75 27.00 90.07 30.27Q94.39 33.55 94.39 39.38L94.39 39.38Q94.39 43.05 92.41 45.43Q90.43 47.80 87.40 49.03L87.40 49.03Q88.99 49.68 90.46 50.65Q91.94 51.62 93.06 52.95Q94.17 54.28 94.82 56.08Q95.47 57.88 95.47 60.12L95.47 60.12Q95.47 63.14 94.21 65.52Q92.95 67.89 90.68 69.55Q88.41 71.20 85.24 72.10Q82.08 73.00 78.19 73.00L78.19 73.00Q74.30 73.00 71.17 72.10Q68.04 71.20 65.77 69.55Q63.50 67.89 62.28 65.44Q61.05 63.00 61.05 59.97ZM86.40 59.54L86.40 59.54Q86.40 56.59 83.98 54.64Q81.57 52.70 77.32 52.05L77.32 52.05Q73.80 52.92 71.96 54.90Q70.12 56.88 70.12 59.61L70.12 59.61Q70.12 62.64 72.28 64.26Q74.44 65.88 78.19 65.88L78.19 65.88Q81.86 65.88 84.13 64.29Q86.40 62.71 86.40 59.54ZM71.13 39.67L71.13 39.67Q71.13 42.69 73.29 44.20Q75.45 45.72 78.84 46.15L78.84 46.15Q82.08 45.21 83.73 43.66Q85.39 42.12 85.39 39.67L85.39 39.67Q85.39 37.00 83.48 35.53Q81.57 34.05 78.19 34.05L78.19 34.05Q74.95 34.05 73.04 35.60Q71.13 37.15 71.13 39.67Z%22></path></svg>" />

        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=instrument-sans:400,500,600" rel="stylesheet" />

        @vite(['resources/js/app.ts', "resources/js/pages/{$page['component']}.vue"])
        @inertiaHead
    </head>
    <body class="font-sans antialiased">
        @inertia
    </body>
</html>
