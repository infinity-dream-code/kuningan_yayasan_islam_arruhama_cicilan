<?php

namespace App\Http\Controllers\Admin\Keuangan\TagihanSiswa;

use App\Http\Controllers\Controller;
use App\Models\mst_kelas;
use App\Models\mst_tagihan;
use App\Models\mst_thn_aka;
use App\Models\scctbill;
use App\Models\scctbill_detail;
use App\Models\scctcust;
use App\Support\MultiVa;
use App\Support\SchoolScope;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class CopyTagihanController extends Controller
{
    public string $title = "Keuangan";
    public string $mainTitle = "Tagihan Siswa";
    public string $dataTitle = "Copy Tagihan";

    private ?string $unitScope = null;

    private const MONTH_MAP = [
        'JANUARI' => '01',
        'FEBRUARI' => '02',
        'MARET' => '03',
        'APRIL' => '04',
        'MEI' => '05',
        'JUNI' => '06',
        'JULI' => '07',
        'AGUSTUS' => '08',
        'SEPTEMBER' => '09',
        'OKTOBER' => '10',
        'NOVEMBER' => '11',
        'DESEMBER' => '12',
    ];

    public function __construct()
    {
        $this->middleware(function ($request, $next) {
            if (Auth::check()) {
                $this->unitScope = Auth::user()->unit ?? null;
            }
            return $next($request);
        });
    }

    public function index()
    {
        $data['title'] = $this->title;
        $data['mainTitle'] = $this->mainTitle;
        $data['dataTitle'] = $this->dataTitle;
        $data['thn_aka'] = mst_thn_aka::getMstThnAkaAttributes();
        $data['kelas'] = mst_kelas::dropdownQuery($this->unitScope)
            ->orderByRaw("CASE WHEN unit LIKE '%SD%' THEN 1 WHEN unit LIKE '%SMP%' THEN 2 WHEN unit LIKE '%SMA%' THEN 3 ELSE 4 END")
            ->orderByRaw("CASE WHEN jenjang REGEXP '^[0-9]+$' THEN 0 ELSE 1 END, jenjang")
            ->orderByRaw("CASE WHEN kelas REGEXP '^[0-9]+$' THEN 0 ELSE 1 END, kelas")
            ->get();
        $data['tagihan'] = mst_tagihan::select(['urut', 'tagihan', 'kode'])
            ->orderBy('tagihan')
            ->get();

        return view('admin.keuangan.tagihan_siswa.copy_tagihan.index', $data);
    }

    public function preview(Request $request)
    {
        $validator = $this->validateInput($request);
        if ($validator->fails()) {
            return response()->json(['message' => $validator->errors()->first()], 422);
        }

        $tagihanLamaNm = $this->resolveTagihanName($request->tagihan_lama);
        $tagihanBaruNm = $this->resolveTagihanName($request->tagihan_baru);
        $newBillac = $this->computeBillac($request->thn_aka, $tagihanBaruNm, $request->input('bulan'));

        if (!$newBillac) {
            return response()->json([
                'message' => 'Tidak dapat menentukan periode (BILLAC). Pilih "Bulan Periode" secara manual karena nama Tagihan Baru tidak mengandung nama bulan.',
            ], 422);
        }

        $rows = $this->collectBillsWithSiswa($request, $tagihanLamaNm);

        $list = $rows->map(function ($r) {
            return [
                'nis' => $r->NOCUST,
                'nama' => $r->NMCUST,
                'kelas' => trim(($r->DESC02 ?? '') . ' ' . ($r->DESC03 ?? '')),
                'nama_tagihan' => $r->BILLNM,
                'billam' => (int) $r->BILLAM,
                'bta' => $r->BTA,
                'paidst' => (int) $r->PAIDST,
            ];
        })->values();

        return response()->json([
            'tagihan_lama' => $tagihanLamaNm,
            'tagihan_baru' => $tagihanBaruNm,
            'periode_baru' => $newBillac,
            'total_siswa' => $list->pluck('nis')->unique()->count(),
            'total_tagihan' => $list->count(),
            'total_nominal' => (int) $list->sum('billam'),
            'rows' => $list,
        ]);
    }

    public function copy(Request $request)
    {
        $validator = $this->validateInput($request);
        if ($validator->fails()) {
            return response()->json(['message' => $validator->errors()->first()], 422);
        }

        $tagihanLamaNm = $this->resolveTagihanName($request->tagihan_lama);
        $tagihanBaruNm = $this->resolveTagihanName($request->tagihan_baru);
        $newBillac = $this->computeBillac($request->thn_aka, $tagihanBaruNm, $request->input('bulan'));

        if (!$newBillac) {
            return response()->json([
                'message' => 'Tidak dapat menentukan periode (BILLAC). Pilih "Bulan Periode" secara manual karena nama Tagihan Baru tidak mengandung nama bulan.',
            ], 422);
        }

        $bills = $this->collectBills($request, $tagihanLamaNm);
        if ($bills->isEmpty()) {
            return response()->json(['message' => 'Tidak ada tagihan yang cocok untuk disalin.'], 422);
        }

        $billYear = substr($newBillac, 0, 4);
        $billMonth = substr($newBillac, 4, 2);

        $copied = 0;
        $skipped = 0;

        try {
            DB::beginTransaction();

            foreach ($bills as $oldBill) {
                $exists = scctbill::where('CUSTID', $oldBill->CUSTID)
                    ->where('BILLNM', $tagihanBaruNm)
                    ->where('BILLAC', $newBillac)
                    ->exists();
                if ($exists) {
                    $skipped++;
                    continue;
                }

                $maxUrut = (int) scctbill::where('CUSTID', $oldBill->CUSTID)->max('FUrutan');
                $newUrut = $maxUrut + 1;

                $newBillCd = "{$billYear}/{$billMonth}-{$newUrut}";
                $suffix = 0;
                while (scctbill::where('CUSTID', $oldBill->CUSTID)
                    ->where('BILLCD', $newBillCd)
                    ->exists()) {
                    $suffix++;
                    $newBillCd = "{$billYear}/{$billMonth}-{$newUrut}-{$suffix}";
                }

                $newBill = scctbill::create([
                    'CUSTID' => $oldBill->CUSTID,
                    'BILLCD' => $newBillCd,
                    'BILLAC' => $newBillac,
                    'BILLNM' => $tagihanBaruNm,
                    'BILLAM' => $oldBill->BILLAM,
                    'FLPART' => $oldBill->FLPART,
                    'PAIDST' => 0,
                    'PAIDDT' => null,
                    'NOREFF' => null,
                    'FSTSBolehBayar' => 1,
                    'FUrutan' => $newUrut,
                    'FTGLTagihan' => now(),
                    'FIDBANK' => null,
                    'BTA' => $request->thn_aka,
                    'va' => MultiVa::vaForBillName($tagihanBaruNm, $oldBill->va ?? $oldBill->VA ?? null),
                ]);

                $details = scctbill_detail::where('CUSTID', $oldBill->CUSTID)
                    ->where('BILLCD', $oldBill->BILLCD)
                    ->get();

                foreach ($details as $detail) {
                    scctbill_detail::create([
                        'KodePost' => $detail->KodePost,
                        'BILLAM' => $detail->BILLAM,
                        'CUSTID' => $newBill->CUSTID,
                        'tahun' => $billYear,
                        'periode' => $billMonth,
                        'BILLCD' => $newBill->BILLCD,
                    ]);
                }

                $copied++;
            }

            DB::commit();

            return response()->json([
                'message' => "Copy tagihan selesai. Berhasil disalin: {$copied}" . ($skipped > 0 ? ", dilewati (sudah ada): {$skipped}" : '') . '.',
                'copied' => $copied,
                'skipped' => $skipped,
                'periode_baru' => $newBillac,
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Gagal copy tagihan!',
                'error' => $e->getMessage(),
            ], 422);
        }
    }

    private function validateInput(Request $request)
    {
        return Validator::make($request->all(), [
            'thn_aka' => ['required', 'string'],
            'kelas' => ['required'],
            'tagihan_lama' => ['required'],
            'tagihan_baru' => ['required'],
            'jenis' => ['required', 'in:belum,sudah,semua'],
            'nis' => ['nullable', 'string'],
            'bta_filter' => ['nullable', 'string'],
            'bulan' => ['nullable', 'string'],
        ], [
            'required' => ':attribute wajib diisi.',
            'in' => 'Jenis tagihan tidak valid.',
        ], [
            'thn_aka' => 'Tahun Pelajaran',
            'kelas' => 'Kelas',
            'tagihan_lama' => 'Tagihan Lama',
            'tagihan_baru' => 'Tagihan Baru',
            'jenis' => 'Jenis Tagihan',
            'nis' => 'NIS',
            'bta_filter' => 'Filter BTA',
            'bulan' => 'Bulan Periode',
        ]);
    }

    private function resolveTagihanName($idOrName): ?string
    {
        if (blank($idOrName)) return null;
        $row = mst_tagihan::query()
            ->where('urut', $idOrName)
            ->orWhere('tagihan', $idOrName)
            ->first();
        return $row?->tagihan;
    }

    private function computeBillac(string $thnAka, ?string $namaTagihan, ?string $bulanOverride = null): ?string
    {
        $bulanNum = null;

        // 1. Manual override dari form ("01".."12")
        if (!blank($bulanOverride)) {
            $b = str_pad((string) $bulanOverride, 2, '0', STR_PAD_LEFT);
            if (preg_match('/^(0[1-9]|1[0-2])$/', $b)) {
                $bulanNum = $b;
            }
        }

        // 2. Deteksi dari nama Tagihan Baru
        if (!$bulanNum && $namaTagihan) {
            $upper = strtoupper($namaTagihan);
            foreach (self::MONTH_MAP as $name => $num) {
                if (str_contains($upper, $name)) {
                    $bulanNum = $num;
                    break;
                }
            }
        }

        // 3. Fallback total: pakai tahun + bulan sistem sekarang langsung
        if (!$bulanNum) {
            return now()->format('Ym');
        }

        // Hitung tahun dari Tahun Pelajaran berdasarkan bulan yang ditemukan
        $clean = str_replace([' ', '-'], '/', trim($thnAka));
        $parts = explode('/', $clean);
        if (count($parts) !== 2) return null;
        $firstYear = (int) $parts[0];
        $secondYear = (int) $parts[1];
        if ($firstYear <= 0 || $secondYear <= 0) return null;

        $useYear = ((int) $bulanNum >= 7) ? $firstYear : $secondYear;

        return $useYear . $bulanNum;
    }

    private function collectBills(Request $request, ?string $tagihanLamaNm)
    {
        if (!$tagihanLamaNm) return collect([]);

        $custQuery = scctcust::query()
            ->where('CODE03', $request->kelas);

        if (!blank($request->nis)) {
            $nis = trim((string) $request->nis);
            $custQuery->where(function ($q) use ($nis) {
                $q->where('NOCUST', 'like', "%{$nis}%")
                    ->orWhere('NUM2ND', 'like', "%{$nis}%");
            });
        }
        if ($this->unitScope) {
            $custQuery->where('CODE01', $this->unitScope);
        }

        $custIds = $custQuery->pluck('CUSTID');
        if ($custIds->isEmpty()) return collect([]);

        $billQuery = scctbill::whereIn('CUSTID', $custIds)
            ->where('BILLNM', $tagihanLamaNm);

        if (!blank($request->bta_filter)) {
            $billQuery->where('BTA', trim((string) $request->bta_filter));
        }

        if ($request->jenis === 'belum') {
            $billQuery->where('PAIDST', 0);
        } elseif ($request->jenis === 'sudah') {
            $billQuery->where('PAIDST', 1);
        }

        return $billQuery->get();
    }

    private function collectBillsWithSiswa(Request $request, ?string $tagihanLamaNm)
    {
        if (!$tagihanLamaNm) return collect([]);

        $query = scctbill::query()
            ->join('scctcust', 'scctcust.CUSTID', '=', 'scctbill.CUSTID')
            ->where('scctbill.BILLNM', $tagihanLamaNm)
            ->where('scctcust.CODE03', $request->kelas);

        if (!blank($request->bta_filter)) {
            $query->where('scctbill.BTA', trim((string) $request->bta_filter));
        }

        if (!blank($request->nis)) {
            $nis = trim((string) $request->nis);
            $query->where(function ($q) use ($nis) {
                $q->where('scctcust.NOCUST', 'like', "%{$nis}%")
                    ->orWhere('scctcust.NUM2ND', 'like', "%{$nis}%");
            });
        }
        if ($this->unitScope) {
            $query->where('scctcust.CODE01', $this->unitScope);
        }
        if ($request->jenis === 'belum') {
            $query->where('scctbill.PAIDST', 0);
        } elseif ($request->jenis === 'sudah') {
            $query->where('scctbill.PAIDST', 1);
        }

        return $query
            ->select([
                'scctbill.AA',
                'scctbill.CUSTID',
                'scctbill.BILLCD',
                'scctbill.BILLAC',
                'scctbill.BILLNM',
                'scctbill.BILLAM',
                'scctbill.BTA',
                'scctbill.PAIDST',
                'scctcust.NOCUST',
                'scctcust.NMCUST',
                'scctcust.NUM2ND',
                'scctcust.DESC02',
                'scctcust.DESC03',
            ])
            ->orderBy('scctcust.NMCUST')
            ->get();
    }
}
