{{-- resources/views/layouts/app.blade.php --}}
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('titulo', 'Inicio') · GestiónStock</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
        :root {
            --stk-sidebar: #0f172a;
            --stk-sidebar-hover: #1e293b;
            --stk-accent: #14b8a6;
        }
        body { background: #f1f5f9; }
        .sidebar { --bs-offcanvas-bg: var(--stk-sidebar); --bs-offcanvas-width: 260px; background: var(--stk-sidebar); }
        @media (min-width: 992px) {
            .sidebar { width: 250px; min-height: 100vh; position: sticky; top: 0; align-self: flex-start; }
        }
        .sidebar .nav-link { color: #cbd5e1; border-radius: .5rem; padding: .55rem .85rem; display: flex; gap: .6rem; align-items: center; }
        .sidebar .nav-link:hover { background: var(--stk-sidebar-hover); color: #fff; }
        .sidebar .nav-link.active { background: var(--stk-accent); color: #fff; }
        .sidebar .seccion { color: #64748b; font-size: .72rem; text-transform: uppercase; letter-spacing: .06em; padding: 1rem .85rem .35rem; }
        .marca { color: #fff; font-weight: 700; font-size: 1.15rem; }
        .marca i { color: var(--stk-accent); }
        .contenido { min-width: 0; }
        .card { border: 0; box-shadow: 0 1px 3px rgba(15, 23, 42, .08); }
        .btn-stk { background: var(--stk-accent); border-color: var(--stk-accent); color: #fff; }
        .btn-stk:hover { background: #0d9488; border-color: #0d9488; color: #fff; }
    </style>
    @stack('styles')
</head>
<body>
@php
    $usuario = auth()->user();
    $empresaActual = \App\Support\EmpresaActual::modelo();
@endphp

<div class="d-flex">

    {{-- Menú lateral (fijo en escritorio, deslizable en celular) --}}
    <aside class="sidebar offcanvas-lg offcanvas-start flex-shrink-0" tabindex="-1" id="menuLateral">
        <div class="offcanvas-header">
            <span class="marca"><i class="bi bi-box-seam"></i> GestiónStock</span>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="offcanvas" data-bs-target="#menuLateral"></button>
        </div>
        <div class="offcanvas-body d-flex flex-column p-3">
            <div class="marca d-none d-lg-block mb-3"><i class="bi bi-box-seam"></i> GestiónStock</div>

            <nav class="nav flex-column gap-1 w-100">
                @if ($empresaActual)
                    <a class="nav-link {{ request()->routeIs('dashboard') ? 'active' : '' }}" href="{{ route('dashboard') }}">
                        <i class="bi bi-speedometer2"></i> Inicio
                    </a>

                    <div class="seccion">Inventario</div>
                    <a class="nav-link {{ request()->routeIs('catalogo.productos.*') ? 'active' : '' }}" href="{{ route('catalogo.productos.index') }}">
                        <i class="bi bi-box-seam"></i> Productos
                    </a>
                    <a class="nav-link {{ request()->routeIs('inventario.stock.*', 'inventario.kardex') ? 'active' : '' }}" href="{{ route('inventario.stock.index') }}">
                        <i class="bi bi-clipboard-data"></i> Stock
                    </a>
                    <a class="nav-link {{ request()->routeIs('inventario.movimientos.*') ? 'active' : '' }}" href="{{ route('inventario.movimientos.index') }}">
                        <i class="bi bi-arrow-left-right"></i> Movimientos
                    </a>
                    @if ($empresaActual->usa_lotes)
                        <a class="nav-link {{ request()->routeIs('inventario.vencimientos.*') ? 'active' : '' }}" href="{{ route('inventario.vencimientos.index') }}">
                            <i class="bi bi-calendar-x"></i> Vencimientos
                        </a>
                    @endif
                    @if ($empresaActual->usa_series)
                        <a class="nav-link {{ request()->routeIs('inventario.series.*') ? 'active' : '' }}" href="{{ route('inventario.series.index') }}">
                            <i class="bi bi-upc"></i> Números de serie
                        </a>
                    @endif
                    @php($transferenciasPendientes = \App\Models\Transferencia::pendientesPara($usuario)->count())
                    <a class="nav-link {{ request()->routeIs('inventario.transferencias.*') ? 'active' : '' }}" href="{{ route('inventario.transferencias.index') }}">
                        <i class="bi bi-truck"></i> Transferencias
                        @if ($transferenciasPendientes)
                            <span class="badge rounded-pill text-bg-warning ms-auto">{{ $transferenciasPendientes }}</span>
                        @endif
                    </a>
                    @if ($usuario->tieneRol('admin', 'encargado', 'operador'))
                        @php($tomasEnCurso = \App\Models\TomaInventario::enCurso()->whereIn('deposito_id', $usuario->depositosPermitidos()->pluck('id'))->count())
                        <a class="nav-link {{ request()->routeIs('inventario.tomas.*') ? 'active' : '' }}" href="{{ route('inventario.tomas.index') }}">
                            <i class="bi bi-clipboard-check"></i> Inventario físico
                            @if ($tomasEnCurso)
                                <span class="badge rounded-pill text-bg-warning ms-auto">{{ $tomasEnCurso }}</span>
                            @endif
                        </a>
                    @endif
                    @if ($usuario->tieneRol('admin', 'encargado'))
                        <a class="nav-link {{ request()->routeIs('inventario.valorizacion.*') ? 'active' : '' }}" href="{{ route('inventario.valorizacion.index') }}">
                            <i class="bi bi-cash-stack"></i> Valorización
                        </a>
                        <a class="nav-link {{ request()->routeIs('inventario.depositos.*', 'inventario.ubicaciones.*') ? 'active' : '' }}" href="{{ route('inventario.depositos.index') }}">
                            <i class="bi bi-buildings"></i> Depósitos
                        </a>
                    @endif

                    @if ($usuario->tieneRol('admin', 'encargado', 'operador'))
                        <div class="seccion">Salidas</div>
                        <a class="nav-link {{ request()->routeIs('salidas.remitos.*') ? 'active' : '' }}" href="{{ route('salidas.remitos.index') }}">
                            <i class="bi bi-box-arrow-right"></i> Remitos
                        </a>
                        @php($reservasActivas = \App\Models\Reserva::activas()->count())
                        <a class="nav-link {{ request()->routeIs('salidas.reservas.*') ? 'active' : '' }}" href="{{ route('salidas.reservas.index') }}">
                            <i class="bi bi-bookmark"></i> Reservas
                            @if ($reservasActivas)
                                <span class="badge rounded-pill text-bg-secondary ms-auto">{{ $reservasActivas }}</span>
                            @endif
                        </a>
                        <a class="nav-link {{ request()->routeIs('salidas.clientes.*') ? 'active' : '' }}" href="{{ route('salidas.clientes.index') }}">
                            <i class="bi bi-people"></i> Clientes
                        </a>
                    @endif

                    @if ($empresaActual->tieneModulo('compras') && $usuario->tieneRol('admin', 'encargado', 'operador'))
                        <div class="seccion">Abastecimiento</div>
                        @if ($usuario->tieneRol('admin', 'encargado'))
                            <a class="nav-link {{ request()->routeIs('compras.reposicion.*') ? 'active' : '' }}" href="{{ route('compras.reposicion.index') }}">
                                <i class="bi bi-cart-plus"></i> Reposición
                            </a>
                        @endif
                        <a class="nav-link {{ request()->routeIs('compras.ordenes.*') ? 'active' : '' }}" href="{{ route('compras.ordenes.index') }}">
                            <i class="bi bi-receipt"></i> Órdenes de compra
                        </a>
                        <a class="nav-link {{ request()->routeIs('compras.recepciones.*') ? 'active' : '' }}" href="{{ route('compras.recepciones.index') }}">
                            <i class="bi bi-box-arrow-in-down"></i> Recepciones
                        </a>
                        @if ($usuario->tieneRol('admin', 'encargado'))
                            <a class="nav-link {{ request()->routeIs('compras.proveedores.*') ? 'active' : '' }}" href="{{ route('compras.proveedores.index') }}">
                                <i class="bi bi-person-vcard"></i> Proveedores
                            </a>
                        @endif
                    @endif

                    @if ($usuario->tieneRol('admin', 'encargado'))
                        <div class="seccion">Catálogo</div>
                        <a class="nav-link {{ request()->routeIs('catalogo.categorias.*') ? 'active' : '' }}" href="{{ route('catalogo.categorias.index') }}">
                            <i class="bi bi-diagram-3"></i> Categorías
                        </a>
                        <a class="nav-link {{ request()->routeIs('catalogo.marcas.*') ? 'active' : '' }}" href="{{ route('catalogo.marcas.index') }}">
                            <i class="bi bi-tags"></i> Marcas
                        </a>
                        <a class="nav-link {{ request()->routeIs('catalogo.unidades.*') ? 'active' : '' }}" href="{{ route('catalogo.unidades.index') }}">
                            <i class="bi bi-rulers"></i> Unidades de medida
                        </a>
                    @endif

                    @if ($usuario->tieneRol('admin'))
                        <div class="seccion">Configuración</div>
                        <a class="nav-link {{ request()->routeIs('configuracion.empresa.*') ? 'active' : '' }}" href="{{ route('configuracion.empresa.edit') }}">
                            <i class="bi bi-building-gear"></i> Empresa
                        </a>
                        <a class="nav-link {{ request()->routeIs('configuracion.motivos.*') ? 'active' : '' }}" href="{{ route('configuracion.motivos.index') }}">
                            <i class="bi bi-list-check"></i> Motivos
                        </a>
                        <a class="nav-link {{ request()->routeIs('configuracion.usuarios.*') ? 'active' : '' }}" href="{{ route('configuracion.usuarios.index') }}">
                            <i class="bi bi-people"></i> Usuarios
                        </a>
                        <a class="nav-link {{ request()->routeIs('configuracion.integraciones.*') ? 'active' : '' }}" href="{{ route('configuracion.integraciones.index') }}">
                            <i class="bi bi-plug"></i> Integraciones
                        </a>
                    @endif
                @endif

                @if ($usuario->esSuperadmin())
                    <div class="seccion">MiGestión</div>
                    <a class="nav-link {{ request()->routeIs('admin.empresas.*') ? 'active' : '' }}" href="{{ route('admin.empresas.index') }}">
                        <i class="bi bi-buildings"></i> Empresas
                    </a>
                @endif
            </nav>
        </div>
    </aside>

    <div class="contenido flex-grow-1">

        {{-- Barra superior --}}
        <header class="bg-white border-bottom px-3 py-2 d-flex align-items-center gap-2">
            <button class="btn btn-outline-secondary d-lg-none" type="button" data-bs-toggle="offcanvas" data-bs-target="#menuLateral">
                <i class="bi bi-list"></i>
            </button>

            <div class="fw-semibold text-truncate">
                {{ $empresaActual?->nombre ?? 'Administración MiGestión' }}
            </div>

            @if ($usuario->esSuperadmin() && $empresaActual)
                <form method="POST" action="{{ route('admin.empresas.salir') }}" class="ms-1">
                    @csrf
                    <button class="btn btn-sm btn-warning"><i class="bi bi-box-arrow-left"></i> <span class="d-none d-md-inline">Salir de la empresa</span></button>
                </form>
            @endif

            <div class="dropdown ms-auto">
                <button class="btn btn-light dropdown-toggle" type="button" data-bs-toggle="dropdown">
                    <i class="bi bi-person-circle"></i> <span class="d-none d-sm-inline">{{ $usuario->name }}</span>
                </button>
                <ul class="dropdown-menu dropdown-menu-end">
                    <li><span class="dropdown-item-text small text-muted">{{ $usuario->email }}<br>{{ $usuario->rol_nombre }}</span></li>
                    <li><hr class="dropdown-divider"></li>
                    <li>
                        <form method="POST" action="{{ route('logout') }}">
                            @csrf
                            <button class="dropdown-item"><i class="bi bi-box-arrow-right"></i> Cerrar sesión</button>
                        </form>
                    </li>
                </ul>
            </div>
        </header>

        <main class="p-3 p-lg-4">
            @foreach (['success' => 'success', 'warning' => 'warning', 'error' => 'danger'] as $clave => $color)
                @if (session($clave))
                    <div class="alert alert-{{ $color }} alert-dismissible fade show">
                        {{ session($clave) }}
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                @endif
            @endforeach

            @if ($errors->any())
                <div class="alert alert-danger">
                    <strong>Revisá los datos:</strong>
                    <ul class="mb-0">
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            @yield('contenido')
        </main>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
@stack('scripts')
</body>
</html>
