<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\MetodeBayar;
use App\Models\mst_kelas;
use App\Models\mst_tagihan;
use App\Models\mst_thn_aka;
use App\Models\scctbill;
use App\Models\scctcust;
use App\Support\MultiVa;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class CekPelunasanController extends Controller
{
    private string $title;
    private string $datasUrl;
    private string $columnsUrl;
    private string $mainTitle;

    public function __construct()
    {
        $this->title = 'Rekap Data';
        $this->mainTitle = 'Cek Pelunasan';
        $this->datasUrl = route('admin.cek-pelunasan.get-data');
        $this->detailDatasUrl = '';
        $this->columnsUrl = route('admin.cek-pelunasan.get-column');
    }

    public function index()
    {
        $data['title'] = $this->title;
        $data['mainTitle'] = $this->mainTitle;
        $data['columnsUrl'] = $this->columnsUrl;
        $data['datasUrl'] = $this->datasUrl;
        $data['post'] = mst_tagihan::select(['tagihan'])->get();
        $data['thn_aka'] = mst_thn_aka::select(['thn_aka'])->where('thn_aka', '!=', null)->get();
        $data["metode_bayar"] = MetodeBayar::attributes();
        $data['kelas'] = mst_kelas::get();

        return view('admin.cek_pelunasan.index', $data);
    }

    public function getColumn()
    {
        return [
            ['data' => 'AA', 'name' => 'no', 'columnType' => 'row'],
            ['data' => 'BTA', 'name' => 'Tahun Pelajaran', 'searchable' => true, 'orderable' => true],
            ['data' => 'NOCUST', 'name' => 'NIS', 'searchable' => true, 'orderable' => true],
            ['data' => 'NUM2ND', 'name' => 'No Pendaftaran', 'searchable' => true, 'orderable' => true],
            ['data' => 'NMCUST', 'name' => 'NAMA', 'searchable' => true, 'orderable' => true],
            ['data' => 'BILLNM', 'name' => 'Nama Tagihan', 'searchable' => true, 'orderable' => true],
            ['data' => 'BILLAM', 'name' => 'Tagihan', 'searchable' => true, 'orderable' => true, 'columnType' => 'currency', 'classname' => 'text-end'],
            ['data' => 'PAIDST', 'name' => 'Lunas', 'searchable' => true, 'orderable' => true, 'columnType' => 'boolean', 'trueVal' => 'LUNAS', 'falseVal' => 'BELUM BAYAR'],

        ];
    }

    public function cetakKartuSiswa(Request $request)
    {
        if (!$request->filled('custid')) {
            return response()->json(['message' => 'Siswa tidak ditemukan'], 422);
        }

        $custid = $request->input('custid');
        $siswa = scctcust::where('CUSTID', $custid)->first();
        if (!$siswa) {
            return response()->json(['message' => 'Siswa tidak ditemukan'], 422);
        }

        $request->merge([
            'filter' => array_merge($request->input('filter', []), [
                'custid' => $custid,
            ]),
            'draw' => 2,
            'start' => 0,
            'length' => 'poll',
        ]);

        try {
            $tagihans = json_decode(json_encode($this->getData($request)), true);
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
            return response()->json([
                'message' => 'Gagal membuat kartu siswa',
                'error' => $e->getMessage(),
            ], 422);
        }
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

        $columnName = 'scctbill.PAIDDT';
        $columnSortOrder = 'asc';

        if (!empty($order_arr)) {
            $columnIndex = $columnIndex_arr[0]['column'] ?? null;
            if ($columnIndex !== null && !empty($columnName_arr[$columnIndex]['data']) && $columnName_arr[$columnIndex]['data'] !== 'no') {
                $columnName = $columnName_arr[$columnIndex]['data'];
                $columnSortOrder = $order_arr[0]['dir'] ?? 'desc';
            }
        }

        $filters = [];
        $filterQuery = null;

        $filter = $request->input('filter');
        if ($filter) {
            foreach ($filter as $key => $val) {
                if (is_array($val) || strtolower($val) != 'all' && $val !== null && $val !== '') {
                    $colName = match ($key) {
                        "tahun_akademik" => "scctbill.BTA",
                        'post' => 'scctbill.BILLNM',
                        "unit" => "scctcust.CODE01",
                        'kelas' => 'scctcust.DESC02',
                        'siswa' => 'scctcust.nmcust',
                        'custid' => 'scctbill.CUSTID',
                        'status_bayar' => 'scctbill.PAIDST',
                        'metode_bayar' => 'scctbill.FIDBANK',
                        default => null
                    };
                    switch ($key) {
                        case "metode_bayar":
                            if ($val === "NULL") {
                                $colName && ($filters[] = [$colName, "=", null]);
                            } else if ($val === "empty") {
                                $colName && ($filters[] = [$colName, "=", '']);
                            } else {
                                $colName && ($filters[] = [$colName, "like", "$val"]);
                            }
                            break;
                        case "kelas":
                            $val = explode("~", $val);
                            if (count($val) == 3) {
                                $filters[] = ["scctcust.CODE02", "=", $val[0]];
                                $filters[] = ["scctcust.DESC02", "=", $val[1]];
                                $filters[] = ["scctcust.DESC03", "=", $val[2]];
                            }
                            break;
                        case "post":
                            $array = array_filter($val, function ($value) {
                                return $value !== "all";
                            });
                            if (count($array) > 0) {
                                $colName &&
                                ($filters[] = [$colName, "in", $array]);
                            }
                            break;
                        case 'siswa':
                            $val = is_numeric($val) ? $val : "%" . $val . "%";
                            $colName = is_numeric($val)
                                ? "scctcust.nocust"
                                : $colName;
                            $colName && ($filters[] = [$colName, "like", $val]);
                            break;
                        default:
                            $colName && ($filters[] = [$colName, "=", $val]);
                            break;
                    }
                }
            };

            if (!empty($filters)) {
                $filterQuery = function ($query) use ($filters) {
                    foreach ($filters as $filter) {
                        switch (count($filter)) {
                            case 3:
                                $filter[1] === 'in'
                                    ? $query->whereIn($filter[0], $filter[2])
                                    : $query->where($filter[0], $filter[1], $filter[2]);
                                break;

                            case 4:
                                $filter[3] === 'whereBetween'
                                    ? $query->whereBetween($filter[0], [$filter[1], $filter[2]])
                                    : $query->{$filter[3]}($filter[0], $filter[1], $filter[2]);
                                break;
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
            'scctbill.BILLAM',
            'scctbill.PAIDST',
            'scctbill.PAIDDT',
            'scctbill.BTA',
            'scctbill.FIDBANK',
            'scctbill.FUrutan',
            'scctcust.CODE02',
            'scctcust.DESC02',
            'scctcust.NUM2ND',
            'scctbill.CUSTID',

        ]));

        $query = scctbill::leftJoin('scctcust', 'scctcust.CUSTID', 'scctbill.CUSTID')
            ->where('scctbill.FSTSBolehBayar', 1)
            ->where('scctcust.STCUST', 1)
            ->whereAny($whereAny, 'like', '%' . $searchValue . '%')
            ->where(function ($query) use ($filterQuery) {
                if ($filterQuery) {
                    $filterQuery($query);
                }
            })->orderByRaw("
                CASE
                    WHEN scctbill.BILLNM LIKE '%JULI%' THEN 1
                    WHEN scctbill.BILLNM LIKE '%AGUSTUS%' THEN 2
                    WHEN scctbill.BILLNM LIKE '%SEPTEMBER%' THEN 3
                    WHEN scctbill.BILLNM LIKE '%OKTOBER%' THEN 4
                    WHEN scctbill.BILLNM LIKE '%NOVEMBER%' THEN 5
                    WHEN scctbill.BILLNM LIKE '%DESEMBER%' THEN 6
                    WHEN scctbill.BILLNM LIKE '%JANUARI%' THEN 7
                    WHEN scctbill.BILLNM LIKE '%FEBRUARI%' THEN 8
                    WHEN scctbill.BILLNM LIKE '%MARET%' THEN 9
                    WHEN scctbill.BILLNM LIKE '%APRIL%' THEN 10
                    WHEN scctbill.BILLNM LIKE '%MEI%' THEN 11
                    WHEN scctbill.BILLNM LIKE '%JUNI%' THEN 12
                    ELSE 999
                END
            ");

        $totalRecords = Cache::remember('total_penerimaan_count', 600, function () {
            return scctbill::select('count(*) as allcount')
                ->where('scctbill.FSTSBolehBayar', 1)
                ->count();
        });

        $totalRecordswithFilter = (clone $query)->count();

        $rowperpage = $rowperpage == "poll" ? $totalRecords : $rowperpage;
        $records = (clone $query)->orderBy($columnName, $columnSortOrder)
            ->select($select)
            ->whereAny($whereAny, 'like', '%' . $searchValue . '%')
            ->skip($start)
            ->take($rowperpage)
            ->get();

        if ($request->get("length") != "poll") {
            $records = $records->map(function ($item, $index) {
                $item->item_id = $item['AA'];
                $item->CUSTID = $item['CUSTID'];
                return $item;
            });
        }

        $data = $records->map(fn ($item) => $item instanceof \Illuminate\Database\Eloquent\Model
            ? $item->toArray()
            : (array) $item
        )->values()->all();

        $response = [
            "draw" => intval($draw),
            "recordsTotal" => $totalRecords ?? 0,
            "recordsFiltered" => $totalRecordswithFilter ?? 0,
            "data" => $data,
        ];
        return response()->json($response);
    }
}
