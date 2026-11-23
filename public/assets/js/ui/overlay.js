// Confirmation dialogs and side/bottom sheets, both on the native <dialog>
// element: it traps focus, closes on Esc and puts focus back where it was.

import { h } from '../core/dom.js';
import { icon } from './icons.js';

function openDialog(dialog, onClose) {
  const opener = document.activeElement;
  document.body.append(dialog);
  dialog.addEventListener('close', () => {
    dialog.remove();
    onClose?.();
    if (opener instanceof HTMLElement && opener.isConnected) opener.focus();
  });
  dialog.addEventListener('click', (event) => {
    if (event.target === dialog) dialog.close(); // a click on the backdrop
  });
  dialog.showModal();
}

/** Resolves true when the person confirms. confirm({ title, body, confirmLabel, danger }) */
export function confirmDialog({ title, body, confirmLabel = 'Confirm', cancelLabel = 'Cancel', danger = false }) {
  return new Promise((resolve) => {
    let answer = false;
    const titleId = `dlg-${Math.random().toString(36).slice(2)}`;
    const dialog = h('dialog', { class: 'modal', 'aria-labelledby': titleId });
    const confirmBtn = h('button', { type: 'button', class: `btn ${danger ? 'btn--danger' : 'btn--primary'}`, text: confirmLabel, onClick: () => { answer = true; dialog.close(); } });
    dialog.append(h('div', { class: 'modal__body' },
      h('h2', { id: titleId, text: title }),
      body ? h('p', { class: 'muted', text: body }) : null,
      h('div', { class: 'modal__actions' },
        h('button', { type: 'button', class: 'btn btn--secondary', text: cancelLabel, onClick: () => dialog.close() }),
        confirmBtn)));
    openDialog(dialog, () => resolve(answer));
    confirmBtn.focus();
  });
}

/**
 * openSheet({ title, side: 'right'|'left'|'bottom', body, footer }) → { close }
 * body/footer are nodes; the sheet closes on Esc, backdrop click or close().
 */
export function openSheet({ title, side = 'right', body, footer = null, className = '', onClose = null }) {
  const titleId = `sheet-${Math.random().toString(36).slice(2)}`;
  const dialog = h('dialog', { class: `sheet sheet--${side} ${className}`.trim(), 'aria-labelledby': titleId });
  dialog.append(h('div', { class: 'sheet__inner' },
    h('div', { class: 'sheet__head' },
      h('h2', { id: titleId, text: title }),
      h('button', { type: 'button', class: 'btn btn--ghost btn--icon', 'aria-label': 'Close', onClick: () => dialog.close() }, icon('close'))),
    h('div', { class: 'sheet__body' }, body),
    footer ? h('div', { class: 'sheet__foot' }, footer) : null));
  openDialog(dialog, onClose);
  return { close: () => dialog.open && dialog.close(), dialog };
}
