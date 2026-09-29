# App Storage

Follow this when the app needs to keep files: photos, avatars, videos, documents, attachments, exports. The
user manages them in the app builder's Tools → App Storage panel, where they can browse, upload and delete
them, so stick to the contract below.

## Buckets

- Files live in buckets: folders under `$APP_STORAGE_DIR` (it's `/data/storage`), so a bucket called
  `photos` is `$APP_STORAGE_DIR/photos`. Objects are the files inside, and may sit in sub-folders
  (`avatars/42.jpg`).
- Buckets are on the sandbox's disk but outside `/workspace`: they aren't part of the code, aren't in git,
  and survive rebuilds. The preview and the published app share them.
- The user creates buckets in the panel. If they named a bucket, use it. If none exists, create one with
  `mkdir -p "$APP_STORAGE_DIR/<name>"` (3–63 lowercase letters, digits and dashes) and tell them its name.
- Never store uploads in `/workspace` (`public/`, `storage/app`, `uploads/`): that's code, it bloats git and
  the user can't manage it from the panel.
- Always read the folder from the `APP_STORAGE_DIR` environment variable, never hard-code `/data/storage`.
  Fall back to a folder inside the app (e.g. Laravel's `storage/app/zap-storage`) only when it isn't set.

## How the app uses a bucket

By stack:

- **Laravel**: add a disk in `config/filesystems.php` and use `Storage::disk('photos')`:

    ```php
    'photos' => [
        'driver' => 'local',
        'root' => rtrim(env('APP_STORAGE_DIR', storage_path('app/zap-storage')), '/').'/photos',
        'throw' => true,
    ],
    ```

- **Node (Express, Next, Remix, …)**: one small module (e.g. `src/server/storage.ts`) that joins keys onto
  `path.join(process.env.APP_STORAGE_DIR ?? '.storage', 'photos')` and uses `node:fs/promises`. Reject keys
  containing `..` so a request can't reach outside the bucket.
- **Front-end-only apps (Vite)**: the browser can't reach the disk. Add a small server route (or switch to a
  stack with a backend) before storing files, and tell the user why in one sentence.

If the app already has this helper or disk, use it; don't add a second one.

## Rules for uploads

1. Validate on the server: allowed types (by content, not only the extension) and a size limit that suits
   the feature (e.g. 5 MB for avatars).
2. Name objects yourself: a random or record-based key with the original extension, grouped in folders
   (`avatars/<user id>.jpg`, `invoices/2026/<uuid>.pdf`). Keep the original file name in the database if
   the app shows it. Never use the uploaded name as the path.
3. Store the object's key in the database, not a full path or URL.
4. Serve files through an app route that streams them (`Storage::disk('photos')->response($key)` in
   Laravel), and check the viewer may see them when they're private. Send SVG and HTML as downloads, never
   inline.
5. Delete the object when its record is deleted or replaced.

## Finishing

Upload a test file through the app in the preview, check it shows up in the bucket
(`ls -R "$APP_STORAGE_DIR/<bucket>"`) and that the app shows or downloads it, then delete it. Tell the user in
one or two sentences what they can now upload, and that the files are in Tools → App Storage.
