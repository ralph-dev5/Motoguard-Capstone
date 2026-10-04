import Echo from 'laravel-echo';

import Pusher from 'pusher-js';
window.Pusher = Pusher;

// Settings come from the page (see partials/head.blade.php), not from build-time variables, so
// the same built files work on the laptop and on the online host.
const reverb = window.reverbConfig ?? {};
const secure = window.location.protocol === 'https:';

window.Echo = new Echo({
    broadcaster: 'reverb',
    key: reverb.key ?? import.meta.env.VITE_REVERB_APP_KEY,
    // The address the page was opened from: Reverb runs on the same machine as the website, and a
    // phone on the WiFi must not be sent to its own 127.0.0.1.
    wsHost: window.location.hostname,
    // Online the host's proxy forwards the page's own HTTPS port to Reverb; on the laptop Reverb
    // listens on its own port.
    wsPort: reverb.port ?? import.meta.env.VITE_REVERB_PORT ?? 8080,
    wssPort: window.location.port || 443,
    forceTLS: secure,
    enabledTransports: ['ws', 'wss'],
});
