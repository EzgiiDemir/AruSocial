<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="theme-color" content="#ffffff">
        <title>{{ config('app.name', 'ARUVERSE') }}</title>
        <link rel="icon" type="image/png" href="{{ asset('images/aruverse-logo.png') }}">
        <style>
            :root { color-scheme: light; font-family: Montserrat, Arial, sans-serif; }
            * { box-sizing: border-box; }
            body {
                align-items: center;
                background: #fff;
                color: #17172b;
                display: flex;
                justify-content: center;
                margin: 0;
                min-height: 100vh;
                padding: 2rem;
            }
            main { max-width: 32rem; text-align: center; }
            img { display: block; margin: 0 auto 1.5rem; max-width: 15rem; width: 55vw; }
            h1 { font-size: clamp(1.75rem, 5vw, 2.75rem); letter-spacing: .08em; margin: 0; }
            p { color: #5c6070; line-height: 1.6; margin: .8rem 0 0; }
        </style>
    </head>
    <body>
        <main>
            <img src="{{ asset('images/aruverse-logo.png') }}" alt="ARUVERSE">
            <h1>ARUVERSE</h1>
            <p>ARUCAD campus services</p>
        </main>
    </body>
</html>
