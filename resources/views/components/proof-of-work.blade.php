@if (\App\Support\Auth\ProofOfWork::enabled())
    <input type="hidden" name="pow" data-challenge="{{ \App\Support\Auth\ProofOfWork::challenge() }}">
    <noscript><p class="rounded-lg bg-red-50 p-3 text-sm text-red-800">{{ __('public.needs_javascript') }}</p></noscript>
    <p class="hidden text-sm text-kasmin-700" data-pow-wait role="status">{{ __('public.checking') }}</p>
    <script>
        (() => {
            const input = document.querySelector('input[name=pow]'), form = input.form, wait = form.querySelector('[data-pow-wait]');
            const challenge = input.dataset.challenge, bits = parseInt(challenge.split('.')[1], 10), encoder = new TextEncoder();
            const fits = (bytes) => { for (let i = 0; i < bits; i++) { if (bytes[i >> 3] >> (7 - i % 8) & 1) return false; } return true; };
            const work = (async () => {
                for (let n = 0; ; n++) {
                    const text = challenge + ':' + n;
                    if (fits(new Uint8Array(await crypto.subtle.digest('SHA-256', encoder.encode(text))))) return text;
                }
            })();
            form.addEventListener('submit', async (event) => {
                if (input.value) return;
                event.preventDefault();
                wait.classList.remove('hidden');
                input.value = await work;
                form.submit();
            });
            work.then((text) => { input.value = text; }).catch(() => {});
        })();
    </script>
@endif
