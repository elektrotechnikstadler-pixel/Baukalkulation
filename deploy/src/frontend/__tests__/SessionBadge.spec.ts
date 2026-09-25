// src/frontend/__tests__/SessionBadge.spec.ts
// Komponenten-Tests für SessionBadge.vue

import { describe, it, expect, beforeEach } from 'vitest';
import { mount }    from '@vue/test-utils';
import { setState } from '@core/store.ts';
import SessionBadge from '../components/SessionBadge.vue';
import type { BkUser } from '@core/store.ts';

// ── Test-Fixtures ─────────────────────────────────────────────

const adminUser: BkUser = {
    username: 'max.muster', role: 'admin', kuerzel: 'MM',
    permissions: {}, visibility: 'all',
    isSubunternehmer: false, dienstleisterId: null, stundenKategorie: '',
};

const masterUser: BkUser = {
    ...adminUser, role: 'master', kuerzel: 'SM', username: 'simon.m',
};

const normalUser: BkUser = {
    ...adminUser, role: 'normal', kuerzel: 'HK', username: 'hans.k',
};

beforeEach(() => {
    // Store zurücksetzen
    setState({ user: null, isOffline: false });
});

// ── Rendering ─────────────────────────────────────────────────

describe('SessionBadge – Rendering', () => {
    it('zeigt nichts wenn kein User eingeloggt', () => {
        const wrapper = mount(SessionBadge);
        expect(wrapper.find('.bk-session-badge').exists()).toBe(false);
    });

    it('zeigt User-Kürzel wenn User gesetzt ist', async () => {
        setState({ user: adminUser });
        const wrapper = mount(SessionBadge);
        await wrapper.vm.$nextTick();
        expect(wrapper.text()).toContain('MM');
    });

    it('zeigt Admin-Rollen-Tag für Admin-User', async () => {
        setState({ user: adminUser });
        const wrapper = mount(SessionBadge);
        await wrapper.vm.$nextTick();
        expect(wrapper.find('.bk-badge-role').text()).toBe('Admin');
    });

    it('zeigt Master-Rollen-Tag für Master-User', async () => {
        setState({ user: masterUser });
        const wrapper = mount(SessionBadge);
        await wrapper.vm.$nextTick();
        expect(wrapper.find('.bk-badge-role').text()).toBe('Master');
    });

    it('zeigt KEINEN Rollen-Tag für normale User', async () => {
        setState({ user: normalUser });
        const wrapper = mount(SessionBadge);
        await wrapper.vm.$nextTick();
        expect(wrapper.find('.bk-badge-role').exists()).toBe(false);
    });
});

// ── Online / Offline ──────────────────────────────────────────

describe('SessionBadge – Online/Offline', () => {
    it('zeigt Online-Status (grüner Punkt) wenn online', async () => {
        setState({ user: adminUser, isOffline: false });
        const wrapper = mount(SessionBadge);
        await wrapper.vm.$nextTick();
        expect(wrapper.find('.bk-badge-dot.is-online').exists()).toBe(true);
        expect(wrapper.find('.bk-badge-dot.is-offline').exists()).toBe(false);
    });

    it('zeigt Offline-Status (grauer Punkt) wenn offline', async () => {
        setState({ user: adminUser, isOffline: true });
        const wrapper = mount(SessionBadge);
        await wrapper.vm.$nextTick();
        expect(wrapper.find('.bk-badge-dot.is-offline').exists()).toBe(true);
        expect(wrapper.find('.bk-badge-dot.is-online').exists()).toBe(false);
    });

    it('reagiert reaktiv auf isOffline-Änderungen', async () => {
        setState({ user: adminUser, isOffline: false });
        const wrapper = mount(SessionBadge);
        await wrapper.vm.$nextTick();
        expect(wrapper.find('.bk-badge-dot.is-online').exists()).toBe(true);

        // Offline gehen
        setState({ isOffline: true });
        await wrapper.vm.$nextTick();
        expect(wrapper.find('.bk-badge-dot.is-offline').exists()).toBe(true);

        // Wieder online
        setState({ isOffline: false });
        await wrapper.vm.$nextTick();
        expect(wrapper.find('.bk-badge-dot.is-online').exists()).toBe(true);
    });
});

// ── Fallback auf Username ─────────────────────────────────────

describe('SessionBadge – Kürzel-Fallback', () => {
    it('zeigt username wenn kein Kürzel gesetzt', async () => {
        const noKuerzel: BkUser = { ...adminUser, kuerzel: '' };
        setState({ user: noKuerzel });
        const wrapper = mount(SessionBadge);
        await wrapper.vm.$nextTick();
        expect(wrapper.find('.bk-badge-name').text()).toBe('max.muster');
    });

    it('bevorzugt Kürzel über username', async () => {
        setState({ user: adminUser });
        const wrapper = mount(SessionBadge);
        await wrapper.vm.$nextTick();
        expect(wrapper.find('.bk-badge-name').text()).toBe('MM');
    });
});
