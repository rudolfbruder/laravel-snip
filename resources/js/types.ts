export type SnipNode = {
    key?: string;
    type: string;
    preview: string;
    children?: SnipNode[];
    redacted?: boolean;
};

export type SnipEntry = {
    label: string | null;
    file: string | null;
    line: number | null;
    time_ms: number;
    bytes: number | null;
    value: SnipNode;
};

export type SnipTiming = {
    label: string;
    file: string | null;
    line: number | null;
    start_ms: number;
    duration_ms: number;
};

export type SnipMilestone = {
    label: string;
    file: string | null;
    line: number | null;
    time_ms: number;
};

export type SnipConfig = {
    datalayer: boolean;
    profiler?: boolean;
    /** The guest-link endpoint, set only for users who pass the gate itself. */
    guest_link_url?: string | null;
    /** True when the viewer got in through a guest link. */
    guest?: boolean;
    cache?: boolean;
    cache_value_url?: string | null;
    queue?: boolean;
    queue_url?: string | null;
    queue_driver?: string | null;
    queue_supports_listing?: boolean;
    queue_horizon?: boolean;
};

export type SnipQueueState = 'all' | 'failed' | 'pending' | 'scheduled' | 'completed';

export type SnipQueueItemState = 'failed' | 'pending' | 'scheduled' | 'completed';

export type SnipQueueItem = {
    id: string;
    name: string;
    queue: string;
    connection: string;
    attempts: number;
    failed_at?: string | null;
    available_at?: string | null;
    created_at?: string | null;
    reserved_at?: string | null;
    completed_at?: string | null;
    silenced?: boolean;
    state?: SnipQueueItemState;
    exception?: string | null;
    exception_full?: string | null;
    payload: SnipNode;
};

export type SnipQueueCounts = {
    failed: number | null;
    pending: number | null;
    scheduled: number | null;
    completed: number | null;
};

export type SnipQueueResponse = {
    state: SnipQueueState;
    supported: boolean;
    items: SnipQueueItem[];
    total: number;
    page: number;
    per_page: number;
    message: string | null;
    counts?: SnipQueueCounts;
};

export type SnipCacheKey = {
    key: string;
    ttl: number | null;
    bytes: number | null;
    hashed?: boolean;
};

export type SnipCache = {
    driver: string;
    prefix: string;
    supported: boolean;
    keys: SnipCacheKey[];
    truncated: boolean;
    message: string | null;
};

export type SnipProfileCounter = {
    kind: string;
    count: number;
    ms: number;
};

export type SnipProfile = {
    surface: string | null;
    method: string;
    url: string;
    /** Null on Inertia visits, whose payload is built before the response exists. */
    response: { status: number; bytes: number | null } | null;
    total_ms: number;
    peak_memory_mb: number;
    context: Record<string, unknown> | unknown[];
    /** `ms` is null when one of the phase's boundaries was never marked. */
    phases: Array<{ label: string; ms: number | null }>;
    totals: SnipProfileCounter[];
    cache: { hit: number; miss: number; write: number };
    steps: Array<{ label: string; depth: number; start_ms: number; duration_ms: number; counters: SnipProfileCounter[] }>;
    calls: Array<{ kind: string; at_ms: number; ms: number; label: string; extra: string; step: string }>;
    views: { count: number; first_at_ms: number | null; heaviest: Array<{ name: string; count: number; ms: number }> };
    redis_keys: Array<{ key: string; count: number; ms: number }>;
    queries: {
        count: number;
        slowest: Array<{ ms: number; sql: string; connection: string; step: string }>;
        repeated: Array<{ sql: string; count: number; ms: number }>;
    };
    /** The same profile as plain text. */
    text: string;
};

export type SnipPayload = {
    snips: SnipEntry[];
    timings: SnipTiming[];
    milestones: SnipMilestone[];
    profile?: SnipProfile | null;
    cache?: SnipCache;
    config?: SnipConfig;
};

export type DataLayerEvent = {
    index: number;
    pushed_at_ms: number;
    payload: unknown;
};
