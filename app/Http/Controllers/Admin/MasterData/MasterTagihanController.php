<?php

namespace App\Http\Controllers\Admin\MasterData;

use App\Http\Controllers\Controller;
use App\Models\mst_tagihan;
use App\Models\ValidationMessage;
use App\Support\MultiVa;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
            ['data' => 'va_label', 'name' => 'VA', 'searchable' => false, 'orderable' => true],
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
        $start = $request->get('start');
        $rowperpage = $request->get('length');

        $columnIndex_arr = $request->get('order', []);
        $columnName_arr = $request->get('columns', []);
        $order_arr = $request->get('order', []);
        $search_arr = $request->get('search', []);
        $searchValue = $search_arr['value'] ?? '';

        $columnName = 'urut';
        $columnSortOrder = 'asc';

        if (!empty($order_arr)) {
            $columnIndex = $columnIndex_arr[0]['column'] ?? null;
            if ($columnIndex !== null && !empty($columnName_arr[$columnIndex]['data']) && $columnName_arr[$columnIndex]['data'] !== 'no') {
                $sortColumn = $columnName_arr[$columnIndex]['data'];
                $columnName = match ($sortColumn) {
                    'isINSTALLMENT_label' => 'isINSTALLMENT',
                    'va_label' => 'VA',
                    default => $sortColumn,
                };
                $columnSortOrder = $order_arr[0]['dir'] ?? 'asc';
            }
        }

        $allowedSort = ['urut', 'tagihan', 'VA', 'isINSTALLMENT'];
        if (!in_array($columnName, $allowedSort, true)) {
            $columnName = 'urut';
        }

        $filtered = mst_tagihan::query()
            ->when($searchValue !== '', function ($q) use ($searchValue) {
                $q->where('tagihan', 'like', '%' . $searchValue . '%');
            });

        $totalRecords = mst_tagihan::count();
        $totalRecordswithFilter = (clone $filtered)->count();

        $records = $filtered
            ->orderBy($columnName, $columnSortOrder)
            ->skip($start)
            ->take($rowperpage)
            ->get()
            ->map(function ($item) {
                $va = MultiVa::normalize($item->VA);
                $item->item_id = $item->urut;
                $item->VA = $va ?? (string) ($item->VA ?? '');
                $item->va_label = $va ? MultiVa::optionLabel($va) : ((string) ($item->VA ?? '-') ?: '-');
                $item->isINSTALLMENT_label = (int) $item->isINSTALLMENT === 1
                    ? 'BISA DI CICIL'
                    : 'TIDAK BISA DI CICIL';
                $item->edit = true;
                return $item;
            })
            ->toArray();

        return response()->json([
            'draw' => intval($draw),
            'recordsTotal' => $totalRecords,
            'recordsFiltered' => $totalRecordswithFilter,
            'data' => $records,
        ]);
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
                'isINSTALLMENT' => MultiVa::isInstallment($va),
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
            $row->isINSTALLMENT = MultiVa::isInstallment($va);
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
