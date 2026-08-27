<?php

namespace App\Http\Controllers\Admin\Keuangan\Saldo;

use App\Http\Controllers\Controller;
use App\Models\mst_kelas;
use App\Models\mst_sekolah;
use App\Models\mst_thn_aka;
use App\Models\scctcust;
use App\Support\MultiVa;
use App\Models\sccttran;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Mockery\Exception;

class SaldoVirtualSakuController extends Controller
{
    public ?string $sekolah = null;
    public string $datasUrl = '';
    public string $detailDatasUrl = '';
    public string $columnsUrl = '';
    private string $title = "Saldo";
    private string $mainTitle = 'Saldo Virtual SAKU';
    private string $dataTitle = 'Saldo Virtual SAKU';
    private string $showTitle = 'Detail Saldo Virtual SAKU';
    private string $cacheKey = 'saldo_virtual_saku';

    private const FIDBANK_SAKU = '23';

    private array $allowedFilters = [
        'kelas' => 'scctcust.DESC02',
        'sekolah' => 'scctcust.CODE01',
        'siswa' => 'scctcust.nmcust',
        'angkatan' => 'scctcust.DESC04',
    ];

    private function resolveScopedSchoolCodes(): array
    {
        if (blank($this->sekolah)) {
            return [];
        }

        return [trim((string) $this->sekolah)];
    }

    private function applyFilterQuery($query, array $filters): void
    {
        foreach ($filters as $filter) {
            if (($filter[0] ?? null) === 'whereRaw') {
                $query->whereRaw($filter[1], $filter[2] ?? []);
                continue;
            }
            if (($filter[0] ?? null) === '_sekolah') {
                $codes = $filter[2] ?? [];
                if (!empty($codes)) {
                    $query->whereIn('scctcust.CODE01', $codes);
                }
                continue;
            }
            if (count($filter) === 3) {
                if (($filter[1] ?? null) === 'in' && is_array($filter[2] ?? null)) {
                    $query->whereIn($filter[0], $filter[2]);
                } else {
                    $query->where($filter[0], $filter[1], $filter[2]);
                }
            } elseif (count($filter) === 4) {
                if ($filter[3] == 'whereBetween') {
                    $query->whereBetween($filter[0], [$filter[1], $filter[2]]);
                } else {
                    $query->{$filter[3]}($filter[0], $filter[1], $filter[2]);
                }
            }
        }
    }

    public function __construct()
    {
        $this->middleware(function ($request, $next) {
            if (Auth::check()) {
                $this->sekolah = Auth::user()->sekolah;
            }
            return $next($request);
        });

        $this->title = 'Keuangan';
        $this->mainTitle = 'Saldo';
        $this->dataTitle = 'Saldo Virtual SAKU';
        $this->showTitle = 'Detail Saldo Virtual SAKU';


        $this->datasUrl = route('admin.keuangan.saldo.saldo-virtual-saku.get-data');
        $this->detailDatasUrl = '';
        $this->columnsUrl = route('admin.keuangan.saldo.saldo-virtual-saku.get-column');
    }

    public function index()
    {
        $schoolCodes = $this->resolveScopedSchoolCodes();

        $data['thn_aka'] = mst_thn_aka::getMstThnAkaAttributes();
        $data['sekolah'] = mst_sekolah::select(['CODE01', 'DESC01'])
            ->when(!empty($schoolCodes), function ($query) use ($schoolCodes) {
                $query->whereIn('CODE01', $schoolCodes);
            })
            ->orderBy('DESC01')
            ->get();
        $data['kelas'] = mst_kelas::dropdownQuery($this->sekolah)
            ->orderByRaw("CASE WHEN jenjang REGEXP '^[0-9]+$' THEN 0 ELSE 1 END, jenjang")
            ->orderByRaw("CASE WHEN kelas REGEXP '^[0-9]+$' THEN 0 ELSE 1 END, kelas")
            ->get();
        $data['title'] = $this->title;
        $data['mainTitle'] = $this->mainTitle;
        $data['dataTitle'] = $this->dataTitle;
        $data['columnsUrl'] = route('admin.keuangan.saldo.saldo-virtual-saku.get-column');
        $data['datasUrl'] = route('admin.keuangan.saldo.saldo-virtual-saku.get-data');

        return view('admin.keuangan.saldo.saldo_virtual_saku.index', $data);
    }

