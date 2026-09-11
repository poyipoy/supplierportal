@extends('layouts.app')
@section('title', $invoice->submission_number)
@section('page-title', 'Invoice Details')
@section('content')
@include('local-invoices.detail', ['portal'=>'accounting'])
@endsection
