<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class scctcust extends Model
{
    protected $connection = "DATA_MYSQL";

    protected $table = 'scctcust';

    protected $primaryKey = 'CUSTID';

    public $timestamps = false;

    public $incrementing = false;

    public static function vaPrefix(): string
    {
        return \App\Support\MultiVa::openPrefix();
    }

    public static function vaTotalLength(): int
    {
        return 16;
    }

    public static function showVAMTS($nis): string
    {
        return self::showVA($nis);
    }

    public static function showVAMA($nis): string
    {
        return self::showVA($nis);
    }

    public static function showVASpp($nis): string
    {
        $spp = \App\Support\MultiVa::masterByLike('SPP%');
        if (!$spp) {
            return \App\Support\MultiVa::formatNoVa($nis, \App\Support\MultiVa::CLOSE);
        }

        return \App\Support\MultiVa::formatNoVa(
            $nis,
            $spp->VA ?? $spp->va ?? null,
            $spp->isINSTALLMENT ?? 0,
            $spp->tagihan ?? null
        );
    }

    public static function showVAIpp($nis): string
    {
        $ipp = \App\Support\MultiVa::masterByLike('IPP%');
        if (!$ipp) {
            return \App\Support\MultiVa::formatNoVa($nis, \App\Support\MultiVa::OPEN);
        }

        return \App\Support\MultiVa::formatNoVa(
            $nis,
            $ipp->VA ?? $ipp->va ?? null,
            $ipp->isINSTALLMENT ?? 1,
            $ipp->tagihan ?? null
        );
    }

    public static function showVASaku($nis): string
    {
        return self::showVA($nis);
    }

    public static function showVA($nis, mixed $va = null, mixed $isInstallment = null, ?string $billName = null): string
    {
        if ($va !== null || $isInstallment !== null || $billName !== null) {
            return \App\Support\MultiVa::formatNoVa($nis, $va, $isInstallment, $billName);
        }

        return self::formatVA(self::vaPrefix(), $nis);
    }

    public static function formatVA(string $prefix, mixed $nis): string
    {
        $prefixDigits = preg_replace('/\D/', '', $prefix);
        $nisDigits = preg_replace('/\D/', '', (string) $nis);

        if ($prefixDigits === '' || $nisDigits === '' || $nisDigits === '-') {
            return '';
        }

        $suffixLength = max(1, self::vaTotalLength() - strlen($prefixDigits));

        return $prefixDigits . str_pad($nisDigits, $suffixLength, '0', STR_PAD_LEFT);
    }

    public static function nextCustId(): int
    {
        $max = self::query()->max('CUSTID');

        return ((int) $max) + 1;
    }

    protected $guarded = [];
}
