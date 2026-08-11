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

// A tap goes out as a Livewire request a beat after the click handler runs, so
// a focus event arriving with the tap — dismissing a wire:confirm hands focus
// back before the request is queued — can reach us first. Look again after
// this long before deciding the page really is idle.
const SETTLE_MS = 250

// A request that has been out this long is hung or lost. Waiting on it forever
// would leave the tab unable to ever reload itself again, which is the whole
// point of this file.
const IN_FLIGHT_TIMEOUT_MS = 15 * 1000

let lastFreshAt = Date.now()
let inFlight = 0
let inFlightSince = 0

function markFresh() {
    lastFreshAt = Date.now()
}

// A reload aborts whatever Livewire has in the air, and the tap that started it
// silently does nothing — no spinner, no error, just an entry that refuses to
// delete. Waiting costs nothing: the response marks the page fresh anyway.
function isBusy() {
    return inFlight > 0 && Date.now() - inFlightSince < IN_FLIGHT_TIMEOUT_MS
}

function isStale() {
    if (isBusy()) {
        return false
    }

    if (Date.now() - lastFreshAt < STALE_MS) {
        return false
    }

    // Reloading while offline just trades stale data for an error page.
    return navigator.onLine
}

function revive() {
    if (!isStale()) {
        return
    }

    setTimeout(() => {
        if (!isStale()) {
            return
        }

        // Marked before the request so a reload that takes a while to come back
        // doesn't immediately look stale again.
        markFresh()

        window.location.reload()
    }, SETTLE_MS)
}

document.addEventListener('visibilitychange', () => {
    document.visibilityState === 'visible' ? revive() : markFresh()
})

window.addEventListener('focus', revive)

// Losing focus is the start of the time away, not part of it. Without this a
// native dialog — confirm(), a permission prompt — spends its whole time on
// screen counting as drift, so dismissing it reloads the page and takes the
// pending tap with it.
window.addEventListener('blur', markFresh)

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
    window.Livewire.hook('commit', ({ respond }) => {
        if (inFlight === 0) {
            inFlightSince = Date.now()
        }

        inFlight++

        // Fires however the request ends — response, error or cancellation.
        respond(() => {
            inFlight = Math.max(0, inFlight - 1)
            markFresh()
        })
    })
})
