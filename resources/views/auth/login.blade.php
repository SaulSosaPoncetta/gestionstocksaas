{{-- resources/views/auth/login.blade.php --}}
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Ingresar · GestiónStock</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
        body { min-height: 100vh; background: linear-gradient(135deg, #0f172a 0%, #134e4a 100%); }
        .card { border: 0; border-radius: 1rem; box-shadow: 0 20px 50px rgba(0, 0, 0, .35); }
        .logo { width: 56px; height: 56px; border-radius: 14px; background: #14b8a6; color: #fff; font-size: 1.6rem; display: grid; place-items: center; }
        .btn-stk { background: #14b8a6; border-color: #14b8a6; color: #fff; }
        .btn-stk:hover { background: #0d9488; border-color: #0d9488; color: #fff; }
    </style>
</head>
<body class="d-flex align-items-center py-4">
<div class="container" style="max-width: 420px;">
    <div class="card p-4">
        <div class="text-center mb-4">
            <div class="logo mx-auto mb-3"><i class="bi bi-box-seam"></i></div>
            <h1 class="h4 fw-bold mb-1">GestiónStock</h1>
            <p class="text-muted small mb-0">Stock e inventario · MiGestión</p>
        </div>

        @if ($errors->any())
            <div class="alert alert-danger py-2 small">{{ $errors->first() }}</div>
        @endif

        <form method="POST" action="{{ route('login.post') }}">
            @csrf
            <div class="mb-3">
                <label class="form-label" for="email">Email</label>
                <input type="email" name="email" id="email" class="form-control" value="{{ old('email') }}" required autofocus autocomplete="username">
            </div>
            <div class="mb-3">
                <label class="form-label" for="password">Contraseña</label>
                <input type="password" name="password" id="password" class="form-control" required autocomplete="current-password">
            </div>
            <div class="form-check mb-3">
                <input class="form-check-input" type="checkbox" name="recordarme" id="recordarme" value="1">
                <label class="form-check-label" for="recordarme">Mantener la sesión iniciada</label>
            </div>
            <button class="btn btn-stk w-100 py-2"><i class="bi bi-box-arrow-in-right"></i> Ingresar</button>
        </form>
    </div>
    <p class="text-center text-white-50 small mt-3 mb-0">© {{ date('Y') }} MiGestión</p>
</div>
</body>
</html>
