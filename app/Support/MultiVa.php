<?php

namespace App\Support;

use App\Models\mst_tagihan;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class MultiVa
{
    public const OPEN = '93';
    public const CLOSE = '94';

    public static function openPrefix(): string
    {
        $raw = preg_replace('/\D/', '', (string) config('app.va_open', '7797793'));

        return $raw !== '' ? $raw : '7797793';
    }

    public static function closePrefix(): string
    {
        $raw = preg_replace('/\D/', '', (string) config('app.va_close', '7797794'));

        return $raw !== '' ? $raw : '7797794';
    }

    public static function prefix(string $reffBank): string
    {
        return self::normalize($reffBank) === self::CLOSE
            ? self::closePrefix()
            : self::openPrefix();
    }

    public static function normalize(mixed $value): ?string
    {
        $raw = trim((string) $value);
        if ($raw === '') {
            return null;
        }

        $digits = preg_replace('/\D/', '', $raw);
        if ($digits === self::OPEN || str_ends_with($digits, self::OPEN)) {
            return self::OPEN;
        }
        if ($digits === self::CLOSE || str_ends_with($digits, self::CLOSE)) {
            return self::CLOSE;
        }

        $upper = strtoupper($raw);
        if (in_array($upper, ['OPEN', '93 - OPEN', 'OPEN PAYMENT'], true)) {
            return self::OPEN;
        }
        if (in_array($upper, ['CLOSE', '94 - CLOSE', 'CLOSED'], true)) {
            return self::CLOSE;
        }

        return null;
    }

    public static function isInstallment(string $reffBank): int
    {
        return self::normalize($reffBank) === self::OPEN ? 1 : 0;
    }

    public static function shortLabel(mixed $value): string
    {
        return match (self::normalize($value)) {
            self::OPEN => 'Open',
            self::CLOSE => 'Close',
            default => '-',
        };
    }

    public static function optionLabel(string $reffBank): string
    {
        return match (self::normalize($reffBank)) {
            self::OPEN => '93 - Open',
            self::CLOSE => '94 - Close',
            default => $reffBank,
        };
    }

    public static function saldoPageTitle(string $reffBank): string
    {
        return self::normalize($reffBank) === self::CLOSE
            ? 'Saldo VA Close'
            : 'Saldo VA Open';
    }

    public static function saldoView(string $reffBank): string
    {
        $va = self::normalize($reffBank) ?? self::OPEN;

        return "v_saldo_va_multivapertagihan{$va}";
    }

    public static function transView(string $reffBank): string
    {
        $va = self::normalize($reffBank) ?? self::OPEN;

        return "v_trans_saldo_multivapertagihan{$va}";
    }

    public static function paymentFunction(string $reffBank): string
    {
        $va = self::normalize($reffBank) ?? self::OPEN;

        return "BuilderPaymentBill_BankBayar_MultiVAPerTagihan{$va}";
    }

    public static function vaForBillName(?string $billName, mixed $existingVa = null): ?string
    {
        $fromExisting = self::normalize($existingVa);
        if ($fromExisting) {
            return $fromExisting;
        }

        $billName = trim((string) $billName);
        if ($billName === '') {
            return null;
        }

        $mstVa = mst_tagihan::query()->where('tagihan', $billName)->value('VA');

        return self::normalize($mstVa);
    }

    public static function resolveFromBill(object $bill): ?string
    {
        return self::vaForBillName(
            $bill->BILLNM ?? null,
            $bill->va ?? $bill->VA ?? null
        );
    }

    public static function requireFromMaster(mst_tagihan $tagihan): string
    {
        $va = self::normalize($tagihan->VA ?? null);
        if ($va === null) {
            throw new RuntimeException(
                'Jenis tagihan "' . $tagihan->tagihan . '" belum diset VA Open (93) atau Close (94).'
            );
        }

        return $va;
    }

    public static function custSaldo(string|int|null $custId, string $reffBank): int
    {
        if (blank($custId)) {
            return 0;
        }

        $row = DB::connection('DATA_MYSQL')
            ->table(self::saldoView($reffBank))
            ->where('CUSTID', $custId)
            ->first();

        return (int) ($row->SALDO ?? 0);
    }
}
