import { useEffect, useRef } from 'react'

const DEFAULT_INTERVAL_MS = 15_000
const subscribers = new Set()
let intervalId = null
let scheduledIntervalMs = null
let lastWakeRefreshAt = 0

function refreshVisibleSubscribers(force = false) {
    if (document.visibilityState !== 'visible') return

    const now = Date.now()
    subscribers.forEach((subscriber) => {
        if (subscriber.inFlight || (!force && now - subscriber.lastRunAt < subscriber.intervalMs)) return

        const controller = new AbortController()
        subscriber.controller = controller
        subscriber.inFlight = true
        subscriber.lastRunAt = now

        Promise.resolve()
            .then(() => subscriber.callbackRef.current?.(controller.signal))
            .catch(() => {})
            .finally(() => {
                subscriber.inFlight = false
                if (subscriber.controller === controller) subscriber.controller = null
            })
    })
}

function refreshOnWake() {
    const now = Date.now()
    // Focus and visibilitychange can fire together; coalesce them into one refresh.
    if (now - lastWakeRefreshAt < 1_000) return
    lastWakeRefreshAt = now
    refreshVisibleSubscribers(true)
}

function startScheduler() {
    if (subscribers.size === 0) return

    const interval = Math.min(...Array.from(subscribers, (subscriber) => subscriber.intervalMs))
    if (intervalId !== null && scheduledIntervalMs === interval) return

    if (intervalId !== null) window.clearInterval(intervalId)
    scheduledIntervalMs = interval
    intervalId = window.setInterval(() => refreshVisibleSubscribers(), interval)
    window.addEventListener('focus', refreshOnWake)
    document.addEventListener('visibilitychange', refreshOnWake)
}

function stopScheduler() {
    if (intervalId === null || subscribers.size > 0) return

    window.clearInterval(intervalId)
    intervalId = null
    scheduledIntervalMs = null
    window.removeEventListener('focus', refreshOnWake)
    document.removeEventListener('visibilitychange', refreshOnWake)
}

export default function useLiveRefresh(onRefresh, intervalMs = DEFAULT_INTERVAL_MS) {
    const callbackRef = useRef(onRefresh)

    useEffect(() => {
        callbackRef.current = onRefresh
    }, [onRefresh])

    useEffect(() => {
        const subscriber = {
            callbackRef,
            controller: null,
            inFlight: false,
            lastRunAt: Date.now(),
            intervalMs: Math.max(5_000, intervalMs),
        }

        subscribers.add(subscriber)
        startScheduler()

        return () => {
            subscribers.delete(subscriber)
            subscriber.controller?.abort()
            startScheduler()
            stopScheduler()
        }
    }, [intervalMs])
}
