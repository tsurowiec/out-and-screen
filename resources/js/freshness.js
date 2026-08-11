// Keeps a backgrounded tab from showing yesterday's data.
//
// The home-screen app on iOS is never really closed: tapping away freezes the
// page and tapping back thaws the exact pixels that were there before, without
// so much as a network request. If a session was started on another device in
// the meantime, the phone happily shows the old screen. So on every return to
// the foreground we check how long the page has been sitting there and bring it
// back up to date.

// Below this you've barely looked away — short enough that it only skips the
// refresh for things like a permission prompt stealing focus for a moment.
const STALE_MS = 5 * 1000

// Past this the page is old enough that a component refresh isn't worth
// trusting: assets may have been redeployed, the session may have expired.
const RELOAD_MS = 30 * 60 * 1000

let lastFreshAt = Date.now()

function markFresh() {
    lastFreshAt = Date.now()
}

function age() {
    return Date.now() - lastFreshAt
}

/**
 * Re-render every Livewire component in place. Cheaper and far less jarring
 * than a reload — no white flash, scroll position kept.
 */
function refreshComponents() {
    if (!window.Livewire) {
        return false
    }

    const components = window.Livewire.all()

    if (!components.length) {
        return false
    }

    components.forEach((component) => component.$refresh())

    markFresh()

    return true
}

function revive() {
    const elapsed = age()

    if (elapsed < STALE_MS) {
        return
    }

    // Reloading while offline just trades stale data for an error page.
    if (elapsed < RELOAD_MS || !navigator.onLine) {
        if (refreshComponents()) {
            return
        }
    }

    if (navigator.onLine) {
        window.location.reload()
    }
}

document.addEventListener('visibilitychange', () => {
    document.visibilityState === 'visible' ? revive() : markFresh()
})

window.addEventListener('focus', revive)

// Restored from the back/forward cache, which is how iOS often resurrects the
// app. The DOM here is a snapshot of whenever it was frozen.
window.addEventListener('pageshow', (event) => {
    if (event.persisted) {
        revive()
    }
})

// A round trip to the server means what's on screen is current, whether it came
// from a wire:navigate or from a component updating itself.
document.addEventListener('livewire:navigated', markFresh)

document.addEventListener('livewire:init', () => {
    window.Livewire.hook('commit', ({ respond }) => respond(markFresh))
})
