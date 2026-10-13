/**
 * The face shown for a member who has not uploaded one.
 *
 * Served from `public/` rather than imported as a bundled asset: it is one
 * image referenced from a handful of places, and a plain path keeps it
 * replaceable by dropping a new file in without a rebuild.
 *
 * Kept here rather than repeated at each call site so that replacing it later
 * is one edit, and so the two places that draw a fallback face cannot drift
 * into showing different ones.
 */
export const DEFAULT_AVATAR = '/images/default-avatar.png'
