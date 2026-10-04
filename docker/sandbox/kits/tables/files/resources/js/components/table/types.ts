/**
 * The table kit's data, as the server sends it (app/Tables) and the grid uses it.
 */

export type FieldType =
    | 'text'
    | 'longText'
    | 'number'
    | 'currency'
    | 'percent'
    | 'checkbox'
    | 'date'
    | 'select'
    | 'multiSelect'
    | 'user'
    | 'link'
    | 'attachment'
    | 'rating'
    | 'url'
    | 'email'
    | 'phone'
    | 'formula'
    | 'lookup'
    | 'rollup'
    | 'count'
    | 'createdAt'
    | 'updatedAt';

export type ChoiceColor =
    | 'gray'
    | 'red'
    | 'orange'
    | 'amber'
    | 'yellow'
    | 'lime'
    | 'green'
    | 'teal'
    | 'cyan'
    | 'blue'
    | 'indigo'
    | 'violet'
    | 'purple'
    | 'pink';

export interface Choice {
    name: string;
    color: ChoiceColor;
}

export type RollupFunction =
    | 'sum'
    | 'average'
    | 'min'
    | 'max'
    | 'count'
    | 'countAll'
    | 'join'
    | 'earliest'
    | 'latest';

/** How a formula or rollup's result is shown. "auto" goes by the value. */
export type ResultFormat =
    | 'auto'
    | 'text'
    | 'number'
    | 'currency'
    | 'percent'
    | 'date';

export interface FieldOptions {
    /** select, multiSelect */
    choices?: Choice[];
    /** number, currency, percent, formula, rollup: decimal places */
    precision?: number;
    /** currency, and formula/rollup shown as currency */
    symbol?: string;
    /** date, createdAt, updatedAt */
    includeTime?: boolean;
    /** rating: number of stars */
    max?: number;
    /** link: the linked table's key */
    table?: string;
    /** link: whether it can link several records */
    multiple?: boolean;
    /** link: this is the other table's side of a link, worked out from it (read-only) */
    inverse?: boolean;
    /** link: whether the other table shows the records linking to it */
    showInverse?: boolean;
    /** link with inverse: the key of the link field (in options.table) this is the other side of */
    source?: string;
    /** lookup, rollup, count: the key of the link field to follow */
    link?: string;
    /** lookup, rollup: the key of the field in the linked table */
    field?: string;
    /** rollup */
    function?: RollupFunction;
    /** formula: the expression, with field names in braces, e.g. "{Value} * 2" */
    formula?: string;
    /** formula, rollup */
    format?: ResultFormat;
    /** lookup: how to show each value (the looked-up field's type; links and people come as their titles, as text) */
    result?: { type: FieldType; options: FieldOptions };
}

export interface Field {
    /** Stable key: the column name for fields the app was built with, "cf_<id>" for added ones. */
    key: string;
    name: string;
    type: FieldType;
    description: string | null;
    /** Built with the app: can't be renamed, retyped or deleted from the grid. */
    builtIn: boolean;
    /** The record's title: shown first, frozen, and used for links. */
    primary: boolean;
    /** Worked out (formula, lookup…) or locked by the app. */
    readOnly: boolean;
    options: FieldOptions;
}

export interface Attachment {
    key: string;
    name: string;
    size: number;
    type: string;
    url: string;
}

/**
 * A cell's value, by field type:
 * text, longText, url, email, phone, select: string | null
 * number, currency, percent (a fraction, 0.5 is 50%), rating: number | null
 * checkbox: boolean
 * date: "YYYY-MM-DD", or an ISO 8601 date and time when includeTime; createdAt, updatedAt: ISO 8601
 * multiSelect: string[]
 * user: user id | null
 * link: record ids in the linked table
 * attachment: Attachment[]
 * formula, rollup: number | string | boolean | null
 * lookup: the linked records' values, flattened
 * count: number
 */
export type CellValue =
    | string
    | number
    | boolean
    | null
    | string[]
    | number[]
    | Attachment[]
    | CellValue[];

export interface TableRecord {
    id: number;
    values: Record<string, CellValue>;
    /** Formula and rollup errors, by field key */
    errors?: Record<string, string>;
    createdAt: string;
    updatedAt: string;
}

export interface TableUser {
    id: number;
    name: string;
    email: string;
    avatar: string | null;
}

/** Titles of a linked table's records, for showing link cells. */
export interface LinkedTable {
    name: string;
    records: { id: number; title: string }[];
}

export interface TableSummary {
    key: string;
    name: string;
    fields: { key: string; name: string; type: FieldType }[];
}

export type ViewType =
    | 'grid'
    | 'board'
    | 'calendar'
    | 'gallery'
    | 'timeline'
    | 'form';

export type TimelineScale = 'day' | 'week' | 'month' | 'quarter';

export interface FormField {
    key: string;
    required: boolean;
    /** A help line shown under the field */
    help: string;
}

/** What a form view asks for (TABLE-009). */
export interface FormConfig {
    title: string;
    description: string;
    /** The fields the form asks for, in order */
    fields: FormField[];
    submitLabel: string;
    /** Shown after sending */
    thankYou: string;
    /** Offer "Send another" after sending */
    allowAnother: boolean;
    /** Anyone with the public link can send it */
    public: boolean;
}

export type Conjunction = 'and' | 'or';

/**
 * Filter operators. Which apply depends on the field's type (filters.ts).
 */
