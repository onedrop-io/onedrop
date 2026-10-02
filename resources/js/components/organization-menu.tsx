import { Form, Link, usePage } from '@inertiajs/react';
import { Check, Plus, Settings } from 'lucide-react';
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
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuSub,
    DropdownMenuSubContent,
    DropdownMenuSubTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useOrganization } from '@/hooks/use-organization';
import { edit, home } from '@/routes/organizations';

/**
 * The account menu's Organization submenu: the user's organizations to switch to (ORG-002), the organization's
 * settings, and a new one (ORG-003, hosted only, when `onCreate` is given). A self-hosted install lists its one.
 */
export function OrganizationSubmenu({ onCreate }: { onCreate?: () => void }) {
    const { userOrganizations: organizations, multiTenant } = usePage().props;
    const organization = useOrganization();

    return (
        <DropdownMenuSub>
            <DropdownMenuSubTrigger
                className="gap-2"
                data-test="organization-switcher"
            >
                <OrganizationMark
                    organization={organization}
                    className="size-4 text-[0.5rem]"
                />
                <span className="truncate">{organization.name}</span>
            </DropdownMenuSubTrigger>
            <DropdownMenuSubContent className="min-w-56">
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
                            <span className="truncate">{other.name}</span>
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
                {multiTenant && onCreate && (
                    <DropdownMenuItem
                        onSelect={onCreate}
                        data-test="create-organization"
                    >
                        <Plus />
                        Create organization
                    </DropdownMenuItem>
                )}
            </DropdownMenuSubContent>
        </DropdownMenuSub>
    );
}

/**
 * Names a new organization the user will own (ORG-003). Lives outside the menu, which unmounts when it closes.
 */
export function CreateOrganizationDialog({
    open,
    onOpenChange,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent>
                <DialogTitle>Create organization</DialogTitle>
                <DialogDescription>
                    You'll own it. Its projects, groups and invites are kept
                    apart from your other organizations.
                </DialogDescription>
                <Form
                    {...OrganizationController.store.form()}
                    onSuccess={() => onOpenChange(false)}
                    className="grid gap-4"
                >
                    {({ processing, errors }) => (
                        <>
                            <div className="grid gap-2">
                                <Label htmlFor="organization-name">Name</Label>
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
    );
}
