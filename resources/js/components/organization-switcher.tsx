import { Form, Link, usePage } from '@inertiajs/react';
import { Check, ChevronsUpDown, Plus, Settings } from 'lucide-react';
import { useRef, useState } from 'react';
import OrganizationController from '@/actions/App/Http/Controllers/OrganizationController';
import InputError from '@/components/input-error';
import { OrganizationMark } from '@/components/organization-mark';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
    useSidebar,
} from '@/components/ui/sidebar';
import { useIsMobile } from '@/hooks/use-mobile';
import { useOrganization } from '@/hooks/use-organization';
import { edit, home } from '@/routes/organizations';

/**
 * The organization the page is in, and the way to the user's others (ORG-002) or a new one (ORG-003). Shown on the
 * hosted install, or when the user is in more than one.
 */
export function OrganizationSwitcher() {
    const { organizations, multiTenant } = usePage().props;
    const organization = useOrganization();
    const { state } = useSidebar();
    const isMobile = useIsMobile();
    const [creating, setCreating] = useState(false);
    // Opened once the menu has closed, so the menu doesn't take focus back from the dialog's input.
    const pendingCreate = useRef(false);

    if (!multiTenant && (organizations?.length ?? 0) < 2) {
        return null;
    }

    return (
        <SidebarMenu>
            <SidebarMenuItem>
                <DropdownMenu>
                    <DropdownMenuTrigger asChild>
                        <SidebarMenuButton
                            size="lg"
                            className="data-[state=open]:bg-sidebar-accent"
                            data-test="organization-switcher"
                        >
                            <OrganizationMark organization={organization} />
                            <span className="truncate font-medium">
                                {organization.name}
                            </span>
                            <ChevronsUpDown className="ml-auto size-4 text-muted-foreground" />
                        </SidebarMenuButton>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent
                        className="w-(--radix-dropdown-menu-trigger-width) min-w-60 rounded-xl p-1.5"
                        align="start"
                        side={
                            isMobile || state !== 'collapsed'
                                ? 'bottom'
                                : 'right'
                        }
                        onCloseAutoFocus={(event) => {
                            if (pendingCreate.current) {
                                event.preventDefault();
                                pendingCreate.current = false;
                                setCreating(true);
                            }
                        }}
                    >
                        <DropdownMenuLabel className="text-xs text-muted-foreground">
                            Organizations
                        </DropdownMenuLabel>
                        {(organizations ?? []).map((other) => (
                            <DropdownMenuItem key={other.id} asChild>
                                <Link
                                    href={home(other.slug)}
                                    className="cursor-pointer"
                                    data-test={`switch-to-${other.slug}`}
                                >
                                    <OrganizationMark
                                        organization={other}
                                        className="size-6 text-xs"
                                    />
                                    <span className="truncate">
                                        {other.name}
                                    </span>
                                    {other.id === organization.id && (
                                        <Check className="ml-auto size-4" />
                                    )}
                                </Link>
                            </DropdownMenuItem>
                        ))}
                        <DropdownMenuSeparator />
                        <DropdownMenuItem asChild>
                            <Link
                                href={edit(organization.slug)}
                                className="cursor-pointer"
                            >
                                <Settings />
                                Organization settings
                            </Link>
                        </DropdownMenuItem>
                        {multiTenant && (
                            <DropdownMenuItem
                                onSelect={() => (pendingCreate.current = true)}
                                data-test="create-organization"
                            >
                                <Plus />
                                Create organization
                            </DropdownMenuItem>
                        )}
                    </DropdownMenuContent>
                </DropdownMenu>

                <Dialog open={creating} onOpenChange={setCreating}>
                    <DialogContent>
                        <DialogTitle>Create organization</DialogTitle>
                        <DialogDescription>
                            You'll own it. Its projects, groups and invites are
                            kept apart from your other organizations.
                        </DialogDescription>
                        <Form
                            {...OrganizationController.store.form()}
                            onSuccess={() => setCreating(false)}
                            className="grid gap-4"
                        >
                            {({ processing, errors }) => (
                                <>
                                    <div className="grid gap-2">
                                        <Label htmlFor="organization-name">
                                            Name
                                        </Label>
                                        <Input
                                            id="organization-name"
                                            name="name"
                                            required
                                            autoFocus
                                            placeholder="Acme"
                                            data-test="organization-name"
                                        />
                                        <InputError message={errors.name} />
                                    </div>
                                    <DialogFooter>
                                        <Button
                                            disabled={processing}
                                            data-test="create-organization-button"
                                        >
                                            Create
                                        </Button>
                                    </DialogFooter>
                                </>
                            )}
                        </Form>
                    </DialogContent>
                </Dialog>
            </SidebarMenuItem>
        </SidebarMenu>
    );
}
