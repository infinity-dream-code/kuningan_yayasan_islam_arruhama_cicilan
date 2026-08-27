<?php

namespace App\Support;

use App\Models\scctbill;
use Auth;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use App\Support\MultiVa;

class ManualPaymentBuilder
{
    private const SALDO_FIDBANK = '1140002';

    public function payBill(
        scctbill $tagihan,
        int $nominal,
        string $fidBank,
        string $paidAt,
        ?string $transno,
        Request $request
    ): void {
        $custId = (string) $tagihan->CUSTID;
        $aa = (string) $tagihan->AA;
        $billCd = (string) ($tagihan->BILLCD ?? '');
        $userId = $this->resolveCyberKeyUserId();

        if ($fidBank === self::SALDO_FIDBANK) {
            $va = MultiVa::resolveFromBill($tagihan);
            if ($va === null) {
                throw new RuntimeException(
                    'Tagihan belum memiliki VA Open/Close. Set tipe VA di Master Tagihan.'
                );
            }
            $this->callBuilderPaymentBill(
                $aa,
                $nominal,
                MultiVa::paymentFunction($va),
                MultiVa::paymentFunctionLegacy($va)
            );
            return;
        }

        $this->callBuilderPaymentCash(
            $custId,
            $fidBank,
            $userId,
            $this->formatPaymentDate($paidAt),
            $billCd,
            $aa,
            $nominal
        );
    }

    /**
     * BuilderPaymentCash_MultiVAPerTagihan(v_CUSTID, p_FIDBANK, p_User, p_Date, p_BILLCD, p_AA, p_Payment)
     * p_Date format: YYYYMMDD (8 karakter)
     */
    private function callBuilderPaymentCash(
        string $custId,
        string $fidBank,
        string $userId,
        string $paymentDate,
        string $billCd,
        string $aa,
        int $nominal
    ): void {
        $functionName = MultiVa::cashPaymentFunction();
        $params = [
            $custId,
            $fidBank,
            $userId,
            $paymentDate,
            $billCd,
            $aa,
            $nominal,
        ];
        $context = [
            'custid' => $custId,
            'fidbank' => $fidBank,
            'users' => $userId,
            'date' => $paymentDate,
            'billcd' => $billCd,
            'aa' => $aa,
            'nominal' => $nominal,
        ];

        Log::info('manual-payment.builder.call', array_merge(['function' => $functionName], $context));

        try {
            $result = $this->invokeStoredFunction($functionName, $params);
        } catch (\Throwable $e) {
            if (!$this->isMissingRoutine($e, $functionName)) {
                throw $e;
            }

            $functionName = 'BuilderPaymentCash';
            Log::info('manual-payment.builder.fallback_legacy', [
                'missing' => MultiVa::cashPaymentFunction(),
                'fallback' => $functionName,
                'aa' => $aa,
            ]);
            $result = $this->invokeStoredFunction($functionName, $params);
        }

        $this->assertBuilderResult($functionName, $result, $context);
    }

    /** BuilderPaymentBill_MultiVAPerTagihan93|94 (aa, nominal) */
    private function callBuilderPaymentBill(
        string $aa,
        int $nominal,
        string $functionName = 'BuilderPaymentBill',
        ?string $fallbackName = null
    ): void {
        Log::info('manual-payment.builder.call', [
            'function' => $functionName,
            'aa' => $aa,
            'nominal' => $nominal,
        ]);

        try {
            $result = $this->invokeStoredFunction($functionName, [$aa, $nominal]);
        } catch (\Throwable $e) {
            if ($fallbackName === null || !$this->isMissingRoutine($e, $functionName)) {
                throw $e;
            }

            Log::info('manual-payment.builder.fallback_legacy', [
                'missing' => $functionName,
                'fallback' => $fallbackName,
                'aa' => $aa,
            ]);
            $functionName = $fallbackName;
            $result = $this->invokeStoredFunction($functionName, [$aa, $nominal]);
        }

        $this->assertBuilderResult($functionName, $result, [
            'aa' => $aa,
            'nominal' => $nominal,
        ]);
    }

    /** MySQL FUNCTION (fx) — pakai SELECT, bukan CALL (procedure). */
    private function invokeStoredFunction(string $functionName, array $params): string
    {
        $placeholders = implode(', ', array_fill(0, count($params), '?'));

        $rows = DB::connection('DATA_MYSQL')->select(
            "SELECT {$functionName}({$placeholders}) AS builder_result",
            $params
        );

        return trim((string) (($rows[0] ?? null)->builder_result ?? ''));
    }

    private function assertBuilderResult(string $functionName, string $result, array $context = []): void
    {
        if ($result === 'OK') {
            Log::info('manual-payment.builder.ok', array_merge(['function' => $functionName], $context));
            return;
        }

        Log::warning('manual-payment.builder.failed', array_merge([
            'function' => $functionName,
            'result' => $result,
        ], $context));

        throw new RuntimeException($this->mapBuilderResultMessage($result));
    }

    private function mapBuilderResultMessage(string $result): string
    {
        return match ($result) {
            'NOMINAL_SALAH_TAGIHAN_TIDAK_BOLEH_DICICIL' => 'Nominal pembayaran salah. Tagihan ini tidak boleh dicicil — harus dibayar lunas.',
            'MELEBIHI_TAGIHAN' => 'Nominal pembayaran melebihi sisa tagihan.',
            'Insufficient_Balance' => 'Saldo VA siswa tidak mencukupi.',
            'NOT_FOUND' => 'Tagihan tidak ditemukan atau sudah tidak bisa dibayar.',
            '' => 'Pembayaran gagal diproses oleh sistem (tidak ada respons dari database).',
            default => "Pembayaran gagal diproses oleh sistem ({$result}).",
        };
    }

    private function formatPaymentDate(string $paidAt): string
    {
        return Carbon::parse($paidAt)->format('Ymd');
    }

    private function resolveCyberKeyUserId(): string
    {
        $user = Auth::user();

        if ($user === null) {
            return '';
        }

        return (string) ($user->urut ?? Auth::id() ?? '');
    }

    private function isMissingRoutine(\Throwable $e, string $routine): bool
    {
        $message = $e->getMessage();

        return stripos($message, $routine) !== false
            && (
                stripos($message, 'does not exist') !== false
                || stripos($message, "doesn't exist") !== false
                || stripos($message, 'unknown procedure') !== false
                || stripos($message, 'unknown function') !== false
                || stripos($message, '1305') !== false
            );
    }
}
