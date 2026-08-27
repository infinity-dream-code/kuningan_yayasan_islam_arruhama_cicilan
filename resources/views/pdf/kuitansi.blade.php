@extends('layouts.export.kop_file')
@section('title', 'Kuitansi Pembayaran')
@section('content')
    @php
        $nis = $siswa->NOCUST ?? null;
        $hasNis = $nis && $nis !== '-';
        $nova = $hasNis
            ? \App\Support\MultiVa::formatNoVaFromBills($nis, $tagihans ?? [])
            : \App\Support\MultiVa::formatNoVaFromBills($siswa->NUM2ND ?? '', $tagihans ?? []);
        $ortu = $siswa->GENUS ?? '-';
        $total = collect($tagihans ?? [])->sum('BILLAM');
    @endphp
    <table width="100%">
        <tr>
            <td colspan="4" align="center"><h4>KUITANSI PEMBAYARAN</h4></td>
        </tr>
    </table>
    <table width="100%" class="main-table">
        <tr>
            <td class="border-right-0">{{ $hasNis ? 'NIS' : 'No. Pendaftaran' }}</td>
            <td class="border-left-0">: <strong>{{ $hasNis ? $nis : ($siswa->NUM2ND ?? '-') }}</strong></td>
            <td class="border-right-0">Unit</td>
            <td class="border-left-0">: <strong>{{ $siswa->CODE02 ?? '' }}</strong></td>
        </tr>
        <tr>
            <td class="border-right-0">No. VA</td>
            <td class="border-left-0">: <strong>{{ $nova ?? '-' }}</strong></td>
            <td class="border-right-0">Kelas</td>
            <td class="border-left-0">: <strong>{{ trim(($siswa->DESC02 ?? '') . ' ' . ($siswa->DESC03 ?? '')) }}</strong></td>
        </tr>
        <tr>
            <td class="border-right-0">Nama Siswa</td>
            <td class="border-left-0">: <strong>{{ $siswa->NMCUST ?? '' }}</strong></td>
            <td class="border-right-0">Orang Tua</td>
            <td class="border-left-0">: <strong>{{ $ortu }}</strong></td>
        </tr>
    </table>
    <table width="100%" class="table-border main-table" style="margin-top: 12px;">
        <thead style="background-color: #ededed;">
        <tr>
            <th>#</th>
            <th>Nama Tagihan</th>
            <th>Periode</th>
            <th align="right">Nominal</th>
        </tr>
        </thead>
        <tbody>
        @foreach($tagihans as $tagihan)
            <tr>
                <td>{{ $loop->iteration }}</td>
                <td>{{ $tagihan->BILLNM }}</td>
                <td>{{ $tagihan->BTA }}</td>
                <td align="right">@rupiah($tagihan->BILLAM)</td>
            </tr>
        @endforeach
        </tbody>
        <tfoot>
        <tr>
            <td colspan="3" align="right"><strong>Total</strong></td>
            <td align="right"><strong>@rupiah($total)</strong></td>
        </tr>
        </tfoot>
    </table>
@endsection
