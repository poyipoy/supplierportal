@extends('layouts.app')
@section('title', 'Pengajuan Invoice: ' . $invoice->submission_number . ' - ADASI Portal')
@section('page-title', 'Detail Invoice')
@section('content')
@include('local-invoices.detail', ['portal'=>'local-supplier'])
@endsection
