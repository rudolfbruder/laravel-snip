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

export type SnipPayload = {
    snips: SnipEntry[];
    timings: SnipTiming[];
    milestones: SnipMilestone[];
    cache?: SnipCache;
    config?: SnipConfig;
};

export type DataLayerEvent = {
    index: number;
    pushed_at_ms: number;
    payload: unknown;
};
