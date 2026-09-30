import { Form, Head } from '@inertiajs/react';
import { Globe, Lock, LockOpen } from 'lucide-react';
import ServerController from '@/actions/App/Http/Controllers/Admin/ServerController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type Requested = {
    domain: string | null;
    email: string | null;
    certificates: 'letsencrypt' | 'local';
    requested_at: string | null;
    status: 'pending' | 'applied' | 'failed' | null;
    message: string | null;
};

/** The server's domain and certificates (ADMIN-004). */
export default function Server({
    install,
    editable,
    current,
    requested,
}: {
    install: 'server' | 'container' | null;
    editable: boolean;
    current: { url: string; domain: string; https: boolean; gateway: boolean };
    requested: Requested;
}) {
    return (
        <>
            <Head title="Server" />

            <div className="space-y-6">
                <Heading
                    variant="small"
                    title="Server domain"
                    description="The address OneDrop is served at. Previews and shells use preview-<id> and shell-<id> under it."
                />

                <div
                    className="flex items-center gap-3 rounded-xl border p-4"
                    data-test="current-address"
                >
                    <Globe className="size-5 text-muted-foreground" />
                    <div className="min-w-0 flex-1">
                        <p className="truncate font-medium">{current.url}</p>
                        <p className="text-sm text-muted-foreground">
                            {current.gateway
                                ? `Previews at preview-<id>.${current.domain}`
                                : 'Previews open on this computer'}
                        </p>
                    </div>
                    {current.https ? (
                        <Badge variant="outline" className="gap-1">
                            <Lock className="size-3" /> HTTPS
                        </Badge>
                    ) : (
                        <Badge variant="outline" className="gap-1">
                            <LockOpen className="size-3" /> HTTP
                        </Badge>
                    )}
                </div>

                {requested.status === 'pending' && (
                    <p
                        className="text-sm text-amber-600 dark:text-amber-400"
                        data-test="server-pending"
                    >
                        Switching to {requested.domain}… The server applies it
                        in the background; everyone logs in again at the new
                        address.
                    </p>
                )}
                {requested.status === 'failed' && (
                    <p className="text-sm text-destructive">
                        Couldn't switch to {requested.domain}:{' '}
                        {requested.message ?? 'unknown error'}
                    </p>
                )}

                {editable ? (
                    <Form
                        {...ServerController.update.form()}
                        options={{ preserveScroll: true }}
                        className="space-y-6"
                    >
                        {({ processing, errors }) => (
                            <>
                                <div className="grid gap-4 sm:grid-cols-2">
                                    <div className="grid content-start gap-2">
                                        <Label htmlFor="domain">Domain</Label>
                                        <Input
                                            id="domain"
                                            name="domain"
                                            defaultValue={
                                                requested.domain ??
                                                current.domain
                                            }
                                            placeholder="onedrop.example.com"
                                            required
                                            data-test="domain-input"
                                        />
                                        <InputError message={errors.domain} />
                                    </div>
                                    <div className="grid content-start gap-2">
                                        <Label htmlFor="email">
                                            Let's Encrypt email
                                        </Label>
                                        <Input
                                            id="email"
                                            name="email"
                                            type="email"
                                            defaultValue={requested.email ?? ''}
                                            placeholder="Optional"
                                        />
                                        <p className="text-xs text-muted-foreground">
                                            Gets notices about certificates that
                                            are about to expire.
                                        </p>
                                        <InputError message={errors.email} />
                                    </div>
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="certificates">
                                        Certificates
                                    </Label>
                                    <select
                                        id="certificates"
                                        name="certificates"
                                        defaultValue={requested.certificates}
                                        className="h-9 rounded-md border border-input bg-transparent px-3 text-sm shadow-xs"
                                    >
                                        <option value="letsencrypt">
                                            Let's Encrypt (HTTPS for a public
                                            domain)
                                        </option>
                                        <option value="local">
                                            OneDrop's own (a LAN without public
                                            DNS; browsers must trust it)
                                        </option>
                                    </select>
                                    <InputError message={errors.certificates} />
                                </div>

                                <p className="text-sm text-muted-foreground">
                                    Point the domain and *.domain at this server
                                    (two DNS records) before saving, and keep
                                    ports 80 and 443 open. Changing the domain
                                    signs everyone out.
                                </p>

                                <Button
                                    disabled={processing}
                                    data-test="save-server-button"
                                >
                                    Save
                                </Button>
                            </>
                        )}
                    </Form>
                ) : (
                    <div
                        className="space-y-2 rounded-xl border border-dashed p-4 text-sm text-muted-foreground"
                        data-test="server-instructions"
                    >
                        {install === 'container' ? (
                            <>
                                <p>
                                    This is a one-line install. To change its
                                    domain, run this on the server:
                                </p>
                                <pre className="rounded-md bg-muted px-3 py-2 font-mono text-foreground">
                                    drop install --domain your.domain.com
                                </pre>
                            </>
                        ) : (
                            <p>
                                This install's address comes from its own
                                configuration (APP_URL and
                                SANDBOX_GATEWAY_DOMAIN in .env, or the AWS
                                stack's settings). Change it there and redeploy.
                            </p>
                        )}
                    </div>
                )}
            </div>
        </>
    );
}
