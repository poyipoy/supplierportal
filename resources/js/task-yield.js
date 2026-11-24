/**
 * ADASI Portal Supplier — Main-thread task yielding helper (`break-up-long-tasks`)
 *
 * Uses `scheduler.yield()` where available (preserving task continuation priority)
 * and falls back cleanly to `setTimeout(resolve, 0)`.
 */

export function yieldToMain() {
    if ('scheduler' in globalThis && globalThis.scheduler && 'yield' in globalThis.scheduler) {
        return globalThis.scheduler.yield();
    }

    return new Promise((resolve) => {
        setTimeout(resolve, 0);
    });
}