    public function show($id)
    {
        try {
            $data['title'] = $this->title;
            $data['mainTitle'] = $this->mainTitle;
            $data['dataTitle'] = $this->dataTitle;
            $data['showTitle'] = $this->showTitle;
            $data['indexUrl'] = route('admin.keuangan.saldo.saldo-virtual-saku.index');
            $data['columnsUrl'] = route('admin.keuangan.saldo.saldo-virtual-saku.transaksi.get-column');
            $data['datasUrl'] = route('admin.keuangan.saldo.saldo-virtual-saku.transaksi.get-data', ['CUSTID' => $id]);

            $data['siswa'] = scctcust::find($id);

            if ($data['siswa']) {
                if ($data['siswa']->NOCUST && $data['siswa']->NOCUST != '-') {
                    $NOVA = MultiVa::formatNoVaBoth($data['siswa']->NOCUST);
                } else {
                    $NOVA = MultiVa::formatNoVaBoth($data['siswa']->NUM2ND);
                }
                $data['siswa']->NOVA = $NOVA;

                $data['totalKredit'] = sccttran::where('CUSTID', $id)
                    ->where('FIDBANK', self::FIDBANK_SAKU)
                    ->sum('KREDIT');
                $data['totalDebet'] = sccttran::where('CUSTID', $id)
                    ->where('FIDBANK', self::FIDBANK_SAKU)
                    ->sum('DEBET');
            } else {
                throw new Exception('Siswa tidak ditemukan');
            }

            return view('admin.keuangan.saldo.saldo_virtual_saku.show', $data);
        } catch (\Exception $e) {
            return redirect()->route('admin.keuangan.saldo.saldo-virtual-saku.index')->with('error', 'Siswa tidak ditemukan!');
        }
    }

    public function getColumn(Request $request)
    {
        return [
            ['data' => null, 'name' => 'no', 'columnType' => 'row', 'exportable' => true],
            ['data' => 'NOCUST', 'name' => 'NIS', 'searchable' => true, 'orderable' => true, 'exportable' => true],
            ['data' => 'NOVA', 'name' => 'NO VA', 'exportable' => true],
            ['data' => 'NMCUST', 'name' => 'NAMA', 'searchable' => true, 'orderable' => true, 'exportable' => true],
            ['data' => 'NUM2ND', 'name' => 'No Pendaftaran', 'searchable' => true, 'orderable' => true, 'exportable' => true],
            ['data' => 'CODE02', 'name' => 'Unit', 'searchable' => true, 'orderable' => true, 'exportable' => true],
            ['data' => 'DESC02', 'name' => 'Kelas', 'searchable' => true, 'orderable' => true, 'exportable' => true],
            ['data' => 'DESC03', 'name' => 'Jenjang', 'searchable' => true, 'orderable' => true, 'exportable' => true],
            ['data' => 'DESC04', 'name' => 'Angkatan', 'searchable' => true, 'orderable' => true, 'exportable' => true],
            ['data' => 'saldo', 'name' => 'Saldo', 'orderable' => true, 'columnType' => 'currency', 'className' => 'text-end', 'exportable' => true],
            [
                'data' => 'print',
                'name' => '',
                'columnType' => 'button',
                'className' => 'text-center',
                'button' => 'link',
                'buttonLink' => route('admin.keuangan.saldo.saldo-virtual-saku.show', ':id'),
                'buttonText' => 'Detail Transaksi',
                'noCaption' => true,
                'buttonClass' => 'btn btn-sm btn-primary btn-icon btn-print-tagihan',
                'buttonIcon' => 'ri-profile-line',
                'exportable' => false,
            ],
        ];
    }

