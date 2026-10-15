<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title') · {{ config('app.name') }}</title>
    <style>
        body { margin: 0; font-family: system-ui, sans-serif; background: #f5f5f4; color: #1c1917; }
        main { max-width: 32rem; margin: 3rem auto; padding: 2rem; background: #fff; border-radius: .75rem; box-shadow: 0 1px 3px rgb(0 0 0 / .1); }
        h1 { margin-top: 0; font-size: 1.5rem; }
        dl { display: grid; grid-template-columns: auto 1fr; gap: .5rem 1rem; }
        dt { color: #78716c; }
        dd { margin: 0; }
        .success { color: #15803d; } .pending { color: #b45309; } .failed { color: #b91c1c; }
        .button { display: inline-block; margin-top: 1rem; padding: .5rem 1rem; border-radius: .5rem; background: #1c1917; color: #fff; text-decoration: none; }
    </style>
    @stack('head')
</head>
<body>
    <main>
        @yield('content')
    </main>
</body>
</html>
