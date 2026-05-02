<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ config('app.name', 'AlphaPanel') }} — Authorize Access</title>
    @vite(['resources/css/app.css'])

    @if($captcha)
        @if($captcha['provider'] === 'turnstile')
            <script nonce="{{ \Illuminate\Support\Facades\Vite::cspNonce() }}" src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
        @elseif($captcha['provider'] === 'recaptcha')
            @if(($captcha['recaptcha_version'] ?? 'v2') === 'v3')
                <script nonce="{{ \Illuminate\Support\Facades\Vite::cspNonce() }}" src="https://www.google.com/recaptcha/api.js?render={{ $captcha['site_key'] }}" async defer></script>
            @else
                <script nonce="{{ \Illuminate\Support\Facades\Vite::cspNonce() }}" src="https://www.google.com/recaptcha/api.js" async defer></script>
            @endif
        @endif
    @endif
</head>
<body class="min-h-screen bg-gray-50 dark:bg-gray-900 flex items-center justify-center p-4">

<div class="w-full max-w-md">
    <div class="text-center mb-8">
        <img src="/img/AlphaPanel-dark.svg" alt="AlphaPanel" class="h-12 mx-auto mb-4 dark:hidden">
        <img src="/img/AlphaPanel-light.svg" alt="AlphaPanel" class="h-12 mx-auto mb-4 hidden dark:block">
        <h1 class="text-2xl font-bold text-gray-900 dark:text-white">Authorize Access</h1>
        <p class="mt-2 text-sm text-gray-600 dark:text-gray-400">
            An external application is requesting access to your AlphaPanel account.
        </p>
    </div>

    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 p-8">

        @if ($errors->any())
            <div class="mb-6 p-4 rounded-lg bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800">
                <p class="text-sm text-red-700 dark:text-red-400">
                    {{ $errors->first('login') ?? $errors->first('captcha_token') ?? 'Authentication failed.' }}
                </p>
            </div>
        @endif

        {{-- Step 1: username / email --}}
        <div id="step-login" class="{{ $errors->any() ? 'hidden' : '' }}">
            <div id="lookup-error" class="hidden mb-6 p-4 rounded-lg bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800">
                <p class="text-sm text-red-700 dark:text-red-400" id="lookup-error-msg"></p>
            </div>

            <div class="space-y-5">
                <div>
                    <label for="login" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">
                        Username or Email
                    </label>
                    <input
                        id="login"
                        type="text"
                        name="login"
                        value="{{ old('login') }}"
                        required
                        autofocus
                        autocomplete="username"
                        class="w-full px-3.5 py-2.5 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 text-gray-900 dark:text-white placeholder-gray-400 dark:placeholder-gray-500 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-transparent transition"
                        placeholder="username or you@example.com"
                    >
                </div>

                @if($captcha && !$errors->any())
                    <div>
                        @if($captcha['provider'] === 'turnstile')
                            <div class="cf-turnstile" data-sitekey="{{ $captcha['site_key'] }}" data-callback="onCaptchaSuccess"></div>
                        @elseif($captcha['provider'] === 'recaptcha' && ($captcha['recaptcha_version'] ?? 'v2') === 'v2')
                            <div class="g-recaptcha" data-sitekey="{{ $captcha['site_key'] }}" data-callback="onCaptchaSuccess"></div>
                        @endif
                    </div>
                @endif

                <button
                    id="btn-continue"
                    type="button"
                    class="w-full py-2.5 px-4 rounded-lg bg-brand-600 hover:bg-brand-700 text-white text-sm font-semibold transition focus:outline-none focus:ring-2 focus:ring-brand-500 focus:ring-offset-2 dark:focus:ring-offset-gray-800"
                >
                    Continue
                </button>
            </div>
        </div>

        {{-- Step WebAuthn --}}
        <div id="step-webauthn" class="hidden">
            <div id="webauthn-error" class="hidden mb-6 p-4 rounded-lg bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800">
                <p class="text-sm text-red-700 dark:text-red-400" id="webauthn-error-msg"></p>
            </div>

            <div class="text-center mb-6">
                <div class="mx-auto mb-4 flex h-16 w-16 items-center justify-center rounded-full bg-brand-50 dark:bg-brand-900/20">
                    <svg class="h-8 w-8 text-brand-600 dark:text-brand-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z"/>
                    </svg>
                </div>
                <p id="webauthn-account" class="text-sm font-medium text-gray-700 dark:text-gray-300 mb-1"></p>
                <p class="text-xs text-gray-500 dark:text-gray-400">This account requires passkey authentication.</p>
            </div>

            <div class="space-y-3">
                <button
                    id="btn-webauthn"
                    type="button"
                    class="w-full py-2.5 px-4 rounded-lg bg-brand-600 hover:bg-brand-700 text-white text-sm font-semibold transition focus:outline-none focus:ring-2 focus:ring-brand-500 focus:ring-offset-2 dark:focus:ring-offset-gray-800 disabled:opacity-50"
                >
                    Sign in with passkey
                </button>
                <button
                    id="btn-webauthn-back"
                    type="button"
                    class="w-full py-2.5 px-4 rounded-lg border border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-300 text-sm font-semibold hover:bg-gray-50 dark:hover:bg-gray-700 transition focus:outline-none"
                >
                    ← Back
                </button>
            </div>
        </div>

        {{-- Step 2: password --}}
        <div id="step-password" class="{{ $errors->any() ? '' : 'hidden' }}">
            <form method="POST" action="{{ url('/oauth/authorize') }}" id="auth-form">
                @csrf
                <input type="hidden" name="redirect_uri" value="{{ $redirect_uri }}">
                <input type="hidden" name="state" value="{{ $state }}">
                <input type="hidden" id="form-login" name="login" value="{{ old('login') }}">
                @if($captcha)
                    <input type="hidden" id="captcha-token" name="captcha_token" value="">
                @endif

                <div class="space-y-5">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">
                            Username or Email
                        </label>
                        <div id="login-display" class="w-full px-3.5 py-2.5 rounded-lg border border-gray-200 dark:border-gray-600 bg-gray-50 dark:bg-gray-700/50 text-gray-700 dark:text-gray-300 text-sm">
                            {{ old('login') }}
                        </div>
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

                    @if($captcha && $errors->any())
                        <div>
                            @if($captcha['provider'] === 'turnstile')
                                <div class="cf-turnstile" data-sitekey="{{ $captcha['site_key'] }}" data-callback="onCaptchaSuccess"></div>
                            @elseif($captcha['provider'] === 'recaptcha' && ($captcha['recaptcha_version'] ?? 'v2') === 'v2')
                                <div class="g-recaptcha" data-sitekey="{{ $captcha['site_key'] }}" data-callback="onCaptchaSuccess"></div>
                            @endif
                        </div>
                    @endif

                    <div class="flex gap-3">
                        <button
                            type="button"
                            id="btn-back"
                            class="flex-none py-2.5 px-4 rounded-lg border border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-300 text-sm font-semibold hover:bg-gray-50 dark:hover:bg-gray-700 transition focus:outline-none"
                        >
                            ← Back
                        </button>
                        <button
                            id="btn-submit"
                            type="submit"
                            class="flex-1 py-2.5 px-4 rounded-lg bg-brand-600 hover:bg-brand-700 text-white text-sm font-semibold transition focus:outline-none focus:ring-2 focus:ring-brand-500 focus:ring-offset-2 dark:focus:ring-offset-gray-800"
                        >
                            Sign In &amp; Authorize
                        </button>
                    </div>
                </div>
            </form>
        </div>

    </div>

    <p class="mt-6 text-center text-xs text-gray-400 dark:text-gray-600">
        Authorizing access to: <span class="font-medium text-gray-600 dark:text-gray-400 break-all">{{ parse_url($redirect_uri, PHP_URL_HOST) }}</span>
    </p>
