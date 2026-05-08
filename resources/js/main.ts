import { defineCustomElement } from 'vue';
import SnipPanel from './SnipPanel.ce.vue';

const SnipElement = defineCustomElement(SnipPanel);

if (typeof window !== 'undefined' && !window.customElements.get('laravel-snip')) {
    window.customElements.define('laravel-snip', SnipElement);
}
