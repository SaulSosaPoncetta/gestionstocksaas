<?php

namespace App\Models;

use App\Models\Concerns\BelongsToEmpresa;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Integracion extends Model
{
    use BelongsToEmpresa;

    protected $table = 'integraciones';

    protected $fillable = [
        'empresa_id',
        'sistema',
        'url_base',
        'id_empresa_remota',
        'token_saliente',
        'opciones',
        'activa',
        'ultimo_ok_at',
        'ultimo_error',
    ];

    protected $hidden = [
        'token_saliente',
        'token_entrante_hash',
        'webhook_secreto',
    ];

    protected function casts(): array
    {
        return [
            'token_saliente' => 'encrypted',
            'webhook_secreto' => 'encrypted',
            'opciones' => 'array',
            'activa' => 'boolean',
            'ultimo_ok_at' => 'datetime',
        ];
    }

    /* ---------- Datos del sistema ---------- */

    public static function sistemasDisponibles(): array
    {
        return config('ecosistema.sistemas', []);
    }

    public function getNombreSistemaAttribute(): string
    {
        return config("ecosistema.sistemas.{$this->sistema}.nombre", $this->sistema);
    }

    public function getUrlApiAttribute(): ?string
    {
        return $this->url_base ?: config("ecosistema.sistemas.{$this->sistema}.url");
    }

    /**
     * Lee una opción combinando los valores por defecto con los guardados.
     */
    public function opcion(string $clave, mixed $default = null): mixed
    {
        $opciones = array_merge(
            config("ecosistema.opciones_por_defecto.{$this->sistema}", []),
            $this->opciones ?? []
        );

        return data_get($opciones, $clave, $default);
    }

    /* ---------- Seguridad ---------- */

    /**
     * Genera el token que el OTRO sistema usará para llamar a nuestra API.
     * Se muestra una sola vez; en la base solo queda el hash.
     */
    public function generarTokenEntrante(): string
    {
        $plano = 'stk_' . Str::random(48);
        $this->token_entrante_hash = hash('sha256', $plano);
        $this->save();

        return $plano;
    }

    public static function porTokenEntrante(string $plano): ?self
    {
        return static::withoutGlobalScopes()
            ->where('token_entrante_hash', hash('sha256', $plano))
            ->where('activa', true)
            ->first();
    }

    public function generarSecretoWebhook(): string
    {
        $secreto = Str::random(64);
        $this->webhook_secreto = $secreto;
        $this->save();

        return $secreto;
    }

    public function firmar(string $payload): string
    {
        return hash_hmac('sha256', $payload, (string) $this->webhook_secreto);
    }

    public function firmaValida(string $payload, ?string $firma): bool
    {
        return $firma !== null && hash_equals($this->firmar($payload), $firma);
    }

    /* ---------- Estado ---------- */

    public function marcarOk(): void
    {
        $this->forceFill(['ultimo_ok_at' => now(), 'ultimo_error' => null])->save();
    }

    public function marcarError(string $error): void
    {
        $this->forceFill(['ultimo_error' => Str::limit($error, 2000)])->save();
    }

    public function scopeActivas($query)
    {
        return $query->where('activa', true);
    }

    public function scopeDeSistema($query, string $sistema)
    {
        return $query->where('sistema', $sistema);
    }
}
