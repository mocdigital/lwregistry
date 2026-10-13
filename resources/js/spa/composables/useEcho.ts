import { onBeforeUnmount, ref, watch, type Ref } from 'vue'
import { usePage } from '@inertiajs/vue3'
import Echo from 'laravel-echo'
import Pusher from 'pusher-js'

/*
 * Laravel Echo over Reverb, for the Inertia front end.
 *
 * The Livewire side has its own copy of this in
 * `resources/views/partials/echo.blade.php`, loaded from a standalone script
 * because that half of the app has no bundle. This half does, so it imports
 * the packages that were already in package.json but never used.
 *
 * One connection per browser tab, created lazily on the first subscribe and
 * shared after that: a screen that never subscribes never opens a socket.
 */

type EchoClient = InstanceType<typeof Echo>
type ReverbConfig = NonNullable<ReturnType<typeof usePage>['props']['reverb']>

let client: EchoClient | null = null

function connect(config: ReverbConfig): EchoClient {
  if (client) return client

  // Echo's reverb broadcaster speaks the Pusher protocol; the client has to be
  // on `window` for it to find.
  ;(window as unknown as { Pusher: typeof Pusher }).Pusher = Pusher

  client = new Echo({
    broadcaster: 'reverb',
    key: config.key,
    wsHost: config.wsHost,
    wsPort: config.wsPort,
    wssPort: config.wssPort,
    forceTLS: config.forceTLS,
    enabledTransports: ['ws', 'wss'],
    authEndpoint: '/broadcasting/auth',
  })

  return client
}

/**
 * Subscribe to a private channel for as long as the component is alive.
 *
 * `channel` is a ref so a screen can follow a changing subject — the inbox
 * re-points at whichever conversation is open — and the old subscription is
 * dropped before the new one is made.
 *
 * Returns a ref that is true once the socket is up, so a screen can tell
 * "delivered live" from "will appear on the next load" rather than promising
 * realtime it may not have.
 */
export function usePrivateChannel(
  channel: Ref<string | null>,
  events: Record<string, (payload: never) => void>,
): Ref<boolean> {
  const connected = ref(false)
  let subscribed: string | null = null

  /*
   * Read once, here in setup. Null means no REVERB_APP_KEY is configured —
   * the normal local state — and then nothing ever connects: the caller sees
   * `connected` stay false and falls back to whatever the server rendered.
   */
  const config = usePage().props.reverb

  function leave() {
    if (subscribed && client) {
      client.leave(subscribed)
      subscribed = null
    }
    connected.value = false
  }

  function join(name: string | null) {
    leave()
    if (!name || !config) return

    const privateChannel = connect(config).private(name)
    Object.entries(events).forEach(([event, handler]) => {
      privateChannel.listen(`.${event}`, handler)
    })

    subscribed = name
    connected.value = true
  }

  watch(channel, join, { immediate: true })
  onBeforeUnmount(leave)

  return connected
}

/**
 * Subscribe to the signed-in user's private notification channel for as long as
 * the component is alive, using Echo's own `.notification()` mapping so the
 * event name matches Laravel's broadcast notifications exactly.
 *
 * Used by the header to light the unread badge and sound a chime the moment a
 * notification arrives, rather than only on the next page load.
 */
export function useUserNotifications(
  userId: Ref<string | number | null>,
  handler: (notification: { type?: string; [key: string]: unknown }) => void,
): void {
  const config = usePage().props.reverb
  let channelName: string | null = null

  function leave() {
    if (channelName && client) {
      client.leave(channelName)
      channelName = null
    }
  }

  function join(id: string | number | null) {
    leave()
    if (!id || !config) return

    const name = `App.Models.User.${id}`
    connect(config).private(name).notification(handler)
    channelName = name
  }

  watch(userId, join, { immediate: true })
  onBeforeUnmount(leave)
}
