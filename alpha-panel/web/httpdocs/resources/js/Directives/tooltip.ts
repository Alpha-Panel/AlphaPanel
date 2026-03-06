import type { Directive } from 'vue';

let tooltipEl: HTMLElement | null = null;
let currentTarget: HTMLElement | null = null;
let hideTimeout: ReturnType<typeof setTimeout> | null = null;

function getTooltipEl(): HTMLElement {
    if (!tooltipEl) {
        tooltipEl = document.createElement('div');
        tooltipEl.className = 'v-tooltip';
        document.body.appendChild(tooltipEl);
    }
    return tooltipEl;
}

function show(el: HTMLElement): void {
    const text = el.dataset.tooltip;
    if (!text) return;

    if (hideTimeout) {
        clearTimeout(hideTimeout);
        hideTimeout = null;
    }

    currentTarget = el;
    const tip = getTooltipEl();
    tip.textContent = '';

    const lines = text.split('\n');
    lines.forEach((line, i) => {
        if (i > 0) tip.appendChild(document.createElement('br'));
        tip.appendChild(document.createTextNode(line));
    });

    tip.classList.add('visible');

    const rect = el.getBoundingClientRect();
    tip.style.left = '0';
    tip.style.top = '0';

    const tipRect = tip.getBoundingClientRect();
    let top = rect.top - tipRect.height - 6;
    let left = rect.left + rect.width / 2 - tipRect.width / 2;

    // If overflows top, show below
    if (top < 4) {
        top = rect.bottom + 6;
        tip.classList.add('below');
    } else {
        tip.classList.remove('below');
    }

    // Clamp horizontally
    if (left < 4) left = 4;
    if (left + tipRect.width > window.innerWidth - 4) {
        left = window.innerWidth - tipRect.width - 4;
    }

    tip.style.left = `${left}px`;
    tip.style.top = `${top}px`;
}

function hide(el: HTMLElement): void {
    if (currentTarget !== el) return;
    const tip = getTooltipEl();
    tip.classList.remove('visible');
    currentTarget = null;
}

function onMouseEnter(this: HTMLElement): void {
    show(this);
}

function onMouseLeave(this: HTMLElement): void {
    hide(this);
}

const tooltip: Directive<HTMLElement, string> = {
    mounted(el, binding) {
        el.dataset.tooltip = binding.value ?? '';
        el.addEventListener('mouseenter', onMouseEnter);
        el.addEventListener('mouseleave', onMouseLeave);
    },
    updated(el, binding) {
        el.dataset.tooltip = binding.value ?? '';
        if (currentTarget === el) {
            show(el);
        }
    },
    beforeUnmount(el) {
        el.removeEventListener('mouseenter', onMouseEnter);
        el.removeEventListener('mouseleave', onMouseLeave);
        if (currentTarget === el) {
            hide(el);
        }
    },
};

export default tooltip;
