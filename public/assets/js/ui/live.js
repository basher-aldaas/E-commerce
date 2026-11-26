// The "Live" badge next to a page title: shows whether the WebSocket to Pusher
// is connected, so it is visible when the list is updating by itself.

import { h } from '../core/dom.js';
import { liveState, onLiveState } from '../core/realtime.js';

const LOOK = {
  live: { tone: 'ok', text: 'Live' },
  connecting: { tone: 'soon', text: 'Connecting…' },
  unavailable: { tone: 'unknown', text: 'Live updates unavailable' },
  off: { tone: 'unknown', text: 'Live updates off' },
};

export function liveBadge(ctx) {
  const el = h('span', { class: 'badge live-badge', role: 'status', title: 'Updates arrive over a WebSocket (Pusher) without reloading.' });
  const paint = (state) => {
    const look = LOOK[state] || LOOK.off;
    el.className = `badge badge--${look.tone} live-badge${state === 'live' ? ' is-live' : ''}`;
    el.textContent = look.text;
  };
  paint(liveState());
  ctx.onLeave(onLiveState(paint));
  return el;
}

/** Briefly highlights a table row that just changed. */
export function flash(row) {
  row.classList.remove('is-fresh');
  void row.offsetWidth; // restart the animation
  row.classList.add('is-fresh');
}
