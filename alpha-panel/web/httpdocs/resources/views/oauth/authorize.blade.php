<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ config('app.name', 'AlphaPanel') }} — Authorize Access</title>
    @vite(['resources/css/app.css'])
</head>
<body class="min-h-screen bg-gray-50 dark:bg-gray-900 flex items-center justify-center p-4">

<div class="w-full max-w-md">
    <div class="text-center mb-8">
        <img src="/img/AlphaPanel-dark.svg" alt="AlphaPanel" class="h-12 mx-auto mb-4 dark:hidden">
        <img src="/img/AlphaPanel-light.svg" alt="AlphaPanel" class="h-12 mx-auto mb-4 hidden dark:block">
        <h1 class="text-2xl font-bold text-gray-900 dark:text-white">Authorize Access</h1>
        <p class="mt-2 text-sm text-gray-600 dark:text-gray-400">
            An external application is requesting access to your AlphaPanel account.
            Sign in to continue.
        </p>
    </div>

    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 p-8">

        @if ($errors->any())
            <div class="mb-6 p-4 rounded-lg bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800">
                <p class="text-sm text-red-700 dark:text-red-400">
                    {{ $errors->first('email') ?? 'Authentication failed. Please try again.' }}
                </p>
            </div>
        @endif

        @if (session('oauth_error'))
            <div class="mb-6 p-4 rounded-lg bg-yellow-50 dark:bg-yellow-900/20 border border-yellow-200 dark:border-yellow-800">
                <p class="text-sm text-yellow-700 dark:text-yellow-400">
                    {{ session('oauth_error') }}
                </p>
            </div>
        @endif

        <form method="POST" action="{{ url('/oauth/authorize') }}">
            @csrf
            <input type="hidden" name="redirect_uri" value="{{ $redirect_uri }}">
            <input type="hidden" name="state" value="{{ $state }}">

            <div class="space-y-5">
                <div>
                    <label for="email" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">
                        Email Address
                    </label>
                    <input
                        id="email"
                        type="email"
                        name="email"
                        value="{{ old('email') }}"
                        required
                        autofocus
                        autocomplete="username"
                        class="w-full px-3.5 py-2.5 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 text-gray-900 dark:text-white placeholder-gray-400 dark:placeholder-gray-500 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-transparent transition"
                        placeholder="you@example.com"
                    >
                </div>

                <div>
                    <label for="password" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">
                        Password
                    </label>
                    <input
                        id="password"
                        type="password"
                        name="password"
                        required
                        autocomplete="current-password"
                        class="w-full px-3.5 py-2.5 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 text-gray-900 dark:text-white placeholder-gray-400 dark:placeholder-gray-500 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-transparent transition"
                        placeholder="••••••••"
                    >
                </div>

                <button
                    type="submit"
                    class="w-full py-2.5 px-4 rounded-lg bg-brand-600 hover:bg-brand-700 text-white text-sm font-semibold transition focus:outline-none focus:ring-2 focus:ring-brand-500 focus:ring-offset-2 dark:focus:ring-offset-gray-800"
                >
                    Sign In &amp; Authorize
                </button>
            </div>
        </form>

        <p class="mt-6 text-center text-xs text-gray-500 dark:text-gray-500">
            You are authorizing access to: <span class="font-medium text-gray-700 dark:text-gray-300 break-all">{{ parse_url($redirect_uri, PHP_URL_HOST) }}</span>
        </p>
    </div>

    <p class="mt-6 text-center text-xs text-gray-400 dark:text-gray-600">
        This authorization expires in 90 seconds after sign-in. You will be redirected automatically.
    </p>
</div>

</body>
</html>
