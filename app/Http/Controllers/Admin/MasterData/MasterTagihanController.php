<?php

namespace App\Http\Controllers\Admin\MasterData;

use App\Http\Controllers\Controller;
use App\Models\mst_tagihan;
use App\Models\ValidationMessage;
use App\Support\MultiVa;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class MasterTagihanController extends Controller
{
    public string $title = 'Master Data';
    public string $mainTitle = 'Master Tagihan';
    public string $dataTitle = 'Master Tagihan';

    public function index()
    {
        $data['title'] = $this->title;
        $data['mainTitle'] = $this->mainTitle;
        $data['dataTitle'] = $this->dataTitle;
        $data['columnsUrl'] = route('admin.master-data.master-tagihan.get-column');
        $data['datasUrl'] = route('admin.master-data.master-tagihan.get-data');

        return view('admin.master_data.master_tagihan.index', $data);
    }

    public function getColumn()
    {
        return [
            ['data' => null, 'name' => 'no', 'className' => 'text-center', 'columnType' => 'no'],
            ['data' => 'tagihan', 'name' => 'Nama Tagihan', 'searchable' => true, 'orderable' => true],
            ['data' => 'VA', 'name' => 'VA', 'searchable' => true, 'orderable' => true],
            ['data' => 'isINSTALLMENT_label', 'name' => 'Status Dapat Di Cicil', 'searchable' => false, 'orderable' => true],
            [
                'data' => 'edit',
                'name' => '',
                'dataVal' => false,
                'columnType' => 'button',
                'className' => 'text-center',
                'button' => 'modal',
                'buttonText' => 'Edit',
                'buttonClass' => 'btn btn-sm btn-info btn-edit',
                'buttonLink' => '#modal-edit',
                'buttonIcon' => 'ri-edit-line me-2',
            ],
        ];
    }

    public function getData(Request $request)
    {
        $draw = $request->get('draw');
        $start = max(0, (int) $request->get('start', 0));
        $rowperpage = (int) $request->get('length', 10);

        $columnIndex_arr = $request->get('order', []);
        $columnName_arr = $request->get('columns', []);
        $order_arr = $request->get('order', []);
        $search_arr = $request->get('search', []);
        $searchValue = trim((string) ($search_arr['value'] ?? ''));

        $columnName = 'urut';
        $columnSortOrder = 'asc';

        if (!empty($order_arr)) {
            $columnIndex = $columnIndex_arr[0]['column'] ?? null;
            if ($columnIndex !== null && !empty($columnName_arr[$columnIndex]['data']) && $columnName_arr[$columnIndex]['data'] !== 'no') {
                $sortColumn = $columnName_arr[$columnIndex]['data'];
                $columnName = match ($sortColumn) {
                    'isINSTALLMENT_label' => 'isINSTALLMENT',
                    'VA', 'va_label' => 'VA',
                    default => $sortColumn,
                };
                $columnSortOrder = $order_arr[0]['dir'] ?? 'asc';
            }
        }

        $allowedSort = ['urut', 'tagihan', 'VA', 'isINSTALLMENT'];
        if (!in_array($columnName, $allowedSort, true)) {
            $columnName = 'urut';
        }

        try {
            $filtered = mst_tagihan::query()
                ->when($searchValue !== '', function ($q) use ($searchValue) {
                    $q->where('tagihan', 'like', '%' . $searchValue . '%');
                });

            $totalRecords = mst_tagihan::count();
            $totalRecordswithFilter = (clone $filtered)->count();

            if ($totalRecords === 0) {
                Log::warning('mst_tagihan kosong pada koneksi DATA_MYSQL', [
                    'database' => DB::connection('DATA_MYSQL')->getDatabaseName(),
                ]);
            }

            $query = $filtered
                ->orderBy($columnName, $columnSortOrder)
                ->skip($start);

            if ($rowperpage > 0) {
                $query->take($rowperpage);
            }

            $records = $query
                ->get()
                ->map(function ($item) {
                    $vaRaw = trim((string) ($item->VA ?? $item->va ?? ''));

                    return [
                        'urut' => $item->urut,
                        'item_id' => $item->urut,
                        'tagihan' => $item->tagihan,
                        'VA' => $vaRaw,
                        'isINSTALLMENT' => (int) $item->isINSTALLMENT,
                        'isINSTALLMENT_label' => (int) $item->isINSTALLMENT === 1
                            ? 'BISA DI CICIL'
                            : 'TIDAK BISA DI CICIL',
                        'edit' => true,
                    ];
                })
                ->values()
                ->all();

            return response()->json([
                'draw' => intval($draw),
                'recordsTotal' => $totalRecords,
                'recordsFiltered' => $totalRecordswithFilter,
                'data' => $records,
            ]);
        } catch (Exception $e) {
            Log::error('Gagal memuat master tagihan', [
                'database' => DB::connection('DATA_MYSQL')->getDatabaseName(),
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'draw' => intval($draw),
                'recordsTotal' => 0,
                'recordsFiltered' => 0,
                'data' => [],
                'error' => $e->getMessage(),
                'message' => 'Gagal memuat master tagihan. Database: ' . DB::connection('DATA_MYSQL')->getDatabaseName(),
            ], 500);
        }
    }

    public function store(Request $request)
    {
        $validator = Validator::make(
            $request->all(),
            [
                'tagihan' => ['required', 'string', 'max:100'],
                'VA' => ['required', 'in:93,94'],
            ],
            ValidationMessage::messages(),
            ValidationMessage::attributes()
        );

        if ($validator->fails()) {
            return response()->json(['message' => $validator->errors()->first(), 'errors' => $validator->errors()], 422);
        }

        $va = MultiVa::normalize($request->VA);
        if ($va === null) {
            return response()->json(['message' => 'Tipe VA tidak valid'], 422);
        }

        $exists = mst_tagihan::where('tagihan', $request->tagihan)->first();
        if ($exists) {
            return response()->json(['message' => 'Nama tagihan sudah ada'], 422);
        }

        try {
            DB::connection('DATA_MYSQL')->beginTransaction();

            $nextUrut = (int) mst_tagihan::max('urut') + 1;

            mst_tagihan::create([
                'urut' => $nextUrut,
                'tagihan' => strtoupper(trim($request->tagihan)),
                'kode' => null,
                'VA' => $va,
                'isINSTALLMENT' => MultiVa::normalize($va) !== null
                    ? MultiVa::isInstallment($va)
                    : 0,
            ]);

            DB::connection('DATA_MYSQL')->commit();
            mst_tagihan::flushInstallmentCache();

            return response()->json(['message' => 'Data ' . $this->mainTitle . ' telah disimpan']);
        } catch (Exception $e) {
            DB::connection('DATA_MYSQL')->rollBack();
            return response()->json(['message' => 'Data ' . $this->mainTitle . ' gagal disimpan', 'error' => $e->getMessage()], 422);
        }
    }

    public function update(Request $request, $id)
    {
        $validator = Validator::make(
            $request->all(),
            [
                'tagihan' => ['required', 'string', 'max:100'],
                'VA' => ['required', 'string', 'max:20'],
            ],
            ValidationMessage::messages(),
            ValidationMessage::attributes()
        );

        if ($validator->fails()) {
            return response()->json(['message' => $validator->errors()->first(), 'errors' => $validator->errors()], 422);
        }

        $vaRaw = trim((string) $request->VA);
        $va = MultiVa::normalize($vaRaw) ?? $vaRaw;
        if ($va === '') {
            return response()->json(['message' => 'Tipe VA tidak valid'], 422);
        }

        $row = mst_tagihan::where('urut', $id)->first();
        if (!$row) {
            return response()->json(['message' => 'Data tagihan tidak ditemukan'], 422);
        }

        $duplicate = mst_tagihan::where('tagihan', strtoupper(trim($request->tagihan)))
            ->where('urut', '!=', $row->urut)
            ->first();
        if ($duplicate) {
            return response()->json(['message' => 'Nama tagihan sudah ada'], 422);
        }

        try {
            DB::connection('DATA_MYSQL')->beginTransaction();

            $row->tagihan = strtoupper(trim($request->tagihan));
            $row->VA = $va;
            if (MultiVa::normalize($va) !== null) {
                $row->isINSTALLMENT = MultiVa::isInstallment($va);
            }
            $row->save();

            DB::connection('DATA_MYSQL')->commit();
            mst_tagihan::flushInstallmentCache();

            return response()->json(['message' => 'Data ' . $this->mainTitle . ' telah diubah']);
        } catch (Exception $e) {
            DB::connection('DATA_MYSQL')->rollBack();
            return response()->json(['message' => 'Data ' . $this->mainTitle . ' gagal diubah', 'error' => $e->getMessage()], 422);
        }
    }
}
