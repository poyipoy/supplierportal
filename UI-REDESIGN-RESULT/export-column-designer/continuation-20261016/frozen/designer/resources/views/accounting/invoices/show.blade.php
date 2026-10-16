@extends('layouts.app')
@section('title', __('local_invoice.form.detail_title', ['number' => $invoice->submission_number]))
@section('page-title', __('local_invoice.labels.detail'))
@section('content')
@include('local-invoices.detail', ['portal'=>'accounting'])
@endsection
