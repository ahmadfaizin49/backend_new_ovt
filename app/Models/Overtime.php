<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Overtime extends Model
{
    protected $fillable = [
        'user_id',
        'tanggal',
        'keterangan',
        'jam_lembur',
        'tarif_per_jam',
        'total',
    ];

    protected $casts = [
        'tanggal'      => 'date',
        'tarif_per_jam' => 'float',
        'total'        => 'float',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Hitung tarif per jam berdasarkan gaji pokok.
     * tarif_per_jam = gaji_pokok / 173
     */
    public static function hitungTarifPerJam(int $gajiPokok): float
    {
        return $gajiPokok / 173;
    }

    /**
     * Hitung total lembur.
     *
     * hari_biasa:
     *   jam 1   = 1.5 × tarif
     *   jam 2+  = 2   × tarif
     *
     * libur (work_days = 5hari):
     *   jam 1–8  = 2 × tarif
     *   jam 9    = 3 × tarif
     *   jam 10+  = 4 × tarif
     *
     * libur (work_days = 6hari):
     *   jam 1–7  = 2 × tarif
     *   jam 8    = 3 × tarif
     *   jam 9+   = 4 × tarif
     */
    public static function hitungTotal(
        int $jamLembur,
        float $tarifPerJam,
        string $keterangan = 'hari_biasa',
        string $workDays = '5hari'
    ): float {
        if ($jamLembur <= 0) {
            return 0;
        }

        if ($keterangan === 'hari_biasa') {
            $total = 1.5 * $tarifPerJam;
            if ($jamLembur > 1) {
                $total += ($jamLembur - 1) * 2 * $tarifPerJam;
            }
            return $total;
        }

        // libur — batas jam normal sebelum multiplier naik
        $batasNormal = $workDays === '6hari' ? 7 : 8;

        $total = 0;
        for ($jam = 1; $jam <= $jamLembur; $jam++) {
            if ($jam <= $batasNormal) {
                $total += 2 * $tarifPerJam;
            } elseif ($jam === $batasNormal + 1) {
                $total += 3 * $tarifPerJam;
            } else {
                $total += 4 * $tarifPerJam;
            }
        }

        return $total;
    }
}
