// Keeps a backgrounded tab from showing yesterday's data.
//
// The home-screen app on iOS is never really closed: tapping away freezes the
// page and tapping back thaws the exact pixels that were there before, without
// so much as a network request. If a session was started on another device in
// the meantime, the phone happily shows the old screen. So on every return to
// the foreground we check how long the page has been sitting there and, if it's
// had any time at all to drift, load it again from the server.

// Below this you've barely looked away — short enough that it only skips the
// reload for things like a permission prompt stealing focus for a moment.
const STALE_MS = 5 * 1000

let lastFreshAt = Date.now()

function markFresh() {
    lastFreshAt = Date.now()
}

function revive() {
    if (Date.now() - lastFreshAt < STALE_MS) {
        return
    }

    // Reloading while offline just trades stale data for an error page.
    if (!navigator.onLine) {
        return
    }

    // Marked before the request so a reload that takes a while to come back
    // doesn't immediately look stale again.
    markFresh()

    window.location.reload()
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
