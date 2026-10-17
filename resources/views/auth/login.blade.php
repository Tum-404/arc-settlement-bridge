<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Sign in - {{ config('app.name') }}</title>
        @vite(['resources/css/app.css'])
    </head>
    <body class="min-h-screen bg-slate-950 font-sans text-slate-100">
        <main class="mx-auto flex min-h-screen w-full max-w-md items-center px-6">
            <section class="w-full border border-slate-800 bg-slate-900 p-7 shadow-2xl shadow-black/30 sm:p-9">
                <p class="text-sm font-semibold text-indigo-300">Arc Settlement Bridge</p>
                <h1 class="mt-3 text-2xl font-semibold text-white">Operator sign in</h1>
                <p class="mt-2 text-sm leading-6 text-slate-400">Use the operator account provisioned for this environment.</p>

                <form class="mt-7 space-y-5" method="POST" action="{{ route('login') }}">
                    @csrf

                    <div>
                        <label class="block text-sm font-medium text-slate-200" for="email">Email</label>
                        <input class="mt-2 w-full border border-slate-700 bg-slate-950 px-3 py-2.5 text-sm text-white outline-none transition focus:border-indigo-400" id="email" name="email" type="email" value="{{ old('email') }}" autocomplete="email" required autofocus>
                        @error('email')
                            <p class="mt-2 text-sm text-rose-300">{{ $message }}</p>
                        @enderror
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-slate-200" for="password">Password</label>
                        <input class="mt-2 w-full border border-slate-700 bg-slate-950 px-3 py-2.5 text-sm text-white outline-none transition focus:border-indigo-400" id="password" name="password" type="password" autocomplete="current-password" required>
                    </div>

                    <label class="flex items-center gap-2 text-sm text-slate-400">
                        <input class="border-slate-700 bg-slate-950 text-indigo-500" name="remember" type="checkbox" value="1">
                        Keep this device signed in
                    </label>

                    <button class="w-full bg-indigo-500 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-indigo-400 focus:outline-none focus:ring-2 focus:ring-indigo-300" type="submit">Sign in</button>
                </form>
            </section>
        </main>
    </body>
</html>
