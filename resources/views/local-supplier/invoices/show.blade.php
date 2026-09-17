@extends('layouts.app')
@section('title', $invoice->submission_number)
@section('page-title', 'Detail Invoice')
@section('content')
@include('local-invoices.detail', ['portal'=>'local-supplier'])
@endsection
