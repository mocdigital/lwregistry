/*
 * A short two-tone chime for a new notification.
 *
 * Synthesised with the Web Audio API so there is no asset to ship and nothing
 * for the CSP to block. The context is created on first use and resumed if the
 * browser suspended it; by the time a notification arrives the member has
 * almost always interacted with the page, so autoplay policy lets it through.
 *
 * The same tone the Livewire notification bell plays
 * (resources/views/livewire/notification-bell.blade.php), so both halves of the
 * app sound identical.
 */

type AudioContextClass = typeof AudioContext

let context: AudioContext | null = null

export function useNotificationSound(): { playChime: () => void } {
  function playChime(): void {
    try {
      const Ctx: AudioContextClass | undefined =
        window.AudioContext ??
        (window as unknown as { webkitAudioContext?: AudioContextClass }).webkitAudioContext
      if (!Ctx) return

      context = context ?? new Ctx()
      if (context.state === 'suspended') void context.resume()

      const now = context.currentTime
      ;([
        [880, 0],
        [1174.7, 0.12],
      ] as const).forEach(([freq, offset]) => {
        const osc = context!.createOscillator()
        const gain = context!.createGain()
        osc.type = 'sine'
        osc.frequency.value = freq
        gain.gain.setValueAtTime(0.0001, now + offset)
        gain.gain.exponentialRampToValueAtTime(0.18, now + offset + 0.02)
        gain.gain.exponentialRampToValueAtTime(0.0001, now + offset + 0.18)
        osc.connect(gain).connect(context!.destination)
        osc.start(now + offset)
        osc.stop(now + offset + 0.2)
      })
    } catch {
      // Sound is a nicety; a browser that refuses it changes nothing else.
    }
  }

  return { playChime }
}
