@extends('layouts.app')
@section('title', 'Invoice: ' . $invoice->submission_number . ' - ADASI Portal')
@section('page-title', 'Invoice Details')
@section('content')
@include('local-invoices.detail', ['portal'=>'accounting'])
@endsection
