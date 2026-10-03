import { Link, router } from '@inertiajs/react';
import {
    ChartArea,
    BookOpen,
    Building2,
    CircleHelp,
    FolderGit2,
    LogOut,
    Palette,
    Settings,
    SlidersHorizontal,
    UserPlus,
} from 'lucide-react';
import {
    DropdownMenuGroup,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuRadioGroup,
    DropdownMenuRadioItem,
    DropdownMenuSeparator,
    DropdownMenuSub,
    DropdownMenuSubContent,
    DropdownMenuSubTrigger,
} from '@/components/ui/dropdown-menu';
import { OrganizationSubmenu } from '@/components/organization-menu';
import { UserInfo } from '@/components/user-info';
import type { Appearance } from '@/hooks/use-appearance';
import { useAppearance } from '@/hooks/use-appearance';
import { useBuildMode } from '@/hooks/use-build-mode';
import { useMobileNavigation } from '@/hooks/use-mobile-navigation';
import { useOrganization } from '@/hooks/use-organization';
import { DOCUMENTATION_URL, REPOSITORY_URL } from '@/lib/links';
import { logout } from '@/routes';
import { index as invitationsIndex } from '@/routes/invitations';
import { usage as organizationUsage } from '@/routes/organizations';
import { edit } from '@/routes/profile';
import { index as usageIndex } from '@/routes/usage';
import type { BuildMode, User } from '@/types';

type Props = {
    user: User;
    /** Opens the create-organization dialog, kept outside the menu by its owner (ORG-003). */
    onCreateOrganization?: () => void;
};

const THEMES: { value: Appearance; label: string }[] = [
    { value: 'light', label: 'Light' },
    { value: 'dark', label: 'Dark' },
    { value: 'system', label: 'System' },
];

const MODES: { value: BuildMode; label: string }[] = [
    { value: 'simple', label: 'Simple' },
    { value: 'advanced', label: 'Advanced' },
];

export function UserMenuContent({ user, onCreateOrganization }: Props) {
    const cleanup = useMobileNavigation();
    const organization = useOrganization();
    const { appearance, updateAppearance } = useAppearance();
    const { chosen: mode, choose: chooseMode } = useBuildMode();

    const handleLogout = () => {
        cleanup();
        router.flushAll();
    };

    return (
        <>
            <DropdownMenuLabel className="p-0 font-normal">
                <div className="flex items-center gap-2 px-1 py-1.5 text-left text-sm">
                    <UserInfo user={user} showEmail={true} />
                </div>
            </DropdownMenuLabel>
            <DropdownMenuSeparator />
            <DropdownMenuGroup>
                <OrganizationSubmenu onCreate={onCreateOrganization} />
            </DropdownMenuGroup>
            <DropdownMenuSeparator />
            <DropdownMenuGroup>
                <DropdownMenuItem asChild>
                    <Link
                        className="w-full cursor-pointer"
                        href={edit()}
                        prefetch
                        onClick={cleanup}
                        data-test="settings-link"
                    >
                        <Settings />
                        Settings
                    </Link>
                </DropdownMenuItem>
                <DropdownMenuItem asChild>
                    <Link
                        className="w-full cursor-pointer"
                        href={usageIndex()}
                        prefetch
                        onClick={cleanup}
                        data-test="usage-link"
                    >
                        <ChartArea />
                        Usage
                    </Link>
                </DropdownMenuItem>
                {organization.manages && (
                    <DropdownMenuItem asChild>
                        <Link
                            className="w-full cursor-pointer"
                            href={organizationUsage(organization.slug)}
                            prefetch
                            onClick={cleanup}
                            data-test="organization-usage-link"
                        >
                            <Building2 />
                            {organization.name} usage
                        </Link>
                    </DropdownMenuItem>
                )}
                <DropdownMenuItem asChild>
                    <Link
                        className="w-full cursor-pointer"
                        href={invitationsIndex(organization.slug)}
                        prefetch
                        onClick={cleanup}
                    >
                        <UserPlus />
                        Invite people
                    </Link>
                </DropdownMenuItem>
                <DropdownMenuSub>
                    <DropdownMenuSubTrigger
                        className="gap-2 [&>svg:last-child]:ml-0"
                        data-test="theme-menu"
                    >
                        <Palette className="size-4 text-muted-foreground" />
                        Theme
                        <span className="ml-auto text-muted-foreground">
                            {THEMES.find(({ value }) => value === appearance)
                                ?.label ?? 'System'}
                        </span>
                    </DropdownMenuSubTrigger>
                    <DropdownMenuSubContent>
                        <DropdownMenuRadioGroup
                            value={appearance}
                            onValueChange={(value) =>
                                updateAppearance(value as Appearance)
                            }
                        >
                            {THEMES.map(({ value, label }) => (
                                <DropdownMenuRadioItem
                                    key={value}
                                    value={value}
                                >
                                    {label}
                                </DropdownMenuRadioItem>
                            ))}
                        </DropdownMenuRadioGroup>
                    </DropdownMenuSubContent>
                </DropdownMenuSub>
                <DropdownMenuSub>
                    <DropdownMenuSubTrigger
                        className="gap-2 [&>svg:last-child]:ml-0"
                        data-test="mode-menu"
                    >
                        <SlidersHorizontal className="size-4 text-muted-foreground" />
                        Mode
                        <span className="ml-auto text-muted-foreground">
                            {MODES.find(({ value }) => value === mode)?.label ??
                                'Advanced'}
                        </span>
                    </DropdownMenuSubTrigger>
                    <DropdownMenuSubContent>
                        <DropdownMenuRadioGroup
                            value={mode ?? 'advanced'}
                            onValueChange={(value) =>
                                chooseMode(value as BuildMode)
                            }
                        >
                            {MODES.map(({ value, label }) => (
                                <DropdownMenuRadioItem
                                    key={value}
                                    value={value}
                                    data-test={`mode-menu-${value}`}
                                >
                                    {label}
                                </DropdownMenuRadioItem>
                            ))}
                        </DropdownMenuRadioGroup>
                    </DropdownMenuSubContent>
                </DropdownMenuSub>
                <DropdownMenuSub>
                    <DropdownMenuSubTrigger
                        className="gap-2"
                        data-test="help-menu"
                    >
                        <CircleHelp className="size-4 text-muted-foreground" />
                        Help
                    </DropdownMenuSubTrigger>
                    <DropdownMenuSubContent>
                        <DropdownMenuItem asChild>
                            <a
                                href={DOCUMENTATION_URL}
                                target="_blank"
                                rel="noopener noreferrer"
                                className="cursor-pointer"
                            >
                                <BookOpen />
                                Documentation
                            </a>
                        </DropdownMenuItem>
                        <DropdownMenuItem asChild>
                            <a
                                href={REPOSITORY_URL}
                                target="_blank"
                                rel="noopener noreferrer"
                                className="cursor-pointer"
                            >
                                <FolderGit2 />
                                Repository
                            </a>
                        </DropdownMenuItem>
                    </DropdownMenuSubContent>
                </DropdownMenuSub>
            </DropdownMenuGroup>
            <DropdownMenuSeparator />
            <DropdownMenuItem asChild>
                <Link
                    className="w-full cursor-pointer"
                    href={logout()}
                    as="button"
                    onClick={handleLogout}
                    data-test="logout-button"
                >
                    <LogOut />
                    Log out
                </Link>
            </DropdownMenuItem>
        </>
    );
}
