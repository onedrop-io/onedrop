import { Head } from '@inertiajs/react';
import { isValidElement } from 'react';
import type { ReactNode } from 'react';
import { FormFill } from '@/components/table/form-fill';
import type { FormPageData } from '@/components/table/types';

/** A table's form view on its own page (TABLE-009), signed in or through its public link. */
export default function FormPage({ form }: { form: FormPageData }) {
    return (
        <>
            <Head title={form.title || form.tableName} />
            <main className="min-h-svh bg-neutral-100 px-3 py-6 sm:px-4 sm:py-14 dark:bg-neutral-900">
                <div className="mx-auto w-full max-w-2xl">
                    <FormFill data={form} />
                </div>
            </main>
        </>
    );
}

/**
 * No app layout (no sidebar): people without an account fill this in. Inertia 2 calls this with the page
 * and shows what it returns; Inertia 3 calls it with the props first, and null there means "no layout"
 * (returning the props would wrap the page in the app's default layout).
 */
FormPage.layout = (page: ReactNode) => (isValidElement(page) ? page : null);
