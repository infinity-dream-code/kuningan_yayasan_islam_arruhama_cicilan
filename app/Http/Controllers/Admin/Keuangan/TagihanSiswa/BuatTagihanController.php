<?php

namespace App\Http\Controllers\Admin\Keuangan\TagihanSiswa;

use App\Http\Controllers\Controller;
use App\Models\mst_kelas;
use App\Models\mst_thn_aka;
use App\Models\u_akun;
use App\Models\u_daftar_harga;
use App\Models\scctbill;
use App\Models\scctbill_detail;
use App\Models\scctcust;
use App\Models\ValidationMessage;
use App\Support\SchoolScope;
use App\Support\MultiVa;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class BuatTagihanController extends Controller
{
    private string $title = 'Keuangan';
    private string $mainTitle = 'Tagihan Siswa';
    private string $dataTitle = 'Buat Tagihan';
    private string $showTitle = 'Buat Tagihan';

    private ?string $unitScope = null;

    public function __construct()
    {
        $this->middleware(function ($request, $next) {
            if (Auth::check()) {
                $user = Auth::user();
                $this->unitScope = $user->unit;
            }
            return $next($request);
        });
    }

    public function index()
    {
        $data['title'] = $this->title;
        $data['mainTitle'] = $this->mainTitle;
        $data['dataTitle'] = $this->dataTitle;
        $data['showTitle'] = $this->showTitle;

        $data['thn_aka'] = mst_thn_aka::orderBy('thn_aka', 'desc')->get();
        $data['kelas'] = mst_kelas::dropdownQuery($this->unitScope)
            ->orderByRaw("CASE WHEN kelas REGEXP '^[0-9]+$' THEN 0 ELSE 1 END, kelas")
            ->get();
        $data['tagihan'] = u_akun::query()
            ->orderBy('KodeAkun', 'asc')
            ->get(['KodeAkun', 'NamaAkun']);

        return view('admin.keuangan.tagihan_siswa.buat_tagihan.index_new', $data);
    }

    public function getColumn()
    {
        return [
            [
                'data' => 'check',
                'name' => "<input type='checkbox' class='form-check-input' id='check-all'>",
                'className' => 'text-center',
                'input' => 'check',
                "targets" => 0
            ],
            ['data' => 'kode', 'name' => 'Kode', 'searchable' => true, 'orderable' => true],
            ['data' => 'nama_post', 'name' => 'Nama Post', 'searchable' => true, 'orderable' => true],
            ['data' => 'nama_tagihan', 'name' => 'Nama Tagihan', 'searchable' => true, 'orderable' => true],
            ['data' => 'nominal', 'name' => 'Nominal', 'input' => 'text', 'currency' => true],
            ['data' => 'potongan', 'name' => 'Potongan', 'input' => 'text', 'currency' => true],
            ['data' => 'cicilan', 'name' => 'Cicilan', 'input' => 'text', 'currency' => false],
        ];
    }

    public function getData(Request $request)
    {
        return response()->json([
            "draw" => intval($request->get('draw')),
            "recordsTotal" => 0,
            "recordsFiltered" => 0,
            "data" => [],
        ]);
    }

    public function getColumnSiswa()
    {
    }

    public function getDataSiswa(Request $request)
    {
    }

    public function getSiswa(Request $request)
    {
        $kelasId = $request->kelas != 'all' ? $request->kelas ?? null : null;
        $thn_aka = $request->angkatan != 'all' ? trim((string) ($request->angkatan ?? '')) : '';
        $thn_aka = $thn_aka === '' ? null : $thn_aka;

        $nis = null;
        $nama = null;
        if (isset($request->cari_siswa) && $request->cari_siswa) {
            is_numeric($request->cari_siswa)
                ? $nis = '%' . $request->cari_siswa . '%'
                : $nama = '%' . $request->cari_siswa . '%';
        }

        $select = [
            'scctcust.CUSTID',
            'scctcust.nocust as nis',
            'scctcust.NUM2ND as nomor_pendaftaran',
            'scctcust.nmcust as nama',
            'scctcust.CODE02',
            'scctcust.CODE03',
            'scctcust.DESC02',
            'scctcust.DESC03',
            'scctcust.DESC04 as angkatan',
        ];

        $query = scctcust::query()
            ->when($this->unitScope, fn ($q) => $q->where('scctcust.CODE01', $this->unitScope));

        $hasAnyFilter = $kelasId || $thn_aka || $nis || $nama;
        if (!$hasAnyFilter) {
            return response()->json(['data' => []]);
        }

        if ($request->siswa_only == true) {
            $query->when($nis, function ($q, $nis) {
                return $q->where(function ($q2) use ($nis) {
                    $q2->where('scctcust.nocust', 'like', $nis)
                        ->orWhere('scctcust.NUM2ND', 'like', $nis);
                });
            })
            ->when($nama, fn ($q, $nama) => $q->where('scctcust.nmcust', 'like', $nama));
        } else {
            $query->when($kelasId, fn ($q, $id) => $q->where('scctcust.CODE03', '=', $id))
                ->when($thn_aka, function ($q) use ($thn_aka) {
                    $normalized = str_replace([' ', '-'], ['', '/'], trim($thn_aka));
                    $q->whereRaw(
                        'REPLACE(REPLACE(TRIM(scctcust.DESC04), " ", ""), "-", "/") = ?',
                        [$normalized]
                    );
                })
                ->when($nis, function ($q, $n) {
                    $q->where(function ($q2) use ($n) {
                        $q2->where('scctcust.nocust', 'like', $n)
                            ->orWhere('scctcust.NUM2ND', 'like', $n);
                    });
                })
                ->when($nama, fn ($q, $n) => $q->where('scctcust.nmcust', 'like', $n));
        }

        if ($request->boolean('debug')) {
            $debugQuery = (clone $query)->select($select);
            $sql = $debugQuery->toSql();
            $bindings = $debugQuery->getBindings();
            $sample = scctcust::query()
                ->when($this->unitScope, fn ($q) => $q->where('scctcust.CODE01', $this->unitScope))
                ->select('CODE02', 'CODE03', 'DESC02', 'DESC03', 'DESC04', DB::raw('COUNT(*) as n'))
                ->groupBy('CODE02', 'CODE03', 'DESC02', 'DESC03', 'DESC04')
                ->orderBy('DESC04', 'desc')
                ->limit(30)
                ->get();
            return response()->json([
                'input' => [
                    'kelas' => $kelasId,
                    'angkatan' => $thn_aka,
                    'unitScope' => $this->unitScope,
                    'cari_siswa' => $request->cari_siswa,
                    'siswa_only' => $request->siswa_only,
                ],
                'sql' => $sql,
                'bindings' => $bindings,
                'sample' => $sample,
                'kelas_in_mst_kelas' => mst_kelas::where('id', $kelasId)->first(),
            ]);
        }

        $siswa = $query->select($select)
            ->orderBy('scctcust.nocust', 'asc')
            ->limit(500)
            ->get()
            ->map(function ($item) {
                return [
                    'CUSTID' => $item->CUSTID,
                    'nis' => $item->nis,
                    'nomor_pendaftaran' => $item->NUM2ND,
                    'nama' => $item->nama,
                    'CODE02' => $item->CODE02,
                    'CODE03' => $item->CODE03,
                    'kelas' => trim(($item->DESC02 ?? '') . ' ' . ($item->DESC03 ?? '')),
                    'jenjang' => $item->DESC02,
                    'angkatan' => $item->angkatan,
                ];
            });

        return response()->json(['data' => $siswa]);
    }

    public function getMasterHarga(Request $request)
    {
        $data = [];
        $thn_aka = $request->thn_aka != 'all' ? $request->thn_aka ?? null : null;
        $kelas = $request->kelas != 'all' ? $request->kelas ?? null : null;

        $select = [
            'u_daftar_harga.thn_masuk as tahun_masuk',
            'u_daftar_harga.KodeAkun as kode_akun',
            'u_akun.NamaAkun as nama_akun',
            'u_daftar_harga.nominal as nominal',
        ];

        if ($thn_aka) {
            $data = u_akun::orderBy('u_akun.KodeAkun', 'asc')
                ->join('u_daftar_harga', 'u_daftar_harga.KodeAkun', '=', 'u_akun.KodeAkun')
                ->when($thn_aka, function ($query, $thn_aka) {
                    return $query->where('u_daftar_harga.thn_masuk', 'like', $thn_aka);
                })->when($kelas, function ($query, $kelas) {
                    return $query->where(function ($q) use ($kelas) {
                        $q->where('u_daftar_harga.kode_prod', 'like', $kelas)
                            ->orWhereNull('u_daftar_harga.kode_prod')
                            ->orWhere('u_daftar_harga.kode_prod', '=', '');
                    });
                })
                ->select($select)
                ->orderBy('u_daftar_harga.KodeAkun', 'asc')
                ->whereNotNull('u_daftar_harga.KodeAkun')
                ->get()
                ->toArray();
        }

        $response = array(
            "data" => $data,
        );

        return response()->json($response);
    }


    public function store(Request $request)
    {
        $request->merge([
            'nama_tagihan' => $this->resolveNamaTagihan($request),
        ]);

        $request->validate([
            'tahun_pelajaran' => ['required', 'regex:/^\d{4}\/\d{4}(?:\s*-\s*(GANJIL|GENAP))?$/'],
            'tahun_angkatan' => ['required', 'regex:/^\d{4}\/\d{4}(?:\s*-\s*(GANJIL|GENAP))?$/'],
            'kelas' => ['required'],
            'siswa' => ['required', 'array', 'min:1'],
            'fungsi' => ['required', 'regex:/^\d{6}$/'],
            'nama_tagihan' => ['required', 'string'],
            'tagihan' => ['required', 'array', 'min:1'],
            'tagihan.*.tagihan' => ['required'],
            'tagihan.*.nominal' => ['required', 'regex:/^[0-9]+(\.[0-9]{3})*$/', 'not_in:0'],
        ], ValidationMessage::messages(), array_merge(ValidationMessage::attributes(), [
            'nama_tagihan' => 'Nama Tagihan',
        ]));

        $tahun_angkatan = mst_thn_aka::where('thn_aka', $request->tahun_angkatan)->value('thn_aka');
        if (!$tahun_angkatan) {
            return response()->json(['message' => 'Tahun angkatan tidak valid'], 422);
        }

        $tahun_pelajaran_val = mst_thn_aka::where('thn_aka', $request->tahun_pelajaran)->value('thn_aka');
        if (!$tahun_pelajaran_val || !preg_match('/\d{4}\/\d{4}/', $tahun_pelajaran_val, $matches)) {
            return response()->json(['message' => 'Tahun pelajaran tidak valid'], 422);
        }
        $tahun_pelajaran_bta = $matches[0];

        $tahun_pelajaran = $request->input('tahun_pelajaran');
        $kelas = $request->input('kelas');
        $kodeAkunList = collect($request->input('tagihan', []))
            ->pluck('tagihan')
            ->filter()
            ->values()
            ->all();

        $tagihans = u_daftar_harga::leftJoin('u_akun', 'u_akun.KodeAkun', '=', 'u_daftar_harga.KodeAkun')
            ->whereIn('u_daftar_harga.KodeAkun', $kodeAkunList)
            ->when($tahun_angkatan, function ($query, $tahun_angkatan) {
                return $query->where('u_daftar_harga.thn_masuk', 'like', $tahun_angkatan);
            })->when($kelas, function ($query, $kelas) {
                return $query->where(function ($q) use ($kelas) {
                    $q->orwhere('u_daftar_harga.kode_prod', 'like', $kelas)
                        ->orWhereNull('u_daftar_harga.kode_prod')
                        ->orWhere('u_daftar_harga.kode_prod', '=', '');
                });
            })->select('u_daftar_harga.KodeAkun', 'u_akun.NamaAkun')
            ->get();

        if ($tagihans->isEmpty()) return response()->json(['message' => 'Tagihan tidak ditemukan 1'], 422);
        if (count($request->input('tagihan')) != $tagihans->count()) return response()->json(['message' => 'Jumlah tagihan yang dipilih tidak sesuai dengan jumlah data, silahkan muat ulang halaman!'], 422);

        $billNm = trim((string) $request->nama_tagihan);
        if (!u_akun::where('NamaAkun', $billNm)->exists()) {
            return response()->json([
                'message' => 'Nama tagihan "' . $billNm . '" tidak ditemukan di daftar akun (u_akun).',
            ], 422);
        }

        try {
            DB::beginTransaction();

            $siswas = scctcust::whereIn('CUSTID', $request->input('siswa'))->get();
            if ($siswas->isEmpty()) return response()->json(['message' => 'Siswa tidak ditemukan'], 422);
            if (count($request->input('siswa')) != $siswas->count()) return response()->json(['message' => 'Jumlah siswa yang dipilih tidak sesuai dengan jumlah data, silahkan muat ulang halaman!'], 422);


            foreach ($siswas as $siswa) {
                $tagihanSiswaTerbaru = scctbill::where('CUSTID', $siswa->CUSTID)
                    ->select('CUSTID', 'FUrutan', 'BILLAC', 'BILLCD')
                    ->orderBy('FUrutan', 'DESC')
                    ->first();

                $urut = $tagihanSiswaTerbaru ? $tagihanSiswaTerbaru['FUrutan'] + 1 : 1;
                $billCD = date('Y') . '/i' . date('m') . '-' . ($urut + 1);

                foreach ($request->input('tagihan') as $item) {
                    $nominal = str_replace('.', '', $item['nominal']);
                    if (!is_numeric($nominal)) {
                        return response()->json(['message' => 'Nominal tidak boleh kosong'], 422);
                    }

                    $post = $tagihans->firstWhere('KodeAkun', $item['tagihan']);
                    if (!$post) return response()->json(['message' => 'Post tidak ditemukan'], 422);

                    $tahun = substr($request->fungsi, 0, 4);
                    $bulan = substr($request->fungsi, 4, 2);

                    $bill = scctbill::firstOrCreate([
                        'CUSTID' => $siswa->CUSTID,
                        'BILLAC' => $request->fungsi,
                        'BILLCD' => $billCD,
                        'BILLNM' => $billNm,
                    ], [
                        'BILLAM' => 0,
                        'PAIDST' => 0,
                        'FUrutan' => $urut,
                        'FTGLTagihan' => now(),
                        'FSTSBolehBayar' => 1,
                        'BTA' => $tahun_pelajaran_bta,
                        'va' => MultiVa::vaForBillName($billNm),
                    ]);

                    $bill->increment('BILLAM', (int) $nominal);

                    scctbill_detail::create([
                        'KodePost' => $post->KodeAkun,
                        'CUSTID' => $bill->CUSTID,
                        'BILLAM' => (int) $nominal,
                        'tahun' => $tahun,
                        'periode' => $bulan,
                        'BILLCD' => $bill->BILLCD,
                    ]);
                }
            }
            DB::commit();
            return response()->json(['message' => 'Tagihan telah dibuat']);
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('Error: ' . $e->getMessage(), ['exception' => $e]);
            return response()->json(['message' => 'Data gagal dibuat', 'error' => $e], 422);
        }
    }

    /**
     * Isi nama tagihan dari dropdown, atau dari baris master harga / kode akun yang dipilih.
     */
    private function resolveNamaTagihan(Request $request): ?string
    {
        $nama = trim((string) $request->input('nama_tagihan', ''));
        if ($nama !== '') {
            return u_akun::where('NamaAkun', $nama)->value('NamaAkun') ?? $nama;
        }

        $items = $request->input('tagihan', []);
        if (!is_array($items) || $items === []) {
            return null;
        }

        $first = reset($items);
        if (!is_array($first)) {
            return null;
        }

        $namaAkun = trim((string) ($first['nama_akun'] ?? ''));
        if ($namaAkun !== '') {
            return u_akun::where('NamaAkun', $namaAkun)->value('NamaAkun') ?? $namaAkun;
        }

        $kodeAkun = $first['tagihan'] ?? null;
        if ($kodeAkun === null || $kodeAkun === '') {
            return null;
        }

        return u_akun::where('KodeAkun', $kodeAkun)->value('NamaAkun');
    }
}
