@extends('layouts.classic')

@php($title = __(':community %activity%', ['community' => $community->name]))

@section('title', $title)

@section('content')
    @include('timeline._community-box', [
        'community' => $community,
        'posts' => $posts,
        'canPost' => $canPost,
        'title' => $title,
        'pager' => true,
    ])
@endsection
