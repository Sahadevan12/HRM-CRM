import { Head } from '@inertiajs/react';

export default function Index({ message }: { message: string }) {
    return (
        <>
            <Head title="Hello Module" />
            <div className="p-8 text-xl font-semibold">{message}</div>
        </>
    );
}
