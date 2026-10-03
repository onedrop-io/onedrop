import {
    Braces,
    Container,
    Database,
    File,
    FileArchive,
    FileAudio,
    FileCode,
    FileCog,
    FileImage,
    FileKey,
    FileLock,
    FileSpreadsheet,
    FileText,
    FileTerminal,
    FileType,
    FileVideo,
    Folder,
    FolderOpen,
    Package,
    Palette,
    Settings,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { cn } from '@/lib/utils';

type IconStyle = { icon: LucideIcon; color: string };

const code = (color: string): IconStyle => ({ icon: FileCode, color });

/** Exact file names, checked before extensions. */
const BY_NAME: Record<string, IconStyle> = {
    'package.json': { icon: Package, color: 'text-red-500' },
    'composer.json': { icon: Package, color: 'text-amber-600' },
    'package-lock.json': { icon: FileLock, color: 'text-red-400/70' },
    'composer.lock': { icon: FileLock, color: 'text-amber-600/70' },
    'pnpm-lock.yaml': { icon: FileLock, color: 'text-orange-400/70' },
    'yarn.lock': { icon: FileLock, color: 'text-sky-500/70' },
    dockerfile: { icon: Container, color: 'text-sky-500' },
    '.gitignore': { icon: FileCog, color: 'text-orange-600' },
    '.gitattributes': { icon: FileCog, color: 'text-orange-600' },
    artisan: { icon: FileTerminal, color: 'text-red-500' },
};

const BY_EXTENSION: Record<string, IconStyle> = {
    js: code('text-yellow-500'),
    mjs: code('text-yellow-500'),
    cjs: code('text-yellow-500'),
    jsx: code('text-cyan-500'),
    ts: code('text-blue-500'),
    mts: code('text-blue-500'),
    tsx: code('text-cyan-500'),
    vue: code('text-emerald-500'),
    svelte: code('text-orange-500'),
    php: code('text-indigo-400'),
    py: code('text-sky-600'),
    rb: code('text-red-600'),
    go: code('text-cyan-600'),
    rs: code('text-orange-700'),
    java: code('text-red-500'),
    html: code('text-orange-500'),
    xml: code('text-orange-400'),
    css: { icon: Palette, color: 'text-sky-500' },
    scss: { icon: Palette, color: 'text-pink-500' },
    sass: { icon: Palette, color: 'text-pink-500' },
    less: { icon: Palette, color: 'text-indigo-500' },
    json: { icon: Braces, color: 'text-amber-500' },
    yml: { icon: FileCog, color: 'text-rose-500' },
    yaml: { icon: FileCog, color: 'text-rose-500' },
    toml: { icon: FileCog, color: 'text-stone-500' },
    ini: { icon: Settings, color: 'text-stone-500' },
    md: { icon: FileText, color: 'text-sky-400' },
    mdx: { icon: FileText, color: 'text-amber-400' },
    txt: { icon: FileText, color: 'text-muted-foreground' },
    sh: { icon: FileTerminal, color: 'text-green-500' },
    bash: { icon: FileTerminal, color: 'text-green-500' },
    zsh: { icon: FileTerminal, color: 'text-green-500' },
    sql: { icon: Database, color: 'text-fuchsia-500' },
    sqlite: { icon: Database, color: 'text-fuchsia-500' },
    png: { icon: FileImage, color: 'text-purple-500' },
    jpg: { icon: FileImage, color: 'text-purple-500' },
    jpeg: { icon: FileImage, color: 'text-purple-500' },
    gif: { icon: FileImage, color: 'text-purple-500' },
    webp: { icon: FileImage, color: 'text-purple-500' },
    ico: { icon: FileImage, color: 'text-purple-500' },
    avif: { icon: FileImage, color: 'text-purple-500' },
    heic: { icon: FileImage, color: 'text-purple-500' },
    svg: { icon: FileImage, color: 'text-amber-500' },
    mp4: { icon: FileVideo, color: 'text-pink-500' },
    mov: { icon: FileVideo, color: 'text-pink-500' },
    webm: { icon: FileVideo, color: 'text-pink-500' },
    m4v: { icon: FileVideo, color: 'text-pink-500' },
    mkv: { icon: FileVideo, color: 'text-pink-500' },
    mp3: { icon: FileAudio, color: 'text-teal-500' },
    wav: { icon: FileAudio, color: 'text-teal-500' },
    m4a: { icon: FileAudio, color: 'text-teal-500' },
    ogg: { icon: FileAudio, color: 'text-teal-500' },
    flac: { icon: FileAudio, color: 'text-teal-500' },
    pdf: { icon: FileText, color: 'text-red-500' },
    doc: { icon: FileText, color: 'text-blue-600' },
    docx: { icon: FileText, color: 'text-blue-600' },
    rtf: { icon: FileText, color: 'text-blue-600' },
    csv: { icon: FileSpreadsheet, color: 'text-green-600' },
    xls: { icon: FileSpreadsheet, color: 'text-green-600' },
    xlsx: { icon: FileSpreadsheet, color: 'text-green-600' },
    ppt: { icon: FileText, color: 'text-orange-600' },
    pptx: { icon: FileText, color: 'text-orange-600' },
    woff: { icon: FileType, color: 'text-red-400' },
    woff2: { icon: FileType, color: 'text-red-400' },
    ttf: { icon: FileType, color: 'text-red-400' },
    zip: { icon: FileArchive, color: 'text-amber-700' },
    gz: { icon: FileArchive, color: 'text-amber-700' },
    tar: { icon: FileArchive, color: 'text-amber-700' },
    lock: { icon: FileLock, color: 'text-muted-foreground' },
};

const FOLDER_COLORS: Record<string, string> = {
    src: 'text-emerald-500',
    app: 'text-emerald-500',
    public: 'text-sky-500',
    resources: 'text-sky-500',
    components: 'text-amber-500',
    pages: 'text-rose-500',
    routes: 'text-lime-500',
    tests: 'text-green-500',
    config: 'text-slate-500',
    database: 'text-fuchsia-500',
    docs: 'text-blue-500',
};

function fileStyle(name: string): IconStyle {
    const lower = name.toLowerCase();

    if (BY_NAME[lower]) {
        return BY_NAME[lower];
    }

    if (lower === '.env' || lower.startsWith('.env.')) {
        return { icon: FileKey, color: 'text-yellow-600' };
    }

    const dot = lower.lastIndexOf('.');
    const extension = dot > 0 ? lower.slice(dot + 1) : '';

    return (
        BY_EXTENSION[extension] ?? {
            icon: File,
            color: 'text-muted-foreground',
        }
    );
}

/**
 * A colored icon for a file or folder, picked from its name.
 */
export default function FileIcon({
    name,
    isDir = false,
    isOpen = false,
    className,
}: {
    name: string;
    isDir?: boolean;
    isOpen?: boolean;
    className?: string;
}) {
    const { icon: Icon, color } = isDir
        ? {
              icon: isOpen ? FolderOpen : Folder,
              color: FOLDER_COLORS[name.toLowerCase()] ?? 'text-sky-600/80',
          }
        : fileStyle(name);

    return <Icon className={cn('size-4 shrink-0', color, className)} />;
}
