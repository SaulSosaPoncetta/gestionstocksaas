<?php

namespace App\Models;

use App\Models\Concerns\BelongsToEmpresa;
use App\Support\EmpresaActual;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

class Lote extends Model
{
    use BelongsToEmpresa;

    protected $table = 'lotes';

    protected $fillable = [
        'empresa_id',
        'producto_id',
        'codigo',
        'fecha_elaboracion',
        'fecha_vencimiento',
        'observaciones',
    ];

    protected function casts(): array
    {
        return [
            'fecha_elaboracion' => 'date',
            'fecha_vencimiento' => 'date',
        ];
    }

    public function producto(): BelongsTo
    {
        return $this->belongsTo(Producto::class)->withTrashed();
    }

    public function existencias(): HasMany
    {
        return $this->hasMany(Existencia::class);
    }

    /* ---------- Vencimiento ---------- */

    public function estaVencido(?Carbon $hoy = null): bool
    {
        return $this->fecha_vencimiento !== null && $this->fecha_vencimiento->lt(($hoy ?? today())->copy()->startOfDay());
    }

    /** Días hasta el vencimiento (negativo si ya venció; null si no vence). */
    public function diasParaVencer(): ?int
    {
        return $this->fecha_vencimiento === null
            ? null
            : (int) today()->diffInDays($this->fecha_vencimiento, false);
    }

    public function getEstadoVencimientoAttribute(): string
    {
        $dias = $this->diasParaVencer();
        $aviso = (int) (EmpresaActual::modelo()?->dias_aviso_vencimiento ?? 30);

        return match (true) {
            $dias === null => 'sin_vencimiento',
            $dias < 0 => 'vencido',
            $dias <= $aviso => 'por_vencer',
            default => 'vigente',
        };
    }

    public function getEtiquetaAttribute(): string
    {
        return $this->codigo . ($this->fecha_vencimiento ? ' · vence ' . $this->fecha_vencimiento->format('d/m/Y') : '');
    }
}
