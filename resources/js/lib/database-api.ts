import ProjectDatabaseController from '@/actions/App/Http/Controllers/ProjectDatabaseController';
import { jsonRequest as request } from '@/lib/json-request';
import type {
    DatabaseChanges,
    DatabaseConnection,
    DatabaseFilter,
    DatabaseQueryResult,
    DatabaseRows,
    DatabaseTable,
} from '@/types';

/** Whose database: the sandbox's, or the hosted app's (HOST-007). */
export type DatabaseLocation = 'sandbox' | 'hosted';

/**
 * Calls to the app's own databases, through the project's sandbox or its hosted app's machine.
 */
export const databaseApi = {
    connections: (projectId: number, where: DatabaseLocation = 'sandbox') =>
        request<{ connections: DatabaseConnection[] }>(
            ProjectDatabaseController.connections.url(projectId, {
                query: { where },
            }),
        ).then((body) => body.connections),

    tables: (
        projectId: number,
        connection: string,
        where: DatabaseLocation = 'sandbox',
    ) =>
        request<{ tables: DatabaseTable[] }>(
            ProjectDatabaseController.tables.url(projectId, {
                query: { connection, where },
            }),
        ).then((body) => body.tables),

    rows: (
        projectId: number,
        options: {
            connection: string;
            table: string;
            page: number;
            perPage: number;
            sort: { column: string; direction: 'asc' | 'desc' } | null;
            filters: DatabaseFilter[];
        },
        where: DatabaseLocation = 'sandbox',
    ) => {
        const query: Record<string, string | number> = {
            where,
            connection: options.connection,
            table: options.table,
            page: options.page,
            per_page: options.perPage,
        };

        if (options.sort) {
            query.sort = options.sort.column;
            query.direction = options.sort.direction;
        }

        options.filters.forEach((filter, index) => {
            query[`filters[${index}][column]`] = filter.column;
            query[`filters[${index}][operator]`] = filter.operator;
            query[`filters[${index}][value]`] = filter.value;
        });

        return request<DatabaseRows>(
            ProjectDatabaseController.rows.url(projectId, { query }),
        );
    },

    change: (
        projectId: number,
        connection: string,
        table: string,
        changes: DatabaseChanges,
        where: DatabaseLocation = 'sandbox',
    ) =>
        request<{ inserted: number; updated: number; deleted: number }>(
            ProjectDatabaseController.change.url(projectId),
            { connection, table, where, ...changes },
        ),

    query: (
        projectId: number,
        connection: string,
        sql: string,
        where: DatabaseLocation = 'sandbox',
    ) =>
        request<DatabaseQueryResult>(
            ProjectDatabaseController.query.url(projectId),
            { connection, sql, where },
        ),

    /** A query the project's AI writes from a plain-words request (DB-003); not run. */
    writeQuery: (
        projectId: number,
        options: {
            connection: string;
            driver: DatabaseConnection['driver'];
            request: string;
            current: string;
        },
        where: DatabaseLocation = 'sandbox',
    ) =>
        request<{ query: string }>(
            ProjectDatabaseController.writeQuery.url(projectId),
            { ...options, where },
        ).then((body) => body.query),

    /** A consistent copy of a SQLite database, as a short-lived download link. */
    download: (
        projectId: number,
        connection: string,
        where: DatabaseLocation = 'sandbox',
    ) =>
        request<{ url: string; name: string; bytes: number }>(
            ProjectDatabaseController.download.url(projectId, {
                query: { where },
            }),
            { connection },
        ),
};
