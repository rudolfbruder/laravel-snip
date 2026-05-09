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
};

export type SnipPayload = {
    snips: SnipEntry[];
    timings: SnipTiming[];
    milestones: SnipMilestone[];
    config?: SnipConfig;
};

export type DataLayerEvent = {
    index: number;
    pushed_at_ms: number;
    payload: unknown;
};
