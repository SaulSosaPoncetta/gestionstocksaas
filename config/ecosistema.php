<?php

/*
|--------------------------------------------------------------------------
| Ecosistema MiGestión
|--------------------------------------------------------------------------
| GestiónStock funciona 100% solo. Estas integraciones son opcionales y
| se activan por empresa desde Configuración > Integraciones.
*/

return [

    'sistema_actual' => 'stock',

    'sistemas' => [

        'panel' => [
            'nombre' => 'MiGestión Panel',
            'descripcion' => 'Suscripción, planes y cobros',
            'url' => env('ECO_PANEL_URL', 'https://panelgestion.migestion.com.ar'),
        ],

        'comercial' => [
            'nombre' => 'GestiónComercial',
            'descripcion' => 'Ventas, compras y caja: descuenta e ingresa stock automáticamente',
            'url' => env('ECO_COMERCIAL_URL', 'https://comercialpos.migestion.com.ar'),
        ],

        'mercado' => [
            'nombre' => 'MiGestión Mercado',
            'descripcion' => 'Tienda online: publica stock disponible y reserva en cada pedido',
            'url' => env('ECO_MERCADO_URL', 'https://mercado.migestion.com.ar'),
        ],

        'contable' => [
            'nombre' => 'GestiónContable',
            'descripcion' => 'Envía asientos de costo de ventas, ajustes y valorización',
            'url' => env('ECO_CONTABLE_URL', 'https://gcontable.migestion.com.ar'),
        ],

    ],

    // Valores por defecto de cada integración (se pisan con integraciones.opciones)
    'opciones_por_defecto' => [

        'comercial' => [
            'maestro_productos' => 'stock',   // stock | comercial  → quién es dueño del alta de productos
            'maestro_proveedores' => 'stock', // stock | comercial  → quién es dueño del alta de proveedores
            'descontar_al' => 'factura',      // factura | remito
            'ingresar_al' => 'recepcion',     // recepcion | factura_compra
            'deposito_id' => null,            // depósito por defecto si Comercial no informa sucursal
        ],

        'mercado' => [
            'publicar' => 'disponible',       // disponible (real - reservado) | real
            'deposito_id' => null,            // depósito desde el que vende la tienda
            'reservar_al_pedido' => true,
        ],

        'contable' => [
            'enviar_asientos' => false,
            'agrupar' => 'diario',            // diario | por_comprobante
        ],

        'panel' => [],

    ],

    'webhooks' => [
        'timeout' => 10,       // segundos
        'reintentos' => 5,     // con backoff exponencial (cola)
    ],

];