</div>

<script nonce="{{ \Illuminate\Support\Facades\Vite::cspNonce() }}">
(function () {
    var loginInput       = document.getElementById('login');
    var btnContinue      = document.getElementById('btn-continue');
    var stepLogin        = document.getElementById('step-login');
    var stepWebAuthn     = document.getElementById('step-webauthn');
    var stepPassword     = document.getElementById('step-password');
    var formLogin        = document.getElementById('form-login');
    var loginDisplay     = document.getElementById('login-display');
    var lookupError      = document.getElementById('lookup-error');
    var lookupErrorMsg   = document.getElementById('lookup-error-msg');
    var webauthnError    = document.getElementById('webauthn-error');
    var webauthnErrorMsg = document.getElementById('webauthn-error-msg');
    var webauthnAccount  = document.getElementById('webauthn-account');
    var btnWebAuthn      = document.getElementById('btn-webauthn');
    var btnWebAuthnBack  = document.getElementById('btn-webauthn-back');
    var btnBack          = document.getElementById('btn-back');
    var csrfToken        = document.querySelector('meta[name="csrf-token"]').content;
    var captchaTokenValue = '';
    var pendingEmail     = '';

    @if($captcha && $captcha['provider'] === 'recaptcha' && ($captcha['recaptcha_version'] ?? 'v2') === 'v3')
    var recaptchaSiteKey = '{{ $captcha['site_key'] }}';
    @endif

    window.onCaptchaSuccess = function (token) {
        captchaTokenValue = token;
        var el = document.getElementById('captcha-token');
        if (el) { el.value = token; }
    };

    // --- WebAuthn helpers ---

    function base64UrlToArrayBuffer(value) {
        var base64 = value.replace(/-/g, '+').replace(/_/g, '/');
        var padded = base64.padEnd(base64.length + ((4 - (base64.length % 4)) % 4), '=');
        var binary = atob(padded);
        var bytes = new Uint8Array(binary.length);
        for (var i = 0; i < binary.length; i++) { bytes[i] = binary.charCodeAt(i); }
        return bytes.buffer;
    }

    function arrayBufferToBase64(value) {
        if (!value) { return null; }
        var bytes = value instanceof Uint8Array ? value : new Uint8Array(value);
        var binary = '';
        for (var i = 0; i < bytes.length; i++) { binary += String.fromCharCode(bytes[i]); }
        return btoa(binary);
    }

    function normalizeRequestOptions(options) {
        return Object.assign({}, options, {
            challenge: base64UrlToArrayBuffer(options.challenge),
            allowCredentials: options.allowCredentials
                ? options.allowCredentials.map(function (c) {
                    return Object.assign({}, c, { id: base64UrlToArrayBuffer(c.id) });
                })
                : undefined,
        });
    }

    function serializeCredential(credential) {
        var response = credential.response;
        return {
            id: credential.id,
            type: credential.type,
            rawId: arrayBufferToBase64(credential.rawId),
            authenticatorAttachment: credential.authenticatorAttachment,
            clientExtensionResults: credential.getClientExtensionResults(),
            response: {
                clientDataJSON:   arrayBufferToBase64(response.clientDataJSON),
                authenticatorData: arrayBufferToBase64(response.authenticatorData),
                signature:         arrayBufferToBase64(response.signature),
                userHandle:        arrayBufferToBase64(response.userHandle),
            },
        };
    }

    // --- Captcha token getter ---

    function getCaptchaToken() {
        @if($captcha && $captcha['provider'] === 'recaptcha' && ($captcha['recaptcha_version'] ?? 'v2') === 'v3')
        return new Promise(function (resolve) {
            if (window.grecaptcha) {
                window.grecaptcha.ready(function () {
                    window.grecaptcha.execute(recaptchaSiteKey, { action: 'oauth_login' })
                        .then(function (token) {
                            captchaTokenValue = token;
                            var el = document.getElementById('captcha-token');
                            if (el) { el.value = token; }
                            resolve(token);
                        })
                        .catch(function () { resolve(''); });
                });
            } else {
                resolve('');
            }
        });
        @else
        return Promise.resolve(captchaTokenValue);
        @endif
    }

    // --- Step navigation ---

    function showStep(step) {
        [stepLogin, stepWebAuthn, stepPassword].forEach(function (s) {
            if (s) { s.classList.add('hidden'); }
        });
        if (step) { step.classList.remove('hidden'); }
    }

    // --- Step 1: Continue ---

    if (btnContinue) {
        btnContinue.addEventListener('click', function () {
            var login = loginInput.value.trim();
            if (!login) {
                lookupErrorMsg.textContent = 'Please enter your username or email.';
                lookupError.classList.remove('hidden');
                return;
            }

            lookupError.classList.add('hidden');
            btnContinue.disabled = true;
            btnContinue.textContent = 'Checking…';

            fetch('{{ url('/oauth/check-user') }}', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': csrfToken,
                },
                body: JSON.stringify({ login: login }),
            })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                btnContinue.disabled = false;
                btnContinue.textContent = 'Continue';

                if (!data.found) {
                    lookupErrorMsg.textContent = 'No account found with that username or email.';
                    lookupError.classList.remove('hidden');
                    return;
                }

                if (data.has_webauthn) {
                    pendingEmail = data.email;
                    if (webauthnAccount) { webauthnAccount.textContent = login; }
                    webauthnError.classList.add('hidden');
                    showStep(stepWebAuthn);
                    doWebAuthn();
                    return;
                }

                if (formLogin)    { formLogin.value = login; }
                if (loginDisplay) { loginDisplay.textContent = login; }
                showStep(stepPassword);
                var pwField = document.getElementById('password');
                if (pwField) { pwField.focus(); }
            })
            .catch(function () {
                lookupErrorMsg.textContent = 'Network error. Please try again.';
                lookupError.classList.remove('hidden');
                btnContinue.disabled = false;
                btnContinue.textContent = 'Continue';
            });
        });

        loginInput.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') { btnContinue.click(); }
        });
    }

    // --- WebAuthn flow ---

    function doWebAuthn() {
        if (!window.PublicKeyCredential) {
            webauthnErrorMsg.textContent = 'This browser does not support passkey login. Go back and try a different account or browser.';
            webauthnError.classList.remove('hidden');
            return;
        }

        if (btnWebAuthn) {
            btnWebAuthn.disabled = true;
            btnWebAuthn.textContent = 'Waiting for device…';
        }
        webauthnError.classList.add('hidden');

        getCaptchaToken().then(function (captchaToken) {
            var optionsBody = { email: pendingEmail };
            if (captchaToken) { optionsBody.captcha_token = captchaToken; }

            fetch('{{ url('/webauthn/login/options') }}', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': csrfToken,
                },
                body: JSON.stringify(optionsBody),
            })
            .then(function (res) {
                if (!res.ok) { throw new Error('options_failed'); }
                return res.json();
            })
            .then(function (options) {
                return navigator.credentials.get({ publicKey: normalizeRequestOptions(options) });
            })
            .then(function (credential) {
                if (!credential) { throw new Error('cancelled'); }

                var payload = serializeCredential(credential);
                if (captchaToken) { payload.captcha_token = captchaToken; }

                return fetch('{{ url('/webauthn/login') }}', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': csrfToken,
                    },
                    body: JSON.stringify(payload),
                });
            })
            .then(function (res) {
                if (res.status === 204) {
                    window.location.href = window.location.href;
                    return;
                }
                throw new Error('auth_failed');
            })
            .catch(function (err) {
                if (btnWebAuthn) {
                    btnWebAuthn.disabled = false;
                    btnWebAuthn.textContent = 'Sign in with passkey';
                }

                var msg = 'Passkey authentication failed. Please try again.';
                if (err && (err.name === 'NotAllowedError' || err.message === 'cancelled')) {
                    msg = 'Authentication was cancelled. Click the button to try again.';
                } else if (err && err.message === 'options_failed') {
                    msg = 'Could not get authentication options. Please try again.';
                }
                webauthnErrorMsg.textContent = msg;
                webauthnError.classList.remove('hidden');
            });
        });
    }

    if (btnWebAuthn)     { btnWebAuthn.addEventListener('click', doWebAuthn); }
    if (btnWebAuthnBack) { btnWebAuthnBack.addEventListener('click', function () { showStep(stepLogin); loginInput.focus(); }); }
    if (btnBack)         { btnBack.addEventListener('click', function () { showStep(stepLogin); loginInput.focus(); }); }

    // --- reCAPTCHA v3 form submit intercept ---
    @if($captcha && $captcha['provider'] === 'recaptcha' && ($captcha['recaptcha_version'] ?? 'v2') === 'v3')
    var authForm = document.getElementById('auth-form');
    if (authForm) {
        authForm.addEventListener('submit', function (e) {
            e.preventDefault();
            getCaptchaToken().then(function () { authForm.submit(); });
        });
    }
    @endif
})();
</script>

</body>
</html>
