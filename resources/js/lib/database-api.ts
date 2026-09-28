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

/**
 * Calls to the app's own databases, through the project's sandbox.
 */
export const databaseApi = {
    connections: (projectId: number) =>
        request<{ connections: DatabaseConnection[] }>(
            ProjectDatabaseController.connections.url(projectId),
        ).then((body) => body.connections),

    tables: (projectId: number, connection: string) =>
        request<{ tables: DatabaseTable[] }>(
            ProjectDatabaseController.tables.url(projectId, {
                query: { connection },
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
    ) => {
        const query: Record<string, string | number> = {
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
    ) =>
        request<{ inserted: number; updated: number; deleted: number }>(
            ProjectDatabaseController.change.url(projectId),
            { connection, table, ...changes },
        ),

    query: (projectId: number, connection: string, sql: string) =>
        request<DatabaseQueryResult>(
            ProjectDatabaseController.query.url(projectId),
            { connection, sql },
        ),
};
