import { Button } from '@/Components/ui/button';
import { Paginated } from '@/types';
import { router } from '@inertiajs/react';

export default function Pagination({ meta }: { meta: Paginated<unknown> }) {
    if (meta.last_page <= 1) return null;

    return (
        <div className="flex items-center justify-between pt-4">
            <p className="text-sm text-muted-foreground">
                Page {meta.current_page} of {meta.last_page} · {meta.total} records
            </p>
            <div className="flex gap-1">
                {meta.links.map((link, i) => (
                    <Button
                        key={i}
                        size="sm"
                        variant={link.active ? 'default' : 'outline'}
                        disabled={!link.url}
                        onClick={() => link.url && router.get(link.url, {}, { preserveState: true })}
                        dangerouslySetInnerHTML={{ __html: link.label }}
                    />
                ))}
            </div>
        </div>
    );
}
