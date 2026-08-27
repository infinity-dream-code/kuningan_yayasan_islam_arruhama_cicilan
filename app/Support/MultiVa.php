<?php

namespace App\Support;

use App\Models\mst_tagihan;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class MultiVa
{
    public const OPEN = '93';
    public const CLOSE = '94';

    private static ?\Illuminate\Support\Collection $masters = null;

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

        return "BuilderPaymentBill_MultiVAPerTagihan{$va}";
    }

    /** Nama lama sebelum rename (salah copas _BankBayar_). */
    public static function paymentFunctionLegacy(string $reffBank): string
    {
        $va = self::normalize($reffBank) ?? self::OPEN;

        return "BuilderPaymentBill_BankBayar_MultiVAPerTagihan{$va}";
    }

    public static function cashPaymentFunction(): string
    {
        return 'BuilderPaymentCash_MultiVAPerTagihan';
    }

    public static function cancelProcedure(string $reffBank): string
    {
        return 'CancelPaymentSaldo_MultiVAPerTagihan';
    }

    /** Nama lama: CancelPaymentSaldo_MultiVAPerTagihan93 / 94 */
    public static function cancelProcedureLegacy(string $reffBank): string
    {
        $va = self::normalize($reffBank) ?? self::OPEN;

        return "CancelPaymentSaldo_MultiVAPerTagihan{$va}";
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

    public static function masters(): \Illuminate\Support\Collection
    {
        if (self::$masters === null) {
            self::$masters = mst_tagihan::query()->orderBy('urut')->get();
        }

        return self::$masters;
    }

    public static function masterByBillName(?string $billName): ?object
    {
        $key = strtoupper(trim((string) $billName));
        if ($key === '') {
            return null;
        }

        return self::masters()->first(
            fn ($row) => strtoupper(trim((string) $row->tagihan)) === $key
        );
    }

    public static function masterByLike(string $prefix): ?object
    {
        $needle = strtoupper(trim($prefix, '%'));
        if ($needle === '') {
            return null;
        }

        return self::masters()->first(
            fn ($row) => str_starts_with(strtoupper(trim((string) $row->tagihan)), $needle)
        );
    }

    /**
     * Prefix bank: VA 93/94 (atau nomor lengkap) dulu, lalu cicil di master/tagihan.
     * VA lama (mis. 81) + non-cicil → Close 7797794; cicil → Open 7797793.
     */
    public static function prefixFromContext(mixed $va = null, mixed $isInstallment = null, ?string $billName = null): string
    {
        $normalized = self::normalize($va);
        if ($normalized !== null) {
            return self::prefix($normalized);
        }

        $mst = $billName ? self::masterByBillName($billName) : null;
        if ($mst) {
            $normalized = self::normalize($mst->VA ?? $mst->va ?? null);
            if ($normalized !== null) {
                return self::prefix($normalized);
            }
            if ($isInstallment === null || $isInstallment === '') {
                $isInstallment = $mst->isINSTALLMENT ?? null;
            }
        }

        if ($isInstallment !== null && $isInstallment !== '') {
            return (int) $isInstallment === 1 ? self::openPrefix() : self::closePrefix();
        }

        return self::openPrefix();
    }

    public static function prefixFromMaster(?object $tagihan): string
    {
        if (!$tagihan) {
            return self::openPrefix();
        }

        return self::prefixFromContext(
            $tagihan->VA ?? $tagihan->va ?? null,
            $tagihan->isINSTALLMENT ?? null
        );
    }

    public static function formatNoVa(mixed $nis, mixed $va = null, mixed $isInstallment = null, ?string $billName = null): string
    {
        $nis = trim((string) $nis);
        if ($nis === '' || $nis === '-') {
            return '';
        }

        return \App\Models\scctcust::formatVA(
            self::prefixFromContext($va, $isInstallment, $billName),
            $nis
        );
    }

    public static function formatNoVaFromBill(mixed $nis, object $bill): string
    {
        $source = trim((string) $nis);
        if ($source === '' || $source === '-') {
            $source = trim((string) ($bill->NUM2ND ?? $bill->NOCUST ?? $bill->nocust ?? ''));
        }

        return self::formatNoVa(
            $source,
            $bill->va ?? $bill->VA ?? null,
            $bill->isINSTALLABLE ?? null,
            $bill->BILLNM ?? $bill->tagihan ?? null
        );
    }

    public static function formatNoVaFromBills(mixed $nis, mixed $bills): string
    {
        $formatted = collect($bills ?? [])
            ->map(function ($bill) use ($nis) {
                $bill = is_array($bill) ? (object) $bill : $bill;

                return is_object($bill) ? self::formatNoVaFromBill($nis, $bill) : '';
            })
            ->filter()
            ->unique()
            ->values();

        return $formatted->implode(' / ');
    }

    public static function formatNoVaBoth(mixed $nis): string
    {
        $open = self::formatNoVa($nis, self::OPEN);
        $close = self::formatNoVa($nis, self::CLOSE);
        if ($open !== '' && $close !== '' && $open !== $close) {
            return $open . ' / ' . $close;
        }

        return $open !== '' ? $open : $close;
    }
}
