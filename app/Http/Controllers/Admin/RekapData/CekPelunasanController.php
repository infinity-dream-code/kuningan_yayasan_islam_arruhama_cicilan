<?php

namespace App\Http\Controllers\Admin\RekapData;

use App\Http\Controllers\Controller;
use App\Models\mst_kelas;
use App\Models\mst_tagihan;
use App\Models\mst_thn_aka;
use App\Models\scctbill;
use App\Models\scctcust;
use App\Support\MultiVa;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

class CekPelunasanController extends Controller
{
    private string $title;
    private string $datasUrl;
    private string $columnsUrl;
    private string $detailDatasUrl;
    private string $mainTitle;

    public function __construct()
    {
        $this->title = 'Rekap Data';
        $this->mainTitle = 'Cek Pelunasan';
        $this->datasUrl = route('admin.rekap-data.cek-pelunasan.get-data');
        $this->detailDatasUrl = '';
        $this->columnsUrl = route('admin.rekap-data.cek-pelunasan.get-column');
    }

    public function index()
    {
        $data['title'] = $this->title;
        $data['mainTitle'] = $this->mainTitle;
        $data['columnsUrl'] = $this->columnsUrl;
        $data['datasUrl'] = $this->datasUrl;
        $data['post'] = mst_tagihan::select(['tagihan'])->get();
        $data['thn_aka'] = mst_thn_aka::select(['thn_aka'])
            ->whereNotNull('thn_aka')
            ->distinct()
            ->orderBy('thn_aka', 'desc')
            ->get();
        $data['kelas'] = mst_kelas::get();
        $data['tagihan'] = mst_tagihan::orderBy('urut', 'asc')->get();

        return view('admin.rekap_data.cek_pelunasan.index_new', $data);
    }

    public function getColumn()
    {
        return [
            ['data' => 'AA', 'name' => 'no', 'columnType' => 'row', 'exportable' => true],
            ['data' => 'FUrutan', 'name' => 'Urutan', 'searchable' => true, 'orderable' => true, 'exportable' => true],
            ['data' => 'BTA', 'name' => 'Tahun Pelajaran', 'searchable' => true, 'orderable' => true, 'exportable' => true],
            ['data' => 'NOCUST', 'name' => 'NIS', 'searchable' => true, 'orderable' => true, 'exportable' => true],
            ['data' => 'NUM2ND', 'name' => 'No Pendaftaran', 'searchable' => true, 'orderable' => true, 'exportable' => true],
            ['data' => 'NMCUST', 'name' => 'NAMA', 'searchable' => true, 'orderable' => true, 'exportable' => true],
            ['data' => 'BILLNM', 'name' => 'Nama Tagihan', 'searchable' => true, 'orderable' => true, 'exportable' => true],
            ['data' => 'BILLAM', 'name' => 'Tagihan', 'searchable' => true, 'orderable' => true, 'columnType' => 'currency', 'classname' => 'text-end', 'exportable' => true],
            ['data' => 'PAIDST', 'name' => 'Lunas', 'searchable' => true, 'orderable' => true, 'columnType' => 'boolean', 'trueVal' => 'LUNAS', 'falseVal' => 'BELUM BAYAR', 'exportable' => true],

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

        $columnName = 'scctcust.NOCUST';
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
                if (strtolower($val) != 'all' && $val !== null && $val !== '') {
                    $colName = match ($key) {
                        'tahun_pelajaran' => 'scctbill.BTA',
                        'nama_tagihan' => 'scctbill.BILLNM',
                        'kelas' => null,
                        'thn_aka' => 'scctcust.DESC04',
                        'nama' => 'scctcust.NMCUST',
                        'nis' => 'scctcust.NOCUST',
                        'custid' => 'scctbill.CUSTID',
                        default => null
                    };
                    if ($key == 'tanggal-pembuatan') {
                        if (preg_match('/^\d{2}-\d{2}-\d{4} [-\/~] \d{2}-\d{2}-\d{4}$/', $val)) {
                            $val = preg_replace('/[-\/~]/', '-', $val);

                            list($startDate, $endDate) = explode(' - ', $val);
                            $startDate = Carbon::createFromFormat('d-m-Y', $startDate)->startOfDay();
                            $endDate = Carbon::createFromFormat('d-m-Y', $endDate)->endOfDay();
                            if ($startDate && $endDate) {
                                ($colName) && $filters[] = [$colName, $startDate, $endDate, 'whereBetween'];
                            }
                        }
                    } elseif ($key == 'kelas') {
                        $raw = trim((string) $val);
                        if ($raw === '') {
                            continue;
                        }
                        if (ctype_digit($raw)) {
                            $filters[] = [
                                'whereRaw',
                                'TRIM(CAST(scctcust.CODE03 AS CHAR)) = ?',
                                [$raw],
                            ];
                        } else {
                            $delim = str_contains($raw, '~') ? '~' : ',';
                            $parts = array_map('trim', explode($delim, $raw));
                            if (count($parts) === 3) {
                                $filters[] = ['scctcust.CODE02', '=', $parts[0]];
                                $filters[] = ['scctcust.DESC02', '=', $parts[1]];
                                $filters[] = ['scctcust.DESC03', '=', $parts[2]];
                            }
                        }
                    } elseif ($key == 'nama') {
                        ($colName) && $filters[] = [$colName, 'like', '%' . $val . '%'];
                    } else {
                        ($colName) && $filters[] = [$colName, '=', $val];
                    }
                }
            };

            if (!empty($filters)) {
                $filterQuery = function ($query) use ($filters) {
                    foreach ($filters as $filter) {
                        if (($filter[0] ?? null) === 'whereRaw' && isset($filter[1])) {
                            $query->whereRaw($filter[1], $filter[2] ?? []);
                            continue;
                        }
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
            'scctbill.BILLAC',
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
            ->when(!blank($searchValue), function ($query) use ($whereAny, $searchValue) {
                $query->where(function ($q) use ($whereAny, $searchValue) {
                    $sanitizeSearch = str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $searchValue);
                    foreach ($whereAny as $column) {
                        $q->orWhere($column, 'like', '%' .$sanitizeSearch . '%');
                    }
                });
            })
            ->where(function ($query) use ($filterQuery) {
                if ($filterQuery) {
                    $filterQuery($query);
                }
            });

        $totalRecords = Cache::remember('total_penerimaan_count', 600, function () {
            return scctbill::select('count(*) as allcount')
                ->where('scctbill.FSTSBolehBayar', 1)
                ->count();
        });

        $totalRecordswithFilter = (clone $query)->count();

        $rowperpage = $rowperpage == "poll" ? $totalRecords : $rowperpage;
        $recordsQuery = (clone $query)
            ->orderByRaw("CASE WHEN scctcust.NOCUST IS NULL OR TRIM(CAST(scctcust.NOCUST AS CHAR)) = '' OR scctcust.NOCUST = '-' THEN 1 ELSE 0 END ASC")
            ->orderBy('scctcust.NOCUST', 'asc')
            ->orderBy('scctbill.FUrutan', 'asc');

        $records = $recordsQuery
            ->select($select)
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
