/**
 * Live on/off state for a device badge.
 *
 * The server can only report what was true at render time, and a device that loses power sends
 * nothing to correct it, so a purely server-rendered badge keeps claiming "on" until the next
 * poll lands. Counting locally flips it the moment the threshold passes and keeps the elapsed
 * time honest between polls, without another query against a database a long way away.
 *
 * The wrapper's wire:key carries last_seen_at, so a fresh heartbeat replaces the node and this
 * re-initialises with the new timestamp; otherwise it just keeps ticking.
 */
function devicePresence({ lastSeen = null, threshold = 90, deviceId = null }) {
    return {
        lastSeen,
        threshold,
        deviceId,
        channel: null,
        now: Date.now() / 1000,
        timer: null,

        init() {
            // Twice a second: with a 3 s ping window, a once-a-second tick could show the badge
            // up to a full second late, which is most of the budget.
            this.timer = setInterval(() => {
                this.now = Date.now() / 1000;
            }, 500);

            this.listenForPings();
        },

        /**
         * The local clock can only ever count upwards, so on its own it will always reach the
         * threshold and claim the device is gone. Each ping arrives here over the websocket and
         * moves lastSeen forward, which is what keeps a live device reading "on" without the page
         * re-rendering or touching the database.
         */
        listenForPings() {
            if (this.deviceId === null || !window.Echo) {
                return;
            }

            this.channel = window.Echo.private(`devices.${this.deviceId}`);
            this.channel.listen('.device.pinged', (event) => {
                // Trust the server's clock, not the browser's: they can differ by seconds.
                this.lastSeen = event.at;
                this.now = Date.now() / 1000;
                this.skew = this.now - event.at;
            });
        },

        destroy() {
            clearInterval(this.timer);
            this.channel?.stopListening('.device.pinged');
        },

        /**
         * Difference between this browser's clock and the server's, learned from the first ping.
         * Without it a laptop a few seconds out of sync would show a live device as off forever.
         */
        skew: 0,

        /**
         * Kept fractional so the flip lands on the threshold itself rather than up to a second
         * late. Clamped at zero: a device clock running slightly ahead must not read as negative.
         */
        get secondsSince() {
            return this.lastSeen === null ? null : Math.max(0, this.now - this.lastSeen - this.skew);
        },

        /** Whole seconds, for display only. */
        get elapsedSeconds() {
            return this.secondsSince === null ? null : Math.floor(this.secondsSince);
        },

        get online() {
            return this.secondsSince !== null && this.secondsSince < this.threshold;
        },

        get label() {
            return this.online ? 'Device on' : 'Device off';
        },

        get detail() {
            if (this.lastSeen === null) {
                return 'No heartbeat received yet.';
            }

            return this.online
                ? `Last signal ${elapsed(this.elapsedSeconds)} ago`
                : `Silent for ${elapsed(this.elapsedSeconds)}`;
        },
    };
}

function elapsed(seconds) {
    if (seconds < 60) {
        return `${seconds}s`;
    }

    if (seconds < 3600) {
        const m = Math.floor(seconds / 60);

        return `${m}m ${seconds % 60}s`;
    }

    if (seconds < 86400) {
        const h = Math.floor(seconds / 3600);

        return `${h}h ${Math.floor((seconds % 3600) / 60)}m`;
    }

    const d = Math.floor(seconds / 86400);

    return `${d}d ${Math.floor((seconds % 86400) / 3600)}h`;
}

document.addEventListener('alpine:init', () => {
    window.Alpine.data('devicePresence', devicePresence);
});
