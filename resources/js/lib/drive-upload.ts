import { jsonRequest } from '@/lib/json-request';
import { store as storeFile } from '@/routes/drive/files';
import { store as storeFolder } from '@/routes/drive/folders';
import {
    part as uploadPart,
    store as startUpload,
} from '@/routes/drive/uploads';

/** A file Drive has, as the page knows it (DRIVE-001). */
export type DriveEntry = {
    id: number;
    name: string;
    folder: boolean;
    size: number;
    mime_type: string | null;
    revision: number;
    updated_at: string | null;
    updated_by: string | null;
    trashed_at?: string | null;
    trashed_by?: string | null;
    location?: string | null;
};

function xsrf(): string {
    return decodeURIComponent(
        document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]+)/)?.[1] ?? '',
    );
}

async function sendPart(
    organization: string,
    upload: string,
    index: number,
    body: Blob,
): Promise<void> {
    const response = await fetch(
        uploadPart.url({ organization, part: index }),
        {
            method: 'PUT',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/octet-stream',
                'X-XSRF-TOKEN': xsrf(),
                'X-Onedrop-Upload': upload,
            },
            credentials: 'same-origin',
            body,
        },
    );

    if (!response.ok) {
        const json = await response.json().catch(() => ({}));

        throw new Error(json.message ?? `Upload failed (${response.status})`);
    }
}

/**
 * Upload a file into a folder of a place (or its top), in parts of `partBytes` through the app (S3 multipart on S3),
 * reporting how many bytes went so far. A file already there by that name gets the new version.
 */
export async function uploadFile(
    organization: string,
    space: string,
    parentId: number | null,
    file: File,
    partBytes: number,
    onProgress: (sent: number) => void,
): Promise<DriveEntry> {
    const { upload } = await jsonRequest<{ upload: string }>(
        startUpload.url(organization),
        { size: file.size },
    );
    const parts = Math.max(1, Math.ceil(file.size / partBytes));

    for (let index = 0; index < parts && file.size > 0; index++) {
        const start = index * partBytes;

        await sendPart(
            organization,
            upload,
            index,
            file.slice(start, Math.min(file.size, start + partBytes)),
        );
        onProgress(Math.min(file.size, start + partBytes));
    }

    return jsonRequest<DriveEntry>(storeFile.url({ organization, space }), {
        upload,
        name: file.name,
        parent_id: parentId,
    });
}

/**
 * The folder at `path` ("Photos/2026") under a folder of a place, made where missing, remembered in `known` so a
 * folder of many files makes each folder once.
 */
export async function ensureFolders(
    organization: string,
    space: string,
    parentId: number | null,
    path: string,
    known: Map<string, number | null>,
): Promise<number | null> {
    let parent = parentId;
    let walked = '';

    for (const name of path.split('/').filter(Boolean)) {
        walked = walked ? `${walked}/${name}` : name;

        if (!known.has(walked)) {
            const folder = await jsonRequest<DriveEntry>(
                storeFolder.url({ organization, space }),
                { name, parent_id: parent, reuse: true },
            );
            known.set(walked, folder.id);
        }

        parent = known.get(walked) ?? null;
    }

    return parent;
}
