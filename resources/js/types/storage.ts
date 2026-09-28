export type StorageBucket = {
    name: string;
    objects: number;
    bytes: number;
};

export type StorageFolder = {
    name: string;
    path: string;
};

export type StorageObject = {
    name: string;
    /** Path inside the bucket, e.g. "cats/tabby.png". */
    path: string;
    size: number;
    /** Unix seconds. */
    modified: number;
};

export type StorageListing = {
    folders: StorageFolder[];
    objects: StorageObject[];
};