    public function getData(Request $request)
    {
        $filters = [];
        $filterQuery = null;

        $draw = $request->get('draw');
        $start = $request->get("start");
        $rowperpage = $request->get("length");

        $columnName_arr = $request->get('columns');
        $search_arr = $request->get('search');

        $defaultColumn = 'scctcust.NOCUST';
        $defaultOrder = 'asc';

        if ($request->has('order') && !empty($request->get('order'))) {
            $columnIndex_arr = $request->get('order');
            $columnIndex = $columnIndex_arr[0]['column'] ?? 0;
            $columnSortOrder = $columnIndex_arr[0]['dir'] ?? $defaultOrder;
            $columnName = $columnName_arr[$columnIndex]['data'] ?? $defaultColumn;
        } else {
            $columnName = $defaultColumn;
            $columnSortOrder = $defaultOrder;
        }

        $searchValue = $search_arr['value'] ?? '';

        if (!$columnName || $columnName == 'no') {
            $columnName = $defaultColumn;
            $columnSortOrder = $defaultOrder;
        }

        if ($columnName === 'saldo') {
            $columnName = DB::raw('(COALESCE(trx.kredit, 0) - COALESCE(trx.debet, 0))');
        } elseif (!str_contains($columnName, '.')) {
            $columnName = 'scctcust.' . $columnName;
        }

        $filter = $request->input('filter');
        if ($filter) {
            foreach ($filter as $key => $val) {
                if (strtolower($val) != 'all' && $val !== null && $val !== '') {
                    $colName = match ($key) {
                        'kelas' => 'scctcust.DESC02',
                        'sekolah' => 'scctcust.CODE01',
                        'siswa' => 'scctcust.nmcust',
                        'angkatan' => 'scctcust.DESC04',
                        'saldo_positif' => '_saldo_positif',
                        default => null
                    };
                    if ($key == 'siswa') {
                        $val = is_numeric($val) ? $val : '%' . $val . '%';
                        $colName = is_numeric($val) ? 'scctcust.NOCUST' : $colName;
                        ($colName) && $filters[] = [$colName, 'like', $val];
                    } else if ($key == 'kelas') {
                        $filters[] = ['scctcust.CODE03', '=', $val];
                    } else if ($key === 'sekolah') {
                        $filters[] = ['scctcust.CODE01', '=', trim((string) $val)];
                    } else if ($key == 'saldo_positif') {
                        if ((string) $val === '1') {
                            $filters[] = ['whereRaw', '(COALESCE(trx.kredit, 0) - COALESCE(trx.debet, 0)) > 0', []];
                        }
                    } else {
                        ($colName) && $filters[] = [$colName, '=', $val];
                    }
                }
            }

            $scopedCodes = $this->resolveScopedSchoolCodes();
            if (!empty($scopedCodes)) {
                $filters[] = ['scctcust.CODE01', 'in', $scopedCodes];
            }

            if (!empty($filters)) {
                $filterQuery = fn($query) => $this->applyFilterQuery($query, $filters);
            }
        } else {
            $scopedCodes = $this->resolveScopedSchoolCodes();
            if (!empty($scopedCodes)) {
                $filters[] = ['scctcust.CODE01', 'in', $scopedCodes];
                $filterQuery = fn($query) => $this->applyFilterQuery($query, $filters);
            }
        }

        $whereAny = [
            'scctcust.NMCUST',
            'scctcust.NOCUST',
            'scctcust.NUM2ND',
        ];

        $select = array_unique(array_merge($whereAny, [
            'scctcust.CODE02',
            'scctcust.DESC02',
            'scctcust.DESC03',
            'scctcust.CUSTID',
            'scctcust.DESC04',
        ]));

        $saldoAgg = sccttran::query()
            ->where('FIDBANK', self::FIDBANK_SAKU)
            ->select([
                'CUSTID',
                DB::raw('COALESCE(SUM(KREDIT), 0) AS kredit'),
                DB::raw('COALESCE(SUM(DEBET), 0) AS debet'),
            ])
            ->groupBy('CUSTID');

        $query = scctcust::query()
            ->leftJoinSub($saldoAgg, 'trx', function ($join) {
                $join->on('trx.CUSTID', '=', 'scctcust.CUSTID');
            });

        if ($filterQuery) {
            $query->where(function ($q) use ($filterQuery) {
                $filterQuery($q);
            });
        }

        if (!blank($searchValue)) {
            $query->where(function ($q) use ($whereAny, $searchValue) {
                $sanitizeSearch = str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $searchValue);
                foreach ($whereAny as $column) {
                    $q->orWhere($column, 'like', '%' . $sanitizeSearch . '%');
                }
            });
        }

