import type { Directive } from 'vue';

const tooltip: Directive<HTMLElement, string> = {
    mounted(el, binding) {
        el.dataset.tooltip = binding.value ?? '';
        el.classList.add('has-tooltip');
    },
    updated(el, binding) {
        el.dataset.tooltip = binding.value ?? '';
    },
};

export default tooltip;
