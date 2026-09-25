// ============================================================
// types.ts – Dashboard (TypeScript-Typen)
// ============================================================

export type DashboardItemTyp        = 'aufgabe' | 'notiz' | 'erinnerung';
export type DashboardItemStatus     = 'offen' | 'erledigt' | 'in_bearbeitung';
export type DashboardItemPrioritaet = 'normal' | 'niedrig' | 'mittel' | 'hoch' | 'dringend';

export interface DashboardItem {
    id:             number;
    typ:            DashboardItemTyp;
    titel:          string;
    beschreibung?:  string;
    status:         DashboardItemStatus;
    prioritaet:     DashboardItemPrioritaet;
    faelligAm?:     string;
    farbe?:         string;
    ersteller:      string;
    zugewiesen_an?: string;
    linkTyp?:       'baustelle';
    linkId?:        number;
    sortOrder?:     number;
    createdAt?:     string;
}

export interface LoadDashboardResponse {
    ok:         boolean;
    items?:     DashboardItem[];
    canManage?: boolean;
    error?:     string;
}

// ── Display-Konfiguration ─────────────────────────────────────

export const TYP_ICON: Record<DashboardItemTyp, string> = {
    aufgabe:    '📋',
    notiz:      '📝',
    erinnerung: '🔔',
};

export const PRIO_STYLE: Record<DashboardItemPrioritaet, { bg: string; color: string }> = {
    normal:   { bg: 'transparent',  color: 'transparent'  },
    niedrig:  { bg: '#F0FDF4',      color: '#15803D'      },
    mittel:   { bg: '#FEF3C7',      color: '#92400E'      },
    hoch:     { bg: '#FFEDD5',      color: '#C2410C'      },
    dringend: { bg: '#FEE2E2',      color: '#B91C1C'      },
};
