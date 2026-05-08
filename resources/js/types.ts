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
    value: SnipNode;
};
