<!doctype html>
<html lang="en" data-bs-theme="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'PicToSVG - Turn Pictures into Precision Vectors')</title>
    <meta name="description" content="Convert JPG, PNG and WebP pictures into clean, physically sized SVG vector files for laser engraving and CNC carving.">
    <link rel="icon" href="/favicon.svg" type="image/svg+xml">
    <link rel="stylesheet" href="/vendor/bootstrap/bootstrap.min.css">
    <link rel="stylesheet" href="/css/pictosvg.css">
</head>
<body>
    @yield('content')
    <script src="/vendor/bootstrap/bootstrap.bundle.min.js"></script>
    @stack('scripts')
</body>
</html>
