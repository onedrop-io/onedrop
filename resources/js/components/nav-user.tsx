import { usePage } from '@inertiajs/react';
import { Settings } from 'lucide-react';
import { useRef, useState } from 'react';
import { CreateOrganizationDialog } from '@/components/organization-menu';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
    useSidebar,
} from '@/components/ui/sidebar';
import { UserInfo } from '@/components/user-info';
import { UserMenuContent } from '@/components/user-menu-content';
import { useIsMobile } from '@/hooks/use-mobile';
import { useOrganization } from '@/hooks/use-organization';

export function NavUser() {
    const { auth } = usePage().props;
    const { state } = useSidebar();
    const isMobile = useIsMobile();
    const organization = useOrganization();
    const [creating, setCreating] = useState(false);
    // Opened once the menu has closed, so the menu doesn't take focus back from the dialog's input.
    const pendingCreate = useRef(false);

    if (!auth.user) {
        return null;
    }

    return (
        <SidebarMenu>
            <SidebarMenuItem>
                <DropdownMenu>
                    <DropdownMenuTrigger asChild>
                        <SidebarMenuButton
                            size="lg"
                            className="group text-sidebar-accent-foreground data-[state=open]:bg-sidebar-accent"
                            data-test="sidebar-menu-button"
                        >
                            <UserInfo
                                user={auth.user}
                                detail={organization.name}
                            />
                            <Settings className="ml-auto size-4 text-muted-foreground" />
                        </SidebarMenuButton>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent
                        className="w-(--radix-dropdown-menu-trigger-width) min-w-60 rounded-xl p-1.5"
                        align="start"
                        side={
                            isMobile
                                ? 'bottom'
                                : state === 'collapsed'
                                  ? 'right'
                                  : 'top'
                        }
                        onCloseAutoFocus={(event) => {
                            if (pendingCreate.current) {
                                event.preventDefault();
                                pendingCreate.current = false;
                                setCreating(true);
                            }
                        }}
                    >
                        <UserMenuContent
                            user={auth.user}
                            onCreateOrganization={() =>
                                (pendingCreate.current = true)
                            }
                        />
                    </DropdownMenuContent>
                </DropdownMenu>
                <CreateOrganizationDialog
                    open={creating}
                    onOpenChange={setCreating}
                />
            </SidebarMenuItem>
        </SidebarMenu>
    );
}
