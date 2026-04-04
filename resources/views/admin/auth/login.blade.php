<!DOCTYPE html>
<!-- STANDALONE BLADE FILE: No npm build/vite required -->
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Admin Login - {{ config('app.name') }}</title>
    
    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;600;800&family=Inter:wght@300;400;500;700&display=swap" rel="stylesheet">

    <!-- Assets -->
    <link rel="stylesheet" href="{{ asset('css/tailwind.css') }}">
    
    <style>
        .animate-fade-in-up {
            animation: fadeInUp 0.6s ease-out forwards;
        }
        @keyframes fadeInUp {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .login-card {
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5);
        }
    </style>
</head>
<body class="font-sans antialiased h-screen flex items-center justify-center relative overflow-hidden selection:bg-indigo-500 selection:text-white">
    <!-- Background Mesh -->
    <div class="bg-mesh opacity-60"></div>

    <div class="w-full max-w-md p-1 px-4">
        <div class="glass login-card rounded-[2.5rem] p-8 md:p-10 animate-fade-in-up relative overflow-hidden">
            <!-- Decorative Light -->
            <div class="absolute -top-24 -right-24 w-48 h-48 bg-indigo-500/20 rounded-full blur-3xl"></div>
            
            <div class="text-center mb-10 relative z-10">
                <div class="inline-flex items-center justify-center w-20 h-20 rounded-3xl bg-gradient-to-tr from-indigo-600 to-purple-600 shadow-2xl shadow-indigo-500/40 mb-6 animate-float">
                    <span class="text-white font-black text-4xl font-outfit">Z</span>
                </div>
                <h1 class="text-3xl font-black font-outfit tracking-tight mb-2">
                    <span class="gradient-text">Admin Portal</span>
                </h1>
                <p class="text-slate-400 font-medium">Authentication required</p>
            </div>

            <form id="loginForm" method="POST" action="{{ route('admin.login.view') }}" class="space-y-6 relative z-10">
                @csrf

                <div class="space-y-2">
                    <label for="email" class="block text-xs font-bold uppercase tracking-widest text-slate-500 ml-1">Email Address</label>
                    <div class="relative group">
                        <input id="email" type="email" name="email" value="{{ old('email') }}" required autofocus
                            placeholder="admin@zilmoney.ai"
                            class="w-full px-5 py-4 rounded-2xl bg-white/5 border border-white/10 focus:border-indigo-500 focus:ring-4 focus:ring-indigo-500/10 outline-none text-white transition-all placeholder:text-slate-600 font-medium">
                        <div class="absolute inset-y-0 right-4 flex items-center pointer-events-none opacity-40 group-focus-within:opacity-100 transition-opacity">
                            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 12a4 4 0 10-8 0 4 4 0 008 0zm0 0v1.5a2.5 2.5 0 005 0V12a9 9 0 10-9 9m4.5-1.206a8.959 8.959 0 01-4.5 1.207" /></svg>
                        </div>
                    </div>
                    @error('email')
                        <p class="text-red-400 text-xs mt-2 ml-1 font-semibold">{{ $message }}</p>
                    @enderror
                </div>

                <div class="space-y-2">
                    <div class="flex justify-between items-center ml-1">
                        <label for="password" class="text-xs font-bold uppercase tracking-widest text-slate-500">Access Key</label>
                        <a href="#" class="text-xs font-bold text-indigo-400 hover:text-indigo-300 transition-colors">Forgot?</a>
                    </div>
                    <div class="relative group">
                        <input id="password" type="password" name="password" required
                            placeholder="••••••••"
                            class="w-full px-5 py-4 rounded-2xl bg-white/5 border border-white/10 focus:border-indigo-500 focus:ring-4 focus:ring-indigo-500/10 outline-none text-white transition-all pr-12 placeholder:text-slate-600 font-medium">
                        <button type="button" onclick="togglePassword(this)" class="absolute right-4 top-1/2 -translate-y-1/2 text-slate-500 hover:text-white transition-colors">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 eye-icon" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                            </svg>
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 eye-off-icon hidden" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.59 3.59m0 0A9.953 9.953 0 0112 5c4.478 0 8.268 2.943 9.543 7a10.025 10.025 0 01-4.132 5.411m0 0L21 21" />
                            </svg>
                        </button>
                    </div>
                </div>

                <div class="flex items-center ml-1">
                    <label class="flex items-center cursor-pointer group">
                        <div class="relative">
                            <input type="checkbox" name="remember" class="peer sr-only">
                            <div class="w-5 h-5 bg-white/5 border border-white/10 rounded-lg transition-all peer-checked:bg-indigo-600 peer-checked:border-indigo-600"></div>
                            <svg class="absolute top-1 left-1 h-3 w-3 text-white opacity-0 peer-checked:opacity-100 transition-opacity" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="4"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" /></svg>
                        </div>
                        <span class="ml-3 text-xs font-bold text-slate-500 uppercase tracking-widest group-hover:text-slate-400 transition-colors">Persistent Session</span>
                    </label>
                </div>

                <div id="error-message" class="p-4 rounded-2xl bg-red-500/10 border border-red-500/20 text-red-400 text-sm font-semibold hidden animate-fade-in-up">
                    <div class="flex items-center gap-2">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                        <span id="error-text"></span>
                    </div>
                </div>

                <button type="submit" id="submitBtn" class="group w-full relative h-[60px] rounded-2xl bg-indigo-600 hover:bg-indigo-550 transition-all font-black text-lg tracking-tight overflow-hidden login-card transform active:scale-95">
                    <div class="absolute inset-0 bg-gradient-to-r from-indigo-600 to-purple-600 group-hover:opacity-80 transition-opacity"></div>
                    <!-- Shimmer -->
                    <div class="absolute inset-0 bg-gradient-to-r from-transparent via-white/10 to-transparent -translate-x-full group-hover:translate-x-full transition-transform duration-1000"></div>
                    <span class="relative text-white flex items-center justify-center gap-2">
                        Initialize Access
                        <svg class="h-5 w-5 group-hover:translate-x-1 transition-transform" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3" /></svg>
                    </span>
                    <div id="loader" class="hidden absolute inset-0 flex items-center justify-center bg-indigo-600 z-20">
                        <svg class="animate-spin h-6 w-6 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                          <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                          <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                        </svg>
                    </div>
                </button>
            </form>
        </div>
        
        <p class="text-center mt-8 text-slate-500 text-xs font-bold uppercase tracking-[0.2em] opacity-40">
            ZilMoney Neural Operations Layer
        </p>
    </div>

    <script>
        function togglePassword(btn) {
            const input = document.getElementById('password');
            const eyeIcon = btn.querySelector('.eye-icon');
            const eyeOffIcon = btn.querySelector('.eye-off-icon');

            if (input.type === 'password') {
                input.type = 'text';
                eyeIcon.classList.add('hidden');
                eyeOffIcon.classList.remove('hidden');
            } else {
                input.type = 'password';
                eyeIcon.classList.remove('hidden');
                eyeOffIcon.classList.add('hidden');
            }
        }

        document.getElementById('loginForm').addEventListener('submit', async function(e) {
            e.preventDefault();
            
            const email = document.getElementById('email').value;
            const password = document.getElementById('password').value;
            const errorElement = document.getElementById('error-message');
            const errorText = document.getElementById('error-text');
            const submitBtn = document.getElementById('submitBtn');
            const loader = document.getElementById('loader');
            
            errorElement.classList.add('hidden');
            loader.classList.remove('hidden');
            submitBtn.disabled = true;

            try {
                const response = await fetch("/api/auth/admin/login", {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json'
                    },
                    body: JSON.stringify({ email, password })
                });

                const data = await response.json();

                if (response.ok) {
                    const token = data.data ? data.data.token : data.token; 
                    const d = new Date();
                    d.setTime(d.getTime() + (1*24*60*60*1000));
                    let expires = "expires="+ d.toUTCString();
                    document.cookie = "admin_token=" + token + ";" + expires + ";path=/";
                    window.location.href = "{{ route('admin.dashboard') }}";
                } else {
                    errorText.textContent = data.message || 'Access Denied: Invalid credentials';
                    errorElement.classList.remove('hidden');
                }
            } catch (error) {
                errorText.textContent = 'Neural link failed: Network disruption detected';
                errorElement.classList.remove('hidden');
            } finally {
                loader.classList.add('hidden');
                submitBtn.disabled = false;
            }
        });
    </script>
</body>
</html>
