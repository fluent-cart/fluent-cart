import Rest from '@/utils/http/Rest';

/**
 * Module-level cache for the carrier endpoints, shared by every consumer so the
 * list is not refetched on each page or form mount.
 *
 * Two distinct endpoints, cached separately:
 *
 *   management (`shipping/carriers`)        — every carrier with its settings and
 *                                             field schema. Gated on store/sensitive.
 *   dispatch   (`shipping/carrier-options`) — every REGISTERED carrier (disabled
 *                                             included — the enable toggle governs
 *                                             checkout, not recording), slug + name
 *                                             + is_enabled. Gated on orders/manage_statuses,
 *                                             the same as creating a shipment.
 *
 * Pages that mutate carrier settings must call invalidateCarriersCache() after a
 * successful save: enabling or disabling a carrier changes what dispatch may offer.
 */
const cache = {
  management: null,
  dispatch: null,
};
const pending = {
  management: null,
  dispatch: null,
};

/**
 * Bumped on every invalidation. A response issued before the bump is stale by
 * definition — it may have read the row before a save committed — so it is
 * allowed to resolve its caller but never to repopulate the cache. Without this,
 * a save landing while a list request is in flight would seed the freshly
 * invalidated cache with pre-save data.
 */
let generation = 0;

/**
 * Per-variant issue counter. `generation` alone guards staleness-vs-invalidation,
 * NOT ordering: a forced refetch can put two requests in flight at the same
 * generation, and the one that RESOLVES last wins the cache even if it was issued
 * first. This makes the newest-issued request the only one allowed to write.
 */
const sequence = {
  management: 0,
  dispatch: 0,
};

const load = (variant, route, force) => {
  if (!force && cache[variant]) {
    return Promise.resolve(cache[variant]);
  }

  if (!force && pending[variant]) {
    return pending[variant];
  }

  const issuedAt = generation;
  const token = ++sequence[variant];

  const request = Rest.get(route)
      .then((response) => {
        const carriers = (response && Array.isArray(response.carriers)) ? response.carriers : [];
        if (issuedAt === generation && token === sequence[variant]) {
          cache[variant] = carriers;
        }
        return carriers;
      })
      .finally(() => {
        // Only clear the slot if it is still OURS — a forced refetch may have
        // replaced it, and nulling that newer request would strand its callers.
        if (pending[variant] === request) {
          pending[variant] = null;
        }
      });

  pending[variant] = request;

  return request;
};

/**
 * Full carrier list with settings — for the Carriers settings pages.
 */
export const fetchCarriers = ({ force = false } = {}) => {
  if (force) {
    cache.management = null;
  }
  return load('management', 'shipping/carriers', force);
};

/**
 * Registered carriers (slug + name only) — for the Add Shipment dropdown.
 */
export const fetchDispatchCarriers = ({ force = false } = {}) => {
  if (force) {
    cache.dispatch = null;
  }
  return load('dispatch', 'shipping/carrier-options', force);
};

export const invalidateCarriersCache = () => {
  generation++;
  cache.management = null;
  cache.dispatch = null;
  // Drop the in-flight requests too: a non-forced caller arriving after this would
  // otherwise be handed the pre-invalidation promise and render pre-save data.
  pending.management = null;
  pending.dispatch = null;
};
