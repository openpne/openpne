{{-- No script: the page holds the token in its URL and form (docs/internals/security.md, "Administrator password links"). --}}
@php
    $title = $username === null ? __('This link cannot be used') : __('Set your password');
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="fi">
    <head>
        <meta charset="utf-8" />
        <meta name="viewport" content="width=device-width, initial-scale=1" />
        <meta name="robots" content="noindex" />
        @if ($favicon = filament()->getFavicon())
            <link rel="icon" href="{{ $favicon }}" />
        @endif
        <title>{{ $title }} - {{ filament()->getBrandName() }}</title>
        @filamentStyles
        {{ filament()->getTheme()->getHtml() }}
        {{ filament()->getFontHtml() }}
        <style>
            :root {
                --font-family: '{!! filament()->getFontFamily() !!}';
            }
        </style>
    </head>
    <body class="fi-body fi-panel-{{ filament()->getId() }}">
        <div class="fi-simple-layout">
            <div class="fi-simple-main-ctn">
                <main class="fi-simple-main fi-width-lg">
                    <div class="fi-simple-page">
                        <div class="fi-simple-page-content">
                            <x-filament-panels::header.simple :heading="$title" />

                            @if ($username === null)
                                <p class="fi-simple-header-subheading" style="text-wrap: pretty">{{ __('It has expired, has been used, or was replaced by a newer link. Ask whoever sent it to you for a new link.') }}</p>
                                <p style="margin-top: 1.5rem; text-align: center">
                                    <x-filament::link :href="filament()->getLoginUrl()">{{ __('Go to the sign-in page') }}</x-filament::link>
                                </p>
                            @else
                                <form method="post" action="{{ $action }}" style="display: grid; gap: 1.5rem; margin-top: 1.5rem">
                                    @csrf

                                    <div class="fi-fo-field">
                                        <div class="fi-fo-field-label-col">
                                            <div class="fi-fo-field-label-ctn">
                                                <label for="username" class="fi-fo-field-label">
                                                    <span class="fi-fo-field-label-content">{{ __('Username') }}</span>
                                                </label>
                                            </div>
                                        </div>
                                        <div class="fi-fo-field-content-col">
                                            <x-filament::input.wrapper>
                                                <x-filament::input id="username" name="username" type="text" :value="$username" readonly autocomplete="username" />
                                            </x-filament::input.wrapper>
                                        </div>
                                    </div>

                                    @foreach (['password' => __('New password'), 'password_confirmation' => __('Confirm new password')] as $field => $label)
                                        @php($fieldErrors = $messages->get($field))
                                        <div class="fi-fo-field">
                                            <div class="fi-fo-field-label-col">
                                                <div class="fi-fo-field-label-ctn">
                                                    <label for="{{ $field }}" class="fi-fo-field-label">
                                                        <span class="fi-fo-field-label-content">{{ $label }}<sup class="fi-fo-field-label-required-mark">*</sup></span>
                                                    </label>
                                                </div>
                                            </div>
                                            <div class="fi-fo-field-content-col">
                                                <x-filament::input.wrapper :valid="$fieldErrors === []">
                                                    <x-filament::input
                                                        :id="$field"
                                                        :name="$field"
                                                        type="password"
                                                        required
                                                        autocomplete="new-password"
                                                        :autofocus="$field === 'password'"
                                                        :aria-invalid="$fieldErrors === [] ? null : 'true'"
                                                        :aria-describedby="$fieldErrors === [] ? null : $field.'-error'"
                                                    />
                                                </x-filament::input.wrapper>
                                                @if ($fieldErrors !== [])
                                                    <ul id="{{ $field }}-error" class="fi-fo-field-wrp-error-list">
                                                        @foreach ($fieldErrors as $error)
                                                            <li class="fi-fo-field-wrp-error-message">{{ $error }}</li>
                                                        @endforeach
                                                    </ul>
                                                @endif
                                            </div>
                                        </div>
                                    @endforeach

                                    <x-filament::button type="submit" :loading-indicator="false">{{ __('Set password') }}</x-filament::button>
                                </form>
                            @endif
                        </div>
                    </div>
                </main>
            </div>
        </div>
    </body>
</html>