        $scopedCodesForCount = $this->resolveScopedSchoolCodes();
        $totalRecords = Cache::remember('scctcust_total_count_saku_' . md5(json_encode($scopedCodesForCount)), 600, function () use ($scopedCodesForCount) {
            return scctcust::when(!empty($scopedCodesForCount), function ($query) use ($scopedCodesForCount) {
                $query->whereIn('CODE01', $scopedCodesForCount);
            })->count('CUSTID');
        });

        $totalRecordswithFilter = (clone $query)->count('scctcust.CUSTID');

        $records = (clone $query)
            ->select($select)
            ->addSelect([
                DB::raw('COALESCE(trx.kredit, 0) AS kredit'),
                DB::raw('COALESCE(trx.debet, 0) AS debet'),
                DB::raw('(COALESCE(trx.kredit, 0) - COALESCE(trx.debet, 0)) AS saldo'),
            ])
            ->orderBy($columnName, $columnSortOrder)
            ->skip($start)
            ->take($rowperpage)
            ->get()
            ->map(function ($item) {
                $item->item_id = $item->CUSTID;
                $item->print = true;
                if ($item->NOCUST && $item->NOCUST != '-') {
                    $NOVA = MultiVa::formatNoVaBoth($item->NOCUST);
                } else {
                    $NOVA = MultiVa::formatNoVaBoth($item->NUM2ND);
                }
                $item->NOVA = $NOVA;
                unset($item->CUSTID);
                return $item;
            })->toArray();

