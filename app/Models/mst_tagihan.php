<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class mst_tagihan extends Model
{
    protected $connection = "DATA_MYSQL";

    protected $table = "mst_tagihan";

    protected $primaryKey = "urut";

    public $timestamps = false;

    public $incrementing = false;

    protected $fillable = [
        "urut",
        "tagihan",
        "kode",
        "isINSTALLMENT",
        "VA",
    ];

    protected $casts = [
        "isINSTALLMENT" => "integer",
    ];

    public static function installmentMap(): array
    {
        return Cache::remember('mst_tagihan_installment_map', 300, function () {
            return static::query()
                ->pluck('isINSTALLMENT', 'tagihan')
                ->map(fn ($value) => (int) $value)
                ->all();
        });
    }

    public static function canInstallment(?string $billName): bool
    {
        $billName = trim((string) $billName);
        if ($billName === '') {
            return false;
        }

        $map = static::installmentMap();

        if (array_key_exists($billName, $map)) {
            return $map[$billName] === 1;
        }

        $upperBillName = strtoupper($billName);
        foreach ($map as $tagihan => $flag) {
            if (strtoupper((string) $tagihan) === $upperBillName) {
                return (int) $flag === 1;
            }
        }

        return false;
    }

    public static function flushInstallmentCache(): void
    {
        Cache::forget('mst_tagihan_installment_map');
    }

    protected static function booted(): void
    {
        static::saved(fn () => static::flushInstallmentCache());
        static::deleted(fn () => static::flushInstallmentCache());
    }
}
