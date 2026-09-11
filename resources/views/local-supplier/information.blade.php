@extends('layouts.app')
@section('title', 'ADASI Information')
@section('page-title', 'ADASI Information')
@section('content')
<x-ui.page-header title="ADASI Information" />
@forelse($announcements as $announcement)
<x-ui.card :title="$announcement->title"><p>{{ $announcement->content }}</p></x-ui.card>
@empty<p>No announcements available.</p>@endforelse
{{ $announcements->links() }}
@endsection
