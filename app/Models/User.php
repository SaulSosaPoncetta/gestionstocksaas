<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use HasFactory, Notifiable;

    public const ROL_SUPERADMIN = 'superadmin';
    public const ROL_ADMIN = 'admin';
    public const ROL_ENCARGADO = 'encargado';
    public const ROL_OPERADOR = 'operador';
    public const ROL_CONSULTA = 'consulta';

    public const ROLES = [
        self::ROL_ADMIN => 'Administrador',
        self::ROL_ENCARGADO => 'Encargado de depósito',
        self::ROL_OPERADOR => 'Operador',
        self::ROL_CONSULTA => 'Solo consulta',
    ];

    protected $fillable = [
        'empresa_id',
        'name',
        'email',
        'password',
        'rol',
        'activo',
        'ultimo_acceso',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'ultimo_acceso' => 'datetime',
            'password' => 'hashed',
            'activo' => 'boolean',
        ];
    }

    /* ---------- Relaciones ---------- */

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class);
    }

    /* ---------- Roles ---------- */

    public function esSuperadmin(): bool
    {
        return $this->rol === self::ROL_SUPERADMIN && $this->empresa_id === null;
    }

    public function esAdmin(): bool
    {
        return $this->rol === self::ROL_ADMIN;
    }

    public function tieneRol(string ...$roles): bool
    {
        return $this->esSuperadmin() || in_array($this->rol, $roles, true);
    }

    public function getRolNombreAttribute(): string
    {
        return $this->esSuperadmin() ? 'Superadmin' : (self::ROLES[$this->rol] ?? $this->rol);
    }

    /* ---------- Scopes ---------- */

    public function scopeActivos($query)
    {
        return $query->where('activo', true);
    }

    public function scopeDeEmpresa($query, int $empresaId)
    {
        return $query->where('empresa_id', $empresaId);
    }
}
