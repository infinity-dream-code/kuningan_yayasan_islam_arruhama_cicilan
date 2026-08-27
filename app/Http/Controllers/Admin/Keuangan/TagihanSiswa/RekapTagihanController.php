<?php

namespace App\Http\Controllers\Admin\Keuangan\TagihanSiswa;

use App\Http\Controllers\Controller;
use App\Support\PerNisMatrixPdf;
use App\Support\MultiVa;
use App\Models\mst_kelas;
use App\Models\mst_sekolah;
use App\Models\mst_tagihan;
use App\Models\mst_thn_aka;
use App\Models\u_akun;
use App\Models\scctbill;
use App\Models\scctbill_detail;
use App\Models\scctcust;
use Barryvdh\DomPDF\Facade\Pdf;
use Exception;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class RekapTagihanController extends Controller
{
    public ?string $sekolah = null;
    private string $title = 'Keuangan';
    private string $mainTitle = 'Tagihan Siswa';
    private string $dataTitle = 'Rekap Tagihan';
    private string $cacheKey = 'rekap tagihan';
    private array $allowedFilters = [
        'dari_tanggal' => 'scctbill.FTGLTagihan_start',
        'sampai_tanggal' => 'scctbill.FTGLTagihan_end',
        'periode_mulai' => 'scctbill.BILLAC_start',
        'periode_akhir' => 'scctbill.BILLAC_end',
        'tahun_pelajaran' => 'scctbill.BTA',
        'kode_rek' => 'scctbill_detail.KodePost',
        'rek' => 'scctbill.BILLAC',
        'nama_tagihan' => 'scctbill.BILLNM',
        'custid' => 'scctbill.CUSTID',
    ];

    public function __construct()
    {
        $key = Str::slug($this->cacheKey) . '_cache_version';

        Cache::add($key, 1);
        $this->middleware(function ($request, $next) {
            if (Auth::check()) {
                $user = Auth::user();
                $this->sekolah = $user->sekolah;
            }
            return $next($request);
        });
    }

    private function columnsUrl(): string
    {
        return route('admin.keuangan.tagihan-siswa.rekap-tagihan.get-column');
    }

    private function datasUrl(): string
    {
        return route('admin.keuangan.tagihan-siswa.rekap-tagihan.get-data');
    }

    private function applyUnitScope($query, string $table = 'scctcust'): void
    {
        \App\Support\SchoolScope::apply($query, $table, $this->sekolah);
    }

    private function resolveScopedSchoolCodes(): array
    {
        if (blank($this->sekolah)) {
            return [];
        }

        return [trim((string) $this->sekolah)];
    }

    public function index()
    {
        $schoolCodes = $this->resolveScopedSchoolCodes();

        $data['title'] = $this->title;
        $data['mainTitle'] = $this->mainTitle;
        $data['dataTitle'] = $this->dataTitle;
        $data['columnsUrl'] = $this->columnsUrl();
        $data['datasUrl'] = $this->datasUrl();

        $data['thn_aka'] = mst_thn_aka::orderBy('thn_aka', 'desc')->get();
        if (Schema::hasTable('mst_sekolah')) {
            $data['sekolah'] = mst_sekolah::when(!empty($schoolCodes), function ($query) use ($schoolCodes) {
                $query->whereIn('CODE01', $schoolCodes);
            })->get();
        } else {
            $data['sekolah'] = scctcust::selectRaw('CODE01, DESC01')
                ->when($this->sekolah, function ($query) {
                    $query->where('DESC01', $this->sekolah)
                        ->orWhere('CODE01', $this->sekolah);
                })
                ->whereNotNull('CODE01')
                ->where('CODE01', '!=', '')
                ->groupBy('CODE01', 'DESC01')
                ->orderBy('DESC01')
                ->get();
        }
        $data['kelas'] = mst_kelas::dropdownQuery($this->sekolah)
            ->orderByRaw("CASE WHEN kelas REGEXP '^[0-9]+$' THEN 0 ELSE 1 END, kelas")
            ->get();
        $data['tagihan'] = mst_tagihan::orderBy('urut', 'asc')->get();
        $data['akun'] = u_akun::orderBy('KodeAkun', 'asc')->get();

        return view('admin.keuangan.tagihan_siswa.rekap_tagihan.index_new', $data);
    }

    public function getColumn()
    {
        return [
            ['data' => null, 'name' => 'no', 'columnType' => 'row', 'exportable' => true],
            ['data' => 'BILLAC', 'name' => 'Periode', 'searchable' => true, 'orderable' => true, 'exportable' => true, 'numberColumn' => true],
            ['data' => 'CODE02', 'name' => 'Unit', 'searchable' => true, 'orderable' => true, 'exportable' => true],
            ['data' => 'DESC02', 'name' => 'Kelas', 'searchable' => true, 'orderable' => true, 'exportable' => true],
            ['data' => 'DESC03', 'name' => 'Kelompok', 'searchable' => true, 'orderable' => true, 'exportable' => true],
            ['data' => 'DESC04', 'name' => 'Angkatan', 'searchable' => true, 'orderable' => true, 'exportable' => true],
            ['data' => 'KodePost', 'name' => 'Kode', 'searchable' => true, 'orderable' => true, 'exportable' => true],
            ['data' => 'NamaAkun', 'name' => 'Nama post', 'searchable' => true, 'orderable' => true, 'exportable' => true],
            ['data' => 'BILLAM', 'name' => 'Tagihan', 'searchable' => true, 'orderable' => true, 'columnType' => 'currency', 'classname' => 'text-end', 'exportable' => true],
            ['data' => 'NOCUST', 'name' => 'NIS', 'searchable' => true, 'orderable' => true, 'exportable' => true],
            ['data' => 'NOVA', 'name' => 'NO VA', 'exportable' => true],
            ['data' => 'NMCUST', 'name' => 'NAMA', 'searchable' => true, 'orderable' => true, 'exportable' => true],
            ['data' => 'BILLNM', 'name' => 'Nama Tagihan', 'searchable' => true, 'orderable' => true, 'exportable' => true],
            ['data' => 'BTA', 'name' => 'Tahun AKA', 'searchable' => true, 'orderable' => true, 'exportable' => true],
            ['data' => 'FUrutan', 'name' => 'Urutan', 'searchable' => true, 'orderable' => true, 'exportable' => true],
        ];
    }

    public function cetakRekap(Request $request)
    {
        $request->merge([
            'draw' => 2,
            'start' => 0,
            'length' => 'poll',
        ]);
        set_time_limit(300);

        try {
            $records = $this->getData($request);
            $records = json_decode(json_encode($records), true);
            $records = $records['original']['data'] ?? [];
            if (empty($records)) {
                return response()->json(['message' => 'Data tagihan tidak ditemukan'], 422);
            }

            $customPaper = [0, 0, 935.43, 595.28];

            $pdf = Pdf::loadView(
                'cetak.rekap-tagihan',
                [
                    'tagihans' => $records,

                ]
            )
                ->setOptions([
                    'isHtml5ParserEnabled' => true,
                    'isPhpEnabled' => true,
                ])
                ->setPaper($customPaper);

            return $pdf->download('rekap-tagihan.pdf');
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Tidak dapat mencetak rekap tagihan.<br>Silahkan coba lagi atau hubungi administrator.',
                'error' => $e->getMessage(),
            ], 422);
        }

        //        return $filter;
    }

    public function cetakPerNis(Request $request)
    {
        try {
            $custid = null;
            if ($request->filled('custid')) {
                try {
                    $custid = Crypt::decrypt($request->input('custid'));
                } catch (DecryptException $e) {
                    return response()->json(['message' => 'Siswa tidak ditemukan'], 422);
                }
            }

            if ($custid === null) {
                return response()->json(['message' => 'Silahkan pilih baris siswa pada tabel terlebih dahulu'], 422);
            }

            $filter = $request->input('filter', []);
            $filter['custid'] = $custid;

            $request->merge([
                'filter' => $filter,
                'draw' => 2,
                'start' => 0,
                'length' => 'poll',
            ]);

            $response = $this->getData($request);
            $payload = json_decode(json_encode($response), true);
            $records = $this->normalizePerNisRecords($payload['original']['data'] ?? []);

            if (empty($records)) {
                return response()->json(['message' => 'Tagihan tidak ditemukan untuk siswa yang dipilih'], 422);
            }

            $postColCount = PerNisMatrixPdf::countPostColumns($records);
            $paper = PerNisMatrixPdf::paperSize($postColCount);
            $orientation = PerNisMatrixPdf::paperOrientation($postColCount);

            $pdf = Pdf::loadView('cetak.per-nis-matrix', [
                'tagihans' => $records,
                'reportTitle' => 'REKAP TAGIHAN - CETAK PER NIS',
                'useNamaAkunHeader' => true,
            ])
                ->setOptions([
                    'isHtml5ParserEnabled' => true,
                    'isPhpEnabled' => true,
                    'dpi' => 96,
                    'defaultFont' => 'DejaVu Serif',
                ]);

            if (is_array($paper)) {
                $pdf->setPaper($paper);
            } else {
                $pdf->setPaper($paper, $orientation);
            }

            return $pdf->download('cetak-per-nis.pdf');
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Tidak dapat mencetak rekap per NIS.<br>Silahkan coba lagi atau hubungi administrator.',
                'error' => $e->getMessage(),
            ], 422);
        }
    }

    private function normalizePerNisRecords(array $records): array
    {
        return collect($records)->map(function ($row) {
            $row = is_array($row) ? $row : (array) $row;
            $get = static fn (string $key) => $row[$key] ?? $row[strtolower($key)] ?? null;

            return [
                'CUSTID' => $get('CUSTID'),
                'NOCUST' => $get('NOCUST'),
                'NUM2ND' => $get('NUM2ND'),
                'NMCUST' => $get('NMCUST'),
                'DESC02' => $get('DESC02'),
                'DESC03' => $get('DESC03'),
                'BILLAC' => $get('BILLAC'),
                'BILLNM' => $get('BILLNM'),
                'BILLAM' => (float) ($get('BILLAM') ?? 0),
                'BTA' => $get('BTA'),
                'FUrutan' => $get('FUrutan') ?? $get('Urutan'),
                'KodePost' => $get('KodePost') ?? $get('KodeAkun') ?? '-',
                'NamaAkun' => $get('NamaAkun') ?? $get('BILLNM') ?? '-',
            ];
        })->values()->all();
    }

    public function getData(Request $request)
    {
        $draw = $request->get('draw');

        $start = $request->get("start");
        $rowperpage = $request->get("length");

        $columnIndex_arr = $request->get('order', []);
        $columnName_arr = $request->get('columns', []);
        $order_arr = $request->get('order', []);
        $search_arr = $request->get('search', []);
        $searchValue = $search_arr['value'] ?? '';

        $columnName = 'scctbill.FUrutan';
        $columnSortOrder = 'ASC';

        if (!empty($order_arr)) {
            $columnIndex = $columnIndex_arr[0]["column"] ?? null;
            if (
                $columnIndex !== null &&
                !empty($columnName_arr[$columnIndex]["data"]) &&
                $columnName_arr[$columnIndex]["data"] !== "no"
            ) {
                $columnName = $columnName_arr[$columnIndex]["data"];
                $columnSortOrder = $order_arr[0]["dir"] ?? "desc";
            }
        }

        $sortableColumns = [
            'BILLNM' => 'scctbill.BILLNM',
            'BILLAM' => 'scctbill_detail.BILLAM',
            'BILLAC' => 'scctbill.BILLAC',
            'BTA' => 'scctbill.BTA',
            'FUrutan' => 'scctbill.FUrutan',
            'Urutan' => 'scctbill.FUrutan',
            'NOCUST' => 'scctcust.NOCUST',
            'NMCUST' => 'scctcust.NMCUST',
            'KodePost' => 'scctbill_detail.KodePost',
            'NamaAkun' => 'u_akun.NamaAkun',
        ];
        if (isset($sortableColumns[$columnName])) {
            $columnName = $sortableColumns[$columnName];
        } elseif ($columnName && !str_contains($columnName, '.')) {
            $columnName = 'scctbill.' . $columnName;
        }

        $filters = [];
        $filters1 = [];
        $filterQuery = null;
        $filterQuery1 = null;

        $filter = $request->input('filter', []);
        if ($request->filled('custid') && !isset($filter['custid'])) {
            try {
                $filter['custid'] = Crypt::decrypt($request->input('custid'));
            } catch (DecryptException $e) {
                $filter['custid'] = $request->input('custid');
            }
        }
        if ($filter) {
            foreach ($filter as $key => $val) {
                if (is_array($val)) {
                    $val = array_values(array_filter($val, fn($item) => !is_null($item) && $item !== '' && strtolower((string) $item) !== 'all'));
                    if (empty($val)) {
                        continue;
                    }
                } elseif (strtolower((string) $val) == 'all' || $val === null || $val === '') {
                    continue;
                }
                if ($val !== null && $val !== '') {
                    $colName = match ($key) {
                        'dari_tanggal', 'sampai_tanggal' => 'scctbill.FTGLTagihan',
                        'periode_mulai', 'periode_akhir' => 'scctbill.BILLAC',
                        'tahun_pelajaran' => 'scctbill.BTA',
                        'kode_rek' => 'scctbill_detail.KodePost',
                        'rek' => 'scctbill.BILLAC',
                        'nama_tagihan' => 'scctbill.BILLNM',
                        'custid' => 'scctbill.CUSTID',
                        default => null
                    };
                    if (in_array($key, ['dari_tanggal', 'sampai_tanggal']) && preg_match('/^\d{2}-\d{2}-\d{4}$/', $val)) {
                        if ($key == 'dari_tanggal') {
                            $date = Carbon::createFromFormat('d-m-Y', $val)->startOfDay();
                        } else {
                            $date = Carbon::createFromFormat('d-m-Y', $val)->endOfDay();
                        }

                        if ($date && $colName) {
                            $operator = $key === 'dari_tanggal' ? '>=' : '<=';
                            $filters[] = [$colName, $operator, $date];
                        }
                    } elseif (in_array($key, ['periode_mulai', 'periode_akhir']) && preg_match('/^\d{6}$/', (string) $val)) {
                        if ($colName) {
                            $operator = $key === 'periode_mulai' ? '>=' : '<=';
                            $filters[] = [$colName, $operator, (string) $val];
                        }
                    } elseif (is_array($val)) {
                        ($colName) && $filters[] = [$colName, 'in', $val];
                    } elseif ($key === 'nama_tagihan') {
                        $namaTagihans = array_values(array_filter(array_map('trim', explode(',', (string) $val))));
                        if (!empty($namaTagihans) && $colName) {
                            $filters[] = [$colName, 'in', $namaTagihans];
                        }
                    } elseif ($key === 'tahun_pelajaran' && $colName) {
                        $normalizedVal = str_replace([' ', '-'], ['', '/'], trim((string) $val));
                        $filters[] = [
                            'whereRaw',
                            'REPLACE(REPLACE(TRIM(scctbill.BTA), " ", ""), "-", "/") = ?',
                            [$normalizedVal],
                        ];
                    } else {
                        ($colName) && $filters[] = [$colName, '=', $val];
                    }

                    $colName = match ($key) {
                        'nis' => 'scctcust.NOCUST',
                        'thn_aka' => 'scctcust.DESC04',
                        default => null
                    };

                    if ($key == 'nis') {
                        ($colName) && $filters1[] = [$colName, 'like', '%' . $val . '%'];
                    } else if ($key == 'kelas') {
                        $kelasValues = is_array($val) ? $val : [$val];
                        $kelasPairs = [];
                        foreach ($kelasValues as $kelasValue) {
                            $kelasPart = explode(",", (string) $kelasValue);
                            if (count($kelasPart) == 3) {
                                $kelasPairs[] = [
                                    'CODE02' => $kelasPart[0],
                                    'DESC02' => $kelasPart[1],
                                    'DESC03' => $kelasPart[2],
                                ];
                            }
                        }
                        if (!empty($kelasPairs)) {
                            $filters1[] = ['_kelas_multi', '=', $kelasPairs];
                        }
                    } else if ($key === 'sekolah') {
                        $filters1[] = ['_sekolah', '=', $val];
                    } else {
                        ($colName) && $filters1[] = [$colName, '=', $val];
                    }
                }
            }

            $filters1[] = ['scctcust.STCUST', '=', '1'];

            if (!empty($filters)) {
                $filterQuery = function ($query) use ($filters) {
                    foreach ($filters as $filter) {
                        if (($filter[0] ?? null) === 'whereRaw') {
                            $query->whereRaw($filter[1], $filter[2] ?? []);
                            continue;
                        }
                        if (count($filter) === 3) {
                            if (($filter[1] ?? null) === 'in' && is_array($filter[2] ?? null)) {
                                $query->whereIn($filter[0], $filter[2]);
                            } else {
                                $query->where($filter[0], $filter[1], $filter[2]);
                            }
                        }
                    }
                };
            }

            if (!empty($filters1)) {
                $filterQuery1 = function ($query) use ($filters1) {
                    foreach ($filters1 as $filter) {
                        if (($filter[0] ?? null) === '_kelas_multi') {
                            $kelasPairs = $filter[2] ?? [];
                            if (!empty($kelasPairs)) {
                                $query->where(function ($q) use ($kelasPairs) {
                                    foreach ($kelasPairs as $kelasPair) {
                                        $q->orWhere(function ($kelasQuery) use ($kelasPair) {
                                            $kelasQuery->where('scctcust.CODE02', '=', $kelasPair['CODE02'])
                                                ->where('scctcust.DESC02', '=', $kelasPair['DESC02'])
                                                ->where('scctcust.DESC03', '=', $kelasPair['DESC03']);
                                        });
                                    }
                                });
                            }
                            continue;
                        }
                        if (($filter[0] ?? null) === '_sekolah') {
                            $value = $filter[2] ?? null;
                            if (!blank($value)) {
                                $query->where(function ($q) use ($value) {
                                    $q->where('scctcust.CODE02', '=', $value);
                                });
                            }
                            continue;
                        }
                        if (count($filter) === 3) {
                            if (($filter[1] ?? null) === 'in' && is_array($filter[2] ?? null)) {
                                $query->whereIn($filter[0], $filter[2]);
                            } else {
                                $query->where($filter[0], $filter[1], $filter[2]);
                            }
                        }
                    }
                };
            }
        }

        $whereAny = [
            'scctcust.NMCUST',
            'scctcust.NOCUST',
        ];

        $select = array_unique(array_merge($whereAny, [
            'scctbill.AA',
            'scctbill.BILLNM',
            'scctbill.BILLAC',
            'scctbill_detail.BILLAM as BILLAM',
            'scctbill.PAIDST',
            'scctbill.BILLCD',
            'scctbill.PAIDDT',
            'scctbill.BTA',
            'scctbill.FIDBANK',
            'scctbill.FUrutan',
            'scctbill.FUrutan as Urutan',
            'scctbill.va',
            'scctbill.isINSTALLABLE',
            'scctcust.CODE02',
            'scctcust.DESC01',
            'scctcust.DESC02',
            'scctcust.DESC03',
            'scctcust.DESC04',
            'scctcust.NUM2ND',
            'scctbill.CUSTID',
            'scctbill_detail.KodePost',
            'u_akun.KodeAkun',
            'u_akun.NamaAkun',
        ]));

        $query = scctbill_detail::join('scctbill', function ($join) {
            // Relasi utama BILLCD, ditambah CUSTID agar join tidak melebar/duplikatif.
            $join->on('scctbill.BILLCD', '=', 'scctbill_detail.BILLCD')
                ->on('scctbill.CUSTID', '=', 'scctbill_detail.CUSTID');
        })
            ->leftJoin('scctcust', 'scctcust.CUSTID', '=', 'scctbill.CUSTID')
            ->leftJoin('u_akun', 'u_akun.KodeAkun', 'scctbill_detail.KodePost')
            ->where('scctbill.PAIDST', 0)
            ->where('scctbill.FSTSBolehBayar', 1)
            ->whereNotNull('scctbill_detail.KodePost')
            ->when(!blank($searchValue), function ($query) use ($whereAny, $searchValue) {
                $query->where(function ($q) use ($whereAny, $searchValue) {
                    $sanitizeSearch = str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $searchValue);
                    foreach ($whereAny as $column) {
                        $q->orWhere($column, 'like', '%' . $sanitizeSearch . '%');
                    }
                });
            })
            ->where(function ($query) use ($filterQuery) {
                if ($filterQuery) {
                    $filterQuery($query);
                }
            })
            ->where(function ($query) use ($filterQuery1) {
                if ($filterQuery1) {
                    $filterQuery1($query);
                }
            });

        $this->applyUnitScope($query);

        $totalFiltered = (clone $query)->count();

        $effectiveRowPerPage = $rowperpage === 'poll' ? $totalFiltered : (int) $rowperpage;
        $records = (clone $query)
            ->orderByRaw("CASE WHEN scctcust.NOCUST IS NULL OR TRIM(CAST(scctcust.NOCUST AS CHAR)) = '' OR scctcust.NOCUST = '-' THEN 1 ELSE 0 END ASC")
            ->orderBy('scctcust.NOCUST', 'asc')
            ->orderBy('scctbill.FUrutan', 'asc')
            ->orderBy($columnName, $columnSortOrder)
            ->select($select)
            ->skip((int) $start)
            ->take($effectiveRowPerPage > 0 ? $effectiveRowPerPage : 10)
            ->get();

        $records->map(function ($item, $index) {
            $item->item_id = Crypt::encrypt($item['AA']);
            $item->CUSTID = Crypt::encrypt($item['CUSTID']);
            $item->NOVA = ($item->NOCUST && $item->NOCUST != '-')
                ? MultiVa::formatNoVaFromBill($item->NOCUST, $item)
                : null;
            // Pastikan kolom urutan selalu tersedia untuk DataTable dan PDF.
            $urut = blank($item->FUrutan) ? ($index + 1) : $item->FUrutan;
            $item->FUrutan = (string) $urut;
            $item->Urutan = (string) $urut;
            if (!$item->NOCUST || $item->NOCUST == '-') $item->NOCUST = null;
            if (!$item->NUM2ND || $item->NUM2ND == '-') $item->NUM2ND = null;
            return $item;
        });

        $response = array(
            "draw" => intval($draw),
            "recordsTotal" => $totalFiltered,
            "recordsFiltered" => $totalFiltered,
            "data" => $records->toArray(),
        );
        return response()->json($response);
    }

    public function cetakKartuSiswa(Request $request)
    {
        if (!$request->filled('custid')) return response()->json(['error' => 'siswa tidak ditemukan']);
        try {
            $val = Crypt::decrypt($request->input('custid'));
        } catch (DecryptException $e) {
            return response()->json(['error' => 'siswa tidak ditemukan']);
        }

        $siswa = scctcust::where('CUSTID', $val)
            ->where(function ($query) {
                $this->applyUnitScope($query);
            })
            ->first();
        if (!$siswa) return response()->json(['message' => 'Siswa tidak ditemukan'], 422);

        $request->merge([
            'filter' => array_merge($request->input('filter', []), [
                'custid' => $val,
            ]),
            'draw' => 2,
            'start' => 0,
            'length' => 'poll',
        ]);
        $tagihans = $this->getData($request);

        try {
            $tagihans = json_decode(json_encode($tagihans), true);
            $tagihans = $tagihans['original']['data'] ?? [];
            if (empty($tagihans)) {
                return response()->json(['message' => 'Tagihan Tidak Ditemukan'], 422);
            }
            $nova = MultiVa::formatNoVaFromBills(
                $siswa->NOCUST ?: $siswa->NUM2ND,
                $tagihans
            );
            $pdf = Pdf::loadView('cetak.kartu-siswa', [
                'tagihans' => $tagihans,
                'siswa' => $siswa,
                'nova' => $nova,
            ]);
            return $pdf->download('kartu-siswa.pdf');
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Tagihan Tidak Ditemukan', 'error' => $e->getMessage()], 422);
        }
    }
}
