import ProjectStorageController from '@/actions/App/Http/Controllers/ProjectStorageController';
import { askAgent } from '@/lib/ask-agent';
import { jsonRequest as request } from '@/lib/json-request';
import type { StorageBucket, StorageListing, StorageObject } from '@/types';

type Buckets = { buckets: StorageBucket[] };

const at = (project: number, bucket: string) => ({ project, bucket });

/**
 * Calls to the app's storage buckets, through the project's sandbox.
 */
export const storageApi = {
    buckets: (projectId: number) =>
        request<Buckets>(ProjectStorageController.index.url(projectId)).then(
            (body) => body.buckets,
        ),

    createBucket: (projectId: number, name: string) =>
        request<Buckets>(ProjectStorageController.store.url(projectId), {
            name,
        }).then((body) => body.buckets),

    deleteBucket: (projectId: number, bucket: string) =>
        request<Buckets>(
            ProjectStorageController.destroy.url(at(projectId, bucket)),
            {},
            'DELETE',
        ).then((body) => body.buckets),

    /** One folder, or with a search, matching objects anywhere in the bucket. */
    objects: (
        projectId: number,
        bucket: string,
        { prefix, search }: { prefix: string; search: string },
    ) =>
        request<StorageListing>(
            ProjectStorageController.objects.url(at(projectId, bucket), {
                query: search ? { search } : { prefix },
            }),
        ),

    upload: (projectId: number, bucket: string, path: string, file: File) => {
        const body = new FormData();
        body.append('path', path);
        body.append('file', file);

        return request<{ object: StorageObject }>(
            ProjectStorageController.upload.url(at(projectId, bucket)),
            body,
        );
    },

    createFolder: (projectId: number, bucket: string, path: string) =>
        request<{ path: string }>(
            ProjectStorageController.folder.url(at(projectId, bucket)),
            { path },
        ),

    /** Delete objects and folders, with everything in them. */
    delete: (projectId: number, bucket: string, paths: string[]) =>
        request<{ deleted: string[] }>(
            ProjectStorageController.destroyObject.url(at(projectId, bucket)),
            { paths },
            'DELETE',
        ),

    /** Move objects and folders into a folder ('' for the top of the bucket). */
    move: (projectId: number, bucket: string, paths: string[], to: string) =>
        request<{ moved: { from: string; to: string }[] }>(
            ProjectStorageController.move.url(at(projectId, bucket)),
            { paths, to },
        ),

    downloadUrl: (
        projectId: number,
        bucket: string,
        path: string,
        inline = false,
    ) =>
        ProjectStorageController.download.url(at(projectId, bucket), {
            query: inline ? { path, inline: 1 } : { path },
        }),

    askAgent: (projectId: number, bucket: string, uses: string) =>
        askAgent(ProjectStorageController.agent.url(at(projectId, bucket)), {
            uses,
        }),
};

/** Download an object through the browser, surfacing the server's message on failure. */
export async function downloadObject(
    projectId: number,
    bucket: string,
    object: StorageObject,
): Promise<void> {
    const response = await fetch(
        storageApi.downloadUrl(projectId, bucket, object.path),
        { credentials: 'same-origin', headers: { Accept: 'application/json' } },
    );

    await saveResponse(response, object.name);
}

/** Download objects and folders as one zip, named from the folder being viewed (`base`). */
export async function downloadZip(
    projectId: number,
    bucket: string,
    paths: string[],
    base: string,
): Promise<void> {
    const token = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]+)/)?.[1];
    const response = await fetch(
        ProjectStorageController.zip.url(at(projectId, bucket)),
        {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-XSRF-TOKEN': decodeURIComponent(token ?? ''),
            },
            body: JSON.stringify({ paths, base }),
        },
    );

    await saveResponse(
        response,
        `${base === '' ? bucket : (base.split('/').pop() ?? bucket)}.zip`,
    );
}

async function saveResponse(response: Response, name: string): Promise<void> {
    if (!response.ok) {
        const body = await response.json().catch(() => ({}));

        throw new Error(body.message ?? `Download failed (${response.status})`);
    }

    const url = URL.createObjectURL(await response.blob());
    const link = document.createElement('a');
    link.href = url;
    link.download = name;
    link.click();
    URL.revokeObjectURL(url);
}

/** "1.4 MB" style sizes. */
export function formatBytes(bytes: number): string {
    if (bytes < 1000) {
        return `${bytes} B`;
    }

    const units = ['KB', 'MB', 'GB', 'TB'];
    let value = bytes;
    let unit = -1;

    do {
        value /= 1000;
        unit++;
    } while (value >= 1000 && unit < units.length - 1);

    return `${value < 10 ? value.toFixed(1) : Math.round(value)} ${units[unit]}`;
}

const ADJECTIVES = [
    'bright',
    'calm',
    'clever',
    'cosmic',
    'gentle',
    'golden',
    'lucky',
    'mellow',
    'quiet',
    'rapid',
    'silver',
    'sunny',
    'swift',
    'tidy',
    'vivid',
    'witty',
];
const NOUNS = [
    'badger',
    'comet',
    'falcon',
    'harbor',
    'lantern',
    'maple',
    'meadow',
    'otter',
    'pebble',
    'river',
    'robin',
    'summit',
    'thistle',
    'tiger',
    'willow',
    'zephyr',
];

/** A friendly, valid bucket name like "swift-otter-42". */
export function suggestBucketName(): string {
    const pick = (list: string[]) =>
        list[Math.floor(Math.random() * list.length)];

    return `${pick(ADJECTIVES)}-${pick(NOUNS)}-${Math.floor(Math.random() * 90) + 10}`;
}

/** A dropped file and its path relative to the drop (folders included). */
export type DroppedFile = { file: File; path: string };

/**
 * The files in a drop, walking into dropped folders. Read the entries before
 * awaiting anything: the browser empties the DataTransfer after the event.
 */
export function droppedFiles(data: DataTransfer): Promise<DroppedFile[]> {
    const entries = Array.from(data.items)
        .map((item) => item.webkitGetAsEntry?.())
        .filter((entry): entry is FileSystemEntry => !!entry);

    if (entries.length === 0) {
        return Promise.resolve(
            Array.from(data.files).map((file) => ({ file, path: file.name })),
        );
    }

    const files: DroppedFile[] = [];

    const walk = async (entry: FileSystemEntry, prefix: string) => {
        if (entry.isFile) {
            const file = await new Promise<File>((resolve, reject) =>
                (entry as FileSystemFileEntry).file(resolve, reject),
            );
            files.push({ file, path: prefix + entry.name });

            return;
        }

        const reader = (entry as FileSystemDirectoryEntry).createReader();
        let batch: FileSystemEntry[];

        // readEntries returns at most ~100 entries per call.
        do {
            batch = await new Promise<FileSystemEntry[]>((resolve, reject) =>
                reader.readEntries(resolve, reject),
            );

            for (const child of batch) {
                await walk(child, `${prefix}${entry.name}/`);
            }
        } while (batch.length > 0);
    };

    return entries
        .reduce(
            (done, entry) => done.then(() => walk(entry, '')),
            Promise.resolve(),
        )
        .then(() => files);
}
