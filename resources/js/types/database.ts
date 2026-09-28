export type DatabaseConnection = {
    id: string;
    driver: 'sqlite' | 'pgsql' | 'mysql';
    label: string;
    summary: string;
    source: string | null;
    error: string | null;
};

export type DatabaseTable = {
    name: string;
    type: 'table' | 'view';
    columns: string[];
};

export type DatabaseColumn = {
    name: string;
    type: string;
    nullable: boolean;
    default: string | null;
    primary: boolean;
    auto: boolean;
};

/** Binary or very long values arrive as a read-only preview. */
export type DatabasePreview = { preview: string | null; bytes: number };

export type DatabaseValue = string | number | boolean | null | DatabasePreview;

export type DatabaseRow = Record<string, DatabaseValue>;

export type DatabaseFilterOperator =
    | 'eq'
    | 'neq'
    | 'gt'
    | 'gte'
    | 'lt'
    | 'lte'
    | 'contains'
    | 'null'
    | 'notnull';

export type DatabaseFilter = {
    column: string;
    operator: DatabaseFilterOperator;
    value: string;
};

export type DatabaseRows = {
    table: string;
    type: 'table' | 'view';
    columns: DatabaseColumn[];
    rows: DatabaseRow[];
    total: number;
    page: number;
    per_page: number;
};

export type DatabaseChanges = {
    inserts: Record<string, DatabaseValue>[];
    updates: {
        key: Record<string, DatabaseValue>;
        values: Record<string, DatabaseValue>;
    }[];
    deletes: Record<string, DatabaseValue>[];
};

export type DatabaseQueryResult = {
    columns: string[];
    rows: DatabaseValue[][];
    truncated: boolean;
    affected: number | null;
    duration_ms: number;
};
