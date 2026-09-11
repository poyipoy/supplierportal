@extends('layouts.app')
@section('title', 'Revise Invoice')
@section('page-title', 'Revise Invoice')
@section('content')
<x-ui.page-header :title="'Revise '.$invoice->submission_number" />
<x-ui.card title="Revision Requested"><p>{{ $invoice->statusHistories->where('event', 'revision_requested')->last()?->notes }}</p></x-ui.card>
@include('local-invoices.form')
@endsection