        $response = array(
            "draw" => intval($draw),
            "recordsTotal" => $totalRecords,
            "recordsFiltered" => $totalRecordswithFilter,
            "data" => $records,
        );
        return response()->json($response);
    }

    public function getColumnTran()
    {
        return [
            ['data' => null, 'columnType' => 'row', 'name' => 'No'],
            ['data' => 'METODE', 'name' => 'Metode', 'orderable' => true],
            ['data' => 'TRXDATE', 'name' => 'Tanggal Transaksi', 'orderable' => true, 'columnType' => 'timestamp'],
            ['data' => 'DEBET', 'name' => 'Debet', 'orderable' => true, "className" => "dt-right", 'columnType' => 'currency'],
            ['data' => 'KREDIT', 'name' => 'Kredit', 'orderable' => true, "className" => "dt-right", 'columnType' => 'currency'],
        ];
    }

    public function getDataTran(Request $request)
    {
        $custid = $request->CUSTID;
        $filters = [];
        $filterQuery = null;

        $draw = $request->get('draw');
        $start = $request->get("start");
        $rowperpage = $request->get("length");

        $columnName_arr = $request->get('columns');
        $search_arr = $request->get('search');

        $defaultColumn = 'sccttran.TRXDATE';
        $defaultOrder = 'desc';

        if ($request->has('order') && !empty($request->get('order'))) {
            $columnIndex_arr = $request->get('order');
            $columnIndex = $columnIndex_arr[0]['column'] ?? 0;
            $columnSortOrder = $columnIndex_arr[0]['dir'] ?? $defaultOrder;
            $columnName = $columnName_arr[$columnIndex]['data'] ?? $defaultColumn;
        } else {
            $columnName = $defaultColumn;
            $columnSortOrder = $defaultOrder;
        }

        $searchValue = $search_arr['value'] ?? '';

        if (!$columnName || $columnName == 'no') {
            $columnName = $defaultColumn;
            $columnSortOrder = $defaultOrder;
        }

        if (!str_contains($columnName, '.')) {
            $columnName = 'sccttran.' . $columnName;
        }

        $filter = $request->input('filter');
        if ($filter) {
            foreach ($filter as $key => $val) {
                if (strtolower($val) != 'all' && $val !== null && $val !== '') {
                    $colName = match ($key) {
                        'status' => 'scctbill.PAIDST',
                        'jenis' => 'scctbill.cicil',
                        'kelas' => 'mst_siswas.id_kelas',
                        'tahun_akademik' => 'mst_siswas.id_thn_aka',
                        default => null
                    };
                    ($colName) && $filters[] = [$colName, '=', $val];
                }
            };
        }

        ($custid) && $filters[] = ['sccttran.CUSTID', '=', $custid];
        $filters[] = ['sccttran.FIDBANK', '=', self::FIDBANK_SAKU];
        if (!empty($filters)) {
            $filterQuery = function ($query) use ($filters) {
                foreach ($filters as $filter) {
                    if (count($filter) === 3) {
                        $query->where($filter[0], $filter[1], $filter[2]);
                    } elseif (count($filter) === 4) {
                        $query->{$filter[3]}($filter[0], $filter[1], $filter[2]);
                    }
                }
            };
        }

        $whereAny = [
            'scctcust.NMCUST',
            'scctcust.NOCUST',
            'scctcust.NUM2ND',
            'sccttran.METODE',

        ];

        $select = array_merge($whereAny, [
            'sccttran.METODE',
            'sccttran.TRXDATE',
            'sccttran.NOREFF',
            'sccttran.FIDBANK',
            'sccttran.KDCHANNEL',
            'sccttran.DEBET',
            'sccttran.KREDIT',
            'sccttran.REFFBANK',
            'sccttran.TRANSNO',
        ]);

        $query = sccttran::whereAny($whereAny, 'like', '%' . $searchValue . '%')
            ->leftJoin('scctcust', 'scctcust.CUSTID', 'sccttran.CUSTID')
            ->where(function ($query) use ($filterQuery) {
                if ($filterQuery) {
                    $filterQuery($query);
                }
            });

        $totalRecords = sccttran::where('FIDBANK', self::FIDBANK_SAKU)
            ->select('count(sccttran.*) as allcount')
            ->count();
        $totalRecordswithFilter = $query->count();

        $records = $query->orderBy($columnName, $columnSortOrder)
            ->select($select)
            ->skip($start)
            ->take($rowperpage)
            ->get()
            ->map(function ($item, $index) {
                unset($item->id);
                return $item;
            })->toArray();

        if ($custid) {
            $totalKredit = Cache::remember("total_kredit_saku_custid_" . $custid, 600,
                function () use ($custid) {
                    return sccttran::where('CUSTID', $custid)
                        ->where('FIDBANK', self::FIDBANK_SAKU)
                        ->sum('KREDIT');
                }
            );

            $totalDebet = Cache::remember("total_debet_saku_custid_" . $custid, 600,
                function () use ($custid) {
                    return sccttran::where('CUSTID', $custid)
                        ->where('FIDBANK', self::FIDBANK_SAKU)
                        ->sum('DEBET');
                }
            );
        }

        $response = array(
            "draw" => intval($draw),
            "recordsTotal" => $totalRecords,
            "recordsFiltered" => $totalRecordswithFilter,
            "data" => $records,
        );


        if ($custid) {
            $response['totals'] = [
                'kredit' => ['location' => 4, 'value' => $totalKredit, 'columnType' => 'currency'],
                'debet' => ['location' => 3, 'value' => $totalDebet, 'columnType' => 'currency'],
            ];
        }
        return response()->json($response);
    }

    public function getSaldo(Request $request)
    {
        if ($request->siswa) {
            $saldo = sccttran::selectRaw(
                'COALESCE(SUM(KREDIT), 0) - COALESCE(SUM(DEBET), 0) as saldo'
            )->where('CUSTID', $request->siswa)
                ->where('FIDBANK', self::FIDBANK_SAKU)
                ->groupBy('CUSTID')
                ->first();

            return $saldo->saldo ?? 0;
        } else {
            return 0;
        }
    }
}
