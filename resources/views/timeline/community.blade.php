@extends('layouts.classic')

@php($title = __(':community %activity%', ['community' => $group->name]))

@section('title', $title)

@section('content')
    @include('timeline._community-box', [
        'group' => $group,
        'posts' => $posts,
        'canPost' => $canPost,
        'title' => $title,
        'pager' => true,
    ])
@endsection
