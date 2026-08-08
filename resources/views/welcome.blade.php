<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        @include('partials.head', ['title' => 'Out&Screen'])
    </head>
    <body class="min-h-svh bg-white antialiased dark:bg-neutral-950">
        <main class="flex min-h-svh flex-col items-center justify-center gap-10 p-6">
            <div class="flex flex-col items-center gap-6 transition-all duration-700 translate-y-0 opacity-100 starting:translate-y-3 starting:opacity-0">
                <x-app-logo-icon class="size-16 text-neutral-900 dark:text-white" />

                <h1 class="text-3xl font-medium tracking-tight text-neutral-900 dark:text-white">
                    Out<span class="text-neutral-400 dark:text-neutral-500">&amp;</span>Screen
                </h1>
            </div>

            @if (Route::has('login'))
                <nav class="flex items-center gap-6 text-sm transition-opacity delay-300 duration-700 opacity-100 starting:opacity-0">
                    @auth
                        <a href="{{ route('dashboard') }}" class="text-neutral-500 underline-offset-4 hover:text-neutral-900 hover:underline dark:text-neutral-400 dark:hover:text-white">
                            Dashboard
                        </a>
                    @else
                        <a href="{{ route('login') }}" class="text-neutral-500 underline-offset-4 hover:text-neutral-900 hover:underline dark:text-neutral-400 dark:hover:text-white">
                            Log in
                        </a>

                        @if (Route::has('register'))
                            <a href="{{ route('register') }}" class="text-neutral-500 underline-offset-4 hover:text-neutral-900 hover:underline dark:text-neutral-400 dark:hover:text-white">
                                Register
                            </a>
                        @endif
                    @endauth
                </nav>
            @endif
        </main>
    </body>
</html>
