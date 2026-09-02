/**
 * @author        Delescu Andrei Vlad <andrei.delescu@gmail.com>
 * @copyright     Copyright(c) 2026 Andrei-Vlad Delescu. All rights reserved.
 * @link          https://www.deless.ro/
 */

/**
 * Gate interaction.
 *
 * Every gate button carries data-airport, data-terminal, data-gate and
 * data-gate-type, and dispatches a gate:selected event. Allocation behaviour is
 * a later step and is intentionally not implemented here — listen for that
 * event rather than editing this file.
 *
 * A closed gate additionally carries its closure, and opens the detail panel.
 * Values the database does not hold arrive as the string "Unknown".
 */
const panel = () => document.getElementById('gate-closure-detail');

const showClosure = (button) => {
    const detail = panel();

    if (!detail) {
        return;
    }

    const set = (selector, value) => {
        const target = detail.querySelector(selector);

        if (target) {
            target.textContent = value ?? 'Unknown';
        }
    };

    set('[data-closure-gate]', button.dataset.gate);
    set('[data-closure-reason]', button.dataset.closureReason);
    set('[data-closure-from]', button.dataset.closureFrom);
    set('[data-closure-until]', button.dataset.closureUntil);

    detail.hidden = false;
    detail.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
};

document.addEventListener('click', (event) => {
    if (event.target.closest('[data-closure-dismiss]')) {
        const detail = panel();

        if (detail) {
            detail.hidden = true;
        }

        return;
    }

    const button = event.target.closest('[data-gate]');

    if (!button) {
        return;
    }

    if (button.dataset.gateClosed) {
        showClosure(button);
    } else if (panel()) {
        panel().hidden = true;
    }

    document.dispatchEvent(new CustomEvent('gate:selected', {
        detail: {
            airport: button.dataset.airport,
            terminal: button.dataset.terminal,
            gate: button.dataset.gate,
            type: button.dataset.gateType,
            closed: Boolean(button.dataset.gateClosed),
        },
    }));
});
