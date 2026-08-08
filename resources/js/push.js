// Web Push enrolment for the installed app.
//
// iOS only grants any of this to a PWA launched from the Home Screen, and only
// asks for permission in response to a real tap — so everything here is driven
// from the button on the dashboard rather than run on page load.

const VAPID_KEY = document.querySelector('meta[name="vapid-public-key"]')?.content
const CSRF = () => document.querySelector('meta[name="csrf-token"]')?.content

/**
 * The push service wants the VAPID key as raw bytes, not the base64url text we
 * ship in the page.
 */
function urlBase64ToUint8Array(base64String) {
    const padding = '='.repeat((4 - (base64String.length % 4)) % 4)
    const base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/')
    const raw = window.atob(base64)

    return Uint8Array.from([...raw].map((char) => char.charCodeAt(0)))
}

export const push = {
    supported() {
        return 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window
    },

    /**
     * On iOS, push exists only in the home-screen app. In Safari itself the APIs
     * above are present but subscribing always fails, so the UI needs to tell
     * these two cases apart.
     */
    standalone() {
        return window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true
    },

    isIos() {
        return /iPad|iPhone|iPod/.test(navigator.userAgent)
    },

    permission() {
        return this.supported() ? Notification.permission : 'unsupported'
    },

    async registration() {
        return navigator.serviceWorker.register('/sw.js', { scope: '/' })
    },

    /**
     * Whether this device already has a live registration.
     */
    async subscribed() {
        if (!this.supported() || Notification.permission !== 'granted') {
            return false
        }

        const registration = await navigator.serviceWorker.getRegistration()

        return Boolean(await registration?.pushManager.getSubscription())
    },

    /**
     * Ask for permission, subscribe, and hand the result to the server. Must be
     * called straight from a click.
     */
    async subscribe() {
        if (!this.supported()) {
            throw new Error('This browser does not support notifications.')
        }

        if (!VAPID_KEY) {
            throw new Error('Push is not configured on the server yet.')
        }

        const permission = await Notification.requestPermission()

        if (permission !== 'granted') {
            // iOS never offers a second chance at the prompt, and won't list the
            // app under Settings › Notifications until it has been allowed once,
            // so the only way back is a reinstall.
            throw new Error(
                this.isIos()
                    ? 'Notifications were turned down. Remove Out&Screen from your Home Screen, add it again from Safari, and tap Enable.'
                    : 'Notifications are blocked. Allow them for this site in your browser settings.',
            )
        }

        const registration = await this.registration()

        // An existing registration is reused; re-subscribing with a different
        // VAPID key would be rejected, so drop it first if the key changed.
        let subscription = await registration.pushManager.getSubscription()

        if (!subscription) {
            subscription = await registration.pushManager.subscribe({
                userVisibleOnly: true,
                applicationServerKey: urlBase64ToUint8Array(VAPID_KEY),
            })
        }

        // toJSON() omits the payload encoding, so it's read off the push manager
        // and sent along — the server can't guess it correctly.
        const contentEncoding = (PushManager.supportedContentEncodings || ['aes128gcm'])[0]

        const response = await fetch('/push/subscribe', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF() },
            body: JSON.stringify({ ...subscription.toJSON(), contentEncoding }),
        })

        if (!response.ok) {
            throw new Error('Could not register this device.')
        }

        return true
    },

    async unsubscribe() {
        const registration = await navigator.serviceWorker.getRegistration()
        const subscription = await registration?.pushManager.getSubscription()

        if (!subscription) {
            return false
        }

        await fetch('/push/subscribe', {
            method: 'DELETE',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF() },
            body: JSON.stringify({ endpoint: subscription.endpoint }),
        })

        await subscription.unsubscribe()

        return true
    },
}

window.push = push