export type FilterOperator =
    | 'contains'
    | 'notContains'
    | 'is'
    | 'isNot'
    | 'isEmpty'
    | 'isNotEmpty'
    | 'eq'
    | 'neq'
    | 'lt'
    | 'lte'
    | 'gt'
    | 'gte'
    | 'isBefore'
    | 'isAfter'
    | 'isOnOrBefore'
    | 'isOnOrAfter'
    | 'isWithin'
    | 'isAnyOf'
    | 'isNoneOf'
    | 'hasAnyOf'
    | 'hasAllOf'
    | 'hasNoneOf'
    | 'isMe';

export interface FilterCondition {
    /** Stable id, for React keys */
    id: string;
    field: string;
    operator: FilterOperator;
    value?: CellValue;
}

export interface Filters {
    conjunction: Conjunction;
    conditions: FilterCondition[];
}

export interface SortRule {
    field: string;
    direction: 'asc' | 'desc';
}

export type SummaryFunction =
    | 'none'
    | 'filled'
    | 'empty'
    | 'unique'
    | 'percentFilled'
    | 'sum'
    | 'average'
    | 'min'
    | 'max'
    | 'earliest'
    | 'latest'
    | 'checked'
    | 'unchecked';

export type RowHeight = 'short' | 'medium' | 'tall' | 'extraTall';

export interface ViewConfig {
    filters: Filters;
    sorts: SortRule[];
    /** Up to three levels */
    groups: SortRule[];
    /** Field keys hidden in this view */
    hidden: string[];
    /** Field keys in the order shown; fields missing from it go at the end */
    order: string[];
    /** Column widths in pixels, by field key */
    widths: Record<string, number>;
    rowHeight: RowHeight;
    summaries: Record<string, SummaryFunction>;
    /** board: the single select or person field the columns come from */
    stackBy: string | null;
    /** calendar: the date field records sit on */
    dateField: string | null;
    /** gallery, board: the attachment field shown as each card's cover */
    coverField: string | null;
    /** timeline: the date field bars end on (dateField is where they start); null for one-day bars */
    endField: string | null;
    /** timeline */
    timelineScale: TimelineScale;
    /** form views only */
    form: FormConfig | null;
}

export interface TableView {
    id: number;
    name: string;
    type: ViewType;
    /** Only its creator sees it */
    personal: boolean;
    config: ViewConfig;
    /** form: the form's page for signed-in people */
    formUrl?: string | null;
    /** form: the public link, while form.public is on */
    publicFormUrl?: string | null;
}

/**
 * The props of the form page (resources/js/pages/tables/form.tsx): a form view to fill in, signed in or
 * through its public link.
 */
export interface FormPageData {
    tableName: string;
    title: string;
    description: string;
    /** The form's fields, in order, as the table defines them */
    fields: (Field & { required: boolean; help: string })[];
    submitLabel: string;
    thankYou: string;
    allowAnother: boolean;
    /** POST { values } here; 422 errors are keyed by field key */
    submitUrl: string;
    /** POST a file here (multipart "file") for attachment fields */
    uploadUrl: string;
    /** For person fields (signed-in forms only) */
    users: TableUser[];
    /** Titles for link fields (signed-in forms only) */
    linked: Record<string, LinkedTable>;
    public: boolean;
}

export interface TablePermissions {
    edit: boolean;
    create: boolean;
    delete: boolean;
    manageFields: boolean;
    manageViews: boolean;
    comment: boolean;
}

/** What a page passes to <DataTable>, from Table::props() on the server. */
export interface TableData {
    key: string;
    name: string;
    /** Base URL of the table's endpoints, e.g. "/tables/deals" */
    endpoint: string;
    fields: Field[];
    records: TableRecord[];
    views: TableView[];
    users: TableUser[];
    linked: Record<string, LinkedTable>;
    /** Every table the user can see (this one included), for picking what a link, lookup or rollup points to */
    tables: TableSummary[];
    can: TablePermissions;
    /** The signed-in user's id */
    me: number | null;
    /** More records exist than were sent (tables.max_rows) */
    truncated: boolean;
}

export interface RecordChanges {
    creates?: { values: Record<string, CellValue> }[];
    updates?: { id: number; values: Record<string, CellValue> }[];
    deletes?: number[];
}

/** The answer to a batch of changes: the created and updated records, worked out again. */
export interface RecordChangesResult {
    records: TableRecord[];
    deleted: number[];
}

export interface FieldInput {
    name: string;
    type: FieldType;
    description?: string | null;
    options?: FieldOptions;
    /** select, multiSelect: options renamed, old name => new name, so records keep them */
    renames?: Record<string, string>;
}

export interface FormulaPreview {
    ok: boolean;
    error?: string;
    results?: {
        id: number;
        title: string;
        value: CellValue;
        error: string | null;
    }[];
}

export interface ActivityItem {
    id: string;
    kind: 'comment' | 'created' | 'updated' | 'deleted';
    user: { id: number; name: string; avatar: string | null } | null;
    createdAt: string;
    /** comment */
    commentId?: number;
    body?: string;
    /** created: the name of the form view the record was sent through ("created through the form <via>"); user is null for public forms */
    via?: string;
    /** updated */
    field?: string;
    fieldName?: string;
    from?: string | null;
    to?: string | null;
}

/** Sent on the private channel "tables.{key}" as ".table.changed". */
export interface TableChangedEvent {
    /** Records created or updated */
    records?: number[];
    deleted?: number[];
    /** Fields, views or linked tables changed: fetch the whole table again */
    reload?: boolean;
}
