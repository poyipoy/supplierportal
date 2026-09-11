@extends('layouts.app')
@section('title', 'Submit Invoice')
@section('page-title', 'Submit Invoice')
@section('content')
<x-ui.page-header title="Submit Invoice" />
@include('local-invoices.form')
@endsection
