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
        if (in_array($digits, [self::OPEN, self::openPrefix()], true)) {
            return self::OPEN;
        }
        if (in_array($digits, [self::CLOSE, self::closePrefix()], true)) {
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

    public static function resolveFromMaster(object $tagihan): ?string
    {
        return self::normalize($tagihan->VA ?? $tagihan->va ?? null);
    }

    public static function masterOptionText(object $item): string
    {
        $name = trim((string) ($item->tagihan ?? ''));
        $raw = trim((string) ($item->VA ?? $item->va ?? ''));
        $va = self::normalize($raw);
        $suffix = match ($va) {
            self::OPEN => '93 - Open',
            self::CLOSE => '94 - Close',
            default => ($raw !== '' ? $raw : '-'),
        };

        return $name === '' ? $suffix : "{$name} ({$suffix})";
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

    /** View v_saldo_va_multivapertagihan93 / 94 */
    public static function saldoQuery(string $reffBank)
    {
        return DB::connection('DATA_MYSQL')->table(self::saldoView($reffBank));
    }

    /** View v_trans_saldo_multivapertagihan93 / 94 */
    public static function transQuery(string $reffBank)
    {
        return DB::connection('DATA_MYSQL')->table(self::transView($reffBank));
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

        $mst = mst_tagihan::query()->where('tagihan', $billName)->first();

        return $mst ? self::resolveFromMaster($mst) : null;
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
        $raw = trim((string) ($tagihan->VA ?? $tagihan->va ?? ''));
        if ($raw === '') {
            throw new RuntimeException(
                'Jenis tagihan "' . $tagihan->tagihan . '" belum diset VA.'
            );
        }

        return self::normalize($raw) ?? $raw;
    }

    public static function custSaldo(string|int|null $custId, string $reffBank): int
    {
        if (blank($custId)) {
            return 0;
        }

        $row = self::saldoQuery($reffBank)
            ->where('CUSTID', $custId)
            ->first();

        return (int) ($row->SALDO ?? 0);
    }
}
