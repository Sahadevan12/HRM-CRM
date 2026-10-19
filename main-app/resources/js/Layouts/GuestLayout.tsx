import { Link } from '@inertiajs/react';
import { PropsWithChildren } from 'react';

export default function Guest({ children }: PropsWithChildren) {
    return (
        <div className="flex min-h-screen flex-col items-center bg-mesh pt-6 sm:justify-center sm:pt-0">
            <div>
                <Link href="/">
                    <span className="text-4xl font-extrabold tracking-tight text-white drop-shadow">ERP Suite</span>
                </Link>
            </div>

            <div className="mt-6 w-full overflow-hidden bg-white px-6 py-6 shadow-2xl sm:max-w-md sm:rounded-xl">
                {children}
            </div>
        </div>
    );
}
