@extends('admin.layout')

@section('title', 'System Settings')

@section('content')
    <div class="max-w-6xl mx-auto">
        <div class="glass rounded-3xl overflow-hidden flex flex-col md:flex-row min-h-[600px]">
            <!-- Sidebar / Tabs -->
            <div class="w-full md:w-64 bg-slate-900/50 border-r border-white/5 flex flex-col">
                <div class="p-6 border-b border-white/5">
                    <h3 class="text-lg font-bold font-outfit text-white">Configuration</h3>
                    <p class="text-xs text-slate-400 mt-1">Manage system variables</p>
                </div>
                
                <nav class="flex-1 p-4 space-y-1">
                    <button onclick="switchTab('general')" id="tab-btn-general" class="tab-btn w-full flex items-center gap-3 px-4 py-3 rounded-xl text-left text-slate-400 hover:text-white hover:bg-white/5 transition-all mb-1 active-tab">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z" />
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                        </svg>
                        General
                    </button>

                    <button onclick="switchTab('email')" id="tab-btn-email" class="tab-btn w-full flex items-center gap-3 px-4 py-3 rounded-xl text-left text-slate-400 hover:text-white hover:bg-white/5 transition-all mb-1">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
                        </svg>
                        Email
                    </button>

                    <button onclick="switchTab('aws')" id="tab-btn-aws" class="tab-btn w-full flex items-center gap-3 px-4 py-3 rounded-xl text-left text-slate-400 hover:text-white hover:bg-white/5 transition-all mb-1">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10" />
                        </svg>
                        AWS S3
                    </button>

                    <button onclick="switchTab('stripe')" id="tab-btn-stripe" class="tab-btn w-full flex items-center gap-3 px-4 py-3 rounded-xl text-left text-slate-400 hover:text-white hover:bg-white/5 transition-all mb-1">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z" />
                        </svg>
                        Stripe
                    </button>

                    <button onclick="switchTab('jwt')" id="tab-btn-jwt" class="tab-btn w-full flex items-center gap-3 px-4 py-3 rounded-xl text-left text-slate-400 hover:text-white hover:bg-white/5 transition-all mb-1">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z" />
                        </svg>
                        JWT Auth
                    </button>

                     <button onclick="switchTab('twilio')" id="tab-btn-twilio" class="tab-btn w-full flex items-center gap-3 px-4 py-3 rounded-xl text-left text-slate-400 hover:text-white hover:bg-white/5 transition-all mb-1">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z" />
                        </svg>
                        Twilio
                    </button>
                    
                    <button onclick="switchTab('support')" id="tab-btn-support" class="tab-btn w-full flex items-center gap-3 px-4 py-3 rounded-xl text-left text-slate-400 hover:text-white hover:bg-white/5 transition-all mb-1">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 5.636l-3.536 3.536m0 5.656l3.536 3.536M9.172 9.172L5.636 5.636m3.536 9.192l-3.536 3.536M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-5 0a4 4 0 11-8 0 4 4 0 018 0z" />
                        </svg>
                        Support
                    </button>

                    <div class="pt-4 pb-2 px-4">
                        <p class="text-[10px] font-black uppercase tracking-[0.2em] text-slate-500">Social Authentication</p>
                    </div>

                    <button onclick="switchTab('google')" id="tab-btn-google" class="tab-btn w-full flex items-center gap-3 px-4 py-3 rounded-xl text-left text-slate-400 hover:text-white hover:bg-white/5 transition-all mb-1">
                        <svg class="h-5 w-5 flex-shrink-0" viewBox="0 0 24 24" fill="currentColor">
                            <path d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z" />
                            <path d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z" />
                            <path d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l3.66-2.84z" />
                            <path d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z" />
                        </svg>
                        Google Login
                    </button>

                    <button onclick="switchTab('apple')" id="tab-btn-apple" class="tab-btn w-full flex items-center gap-3 px-4 py-3 rounded-xl text-left text-slate-400 hover:text-white hover:bg-white/5 transition-all mb-1">
                        <svg class="h-5 w-5 flex-shrink-0" viewBox="0 0 24 24" fill="currentColor">
                            <path d="M17.05 20.28c-.96.95-2.04 1.72-3.41 1.72-1.33 0-1.78-.83-3.34-.83-1.58 0-2.08.81-3.32.81-1.33 0-2.4-.8-3.41-1.72-2.04-1.89-3.57-5.36-3.57-8.38 0-4.99 3.09-7.62 6.07-7.62 1.54 0 2.47.81 3.51.81.99 0 1.83-.81 3.53-.81 2.53 0 4.7 1.41 5.8 3.51-5.11 2.14-4.29 9.38 1.14 11.51zM12.03 4.39c-.11-2.52 1.95-4.63 4.27-4.39.24 2.62-2.12 4.91-4.27 4.39z" />
                        </svg>
                        Apple Login
                    </button>
                </nav>
            </div>

            <!-- Content -->
            <div class="flex-1 p-8 bg-slate-900/30">
                @if(session('success'))
                    <div class="mb-6 px-4 py-3 rounded-xl bg-green-500/10 border border-green-500/20 text-green-400 flex items-center gap-2 animate-pulse">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                        </svg>
                        <span>{{ session('success') }}</span>
                    </div>
                @endif
                
                <form id="settingsForm" class="space-y-6">
                    
                    <!-- General Tab -->
                    <div id="tab-general" class="tab-content">
                        <h2 class="text-2xl font-bold font-outfit text-white mb-6">General Settings</h2>
                        <div class="space-y-6">
                            <div>
                                <label class="block text-sm font-medium text-slate-400 mb-2">Site Name</label>
                                <input type="text" name="site_name" 
                                    class="w-full px-4 py-3 rounded-xl bg-white/5 border border-white/10 focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 outline-none text-white transition-all">
                            </div>
                            
                            <div class="flex items-center justify-between p-4 rounded-xl bg-white/5 border border-white/10">
                                <div>
                                    <span class="block text-white font-medium">Maintenance Mode</span>
                                    <span class="text-sm text-slate-400">Restrict access to admins only.</span>
                                </div>
                                <label class="relative inline-flex items-center cursor-pointer">
                                    <input type="hidden" name="maintenance_mode" value="0">
                                    <input type="checkbox" name="maintenance_mode" value="1" class="sr-only peer">
                                    <div class="w-11 h-6 bg-slate-700 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-indigo-800 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-indigo-600"></div>
                                </label>
                            </div>
                        </div>
                    </div>

                    <!-- Email Tab -->
                    <div id="tab-email" class="tab-content hidden">
                        <div class="flex items-center justify-between mb-6">
                            <h2 class="text-2xl font-bold font-outfit text-white">Email Configuration</h2>
                            <div class="relative">
                                <select onchange="applyEmailPreset(this.value)" class="appearance-none bg-white/5 border border-white/10 text-slate-300 text-sm rounded-xl px-4 py-2 pr-8 focus:outline-none focus:border-indigo-500 cursor-pointer">
                                    <option value="">Quick Config...</option>
                                    <option value="gmail">Gmail</option>
                                    <option value="outlook">Outlook</option>
                                    <option value="privateemail">PrivateEmail (Namecheap)</option>
                                    <option value="mailgun">Mailgun</option>
                                    <option value="mailtrap">Mailtrap</option>
                                </select>
                                <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center px-2 text-slate-400">
                                    <svg class="fill-current h-4 w-4" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20"><path d="M9.293 12.95l.707.707L15.657 8l-1.414-1.414L10 10.828 5.757 6.586 4.343 8z"/></svg>
                                </div>
                            </div>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div>
                                <label class="block text-sm font-medium text-slate-400 mb-2">Mailer</label>
                                <input type="text" name="MAIL_MAILER" id="mail_mailer" 
                                    class="w-full px-4 py-3 rounded-xl bg-white/5 border border-white/10 focus:border-indigo-500 outline-none text-white">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-slate-400 mb-2">Host</label>
                                <input type="text" name="MAIL_HOST" id="mail_host" 
                                    class="w-full px-4 py-3 rounded-xl bg-white/5 border border-white/10 focus:border-indigo-500 outline-none text-white">
                            </div>
                             <div>
                                <label class="block text-sm font-medium text-slate-400 mb-2">Port</label>
                                <input type="text" name="MAIL_PORT" id="mail_port" 
                                    class="w-full px-4 py-3 rounded-xl bg-white/5 border border-white/10 focus:border-indigo-500 outline-none text-white">
                            </div>
                             <div>
                                <label class="block text-sm font-medium text-slate-400 mb-2">Encryption</label>
                                <input type="text" name="MAIL_ENCRYPTION" id="mail_encryption" 
                                    class="w-full px-4 py-3 rounded-xl bg-white/5 border border-white/10 focus:border-indigo-500 outline-none text-white">
                            </div>
                             <div>
                                <label class="block text-sm font-medium text-slate-400 mb-2">Username</label>
                                <input type="text" name="MAIL_USERNAME" 
                                    class="w-full px-4 py-3 rounded-xl bg-white/5 border border-white/10 focus:border-indigo-500 outline-none text-white">
                            </div>
                             <div>
                                <label class="block text-sm font-medium text-slate-400 mb-2">Password</label>
                                <div class="relative">
                                    <input type="password" name="MAIL_PASSWORD" 
                                        class="w-full px-4 py-3 rounded-xl bg-white/5 border border-white/10 focus:border-indigo-500 outline-none text-white pr-10">
                                    <button type="button" onclick="togglePassword(this)" class="absolute right-3 top-1/2 -translate-y-1/2 text-slate-400 hover:text-white">
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
                             <div>
                                <label class="block text-sm font-medium text-slate-400 mb-2">From Address</label>
                                <input type="text" name="MAIL_FROM_ADDRESS" 
                                    class="w-full px-4 py-3 rounded-xl bg-white/5 border border-white/10 focus:border-indigo-500 outline-none text-white">
                            </div>
                             <div>
                                <label class="block text-sm font-medium text-slate-400 mb-2">From Name</label>
                                <input type="text" name="MAIL_FROM_NAME" 
                                    class="w-full px-4 py-3 rounded-xl bg-white/5 border border-white/10 focus:border-indigo-500 outline-none text-white">
                            </div>
                        </div>
                    </div>

                    <script>
                    function applyEmailPreset(preset) {
                        const presets = {
                            'gmail': { host: 'smtp.gmail.com', port: '587', encryption: 'tls' },
                            'outlook': { host: 'smtp.office365.com', port: '587', encryption: 'tls' },
                            'privateemail': { host: 'mail.privateemail.com', port: '465', encryption: 'ssl' },
                            'mailgun': { host: 'smtp.mailgun.org', port: '587', encryption: 'tls' },
                            'mailtrap': { host: 'smtp.mailtrap.io', port: '2525', encryption: 'tls' }
                        };

                        if (presets[preset]) {
                            document.getElementById('mail_host').value = presets[preset].host;
                            document.getElementById('mail_port').value = presets[preset].port;
                            document.getElementById('mail_encryption').value = presets[preset].encryption;
                            document.getElementById('mail_mailer').value = 'smtp';
                        }
                    }
                    </script>

                    <!-- AWS Tab -->
                    <div id="tab-aws" class="tab-content hidden">
                        <h2 class="text-2xl font-bold font-outfit text-white mb-6">AWS S3 Settings</h2>
                        <div class="grid grid-cols-1 gap-6">
                            <div>
                                <label class="block text-sm font-medium text-slate-400 mb-2">Access Key ID</label>
                                <input type="text" name="AWS_ACCESS_KEY_ID" 
                                    class="w-full px-4 py-3 rounded-xl bg-white/5 border border-white/10 focus:border-indigo-500 outline-none text-white">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-slate-400 mb-2">Secret Access Key</label>
                                <div class="relative">
                                    <input type="password" name="AWS_SECRET_ACCESS_KEY" 
                                        class="w-full px-4 py-3 rounded-xl bg-white/5 border border-white/10 focus:border-indigo-500 outline-none text-white pr-10">
                                    <button type="button" onclick="togglePassword(this)" class="absolute right-3 top-1/2 -translate-y-1/2 text-slate-400 hover:text-white">
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
                            <div class="grid grid-cols-2 gap-6">
                                <div>
                                    <label class="block text-sm font-medium text-slate-400 mb-2">Default Region</label>
                                    <input type="text" name="AWS_DEFAULT_REGION" 
                                        class="w-full px-4 py-3 rounded-xl bg-white/5 border border-white/10 focus:border-indigo-500 outline-none text-white">
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-slate-400 mb-2">Bucket</label>
                                    <input type="text" name="AWS_BUCKET" 
                                        class="w-full px-4 py-3 rounded-xl bg-white/5 border border-white/10 focus:border-indigo-500 outline-none text-white">
                                </div>
                            </div>
                             <div>
                                <label class="block text-sm font-medium text-slate-400 mb-2">File Load Base URL</label>
                                <input type="text" name="AWS_FILE_LOAD_BASE" 
                                    class="w-full px-4 py-3 rounded-xl bg-white/5 border border-white/10 focus:border-indigo-500 outline-none text-white">
                            </div>
                            <div>
                                <label class="flex items-center gap-3">
                                    <input type="hidden" name="AWS_USE_PATH_STYLE_ENDPOINT" value="false">
                                    <input type="checkbox" name="AWS_USE_PATH_STYLE_ENDPOINT" value="true" class="w-5 h-5 rounded bg-white/5 border-white/10 text-indigo-500">
                                    <span class="text-sm font-medium text-slate-400">Use Path Style Endpoint</span>
                                </label>
                            </div>
                        </div>
                    </div>

                    <!-- Stripe Tab -->
                    <div id="tab-stripe" class="tab-content hidden">
                        <h2 class="text-2xl font-bold font-outfit text-white mb-6">Stripe Payment Settings</h2>
                        <div class="space-y-6">
                            <div>
                                <label class="block text-sm font-medium text-slate-400 mb-2">Stripe Key (Public)</label>
                                <input type="text" name="STRIPE_KEY" 
                                    class="w-full px-4 py-3 rounded-xl bg-white/5 border border-white/10 focus:border-indigo-500 outline-none text-white font-mono text-xs">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-slate-400 mb-2">Stripe Secret</label>
                                <div class="relative">
                                    <input type="password" name="STRIPE_SECRET" 
                                        class="w-full px-4 py-3 rounded-xl bg-white/5 border border-white/10 focus:border-indigo-500 outline-none text-white font-mono text-xs pr-10">
                                    <button type="button" onclick="togglePassword(this)" class="absolute right-3 top-1/2 -translate-y-1/2 text-slate-400 hover:text-white">
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
                            <div>
                                <label class="block text-sm font-medium text-slate-400 mb-2">Webhook Secret</label>
                                <div class="relative">
                                    <input type="password" name="STRIPE_WEBHOOK_SECRET" 
                                        class="w-full px-4 py-3 rounded-xl bg-white/5 border border-white/10 focus:border-indigo-500 outline-none text-white font-mono text-xs pr-10">
                                    <button type="button" onclick="togglePassword(this)" class="absolute right-3 top-1/2 -translate-y-1/2 text-slate-400 hover:text-white">
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
                        </div>
                    </div>

                    <!-- JWT Tab -->
                    <div id="tab-jwt" class="tab-content hidden">
                        <h2 class="text-2xl font-bold font-outfit text-white mb-6">JWT Authentication</h2>
                        <div class="space-y-6">
                            <div class="grid grid-cols-2 gap-6">
                                <div>
                                    <label class="block text-sm font-medium text-slate-400 mb-2">TTL (Minutes)</label>
                                    <input type="number" name="JWT_TTL" 
                                        class="w-full px-4 py-3 rounded-xl bg-white/5 border border-white/10 focus:border-indigo-500 outline-none text-white">
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-slate-400 mb-2">Refresh TTL (Minutes)</label>
                                    <input type="number" name="JWT_REFRESH_TTL" 
                                        class="w-full px-4 py-3 rounded-xl bg-white/5 border border-white/10 focus:border-indigo-500 outline-none text-white">
                                </div>
                            </div>
                             <div>
                                <label class="flex items-center gap-3">
                                    <input type="hidden" name="JWT_BLACKLIST_ENABLED" value="false">
                                    <input type="checkbox" name="JWT_BLACKLIST_ENABLED" value="true" class="w-5 h-5 rounded bg-white/5 border-white/10 text-indigo-500">
                                    <span class="text-sm font-medium text-slate-400">Enable Blacklist</span>
                                </label>
                            </div>
                        </div>
                    </div>

                    <!-- Twilio Tab -->
                    <div id="tab-twilio" class="tab-content hidden">
                        <h2 class="text-2xl font-bold font-outfit text-white mb-6">Twilio SMS Settings</h2>
                        <div class="space-y-6">
                            <div>
                                <label class="block text-sm font-medium text-slate-400 mb-2">Twilio SID</label>
                                <input type="text" name="TWILIO_SID" 
                                    class="w-full px-4 py-3 rounded-xl bg-white/5 border border-white/10 focus:border-indigo-500 outline-none text-white">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-slate-400 mb-2">Auth Token</label>
                                <div class="relative">
                                    <input type="password" name="TWILIO_AUTH_TOKEN" 
                                        class="w-full px-4 py-3 rounded-xl bg-white/5 border border-white/10 focus:border-indigo-500 outline-none text-white pr-10">
                                    <button type="button" onclick="togglePassword(this)" class="absolute right-3 top-1/2 -translate-y-1/2 text-slate-400 hover:text-white">
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
                            <div>
                                <label class="block text-sm font-medium text-slate-400 mb-2">Phone Number</label>
                                <input type="text" name="TWILIO_PHONE_NUMBER" 
                                    class="w-full px-4 py-3 rounded-xl bg-white/5 border border-white/10 focus:border-indigo-500 outline-none text-white">
                            </div>
                        </div>
                    </div>

                     <!-- Support Tab -->
                    <div id="tab-support" class="tab-content hidden">
                        <h2 class="text-2xl font-bold font-outfit text-white mb-6">Support & Contact</h2>
                        <div class="space-y-6">
                            <div>
                                <label class="block text-sm font-medium text-slate-400 mb-2">Admin Email</label>
                                <input type="email" name="ADMIN_EMAIL" 
                                    class="w-full px-4 py-3 rounded-xl bg-white/5 border border-white/10 focus:border-indigo-500 outline-none text-white">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-slate-400 mb-2">Support Email</label>
                                <input type="email" name="support_email" 
                                    class="w-full px-4 py-3 rounded-xl bg-white/5 border border-white/10 focus:border-indigo-500 outline-none text-white">
                            </div>
                        </div>
                    </div>

                    <!-- Google Tab -->
                    <div id="tab-google" class="tab-content hidden">
                        <h2 class="text-2xl font-bold font-outfit text-white mb-6">Google Authentication</h2>
                        <div class="space-y-6">
                            <div>
                                <div class="flex items-center justify-between mb-2">
                                    <label class="block text-sm font-medium text-slate-400">Google Client ID</label>
                                    <button type="button" onclick="openHelp('google')" class="text-[10px] bg-white/5 hover:bg-indigo-500/20 text-indigo-400 px-2 py-1 rounded border border-indigo-500/20 transition-all flex items-center gap-1">
                                        <svg xmlns="http://www.w3.org/2000/svg" class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                                        </svg>
                                        How to get this?
                                    </button>
                                </div>
                                <input type="text" name="google_client_id" 
                                    class="w-full px-4 py-3 rounded-xl bg-white/5 border border-white/10 focus:border-indigo-500 outline-none text-white font-mono text-sm" placeholder="xxxxxxxxxxxx-xxxxxxxxxxxxxxxx.apps.googleusercontent.com">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-slate-400 mb-2 font-outfit uppercase tracking-widest text-[10px]">Login Flow Preference</label>
                                <div class="relative">
                                    <input type="hidden" name="google_login_flow" id="google_login_flow" value="popup">
                                    <button type="button" onclick="toggleFlowDropdown('google')" id="google-flow-trigger" class="w-full px-4 py-3 rounded-xl bg-white/5 border border-white/10 hover:border-indigo-500/50 outline-none text-white text-left flex justify-between items-center transition-all">
                                        <span id="google-flow-label" class="flex items-center gap-3">
                                            <span class="p-1 rounded-lg bg-indigo-500/20 text-indigo-400">
                                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4" />
                                                </svg>
                                            </span>
                                            <div>
                                                <p class="text-sm font-bold">Popup Modal</p>
                                                <p class="text-[10px] text-slate-500">Opens in a separate window</p>
                                            </div>
                                        </span>
                                        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-slate-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                                        </svg>
                                    </button>

                                    <!-- Dropdown Menu -->
                                    <div id="google-flow-menu" class="hidden absolute top-full left-0 w-full mt-2 bg-slate-900 border border-white/10 rounded-2xl p-2 shadow-2xl z-50 backdrop-blur-xl">
                                        <button type="button" onclick="selectFlow('google', 'popup')" class="w-full p-3 rounded-xl hover:bg-white/5 flex items-center gap-3 text-left transition-all group">
                                            <span class="p-2 rounded-lg bg-slate-800 text-slate-400 group-hover:bg-indigo-500 group-hover:text-white transition-all">
                                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4" />
                                                </svg>
                                            </span>
                                            <div>
                                                <p class="text-sm font-bold text-white">Popup Modal</p>
                                                <p class="text-[10px] text-slate-500">Fastest setup (Recommended)</p>
                                            </div>
                                        </button>
                                        <button type="button" onclick="selectFlow('google', 'redirect')" class="w-full p-3 rounded-xl hover:bg-white/5 flex items-center gap-3 text-left transition-all group mt-1">
                                            <span class="p-2 rounded-lg bg-slate-800 text-slate-400 group-hover:bg-emerald-500 group-hover:text-white transition-all">
                                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14" />
                                                </svg>
                                            </span>
                                            <div>
                                                <p class="text-sm font-bold text-white">Full Page Redirect</p>
                                                <p class="text-[10px] text-slate-500">Best for complex configurations</p>
                                            </div>
                                        </button>
                                    </div>
                                </div>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-slate-400 mb-2 font-outfit uppercase tracking-widest text-[10px]">Google Client Secret</label>
                                <div class="relative group">
                                    <input type="password" name="google_client_secret" 
                                        class="w-full px-4 py-3 rounded-xl bg-white/5 border border-white/10 focus:border-indigo-500 outline-none text-white font-mono text-sm pr-12" placeholder="GOCSPX-xxxxxxxxxxxxxxxxxxxxxxxxxxxx">
                                    <button type="button" onclick="togglePassword(this)" class="absolute right-4 top-1/2 -translate-y-1/2 text-slate-500 hover:text-white transition-colors">
                                        <svg class="w-5 h-5 eye-icon" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                                        <svg class="w-5 h-5 eye-off-icon hidden" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l18 18"/></svg>
                                    </button>
                                </div>
                                <p class="text-[10px] text-slate-500 mt-2 italic px-1 font-medium">Required only for "Full Page Redirect" mode.</p>
                            </div>
                        </div>
                    </div>

                    <!-- Apple Tab -->
                    <div id="tab-apple" class="tab-content hidden">
                        <h2 class="text-2xl font-bold font-outfit text-white mb-6">Apple Authentication</h2>
                        <div class="space-y-6">
                            <div>
                                <div class="flex items-center justify-between mb-2">
                                    <label class="block text-sm font-medium text-slate-400">Apple Client ID (Service ID)</label>
                                    <button type="button" onclick="openHelp('apple')" class="text-[10px] bg-white/5 hover:bg-indigo-500/20 text-indigo-400 px-2 py-1 rounded border border-indigo-500/20 transition-all flex items-center gap-1">
                                        <svg xmlns="http://www.w3.org/2000/svg" class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                                        </svg>
                                        How to get this?
                                    </button>
                                </div>
                                <input type="text" name="apple_client_id" 
                                    class="w-full px-4 py-3 rounded-xl bg-white/5 border border-white/10 focus:border-indigo-500 outline-none text-white font-mono text-sm" placeholder="com.example.app.service">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-slate-400 mb-2 font-outfit uppercase tracking-widest text-[10px]">Login Flow Preference</label>
                                <div class="relative">
                                    <input type="hidden" name="apple_login_flow" id="apple_login_flow" value="popup">
                                    <button type="button" onclick="toggleFlowDropdown('apple')" id="apple-flow-trigger" class="w-full px-4 py-3 rounded-xl bg-white/5 border border-white/10 hover:border-indigo-500/50 outline-none text-white text-left flex justify-between items-center transition-all">
                                        <span id="apple-flow-label" class="flex items-center gap-3">
                                            <span class="p-1 rounded-lg bg-indigo-500/20 text-indigo-400">
                                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4" />
                                                </svg>
                                            </span>
                                            <div>
                                                <p class="text-sm font-bold">Popup Modal</p>
                                                <p class="text-[10px] text-slate-500">Opens in a separate window</p>
                                            </div>
                                        </span>
                                        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-slate-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                                        </svg>
                                    </button>

                                    <!-- Dropdown Menu -->
                                    <div id="apple-flow-menu" class="hidden absolute top-full left-0 w-full mt-2 bg-slate-900 border border-white/10 rounded-2xl p-2 shadow-2xl z-50 backdrop-blur-xl">
                                        <button type="button" onclick="selectFlow('apple', 'popup')" class="w-full p-3 rounded-xl hover:bg-white/5 flex items-center gap-3 text-left transition-all group">
                                            <span class="p-2 rounded-lg bg-slate-800 text-slate-400 group-hover:bg-indigo-500 group-hover:text-white transition-all">
                                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4" />
                                                </svg>
                                            </span>
                                            <div>
                                                <p class="text-sm font-bold text-white">Popup Modal</p>
                                                <p class="text-[10px] text-slate-500">Fastest setup (Recommended)</p>
                                            </div>
                                        </button>
                                        <button type="button" onclick="selectFlow('apple', 'redirect')" class="w-full p-3 rounded-xl hover:bg-white/5 flex items-center gap-3 text-left transition-all group mt-1">
                                            <span class="p-2 rounded-lg bg-slate-800 text-slate-400 group-hover:bg-emerald-500 group-hover:text-white transition-all">
                                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14" />
                                                </svg>
                                            </span>
                                            <div>
                                                <p class="text-sm font-bold text-white">Full Page Redirect</p>
                                                <p class="text-[10px] text-slate-500">Best for complex configurations</p>
                                            </div>
                                        </button>
                                    </div>
                                </div>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-slate-400 mb-2 font-outfit uppercase tracking-widest text-[10px]">Apple Client Secret</label>
                                <div class="relative group">
                                    <input type="password" name="apple_client_secret" 
                                        class="w-full px-4 py-3 rounded-xl bg-white/5 border border-white/10 focus:border-indigo-500 outline-none text-white font-mono text-sm pr-12" placeholder="Your Apple Client Secret">
                                    <button type="button" onclick="togglePassword(this)" class="absolute right-4 top-1/2 -translate-y-1/2 text-slate-500 hover:text-white transition-colors">
                                        <svg class="w-5 h-5 eye-icon" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                                        <svg class="w-5 h-5 eye-off-icon hidden" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l18 18"/></svg>
                                    </button>
                                </div>
                                <p class="text-[10px] text-slate-500 mt-2 italic px-1 font-medium">Required only if you are using Full Page Redirect.</p>
                            </div>
                        </div>
                    </div>

                    <div class="pt-8 flex justify-end border-t border-white/5 mt-8">
                        <button type="submit" class="px-8 py-3 rounded-xl bg-indigo-600 hover:bg-indigo-500 text-white font-bold shadow-lg shadow-indigo-500/30 transition-all flex items-center gap-2">
                             <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7H5a2 2 0 00-2 2v9a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-3m-1 4l-3 3m0 0l-3-3m3 3V4" />
                            </svg>
                            Save Configuration
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    @push('styles')
    <style>
        .active-tab {
            background: rgba(255, 255, 255, 0.05);
            color: #fff;
            border-left: 3px solid #6366f1;
        }
    </style>
    @endpush

    <script>
        function switchTab(tabId) {
            // Hide all contents
            document.querySelectorAll('.tab-content').forEach(el => el.classList.add('hidden'));
            
            // Show selected content
            document.getElementById('tab-' + tabId).classList.remove('hidden');
            
            // Update buttons
            document.querySelectorAll('.tab-btn').forEach(el => {
                el.classList.remove('active-tab');
                el.classList.add('text-slate-400');
            });
            
            // Activate selected button
            const activeBtn = document.getElementById('tab-btn-' + tabId);
            if(activeBtn) {
                activeBtn.classList.add('active-tab');
                activeBtn.classList.remove('text-slate-400');
            }

            // Update URL
            const url = new URL(window.location);
            url.searchParams.set('tab', tabId);
            window.history.pushState({}, '', url);
        }

        function getCookie(name) {
            const value = `; ${document.cookie}`;
            const parts = value.split(`; ${name}=`);
            if (parts.length === 2) return parts.pop().split(';').shift();
        }

        // Check URL on load
        document.addEventListener('DOMContentLoaded', () => {
            const urlParams = new URLSearchParams(window.location.search);
            const tab = urlParams.get('tab') || 'general';
            switchTab(tab);

            // Fetch Settings
            fetchSettings();

            // Handle Form Submission
            const form = document.querySelector('form');
            form.addEventListener('submit', async function(e) {
                e.preventDefault();
                
                const formData = new FormData(form);
                const settings = [];
                
                formData.forEach((value, key) => {
                     // Checkboxes with same name workaround:
                     // If we have a hidden input with value 0 and checkbox with same name,
                     // FormData includes both. The logic to handle this is usually backend or meticulous frontend.
                     // Here we just push exactly what form data gives, and backend should handle "last one wins" or similar.
                    if (key !== '_token' && value !== '' && value !== null) {
                        settings.push({ key: key, value: value });
                    }
                });

                try {
                    const response = await fetch("/api/admin/system-setting", {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'Accept': 'application/json',
                            'Authorization': 'Bearer ' + getCookie('admin_token')
                        },
                        body: JSON.stringify(settings)
                    });

                    const result = await response.json();

                    if (response.ok) {
                        // Show success message (simple alert or toast)
                        alert(result.message || 'Settings saved successfully');
                    } else {
                        alert('Error saving settings: ' + (result.message || 'Unknown error'));
                    }
                } catch (error) {
                    console.error('Error:', error);
                    alert('An unexpected error occurred.');
                }
            });
        });

        async function fetchSettings() {
             try {
                const response = await fetch("/api/admin/system-setting", {
                    method: 'GET',
                     headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'Authorization': 'Bearer ' + getCookie('admin_token')
                    }
                });

                if (!response.ok) throw new Error('Failed to fetch settings');

                const result = await response.json();
                const settings = result.data || result; // Handle both wrapped and unwrapped responses
                
                // Populate inputs
                for (const [key, value] of Object.entries(settings)) {
                    const input = document.querySelector(`[name="${key}"]`);
                    if (input) {
                        if (input.type === 'checkbox' || input.type === 'radio') {
                             if (input.value === value || (value === 'true' || value === '1')) {
                                 input.checked = true;
                             } else {
                                 input.checked = false;
                             }
                        } else {
                            input.value = value;
                            // SYNC UI for flow dropdowns
                            if (key === 'google_login_flow' || key === 'apple_login_flow') {
                                const type = key.split('_')[0];
                                selectFlow(type, value);
                            }
                        }
                    }
                }

            } catch (error) {
                console.error("Error fetching settings:", error);
            }
        }


        function togglePassword(btn) {
            const input = btn.previousElementSibling;
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

        const flowOptions = {
            popup: {
                label: 'Popup Modal',
                sub: 'Opens in a new window/tab (Recommended)',
                color: 'indigo'
            },
            redirect: {
                label: 'Full Page Redirect',
                sub: 'Replaces the current tab (Auth Code Flow)',
                color: 'emerald'
            }
        };

        function toggleFlowDropdown(type) {
            const menu = document.getElementById(`${type}-flow-menu`);
            document.querySelectorAll('[id$="-flow-menu"]').forEach(m => {
                if(m.id !== `${type}-flow-menu`) m.classList.add('hidden');
            });
            menu.classList.toggle('hidden');
        }

        function selectFlow(type, flow) {
            const input = document.getElementById(`${type}_login_flow`);
            const label = document.getElementById(`${type}-flow-label`);
            const opt = flowOptions[flow];
            
            input.value = flow;
            label.innerHTML = `
                <span class="p-1 rounded-lg bg-${opt.color}-500/20 text-${opt.color}-400">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4" />
                    </svg>
                </span>
                <div>
                    <p class="text-sm font-bold">${opt.label}</p>
                    <p class="text-[10px] text-slate-500">${opt.sub}</p>
                </div>
            `;
            
            document.getElementById(`${type}-flow-menu`).classList.add('hidden');
        }

        // Close dropdowns on outside click
        window.addEventListener('click', (e) => {
            if (!e.target.closest('[id$="-flow-trigger"]') && !e.target.closest('[id$="-flow-menu"]')) {
                document.querySelectorAll('[id$="-flow-menu"]').forEach(m => m.classList.add('hidden'));
            }
        });

        const helpDocs = {
            google: {
                title: 'Google Login Setup',
                link: 'https://console.cloud.google.com/apis/credentials',
                icon: `<svg class="w-6 h-6" viewBox="0 0 24 24" fill="currentColor"><path d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z"/><path d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z"/><path d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l3.66-2.84z"/><path d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z"/></svg>`,
                steps: [
                    "Go to the <span class='text-white font-bold'>Google Cloud Console</span>.",
                    "Create a new Project (or select an existing one).",
                    "Do <b>not</b> search the API Library. Instead, open the <span class='text-white font-bold'>Side Menu (≡)</span>.",
                    "Go to <span class='text-white font-bold'>APIs & Services > <a href='https://console.cloud.google.com/apis/credentials/consent' target='_blank' class='text-indigo-400 hover:underline'>OAuth consent screen</a></span> and set it up.",
                    "After setting up the consent screen, go to <span class='text-white font-bold'>Credentials > Create Credentials > OAuth client ID</span>.",
                    "Choose <span class='text-white font-bold'>Web application</span>.",
                    "Add your domain (e.g., <code class='bg-white/5 text-indigo-400 px-1.5 py-0.5 rounded border border-white/10 cursor-pointer hover:bg-indigo-500/10 transition-colors' onclick='copyText(\"http://localhost:3001\", this)'>http://localhost:3001</code>) to <span class='text-white font-bold'>Authorized JavaScript origins</span>.",
                    "Add <b>both</b> of these to <span class='text-white font-bold tracking-tight'>Authorized redirect URIs</span>:<br>1. <code class='bg-white/5 text-indigo-400 px-1.5 py-0.5 rounded border border-white/10 cursor-pointer hover:bg-indigo-500/10 transition-colors inline-block mt-1' onclick='copyText(\"http://localhost:3001/login\", this)'>http://localhost:3001/login</code><br>2. <code class='bg-white/5 text-indigo-400 px-1.5 py-0.5 rounded border border-white/10 cursor-pointer hover:bg-indigo-500/10 transition-colors inline-block mt-1' onclick='copyText(\"http://localhost:3001/register\", this)'>http://localhost:3001/register</code>",
                    "After saving, copy the <span class='text-white font-bold font-mono'>Client ID</span> and <span class='text-emerald-400 font-bold'>Client Secret</span> and paste them here."
                ]
            },
            apple: {
                title: 'Apple Login Setup',
                link: 'https://developer.apple.com/account/resources/identifiers/list',
                icon: `<svg class="w-6 h-6" viewBox="0 0 24 24" fill="currentColor"><path d="M17.05 20.28c-.96.95-2.04 1.72-3.41 1.72-1.33 0-1.78-.83-3.34-.83-1.58 0-2.08.81-3.32.81-1.33 0-2.4-.8-3.41-1.72-2.04-1.89-3.57-5.36-3.57-8.38 0-4.99 3.09-7.62 6.07-7.62 1.54 0 2.47.81 3.51.81.99 0 1.83-.81 3.53-.81 2.53 0 4.7 1.41 5.8 3.51-5.11 2.14-4.29 9.38 1.14 11.51zM12.03 4.39c-.11-2.52 1.95-4.63 4.27-4.39.24 2.62-2.12 4.91-4.27 4.39z"/></svg>`,
                steps: [
                    "Go to the <span class='text-white font-bold'>Apple Developer Console</span>.",
                    "Navigate to <span class='text-white font-bold'>Certificates, Identifiers & Profiles</span>.",
                    "Register a new <span class='text-white font-bold'>Services ID</span>.",
                    "Configure <span class='text-white font-bold'>Sign In with Apple</span> for your Services ID.",
                    "Enter your <span class='text-white font-bold'>Primary App ID</span>.",
                    "Add your <span class='text-indigo-400 font-bold'>domain</span> and <code class='bg-white/5 text-indigo-400 px-1.5 py-0.5 rounded border border-white/10 cursor-pointer hover:bg-indigo-500/10 transition-colors font-mono' onclick='copyText(\"http://localhost:3000/auth/apple/callback\", this)'>http://localhost:3000/auth/apple/callback</code> to <span class='text-white font-bold'>Return URLs</span>.",
                    "Copy the <span class='text-white font-bold font-mono'>Identifier</span> (this is your Apple Client ID) and paste it here."
                ]
            }
        };

        function copyText(text, el) {
            navigator.clipboard.writeText(text).then(() => {
                const original = el.innerHTML;
                el.innerHTML = "Copied!";
                el.classList.add('bg-green-500/20', 'text-green-400', 'border-green-500/20');
                el.classList.remove('text-indigo-400', 'bg-white/5');
                
                setTimeout(() => {
                    el.innerHTML = original;
                    el.classList.remove('bg-green-500/20', 'text-green-400', 'border-green-500/20');
                    el.classList.add('text-indigo-400', 'bg-white/5');
                }, 1000);
            });
        }

        function openHelp(type) {
            const doc = helpDocs[type];
            document.getElementById('help-title').innerText = doc.title;
            document.getElementById('help-icon').innerHTML = doc.icon;
            document.getElementById('help-link').href = doc.link;
            
            const content = document.getElementById('help-content');
            content.innerHTML = `
                <ul class="space-y-4 text-sm">
                    ${doc.steps.map((step, idx) => `
                        <li class="flex gap-4">
                            <span class="flex-shrink-0 w-6 h-6 rounded-full bg-slate-800 text-slate-400 flex items-center justify-center text-[10px] font-bold border border-white/5">${idx + 1}</span>
                            <span>${step}</span>
                        </li>
                    `).join('')}
                </ul>
            `;
            
            document.getElementById('help-modal').classList.remove('hidden');
        }

        function closeHelp() {
            document.getElementById('help-modal').classList.add('hidden');
        }
    </script>

    <!-- Help Modals -->
    <div id="help-modal" class="fixed inset-0 z-[100] hidden">
        <div class="absolute inset-0 bg-slate-950/80 backdrop-blur-sm" onclick="closeHelp()"></div>
        <div class="absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 w-full max-w-2xl bg-slate-900 border border-white/10 rounded-3xl p-8 shadow-2xl overflow-y-auto max-h-[90vh]">
            <div class="flex justify-between items-start mb-6">
                <div class="flex items-center gap-4">
                    <div id="help-icon" class="w-12 h-12 rounded-2xl flex items-center justify-center bg-indigo-500/20 text-indigo-400 shadow-lg shadow-indigo-500/20">
                        <!-- Icon injected by JS -->
                    </div>
                    <div>
                        <h3 id="help-title" class="text-xl font-bold text-white font-outfit">Configuration Guide</h3>
                        <p class="text-xs text-slate-500 mt-1 uppercase tracking-widest font-bold">Step-by-step instructions</p>
                    </div>
                </div>
                <button onclick="closeHelp()" class="p-2 rounded-xl hover:bg-white/5 text-slate-400 hover:text-white transition-all">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>

            <div id="help-content" class="text-slate-300 leading-relaxed py-4 border-y border-white/5 my-6">
                <!-- Content injected by JS -->
            </div>

            <div class="pt-6 flex justify-between items-center">
                <p class="text-[10px] text-slate-500 uppercase tracking-widest font-bold">Follow these steps carefully</p>
                <div class="flex items-center gap-3">
                    <button onclick="closeHelp()" class="px-6 py-2.5 rounded-xl bg-white/5 hover:bg-white/10 text-white text-sm font-semibold transition-all">
                        Close
                    </button>
                    <a id="help-link" href="#" target="_blank" class="px-6 py-2.5 rounded-xl bg-indigo-600 hover:bg-indigo-500 text-white text-sm font-bold shadow-lg shadow-indigo-500/30 transition-all flex items-center gap-2">
                        Open Developer Console
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14" />
                        </svg>
                    </a>
                </div>
            </div>
        </div>
    </div>
@endsection

